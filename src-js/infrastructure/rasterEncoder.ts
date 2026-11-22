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

export async function isAnimated( file: File ): Promise< boolean > {
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
