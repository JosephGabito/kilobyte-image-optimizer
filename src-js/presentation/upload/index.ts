import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { canUseServerAfterBrowserSave, OptimizationFallbackError, toFriendlyError } from '../../domain/errors';
import { isSupportedMime } from '../../domain/format';
import type { OptimizeResponse, UploadBootstrapConfig } from '../../domain/types';
import { browserCompressor } from '../../infrastructure/browserCompressor';
import { wpRestClient } from '../../infrastructure/wpRestClient';
import { restoreControls, retryControls, updateOptimizedAttachment, updateRestoredAttachment } from '../../infrastructure/wpMedia';
import { restoreDetails } from '../restoreDetails';
import { optimizationSkip } from '../optimizationSkip';

declare global {
	interface Window {
		KilobyteIOUploadConfig?: UploadBootstrapConfig;
	}
}


function uploadStatus( optimizerUrl: string ) {
	let pending = 0;
	let started = 0;
	let timer: number | undefined;
	let notice: HTMLDivElement | undefined;
	let content: HTMLDivElement | undefined;
	let progress: HTMLParagraphElement | undefined;
	let failedUploads: HTMLUListElement | undefined;
	let skippedUploads: HTMLUListElement | undefined;
	const warnBeforeLeaving = ( event: BeforeUnloadEvent ) => {
		event.preventDefault();
		event.returnValue = '';
	};

	const getNoticeContent = () => {
		if ( ! content ) {
			notice = document.createElement( 'div' );
			content = document.createElement( 'div' );
			const editorNotices = document.querySelector( '.interface-interface-skeleton__notices, .editor-notices' );
			notice.className = editorNotices ? 'components-notice is-info' : 'notice notice-info inline';
			content.className = editorNotices ? 'components-notice__content' : '';
			notice.append( content );
			( editorNotices ?? document.querySelector( '#wpbody-content .wrap' ) ?? document.body ).prepend( notice );
		}
		return content;
	};

	const removeEmptyNotice = () => {
		if ( ! progress && ! failedUploads && ! skippedUploads ) {
			notice?.remove();
			notice = undefined;
			content = undefined;
		}
	};

	const renderProgress = () => {
		if ( ! progress ) {
			progress = document.createElement( 'p' );
			getNoticeContent().prepend( progress );
		}
		progress.textContent = sprintf(
			/* translators: %d: elapsed seconds. */
			__( 'Making smaller copies. Keep this tab open. %ds elapsed.', 'kilobyte-image-optimizer' ),
			Math.floor( ( Date.now() - started ) / 1000 )
		);
	};

	return {
		start() {
			if ( ! pending ) {
				started = Date.now();
				window.addEventListener( 'beforeunload', warnBeforeLeaving );
				timer = window.setInterval( renderProgress, 1000 );
			}
			pending += 1;
			renderProgress();
		},
		fail( filename: string, error: unknown ) {
			const failure = toFriendlyError( error );
			if ( ! failedUploads ) {
				const warning = document.createElement( 'div' );
				warning.setAttribute( 'role', 'status' );
				const message = document.createElement( 'p' );
				message.textContent = __( 'Your original uploads are saved. Some smaller copies still need a retry.', 'kilobyte-image-optimizer' );
				const link = document.createElement( 'a' );
				link.href = optimizerUrl;
				link.textContent = __( 'Retry in Media → Optimization', 'kilobyte-image-optimizer' );
				const dismiss = document.createElement( 'button' );
				dismiss.type = 'button';
				dismiss.className = 'button-link';
				dismiss.textContent = __( 'Dismiss', 'kilobyte-image-optimizer' );
				dismiss.addEventListener( 'click', () => {
					warning.remove();
					failedUploads = undefined;
					removeEmptyNotice();
				} );
				failedUploads = document.createElement( 'ul' );
				warning.append( message, failedUploads, link, ' · ', dismiss );
				getNoticeContent().append( warning );
			}
			const item = document.createElement( 'li' );
			const name = document.createElement( 'strong' );
			name.textContent = filename;
			const details = document.createElement( 'details' );
			const summary = document.createElement( 'summary' );
			summary.textContent = __( 'Technical details', 'kilobyte-image-optimizer' );
			details.append( summary, failure.technical );
			item.append( name, ' ', failure.friendly, ' ', failure.action, details );
			failedUploads.append( item );
		},
		skip( filename: string, reason?: string ) {
			if ( ! skippedUploads ) {
				const result = document.createElement( 'div' );
				result.setAttribute( 'role', 'status' );
				skippedUploads = document.createElement( 'ul' );
				const dismiss = document.createElement( 'button' );
				dismiss.type = 'button';
				dismiss.className = 'button-link';
				dismiss.textContent = __( 'Dismiss skipped images', 'kilobyte-image-optimizer' );
				dismiss.addEventListener( 'click', () => {
					result.remove();
					skippedUploads = undefined;
					removeEmptyNotice();
				} );
				result.append( skippedUploads, dismiss );
				getNoticeContent().append( result );
			}
			const explanation = optimizationSkip( reason );
			const item = document.createElement( 'li' );
			const name = document.createElement( 'strong' );
			name.textContent = filename;
			item.append( name, ' ', explanation.subtitle, explanation.action ? ` ${ explanation.action }` : '' );
			skippedUploads.append( item );
		},
		finish() {
			pending -= 1;
			if ( ! pending ) {
				window.removeEventListener( 'beforeunload', warnBeforeLeaving );
				window.clearInterval( timer );
				progress?.remove();
				progress = undefined;
				removeEmptyNotice();
			}
		},
	};
}

