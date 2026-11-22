import { clampQuality } from '../domain/dimensions';
import { isSupportedMime, resolveTargetMime, swapExtension } from '../domain/format';
import type { BrowserSupport, CompressionResult, PluginSettings } from '../domain/types';
import { assertBoundedRaster, encodeRaster, isAnimated, MAX_IMAGE_BYTES, type EncodeRequest } from './rasterEncoder';

let workerUrl = '';
let browserSupport: Promise< BrowserSupport > | undefined;

async function supportsMime( mime: string ): Promise< boolean > {
	try {
		const canvas = document.createElement( 'canvas' );
		canvas.width = canvas.height = 2;
		const blob = await new Promise< Blob | null >( ( resolve ) => canvas.toBlob( resolve, mime ) );
		return blob?.type === mime;
	} catch {
		return false;
	}
}

function detectSupport(): Promise< BrowserSupport > {
	browserSupport ??= Promise.all( [ supportsMime( 'image/webp' ), supportsMime( 'image/avif' ) ] )
		.then( ( [ webp, avif ] ) => ( { webp, avif } ) );
	return browserSupport;
}

function encodeInWorker(
	request: EncodeRequest,
	signal?: AbortSignal
): ReturnType< typeof encodeRaster > {
	return new Promise( ( resolve, reject ) => {
		const worker = new Worker( workerUrl );
		const cleanup = () => {
			worker.terminate();
			window.clearTimeout( timer );
			signal?.removeEventListener( 'abort', abort );
		};
		const abort = () => {
			cleanup();
			reject( new DOMException( 'Image processing cancelled.', 'AbortError' ) );
		};
		const timer = window.setTimeout( () => {
			cleanup();
			reject( new Error( 'Image compression timed out.' ) );
		}, 30_000 );

		worker.onmessage = ( { data } ) => {
			cleanup();
			if ( data.result ) {
				resolve( data.result );
			} else {
				reject( new Error( data.error || 'Image compression failed.' ) );
			}
		};
		const handleError = () => {
			cleanup();
			reject( new Error( 'Image worker could not start.' ) );
		};
		worker.onerror = handleError;
		worker.onmessageerror = handleError;
		signal?.addEventListener( 'abort', abort, { once: true } );
		if ( signal?.aborted ) {
			abort();
			return;
		}
		try {
			worker.postMessage( request );
		} catch ( error ) {
			cleanup();
			reject( error );
		}
	} );
}

function canUseWorker(): boolean {
	return Boolean( workerUrl ) && typeof Worker === 'function' && typeof OffscreenCanvas === 'function';
}

async function compress(
	file: File,
	settings: PluginSettings,
	signal?: AbortSignal,
	allowMainThread = false
): Promise< CompressionResult< File > > {
	const originalSize = file.size;
	const skipped = ( reason: CompressionResult< File >[ 'reason' ] ): CompressionResult< File > => ( {
		skipped: true, reason, originalSize, optimizedSize: originalSize,
	} );
	if ( ! isSupportedMime( file.type ) ) {
		return skipped( 'unsupported_mime' );
	}
	if ( ! originalSize || originalSize > MAX_IMAGE_BYTES ) {
		throw new Error( 'image_too_large' );
	}
	await assertBoundedRaster( file );
	if ( await isAnimated( file ) ) {
		return skipped( 'animated' );
	}
	if ( file.type === 'image/gif' && settings.format === 'original' ) {
		return skipped( 'original_format_kept' );
	}

	const support = await detectSupport();
	const target = resolveTargetMime( settings.format, file.type, support ) ??
		( allowMainThread ? resolveTargetMime( 'original', file.type, support ) : null );
	if ( ! target ) {
		return skipped( 'unsupported_mime' );
	}
	const request: EncodeRequest = {
		file,
		maxWidth: settings.max_width,
		maxHeight: settings.max_height,
		mime: target.mime,
		quality: clampQuality( settings.quality ),
	};
	const hasWorker = canUseWorker();
	if ( ! hasWorker && ! allowMainThread ) {
		throw new Error( 'The image worker is unavailable.' );
	}
	if ( signal?.aborted ) {
		throw new DOMException( 'Image processing cancelled.', 'AbortError' );
	}

	const onMainThread = () => encodeRaster( request, ( width, height ) => {
		const canvas = document.createElement( 'canvas' );
		canvas.width = width;
		canvas.height = height;
		return canvas;
	} );
	let encoded: Awaited< ReturnType< typeof encodeRaster > >;
	if ( hasWorker ) {
		try {
			encoded = await encodeInWorker( request, signal );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : '';
			if ( ! allowMainThread || signal?.aborted || message === 'image_too_large' || message.includes( 'timed out' ) ) {
				throw error;
			}
			encoded = await onMainThread();
		}
	} else {
		encoded = await onMainThread();
	}

	let sourceHash: string | undefined;
	if ( globalThis.crypto?.subtle ) {
		try {
			const digest = await globalThis.crypto.subtle.digest( 'SHA-256', await file.arrayBuffer() );
			sourceHash = Array.from( new Uint8Array( digest ), ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) ).join( '' );
		} catch {
			// Callers can use the server when the browser cannot verify the source.
		}
	}
	if ( settings.skip_smaller && encoded.blob.size >= originalSize ) {
		return { ...skipped( 'not_smaller' ), sourceHash };
	}

	const filename = swapExtension( file.name, target.ext );
	return {
		skipped: false,
		file: new File( [ encoded.blob ], filename, { type: target.mime } ),
		filename,
		mimeType: target.mime,
		format: target.ext,
		originalSize,
		optimizedSize: encoded.blob.size,
		savedBytes: Math.max( 0, originalSize - encoded.blob.size ),
		sourceHash,
		width: encoded.width,
		height: encoded.height,
	};
}

export const browserCompressor = {
	setWorkerUrl: ( url: string ) => { workerUrl = url; },
	canUseWorker,
	detectSupport,
	compress,
};
