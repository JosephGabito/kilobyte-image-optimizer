import { computeScaledDimensions } from '../domain/dimensions';

export const MAX_IMAGE_BYTES = 24 * 1024 * 1024;
export const MAX_IMAGE_PIXELS = 40_000_000;

export interface EncodeRequest {
	file: File;
	maxWidth: number;
	maxHeight: number;
	mime: string;
	quality: number;
}

function isStillGif( bytes: Uint8Array ): boolean {
	const header = String.fromCharCode( ...bytes.subarray( 0, 6 ) );
	if ( bytes.length < 13 || ( header !== 'GIF87a' && header !== 'GIF89a' ) ) {
		return false;
	}

	const readSize = ( offset: number ) => bytes[ offset ] + bytes[ offset + 1 ] * 256;
	const canvasWidth = readSize( 6 );
	const canvasHeight = readSize( 8 );
	if ( ! canvasWidth || ! canvasHeight || canvasWidth * canvasHeight > MAX_IMAGE_PIXELS ) {
		return false;
	}
	const colorTableSize = ( flags: number ) => ( flags & 0x80 ) ? 3 * ( 2 << ( flags & 7 ) ) : 0;
	let offset = 13 + colorTableSize( bytes[ 10 ] );
	let frames = 0;
	while ( offset < bytes.length ) {
		const block = bytes[ offset++ ];
		if ( block === 0x3b ) {
			return frames === 1 && offset === bytes.length;
		}
		if ( block === 0x2c ) {
			frames += 1;
			if ( frames > 1 || offset + 9 >= bytes.length ) {
				return false;
			}
			const width = readSize( offset + 4 );
			const height = readSize( offset + 6 );
			if ( ! width || ! height || readSize( offset ) + width > canvasWidth || readSize( offset + 2 ) + height > canvasHeight ) {
				return false;
			}
			// Image descriptor, local color table, and LZW minimum code size.
			offset += 10 + colorTableSize( bytes[ offset + 8 ] );
		} else if ( block === 0x21 && bytes[ offset ] !== 0x01 ) {
			offset += 1;
		} else {
			// Plain text is also a rendering block. Keep the original if present.
			return false;
		}

		while ( offset < bytes.length ) {
			const length = bytes[ offset++ ];
			if ( length === 0 ) {
				break;
			}
			offset += length;
		}
	}
	return false;
}

export async function isAnimated( file: File ): Promise< boolean > {
	if ( file.type === 'image/gif' ) {
		return ! isStillGif( new Uint8Array( await file.arrayBuffer() ) );
	}

	if ( file.type === 'image/png' ) {
		const bytes = new DataView( await file.arrayBuffer() );
		for ( let offset = 8; offset + 12 <= bytes.byteLength; ) {
			const type = bytes.getUint32( offset + 4 );
			if ( type === 0x6163544c ) { // acTL
				return true;
			}
			if ( type === 0x49444154 || type === 0x49454e44 ) { // IDAT or IEND
				break;
			}
			offset += 12 + bytes.getUint32( offset );
		}
		return false;
	}

	if ( file.type !== 'image/webp' && file.type !== 'image/avif' ) {
		return false;
	}
	const bytes = new Uint8Array( await file.slice( 0, 128 ).arrayBuffer() );
	if ( file.type === 'image/webp' ) {
		return new TextDecoder().decode( bytes.subarray( 12, 16 ) ) === 'VP8X' && Boolean( bytes[ 20 ] & 0x02 );
	}
	return new TextDecoder().decode( bytes.subarray( 8 ) ).includes( 'avis' );
}

export async function encodeRaster(
	request: EncodeRequest,
	canvasFor: ( width: number, height: number ) => HTMLCanvasElement | OffscreenCanvas
) {
	const { file, maxWidth, maxHeight, mime, quality } = request;
	const bitmap = await createImageBitmap( file );
	try {
		if ( file.type === 'image/gif' ) {
			const canvas = new DataView( await file.slice( 6, 10 ).arrayBuffer() );
			if ( bitmap.width !== canvas.getUint16( 0, true ) || bitmap.height !== canvas.getUint16( 2, true ) ) {
				throw new Error( 'The browser could not read the whole GIF canvas.' );
			}
		}
		if ( bitmap.width * bitmap.height > MAX_IMAGE_PIXELS ) {
			throw new Error( 'image_too_large' );
		}
		const dimensions = computeScaledDimensions( bitmap.width, bitmap.height, maxWidth, maxHeight );
		const canvas = canvasFor( dimensions.width, dimensions.height );
		const context = canvas.getContext( '2d' );
		if ( ! context || ! ( 'drawImage' in context ) ) {
			throw new Error( 'Canvas image drawing is unavailable.' );
		}
		context.imageSmoothingQuality = 'high';
		context.drawImage( bitmap, 0, 0, dimensions.width, dimensions.height );

		const blob = 'convertToBlob' in canvas
			? await canvas.convertToBlob( { type: mime, quality } )
			: await new Promise< Blob | null >( ( resolve ) => canvas.toBlob( resolve, mime, quality ) );
		if ( ! blob?.size || blob.type !== mime ) {
			throw new Error( 'unsupported_output_format' );
		}
		return { blob, ...dimensions };
	} finally {
		bitmap.close();
	}
}
