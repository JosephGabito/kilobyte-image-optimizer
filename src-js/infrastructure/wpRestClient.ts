import apiFetch from '@wordpress/api-fetch';
import { MAX_IMAGE_BYTES } from './rasterEncoder';
import type {
	LibraryStats,
	OptimizeResponse,
	PluginSettings,
	QueueMode,
	QueuePage,
	RestoreResponse,
} from '../domain/types';

const BASE = '/kilobyte-io/v1';

export const wpRestClient = {
	getStats(): Promise< LibraryStats > {
		return apiFetch< LibraryStats >( { path: `${ BASE }/stats` } );
	},

	getQueuePage( mode: QueueMode, before: number, signal: AbortSignal ): Promise< QueuePage > {
		return apiFetch< QueuePage >( {
			path: `${ BASE }/queue?mode=${ mode }&before=${ before }&per_page=40`,
			signal,
		} );
	},

	async fetchImageBlob(
		url: string,
		onProgress?: ( loaded: number, total: number ) => void,
		signal?: AbortSignal
	): Promise< Blob > {
		let targetUrl = url;
		try {
			const parsed = new URL( url, window.location.href );
			if ( parsed.host === window.location.host ) {
				parsed.protocol = window.location.protocol;
				targetUrl = parsed.href;
			}
		} catch {
			// Keep original URL if unparseable.
		}

		const controller = new AbortController();
		const abort = () => controller.abort();
		signal?.addEventListener( 'abort', abort, { once: true } );
		if ( signal?.aborted ) {
			abort();
		}
		const timeout = window.setTimeout( abort, 45_000 );
		try {
			const resp = await fetch( targetUrl, {
				credentials: 'same-origin',
				signal: controller.signal,
			} );
			if ( ! resp.ok ) {
				throw new Error( `HTTP ${ resp.status } fetching image` );
			}

			const contentLength = Number( resp.headers.get( 'content-length' ) || 0 );
			if ( contentLength > MAX_IMAGE_BYTES ) {
				throw new Error( 'image_too_large' );
			}
			if ( resp.body && typeof resp.body.getReader === 'function' ) {
				const reader = resp.body.getReader();
				const chunks: Uint8Array< ArrayBuffer >[] = [];
				let loaded = 0;

				while ( true ) {
					const { done, value } = await reader.read();
					if ( done ) {
						break;
					}
					if ( value ) {
						chunks.push( value );
						loaded += value.byteLength;
						if ( loaded > MAX_IMAGE_BYTES ) {
							await reader.cancel();
							throw new Error( 'image_too_large' );
						}
						onProgress?.( loaded, contentLength );
					}
				}

				const mime = resp.headers.get( 'content-type' ) || 'application/octet-stream';
				return new Blob( chunks, { type: mime } );
			}

			const blob = await resp.blob();
			if ( blob.size > MAX_IMAGE_BYTES ) {
				throw new Error( 'image_too_large' );
			}
			return blob;
		} catch ( err: any ) {
			if ( err?.name === 'AbortError' && ! signal?.aborted ) {
				throw new Error( 'Timed out loading image from Media Library.' );
			}
			throw err;
		} finally {
			window.clearTimeout( timeout );
			controller.abort();
			signal?.removeEventListener( 'abort', abort );
		}
	},

	uploadOptimized(
		id: number,
		file: File,
		filename: string,
		options: { preview?: boolean; sourceHash?: string } = {}
	): Promise< OptimizeResponse > {
		const body = new FormData();
		body.append( 'file', file, filename );
		if ( options.preview ) {
			body.append( 'preview', '1' );
		}
		if ( options.sourceHash ) {
			body.append( 'source_hash', options.sourceHash );
		}
		return apiFetch< OptimizeResponse >( {
			path: `${ BASE }/optimize/${ id }`,
			method: 'POST',
			body,
		} );
	},

	optimizeOnServer( id: number ): Promise< OptimizeResponse > {
		return apiFetch< OptimizeResponse >( {
			path: `${ BASE }/optimize-server/${ id }`,
			method: 'POST',
		} );
	},

	async markSkipped( id: number ): Promise< void > {
		await apiFetch( {
			path: `${ BASE }/skip/${ id }`,
			method: 'POST',
		} );
	},

	regenerateThumbnails( id: number ): Promise< { id: number; sizes: number } > {
		return apiFetch< { id: number; sizes: number } >( {
			path: `${ BASE }/regenerate/${ id }`,
			method: 'POST',
		} );
	},

	restoreAttachment( id: number ): Promise< RestoreResponse > {
		return apiFetch< RestoreResponse >( {
			path: `${ BASE }/restore/${ id }`,
			method: 'POST',
		} );
	},

	saveSettings( settings: PluginSettings ): Promise< PluginSettings > {
		return apiFetch< PluginSettings >( {
			path: `${ BASE }/settings`,
			method: 'POST',
			data: settings,
		} );
	},
};
