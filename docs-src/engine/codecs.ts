import { verifyFileSignature, verifyPngComplete } from '../../src-js/core/format';
import { parseTiffOrientation } from '../../src-js/core/rasterHeader';
import type { PngOptimizationMode } from '../../src-js/core/types';
import { transformLosslessPngOrientation } from './pngOrientation';
import type { ToolProcessStage } from './types';

type OxipngExports = {
	optimise: ( data: Uint8Array, level: number, interlace: boolean, optimiseAlpha: boolean ) => Uint8Array;
	optimise_raw: (
		data: Uint8Array,
		width: number,
		height: number,
		level: number,
		interlace: boolean,
		optimiseAlpha: boolean
	) => Uint8Array;
};

type WebpModule = {
	encode: ( data: Uint8Array, width: number, height: number, options: Record< string, number > ) => Uint8Array | null;
};

type AvifModule = {
	encode: ( data: Uint8Array, width: number, height: number, options: Record< string, unknown > ) => Uint8Array | null;
};

/**
 * Chunks required to preserve bit-exact pixel samples, transparency, and color management
 * while stripping privacy-sensitive metadata (eXIf, tEXt, iTXt, zTXt, tIME, etc.).
 */
const PNG_COLOR_AND_PIXEL_CHUNKS = new Set< string >( [
	'IHDR',
	'PLTE',
	'IDAT',
	'IEND',
	'tRNS',
	'cHRM',
	'gAMA',
	'iCCP',
	'sBIT',
	'sRGB',
	'cICP',
] );

let oxipngPromise: Promise< OxipngExports > | null = null;
let webpPromise: Promise< WebpModule > | null = null;
let avifPromise: Promise< AvifModule > | null = null;

function joinUrl( base: string, file: string ): string {
	return `${ base.replace( /\/+$/, '' ) }/${ file }`;
}

async function loadOxipng( codecBaseUrl: string, onStage?: ( stage: ToolProcessStage ) => void ): Promise< OxipngExports > {
	if ( ! oxipngPromise ) {
		onStage?.( 'loading-codec' );
		oxipngPromise = ( async () => {
			try {
				const mod = await import(
					/* webpackChunkName: "codec-oxipng" */
					'@jsquash/oxipng/codec/pkg/squoosh_oxipng.js'
				);
				const wasmUrl = joinUrl( codecBaseUrl, 'squoosh_oxipng_bg.wasm' );
				await mod.default( wasmUrl );
				return {
					optimise: mod.optimise,
					optimise_raw: mod.optimise_raw,
				};
			} catch {
				throw new Error( 'codec_load_failed' );
			}
		} )().catch( ( err ) => {
			oxipngPromise = null;
			throw err;
		} );
	}
	return oxipngPromise;
}

async function loadWasmWebp( codecBaseUrl: string, onStage?: ( stage: ToolProcessStage ) => void ): Promise< WebpModule > {
	if ( ! webpPromise ) {
		onStage?.( 'loading-codec' );
		webpPromise = ( async () => {
			try {
				const mod = await import(
					/* webpackChunkName: "codec-webp" */
					'@jsquash/webp/codec/enc/webp_enc.js'
				);
				const wasmUrl = joinUrl( codecBaseUrl, 'webp_enc.wasm' );
				const factory = mod.default as ( opts: Record< string, unknown > ) => Promise< WebpModule >;
				return await factory( {
					noInitialRun: true,
					locateFile: () => wasmUrl,
				} );
			} catch {
				throw new Error( 'codec_load_failed' );
			}
		} )().catch( ( err ) => {
			webpPromise = null;
			throw err;
		} );
	}
	return webpPromise;
}

async function loadWasmAvif( codecBaseUrl: string, onStage?: ( stage: ToolProcessStage ) => void ): Promise< AvifModule > {
	if ( ! avifPromise ) {
		onStage?.( 'loading-codec' );
		avifPromise = ( async () => {
			try {
				const mod = await import(
					/* webpackChunkName: "codec-avif" */
					'@jsquash/avif/codec/enc/avif_enc.js'
				);
				const wasmUrl = joinUrl( codecBaseUrl, 'avif_enc.wasm' );
				const factory = mod.default as ( opts: Record< string, unknown > ) => Promise< AvifModule >;
				return await factory( {
					noInitialRun: true,
					locateFile: () => wasmUrl,
				} );
			} catch {
				throw new Error( 'codec_load_failed' );
			}
		} )().catch( ( err ) => {
			avifPromise = null;
			throw err;
		} );
	}
	return avifPromise;
}

function safeQuality( quality: number ): number {
	const n = Number.isFinite( quality ) ? Math.round( quality ) : 82;
	return Math.max( 40, Math.min( 95, n ) );
}

function paletteSizeForQuality( quality: number ): number {
	const q = safeQuality( quality );
	if ( q <= 50 ) {
		return 64;
	}
	if ( q <= 65 ) {
		return 128;
	}
	if ( q <= 78 ) {
		return 192;
	}
	return 256;
}

