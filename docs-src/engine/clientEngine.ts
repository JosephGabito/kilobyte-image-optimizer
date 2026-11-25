import { verifyBlobSignature } from '../../src-js/core/format';
import type { CoreBrowserSupport } from '../../src-js/core/types';
import { processStandaloneImage } from './processor';
import type {
	ToolProcessRequest,
	ToolProcessResult,
	ToolProcessStage,
	WorkerResponseMessage,
} from './types';

let supportCache: Promise< CoreBrowserSupport > | null = null;
let workerSeq = 0;

interface PooledWorker {
	worker: Worker;
	url: string;
	idleTimer: number | null;
}

const MAX_IDLE_WORKERS = 3;
const WORKER_IDLE_MS = 45_000;
const idleWorkers: PooledWorker[] = [];

function clearIdleTimer( entry: PooledWorker ): void {
	if ( entry.idleTimer !== null ) {
		window.clearTimeout( entry.idleTimer );
		entry.idleTimer = null;
	}
}

function acquireWorker( workerUrl: string ): { entry: PooledWorker; reused: boolean } {
	for ( let i = idleWorkers.length - 1; i >= 0; i-- ) {
		if ( idleWorkers[ i ].url === workerUrl ) {
			const entry = idleWorkers.splice( i, 1 )[ 0 ];
			clearIdleTimer( entry );
			return { entry, reused: true };
		}
	}
	return {
		entry: { worker: new Worker( workerUrl ), url: workerUrl, idleTimer: null },
		reused: false,
	};
}

function releaseWorker( entry: PooledWorker ): void {
	entry.worker.onmessage = null;
	entry.worker.onerror = null;
	entry.worker.onmessageerror = null;
	if ( idleWorkers.length >= MAX_IDLE_WORKERS ) {
		entry.worker.terminate();
		return;
	}
	entry.idleTimer = window.setTimeout( () => {
		const idx = idleWorkers.indexOf( entry );
		if ( idx >= 0 ) {
			idleWorkers.splice( idx, 1 );
		}
		entry.worker.terminate();
	}, WORKER_IDLE_MS );
	idleWorkers.push( entry );
}

export function disposeWorkerPool(): void {
	while ( idleWorkers.length > 0 ) {
		const entry = idleWorkers.pop()!;
		clearIdleTimer( entry );
		entry.worker.terminate();
	}
}

async function probeCanvasMime( mime: string ): Promise< boolean > {
	try {
		const canvas = document.createElement( 'canvas' );
		canvas.width = 4;
		canvas.height = 4;
		const ctx = canvas.getContext( '2d' );
		if ( ! ctx ) {
			return false;
		}
		ctx.fillStyle = '#3858e9';
		ctx.fillRect( 0, 0, 4, 4 );
		const blob = await new Promise< Blob | null >( ( resolve ) => canvas.toBlob( resolve, mime, 0.8 ) );
		canvas.width = 0;
		canvas.height = 0;
		if ( ! blob || blob.type !== mime ) {
			return false;
		}
		return verifyBlobSignature( blob, mime );
	} catch {
		return false;
	}
}

export function detectNativeCapabilities(): Promise< CoreBrowserSupport > {
	if ( ! supportCache ) {
		supportCache = Promise.all( [
			probeCanvasMime( 'image/webp' ),
			probeCanvasMime( 'image/avif' ),
			probeCanvasMime( 'image/jpeg' ),
			probeCanvasMime( 'image/png' ),
		] ).then( ( [ webp, avif, jpeg, png ] ) => ( { webp, avif, jpeg, png } ) );
	}
	return supportCache;
}

export function canUseToolWorker(): boolean {
	return typeof Worker === 'function' && typeof OffscreenCanvas === 'function';
}

class WorkerStartupError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'WorkerStartupError';
	}
}

function runInDedicatedWorker(
	workerUrl: string,
	request: ToolProcessRequest,
	signal?: AbortSignal,
	onStage?: ( stage: ToolProcessStage ) => void
): Promise< ToolProcessResult > {
	return new Promise( ( resolve, reject ) => {
		let acquired: { entry: PooledWorker; reused: boolean };
		try {
			acquired = acquireWorker( workerUrl );
		} catch ( error ) {
			reject( new WorkerStartupError( error instanceof Error ? error.message : 'worker_unavailable' ) );
			return;
		}

		const { entry, reused } = acquired;
		const { worker } = entry;
		const id = ++workerSeq;
		const timeoutMs = request.outputMime === 'image/avif' ? 90_000 : 45_000;
		let settled = false;

		const detach = ( terminate: boolean ) => {
			window.clearTimeout( timer );
			signal?.removeEventListener( 'abort', onAbort );
			if ( terminate ) {
				worker.onmessage = null;
				worker.onerror = null;
				worker.onmessageerror = null;
				worker.terminate();
			} else {
				releaseWorker( entry );
			}
		};

		const onAbort = () => {
			if ( settled ) {
				return;
			}
			settled = true;
			detach( true );
			reject( new DOMException( 'Processing cancelled.', 'AbortError' ) );
		};

		const timer = window.setTimeout( () => {
			if ( settled ) {
				return;
			}
			settled = true;
			detach( true );
			reject( new Error( 'processing_timeout' ) );
		}, timeoutMs );

		worker.onmessage = ( event: MessageEvent< WorkerResponseMessage > ) => {
			if ( settled || event.data.id !== id ) {
				return;
			}
			if ( event.data.stage && ! event.data.result && ! event.data.error ) {
				onStage?.( event.data.stage );
				return;
			}
			settled = true;
			detach( false );
			if ( event.data.result ) {
				resolve( event.data.result );
			} else {
				reject( new Error( event.data.error || 'processing_failed' ) );
			}
		};

		const onWorkerError = () => {
			if ( settled ) {
				return;
			}
			settled = true;
			detach( true );
			if ( ! reused ) {
				reject( new WorkerStartupError( 'worker_unavailable' ) );
			} else {
				reject( new Error( 'processing_failed' ) );
			}
		};

		worker.onerror = onWorkerError;
		worker.onmessageerror = onWorkerError;
		signal?.addEventListener( 'abort', onAbort, { once: true } );
		if ( signal?.aborted ) {
			onAbort();
			return;
		}

		try {
			worker.postMessage( { id, request } );
		} catch ( error ) {
			if ( ! settled ) {
				settled = true;
				detach( true );
				reject( new WorkerStartupError( error instanceof Error ? error.message : 'worker_unavailable' ) );
			}
		}
	} );
}