function installUploadMiddleware( config: UploadBootstrapConfig ): void {
	const mediaUrl = new URL( config.mediaUrl, window.location.href );
	let processing = Promise.resolve();
	const status = uploadStatus( config.optimizerUrl );

	apiFetch.use( async ( options, next ) => {
		const isMediaPath = options.path?.split( '?' )[ 0 ].replace( /\/$/, '' ) === '/wp/v2/media';
		const body = options.body;
		if ( ! config.settings.enabled || ! isMediaPath || options.method?.toUpperCase() !== 'POST' || ! ( body instanceof FormData ) || options.parse === false ) {
			return next( options );
		}
		if ( mediaUrl.origin !== window.location.origin || body.get( 'generate_sub_sizes' ) === 'false' ) {
			return next( options );
		}
		const file = body.get( 'file' );
		if ( ! ( file instanceof File ) || ! isSupportedMime( file.type ) || ! browserCompressor.canUseWorker() ) {
			return next( options );
		}

		// WordPress must receive the original. Process a copy after it returns an attachment ID.
		const headers: Record< string, string > = {};
		new Headers( options.headers ).forEach( ( value, name ) => { headers[ name ] = value; } );
		headers[ 'X-Kilobyte-Browser-Optimize' ] = '1';
		const response = await next( { ...options, headers } );
		const id = ( response as { id?: number } | null )?.id;
		if ( ! Number.isSafeInteger( id ) || id <= 0 ) {
			return response;
		}

		status.start();
		processing = processing.then( async () => {
			if ( ! config.settings.enabled ) {
				return;
			}
			const report = ( result: OptimizeResponse ) => {
				if ( result.skipped ) {
					status.skip( file.name, result.status );
				}
			};
			let result;
			try {
				// WordPress compares with the file it actually kept after upload processing.
				result = await browserCompressor.compress( file, { ...config.settings, skip_smaller: false } );
			} catch {
				report( await wpRestClient.optimizeOnServer( id ) );
				return;
			}
			if ( result.file && result.filename && config.outputMimes.includes( result.file.type ) && result.sourceHash ) {
				try {
					report( await wpRestClient.uploadOptimized( id, result.file, result.filename, { sourceHash: result.sourceHash } ) );
					return;
				} catch ( error ) {
					if ( ! canUseServerAfterBrowserSave( error ) ) {
						throw error;
					}
					try {
						report( await wpRestClient.optimizeOnServer( id ) );
					} catch ( serverError ) {
						throw new OptimizationFallbackError( error, serverError );
					}
					return;
				}
			}
			report( await wpRestClient.optimizeOnServer( id ) );
		} ).catch( ( error ) => status.fail( file.name, error ) ).finally( status.finish );

		return response;
	} );
}

