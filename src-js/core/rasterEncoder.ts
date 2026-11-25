import { computeTargetDimensions } from './dimensions';
import { resolveInputMime, verifyBlobSignature } from './format';
import { assertBoundedRaster as checkRasterHeader, MAX_IMAGE_DIMENSION } from './rasterHeader';
import type { CoreEncodeRequest } from './types';

export const MAX_IMAGE_BYTES = 24 * 1024 * 1024;
export const MAX_IMAGE_PIXELS = 40_000_000;
export { MAX_IMAGE_DIMENSION };

export type EncodeRequest = CoreEncodeRequest;

export async function assertBoundedRaster( file: File ): Promise< void > {
	if ( ! file.size ) {
		throw new Error( 'empty_file' );
	}
	if ( file.size > MAX_IMAGE_BYTES ) {
		throw new Error( 'image_too_large' );
	}
	await checkRasterHeader( file, MAX_IMAGE_PIXELS );
}

function gifFrameCount( bytes: Uint8Array ): number {
	const header = String.fromCharCode( ...bytes.subarray( 0, 6 ) );
	if ( bytes.length < 13 || ( header !== 'GIF87a' && header !== 'GIF89a' ) ) {
		return 0;
	}

	const readSize = ( offset: number ) => bytes[ offset ] + bytes[ offset + 1 ] * 256;
	const canvasWidth = readSize( 6 );
	const canvasHeight = readSize( 8 );
	if ( ! canvasWidth || ! canvasHeight || canvasWidth * canvasHeight > MAX_IMAGE_PIXELS ) {
		return 0;
	}
	const colorTableSize = ( flags: number ) => ( flags & 0x80 ) ? 3 * ( 2 << ( flags & 7 ) ) : 0;
	let offset = 13 + colorTableSize( bytes[ 10 ] );
	let frames = 0;
	while ( offset < bytes.length ) {
		const block = bytes[ offset++ ];
		if ( block === 0x3b ) {
			return offset === bytes.length ? frames : 0;
		}
		if ( block === 0x2c ) {
			frames += 1;
			if ( offset + 9 >= bytes.length ) {
				return 0;
			}
			const width = readSize( offset + 4 );
			const height = readSize( offset + 6 );
			if ( ! width || ! height || readSize( offset ) + width > canvasWidth || readSize( offset + 2 ) + height > canvasHeight ) {
				return 0;
			}
			offset += 10 + colorTableSize( bytes[ offset + 8 ] );
		} else if ( block === 0x21 && offset < bytes.length && bytes[ offset ] !== 0x01 ) {
			offset += 1;
		} else {
			return 0;
		}

		while ( offset < bytes.length ) {
			const length = bytes[ offset++ ];
			if ( length === 0 ) {
				break;
			}
			offset += length;
		}
	}
	return 0;
}

export async function isAnimated( file: File ): Promise< boolean > {
	const mime = resolveInputMime( file );
	if ( mime === 'image/gif' ) {
		const frames = gifFrameCount( new Uint8Array( await file.arrayBuffer() ) );
		if ( ! frames ) {
			throw new Error( 'gif_structure_unreadable' );
		}
		return frames > 1;
	}

	if ( mime === 'image/png' ) {
		// Scan pre-IDAT chunks incrementally without loading the full PNG payload into RAM.
		// Fail closed if chunk headers or structure are incomplete before IDAT/IEND.
		if ( file.size < 33 ) {
			throw new Error( 'png_structure_unreadable' );
		}
		let windowSize = Math.min( file.size, 256 * 1024 );
		while ( true ) {
			const buf = await file.slice( 0, windowSize ).arrayBuffer();
			const raw = new Uint8Array( buf );
			const bytes = new DataView( buf );
			if (
				raw.length < 8 ||
				raw[ 0 ] !== 0x89 || raw[ 1 ] !== 0x50 || raw[ 2 ] !== 0x4e || raw[ 3 ] !== 0x47 ||
				raw[ 4 ] !== 0x0d || raw[ 5 ] !== 0x0a || raw[ 6 ] !== 0x1a || raw[ 7 ] !== 0x0a
			) {
				throw new Error( 'png_structure_unreadable' );
			}
			let offset = 8;
			let needsMore = false;
			while ( offset < file.size ) {
				if ( offset + 12 > bytes.byteLength ) {
					if ( windowSize < file.size ) {
						needsMore = true;
						break;
					}
					throw new Error( 'png_structure_unreadable' );
				}
				const chunkLen = bytes.getUint32( offset );
				if ( chunkLen > 0x7fffffff || offset + 12 + chunkLen > file.size ) {
					throw new Error( 'png_structure_unreadable' );
				}
				const type = bytes.getUint32( offset + 4 );
				if ( type === 0x6163544c ) { // acTL
					return true;
				}
				if ( type === 0x49444154 || type === 0x49454e44 ) { // IDAT or IEND
					return false;
				}
				const nextOffset = offset + 12 + chunkLen;
				if ( nextOffset > bytes.byteLength ) {
					if ( windowSize < file.size ) {
						needsMore = true;
						break;
					}
					throw new Error( 'png_structure_unreadable' );
				}
				offset = nextOffset;
			}
			if ( ! needsMore ) {
				// Reached EOF without finding IDAT or IEND -> truncated/invalid PNG.
				throw new Error( 'png_structure_unreadable' );
			}
			windowSize = Math.min( file.size, Math.max( windowSize * 4, offset + 64 * 1024 ) );
		}
	}

	if ( mime !== 'image/webp' && mime !== 'image/avif' ) {
		return false;
	}
	const bytes = new Uint8Array( await file.slice( 0, 512 ).arrayBuffer() );
	if ( mime === 'image/webp' ) {
		return bytes.length >= 21 && new TextDecoder().decode( bytes.subarray( 12, 16 ) ) === 'VP8X' && Boolean( bytes[ 20 ] & 0x02 );
	}
	// Parse AVIF ftyp box brands only (do not scan arbitrary metadata bytes).
	if ( bytes.length < 16 || String.fromCharCode( ...bytes.subarray( 4, 8 ) ) !== 'ftyp' ) {
		return false;
	}
	const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );
	let boxSize = view.getUint32( 0 );
	let brandStart = 8;
	if ( boxSize === 1 ) {
		if ( bytes.length < 24 ) {
			return false;
		}
		boxSize = view.getUint32( 8 ) * 0x100000000 + view.getUint32( 12 );
		brandStart = 16;
	}
	const limit = Math.min( bytes.length, boxSize );
	const minorVersionOffset = brandStart + 4;
	for ( let i = brandStart; i + 4 <= limit; i += 4 ) {
		if ( i === minorVersionOffset ) {
			continue;
		}
		if ( String.fromCharCode( ...bytes.subarray( i, i + 4 ) ) === 'avis' ) {
			return true;
		}
	}
	return false;
}