export async function processImageInBrowser(
	request: ToolProcessRequest,
	options: {
		workerUrl?: string;
		signal?: AbortSignal;
		onStage?: ( stage: ToolProcessStage, viaWorker: boolean ) => void;
	} = {}
): Promise< ToolProcessResult > {
	const { workerUrl, signal, onStage } = options;

	if ( signal?.aborted ) {
		throw new DOMException( 'Processing cancelled.', 'AbortError' );
	}

	let effectiveRequest = request;
	if ( request.preferWasm === undefined && ( request.outputMime === 'image/webp' || request.outputMime === 'image/avif' ) ) {
		const support = await detectNativeCapabilities();
		if ( signal?.aborted ) {
			throw new DOMException( 'Processing cancelled.', 'AbortError' );
		}
		if ( ( request.outputMime === 'image/webp' && ! support.webp ) || ( request.outputMime === 'image/avif' && ! support.avif ) ) {
			effectiveRequest = { ...request, preferWasm: true };
		}
	}

	if ( workerUrl && canUseToolWorker() ) {
		try {
			return await runInDedicatedWorker(
				workerUrl,
				effectiveRequest,
				signal,
				( stage ) => onStage?.( stage, true )
			);
		} catch ( error ) {
			if ( ! ( error instanceof WorkerStartupError ) ) {
				throw error;
			}
		}
	}

	if ( signal?.aborted ) {
		throw new DOMException( 'Processing cancelled.', 'AbortError' );
	}

	return processStandaloneImage(
		effectiveRequest,
		( width, height ) => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = width;
			canvas.height = height;
			return canvas;
		},
		signal,
		( stage ) => onStage?.( stage, false )
	);
}

export function friendlyErrorMessage( error: unknown ): string {
	const raw = error instanceof Error ? error.message : String( error ?? '' );
	if ( error instanceof DOMException && error.name === 'AbortError' ) {
		return 'Processing was cancelled.';
	}
	switch ( raw ) {
		case 'empty_file':
			return 'This file is empty (0 bytes) and cannot be decoded. Choose a valid image file.';
		case 'unsupported_input_format':
			return 'Unsupported file format. Choose a JPEG, PNG, WebP, AVIF, GIF, or BMP image.';
		case 'image_too_large':
			return 'This image exceeds the browser safety limit (24 MB, 40 megapixels, or 16,384 px output side). Resize it or use a smaller file.';
		case 'animated_image':
			return 'Animated GIFs, APNGs, and animated WebP/AVIF files are left untouched so their animation is not lost.';
		case 'gif_structure_unreadable':
			return 'Could not safely inspect this GIF file. Please check that the file is not corrupted.';
		case 'png_structure_unreadable':
			return 'Could not safely inspect this PNG file. It may be truncated or have malformed chunks.';
		case 'truncated_image':
			return 'This image file appears truncated or incomplete and cannot be safely converted.';
		case 'codec_load_failed':
			return 'Could not load the local encoder module. Check your internet connection and try again.';
		case 'browser_decode_unavailable':
			return 'This browser lacks the image decoding APIs required to process files locally. Try updating your browser.';
		case 'unsupported_output_format':
			return 'Your browser could not encode this output format. Try another format or reload the page.';
		case 'processing_timeout':
			return 'Encoding took too long on this device. Try a smaller image or faster format like WebP.';
		case 'zip_too_large':
			return 'The combined batch exceeds the 150 MB browser ZIP limit. Download images individually or process smaller batches.';
		default:
			if ( raw.includes( 'Cannot safely read image dimensions' ) ) {
				return 'Could not decode this file as a valid image. Make sure the file is not corrupted or renamed.';
			}
			if ( raw.includes( 'Canvas image drawing is unavailable' ) ) {
				return 'Your browser could not allocate a canvas for this image size. Try resizing it to smaller dimensions.';
			}
			return 'Could not process this image in your browser. Check that the file is a valid image and try again.';
	}
}
