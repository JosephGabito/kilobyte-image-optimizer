import { clampQuality, computeTargetDimensions } from '../../src-js/core/dimensions';
import {
	extForMime,
	isSupportedMime,
	resolveInputMime,
	swapExtension,
	verifyBlobSignature,
} from '../../src-js/core/format';
import {
	assertBoundedRaster,
	isAnimated,
	MAX_IMAGE_BYTES,
	MAX_IMAGE_DIMENSION,
	renderToCanvas,
} from '../../src-js/core/rasterEncoder';
import { assertCompleteJpeg, readRasterDimensions } from '../../src-js/core/rasterHeader';
import { encodeLosslessPngBytes, encodePng, encodeWasmAvif, encodeWasmWebp } from './codecs';
import type { ToolProcessRequest, ToolProcessResult, ToolProcessStage } from './types';

async function canvasToVerifiedBlob(
	canvas: HTMLCanvasElement | OffscreenCanvas,
	mime: string,
	qualityFraction: number
): Promise< Blob | null > {
	try {
		const blob = 'convertToBlob' in canvas
			? await canvas.convertToBlob( { type: mime, quality: qualityFraction } )
			: await new Promise< Blob | null >( ( resolve ) => canvas.toBlob( resolve, mime, qualityFraction ) );
		if ( ! blob || blob.size === 0 || blob.type !== mime ) {
			return null;
		}
		const valid = await verifyBlobSignature( blob, mime );
		return valid ? blob : null;
	} catch {
		return null;
	}
}

function checkAborted( signal?: AbortSignal ): void {
	if ( signal?.aborted ) {
		throw new DOMException( 'Processing cancelled.', 'AbortError' );
	}
}