function stripNonColorPngMetadata( bytes: Uint8Array ): { bytes: Uint8Array; orientation: number } {
	if ( bytes.length < 33 || ! verifyFileSignature( bytes, 'image/png' ) ) {
		throw new Error( 'png_structure_unreadable' );
	}
	const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );
	const parts: Uint8Array[] = [ bytes.subarray( 0, 8 ) ];
	let offset = 8;
	let sawIend = false;
	let orientation = 1;
	let sawExif = false;
	while ( offset + 12 <= bytes.length ) {
		const chunkLen = view.getUint32( offset );
		const end = offset + 12 + chunkLen;
		if ( chunkLen > 0x7fffffff || end > bytes.length ) {
			throw new Error( 'png_structure_unreadable' );
		}
		const type = String.fromCharCode( ...bytes.subarray( offset + 4, offset + 8 ) );
		if ( type === 'eXIf' && ! sawExif ) {
			sawExif = true;
			orientation = parseTiffOrientation( bytes, offset + 8, offset + 8 + chunkLen );
		}
		if ( PNG_COLOR_AND_PIXEL_CHUNKS.has( type ) ) {
			parts.push( bytes.subarray( offset, end ) );
		}
		offset = end;
		if ( type === 'IEND' ) {
			sawIend = true;
			break;
		}
	}
	if ( ! sawIend ) {
		throw new Error( 'png_structure_unreadable' );
	}
	const total = parts.reduce( ( sum, part ) => sum + part.length, 0 );
	const out = new Uint8Array( total );
	let pos = 0;
	for ( const part of parts ) {
		out.set( part, pos );
		pos += part.length;
	}
	return { bytes: out, orientation };
}

/**
 * Bit-exact PNG-to-PNG lossless optimizer. Bypasses Canvas completely so
 * semi-transparent RGBA samples, 16-bit bit depth, and ICC profiles stay intact
 * while stripping all private metadata (eXIf, tEXt, iTXt, zTXt, tIME).
 * When the input carries EXIF Orientation 2..8, the pixel matrix is losslessly
 * oriented into upright pixel order before oxipng packing so every browser and
 * viewer displays the exact upright image without relying on PNG eXIf support.
 */
export async function encodeLosslessPngBytes(
	originalPngBytes: Uint8Array,
	codecBaseUrl: string,
	onStage?: ( stage: ToolProcessStage ) => void
): Promise< { blob: Blob; encoder: 'oxipng' } > {
	const { bytes: filtered, orientation } = stripNonColorPngMetadata( originalPngBytes );
	const oriented = orientation >= 2 && orientation <= 8
		? await transformLosslessPngOrientation( filtered, orientation )
		: filtered;
	const oxipng = await loadOxipng( codecBaseUrl, onStage );
	onStage?.( 'encoding' );
	let out: Uint8Array;
	try {
		// optimiseAlpha = false preserves exact RGB samples even under alpha=0/semi-alpha.
		out = oxipng.optimise( oriented, 2, false, false );
	} catch {
		throw new Error( 'png_structure_unreadable' );
	}
	if ( ! verifyPngComplete( out ) ) {
		throw new Error( 'unsupported_output_format' );
	}
	return {
		blob: new Blob( [ out as BlobPart ], { type: 'image/png' } ),
		encoder: 'oxipng',
	};
}

async function encodeLosslessRawPng(
	imageData: ImageData,
	codecBaseUrl: string,
	onStage?: ( stage: ToolProcessStage ) => void
): Promise< { blob: Blob; encoder: 'oxipng' } > {
	const oxipng = await loadOxipng( codecBaseUrl, onStage );
	onStage?.( 'encoding' );
	const rgba = new Uint8Array( imageData.data.buffer, imageData.data.byteOffset, imageData.data.byteLength );
	const bytes = oxipng.optimise_raw( rgba, imageData.width, imageData.height, 2, false, false );
	if ( ! verifyPngComplete( bytes ) ) {
		throw new Error( 'unsupported_output_format' );
	}
	return {
		blob: new Blob( [ bytes as BlobPart ], { type: 'image/png' } ),
		encoder: 'oxipng',
	};
}