function mediaActionError( error: unknown, className: string ): HTMLDivElement {
	const message = toFriendlyError( error );
	const feedback = document.createElement( 'div' );
	feedback.className = className;
	feedback.setAttribute( 'role', 'status' );
	feedback.textContent = `${ message.friendly } ${ message.action }`;
	const details = document.createElement( 'details' );
	const summary = document.createElement( 'summary' );
	summary.textContent = __( 'Technical details', 'kilobyte-image-optimizer' );
	details.append( summary, message.technical );
	feedback.append( details );
	return feedback;
}

function installMediaActionHandler(): void {
	const working = new Set< number >();
	document.addEventListener( 'click', async ( event ) => {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const button = target.closest< HTMLButtonElement >( '.kilobyte-restore-btn, .kilobyte-optimize-retry-btn' );
		const id = Number( button?.dataset.id );
		if ( ! button || button.disabled || ! Number.isSafeInteger( id ) || id <= 0 || working.has( id ) ) {
			return;
		}

		const restore = button.classList.contains( 'kilobyte-restore-btn' );
		const controls = () => restore ? restoreControls( id ) : retryControls( id );
		const errorClass = restore ? 'kilobyte-restore-error' : 'kilobyte-retry-error';
		const restoreLabel = __( 'Restore original', 'kilobyte-image-optimizer' );
		working.add( id );
		for ( const control of controls() ) {
			if ( control.nextElementSibling?.classList.contains( errorClass ) ) {
				control.nextElementSibling.remove();
			}
			control.disabled = true;
			control.textContent = restore ? __( 'Restoring...', 'kilobyte-image-optimizer' ) : __( 'Optimizing...', 'kilobyte-image-optimizer' );
		}

		try {
			if ( restore ) {
				const result = await wpRestClient.restoreAttachment( id );
				const details = restoreDetails( result );
				const message = details
					? `${ __( 'Original restored.', 'kilobyte-image-optimizer' ) } ${ details }`
					: __( 'Restored', 'kilobyte-image-optimizer' );
				updateRestoredAttachment( id, message, true );
				window.dispatchEvent( new CustomEvent( 'kilobyte:restored', { detail: { id } } ) );
			} else {
				const result = await wpRestClient.optimizeOnServer( id, true );
				const skipped = result.skipped ? optimizationSkip( result.status ) : null;
				const message = skipped
					? [ skipped.subtitle, skipped.action ].filter( Boolean ).join( ' ' )
					: __( 'Optimized copy saved.', 'kilobyte-image-optimizer' );
				updateOptimizedAttachment( id, message, result.has_backup ? restoreLabel : undefined );
				window.dispatchEvent( new CustomEvent( 'kilobyte:optimized', { detail: { id } } ) );
			}
		} catch ( error ) {
			const feedback = mediaActionError( error, errorClass );
			for ( const control of controls() ) {
				control.after( feedback.cloneNode( true ) );
				control.disabled = false;
				control.textContent = restore ? restoreLabel : __( 'Optimize now', 'kilobyte-image-optimizer' );
			}
		} finally {
			working.delete( id );
		}
	} );
}

const config = window.KilobyteIOUploadConfig;
if ( config ) {
	browserCompressor.setWorkerUrl( config.workerUrl );
	installUploadMiddleware( config );
	installMediaActionHandler();
}