export async function renderToCanvas(
	request: Pick< CoreEncodeRequest, 'file' | 'maxWidth' | 'maxHeight' | 'resize' >,
	canvasFor: ( width: number, height: number ) => HTMLCanvasElement | OffscreenCanvas,
	fillBackground?: string,
	options?: { skipBoundsCheck?: boolean; maxOutputDimension?: number }
): Promise< {
	canvas: HTMLCanvasElement | OffscreenCanvas;
	context: CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D;
	width: number;
	height: number;
	sourceWidth: number;
	sourceHeight: number;
	resized: boolean;
} > {
	const { file, maxWidth, maxHeight, resize } = request;
	const inputMime = resolveInputMime( file );
	if ( ! options?.skipBoundsCheck ) {
		await assertBoundedRaster( file );
	}
	if ( typeof createImageBitmap !== 'function' ) {
		throw new Error( 'browser_decode_unavailable' );
	}
	const bitmap = await createImageBitmap( file );
	try {
		if ( inputMime === 'image/gif' ) {
			const headerCanvas = new DataView( await file.slice( 6, 10 ).arrayBuffer() );
			if ( bitmap.width !== headerCanvas.getUint16( 0, true ) || bitmap.height !== headerCanvas.getUint16( 2, true ) ) {
				throw new Error( 'The browser could not read the whole GIF canvas.' );
			}
		}
		if ( bitmap.width * bitmap.height > MAX_IMAGE_PIXELS ) {
			throw new Error( 'image_too_large' );
		}
		const dimensions = computeTargetDimensions( bitmap.width, bitmap.height, resize, maxWidth, maxHeight );
		const outputAxisLimit = options?.maxOutputDimension ?? MAX_IMAGE_DIMENSION;
		if (
			dimensions.width > outputAxisLimit ||
			dimensions.height > outputAxisLimit ||
			dimensions.width * dimensions.height > MAX_IMAGE_PIXELS
		) {
			throw new Error( 'image_too_large' );
		}
		const canvas = canvasFor( dimensions.width, dimensions.height );
		const context = canvas.getContext( '2d' ) as CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D | null;
		if ( ! context || ! ( 'drawImage' in context ) ) {
			canvas.width = 0;
			canvas.height = 0;
			throw new Error( 'Canvas image drawing is unavailable.' );
		}
		if ( fillBackground ) {
			context.fillStyle = fillBackground;
			context.fillRect( 0, 0, dimensions.width, dimensions.height );
		}
		context.imageSmoothingQuality = 'high';
		context.drawImage( bitmap, 0, 0, dimensions.width, dimensions.height );
		return {
			canvas,
			context,
			...dimensions,
			sourceWidth: bitmap.width,
			sourceHeight: bitmap.height,
		};
	} finally {
		bitmap.close();
	}
}

export async function encodeRaster(
	request: CoreEncodeRequest,
	canvasFor: ( width: number, height: number ) => HTMLCanvasElement | OffscreenCanvas
) {
	const { mime, quality } = request;
	const fillBackground = mime === 'image/jpeg' ? '#ffffff' : undefined;
	const { canvas, width, height, resized } = await renderToCanvas( request, canvasFor, fillBackground );

	try {
		const blob = 'convertToBlob' in canvas
			? await canvas.convertToBlob( { type: mime, quality } )
			: await new Promise< Blob | null >( ( resolve ) => canvas.toBlob( resolve, mime, quality ) );
		if ( ! blob?.size || blob.type !== mime || ! ( await verifyBlobSignature( blob, mime ) ) ) {
			throw new Error( 'unsupported_output_format' );
		}
		return { blob, width, height, resized };
	} finally {
		canvas.width = 0;
		canvas.height = 0;
	}
}