export async function encodePng(
	imageData: ImageData,
	options: {
		mode: PngOptimizationMode;
		quality: number;
		codecBaseUrl: string;
		onStage?: ( stage: ToolProcessStage ) => void;
	}
): Promise< { blob: Blob; encoder: 'upng' | 'oxipng' | 'upng+oxipng' } > {
	const { mode, quality, codecBaseUrl, onStage } = options;

	// Use oxipng.optimise_raw directly for lossless transformed PNGs or tiny images (< 1024 px)
	// where upng-js's fixed output buffer (`w * h * 4 + 100`) can overflow and truncate IEND.
	if ( mode === 'lossless' || imageData.width * imageData.height * 4 < 4096 ) {
		return encodeLosslessRawPng( imageData, codecBaseUrl, onStage );
	}

	onStage?.( 'loading-codec' );
	let upngMod: { default?: unknown; encode?: unknown };
	try {
		upngMod = await import(
			/* webpackChunkName: "codec-upng" */
			'upng-js'
		);
	} catch {
		throw new Error( 'codec_load_failed' );
	}
	const UPNG = ( upngMod.default ?? upngMod ) as {
		encode: ( imgs: ArrayBuffer[], w: number, h: number, cnum: number ) => ArrayBuffer;
	};
	onStage?.( 'encoding' );
	const cnum = paletteSizeForQuality( quality );
	const rgbaBuffer = ( imageData.data.byteOffset === 0 && imageData.data.byteLength === imageData.data.buffer.byteLength )
		? imageData.data.buffer
		: imageData.data.slice().buffer;

	let quantized: Uint8Array | null = null;
	try {
		const encoded = new Uint8Array( UPNG.encode( [ rgbaBuffer ], imageData.width, imageData.height, cnum ) );
		if ( verifyPngComplete( encoded ) ) {
			quantized = encoded;
		}
	} catch {
		quantized = null;
	}

	if ( ! quantized ) {
		return encodeLosslessRawPng( imageData, codecBaseUrl, onStage );
	}

	let oxipng: OxipngExports | null = null;
	try {
		oxipng = await loadOxipng( codecBaseUrl, onStage );
	} catch {
		// If oxipng WASM fails to load, return the already verified complete UPNG PNG.
		return {
			blob: new Blob( [ quantized as BlobPart ], { type: 'image/png' } ),
			encoder: 'upng',
		};
	}

	try {
		onStage?.( 'encoding' );
		const polished = oxipng.optimise( quantized, 2, false, true );
		if ( polished.length > 0 && polished.length < quantized.length && verifyPngComplete( polished ) ) {
			return {
				blob: new Blob( [ polished as BlobPart ], { type: 'image/png' } ),
				encoder: 'upng+oxipng',
			};
		}
	} catch {
		return encodeLosslessRawPng( imageData, codecBaseUrl, onStage );
	}

	return {
		blob: new Blob( [ quantized as BlobPart ], { type: 'image/png' } ),
		encoder: 'upng',
	};
}

export async function encodeWasmWebp(
	imageData: ImageData,
	quality: number,
	codecBaseUrl: string,
	onStage?: ( stage: ToolProcessStage ) => void
): Promise< Blob > {
	const mod = await loadWasmWebp( codecBaseUrl, onStage );
	onStage?.( 'encoding' );
	const rgba = new Uint8Array( imageData.data.buffer, imageData.data.byteOffset, imageData.data.byteLength );
	const encoded = mod.encode( rgba, imageData.width, imageData.height, {
		quality: safeQuality( quality ),
		target_size: 0,
		target_PSNR: 0,
		method: 4,
		sns_strength: 50,
		filter_strength: 60,
		filter_sharpness: 0,
		filter_type: 1,
		partitions: 0,
		segments: 4,
		pass: 1,
		show_compressed: 0,
		preprocessing: 0,
		autofilter: 0,
		partition_limit: 0,
		alpha_compression: 1,
		alpha_filtering: 1,
		alpha_quality: 100,
		lossless: 0,
		exact: 0,
		image_hint: 0,
		emulate_jpeg_size: 0,
		thread_level: 0,
		low_memory: 0,
		near_lossless: 100,
		use_delta_palette: 0,
		use_sharp_yuv: 0,
	} );
	if ( ! encoded ) {
		throw new Error( 'unsupported_output_format' );
	}
	const bytes = new Uint8Array( encoded );
	if ( ! verifyFileSignature( bytes, 'image/webp' ) ) {
		throw new Error( 'unsupported_output_format' );
	}
	return new Blob( [ bytes ], { type: 'image/webp' } );
}

export async function encodeWasmAvif(
	imageData: ImageData,
	quality: number,
	codecBaseUrl: string,
	onStage?: ( stage: ToolProcessStage ) => void
): Promise< Blob > {
	const mod = await loadWasmAvif( codecBaseUrl, onStage );
	onStage?.( 'encoding' );
	const rgba = new Uint8Array( imageData.data.buffer, imageData.data.byteOffset, imageData.data.byteLength );
	// Map 40..95 UI quality to libaom AVIF quality (~31..74) for strong perceptual fidelity & speed=6.
	const clamped = safeQuality( quality );
	const avifQuality = Math.max( 25, Math.min( 85, Math.round( clamped * 0.78 ) ) );
	const encoded = mod.encode( rgba, imageData.width, imageData.height, {
		quality: avifQuality,
		qualityAlpha: -1,
		denoiseLevel: 0,
		tileColsLog2: 0,
		tileRowsLog2: 0,
		speed: 6,
		subsample: 1,
		chromaDeltaQ: false,
		sharpness: 0,
		tune: 0,
		enableSharpYUV: false,
		bitDepth: 8,
		lossless: false,
	} );
	if ( ! encoded ) {
		throw new Error( 'unsupported_output_format' );
	}
	const bytes = new Uint8Array( encoded );
	if ( ! verifyFileSignature( bytes, 'image/avif' ) ) {
		throw new Error( 'unsupported_output_format' );
	}
	return new Blob( [ bytes ], { type: 'image/avif' } );
}