export async function processStandaloneImage(
	request: ToolProcessRequest,
	canvasFor: ( width: number, height: number ) => HTMLCanvasElement | OffscreenCanvas,
	signal?: AbortSignal,
	onStage?: ( stage: ToolProcessStage ) => void
): Promise< ToolProcessResult > {
	const startedAt = performance.now();
	const {
		file,
		outputMime,
		quality,
		pngMode = 'lossy',
		maxWidth = 0,
		maxHeight = 0,
		resize,
		codecBaseUrl,
		preferWasm = false,
	} = request;

	checkAborted( signal );
	onStage?.( 'validating' );

	const inputMime = resolveInputMime( file );
	if ( ! inputMime || ! isSupportedMime( inputMime ) ) {
		throw new Error( 'unsupported_input_format' );
	}
	if ( ! file.size ) {
		throw new Error( 'empty_file' );
	}
	if ( file.size > MAX_IMAGE_BYTES ) {
		throw new Error( 'image_too_large' );
	}

	await assertBoundedRaster( file );
	checkAborted( signal );
	if ( inputMime === 'image/jpeg' ) {
		await assertCompleteJpeg( file );
		checkAborted( signal );
	}
	if ( await isAnimated( file ) ) {
		throw new Error( 'animated_image' );
	}
	checkAborted( signal );

	// Bit-exact PNG-to-PNG lossless path: never route through 8-bit premultiplied sRGB Canvas
	// when no resize or format conversion is needed. Preserves semi-transparent RGBA samples,
	// 16-bit depth, and ICC profiles while stripping EXIF/text metadata chunks.
	if ( inputMime === 'image/png' && outputMime === 'image/png' && pngMode === 'lossless' ) {
		const sourceDims = await readRasterDimensions( file );
		if ( ! sourceDims ) {
			throw new Error( 'Cannot safely read image dimensions in the browser.' );
		}
		const targetDims = computeTargetDimensions( sourceDims[ 0 ], sourceDims[ 1 ], resize, maxWidth, maxHeight );
		if ( ! targetDims.resized ) {
			const rawBytes = new Uint8Array( await file.arrayBuffer() );
			checkAborted( signal );
			const pngOut = await encodeLosslessPngBytes( rawBytes, codecBaseUrl, onStage );
			onStage?.( 'verifying' );
			if ( ! ( await verifyBlobSignature( pngOut.blob, 'image/png' ) ) ) {
				throw new Error( 'unsupported_output_format' );
			}
			const originalSize = file.size;
			const optimizedSize = pngOut.blob.size;
			const savedBytes = originalSize - optimizedSize;
			const savedPercent = originalSize > 0
				? Math.round( ( ( originalSize - optimizedSize ) / originalSize ) * 1000 ) / 10
				: 0;
			return {
				blob: pngOut.blob,
				filename: swapExtension( file.name, 'png' ),
				mimeType: 'image/png',
				originalSize,
				optimizedSize,
				savedBytes,
				savedPercent,
				sourceWidth: sourceDims[ 0 ],
				sourceHeight: sourceDims[ 1 ],
				width: sourceDims[ 0 ],
				height: sourceDims[ 1 ],
				resized: false,
				encoder: pngOut.encoder,
				durationMs: Math.round( performance.now() - startedAt ),
				keptOriginal: false,
				notice: savedBytes <= 0
					? 'This PNG is already losslessly packed. Try Palette mode or WebP/AVIF for further savings.'
					: undefined,
			};
		}
	}

	onStage?.( 'decoding' );
	const fillBackground = outputMime === 'image/jpeg' ? '#ffffff' : undefined;
	const rendered = await renderToCanvas(
		{ file, maxWidth, maxHeight, resize },
		canvasFor,
		fillBackground,
		{ skipBoundsCheck: true, maxOutputDimension: MAX_IMAGE_DIMENSION }
	);
	const { canvas, context, width, height, sourceWidth, sourceHeight, resized } = rendered;

	const releaseCanvas = () => {
		canvas.width = 0;
		canvas.height = 0;
	};

	const qualityFraction = clampQuality( quality );
	let blob: Blob | null = null;
	let encoder: ToolProcessResult[ 'encoder' ] = 'native';

	try {
		checkAborted( signal );

		if ( outputMime === 'image/png' ) {
			const imageData = context.getImageData( 0, 0, width, height );
			releaseCanvas();
			checkAborted( signal );
			const pngOut = await encodePng( imageData, {
				mode: pngMode,
				quality,
				codecBaseUrl,
				onStage,
			} );
			blob = pngOut.blob;
			encoder = pngOut.encoder;
		} else {
			if ( ! preferWasm || outputMime === 'image/jpeg' ) {
				onStage?.( 'encoding' );
				blob = await canvasToVerifiedBlob( canvas, outputMime, qualityFraction );
				encoder = 'native';
			}

			if ( ! blob && outputMime === 'image/webp' ) {
				const imageData = context.getImageData( 0, 0, width, height );
				releaseCanvas();
				checkAborted( signal );
				blob = await encodeWasmWebp( imageData, quality, codecBaseUrl, onStage );
				encoder = 'wasm-webp';
			} else if ( ! blob && outputMime === 'image/avif' ) {
				const imageData = context.getImageData( 0, 0, width, height );
				releaseCanvas();
				checkAborted( signal );
				blob = await encodeWasmAvif( imageData, quality, codecBaseUrl, onStage );
				encoder = 'wasm-avif';
			}
		}
	} finally {
		releaseCanvas();
	}

	checkAborted( signal );
	onStage?.( 'verifying' );

	if ( ! blob || ! ( await verifyBlobSignature( blob, outputMime ) ) ) {
		throw new Error( 'unsupported_output_format' );
	}

	const originalSize = file.size;
	const optimizedSize = blob.size;
	const savedBytes = originalSize - optimizedSize;
	const savedPercent = originalSize > 0
		? Math.round( ( ( originalSize - optimizedSize ) / originalSize ) * 1000 ) / 10
		: 0;

	let notice: string | undefined;
	if ( inputMime === 'image/png' && outputMime === 'image/jpeg' ) {
		notice = 'Transparent pixels were filled with white because JPEG does not support transparency.';
	} else if ( outputMime === 'image/png' && pngMode === 'lossless' && ( resized || inputMime !== 'image/png' ) ) {
		notice = 'Resizing or converting to PNG decoded the image through 8-bit sRGB Canvas before lossless PNG packing.';
	} else if ( savedBytes <= 0 && inputMime === outputMime && ! resized ) {
		notice = 'This image is already well optimized at this quality setting. Try a lower quality or WebP/AVIF for further savings.';
	}

	const filename = swapExtension( file.name, extForMime( outputMime ) );

	return {
		blob,
		filename,
		mimeType: outputMime,
		originalSize,
		optimizedSize,
		savedBytes,
		savedPercent,
		sourceWidth,
		sourceHeight,
		width,
		height,
		resized,
		encoder,
		durationMs: Math.round( performance.now() - startedAt ),
		keptOriginal: false,
		notice,
	};
}
