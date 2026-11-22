export type ErrorCode =
	| 'invalid_json_response'
	| 'permission_denied'
	| 'file_too_large'
	| 'not_found'
	| 'timeout'
	| 'network_error'
	| 'unsupported_format'
	| 'write_failed'
	| 'clipboard_failed'
	| 'original_missing'
	| 'source_changed'
	| 'busy'
	| 'restore_failed'
	| 'restore_unfinished'
	| 'regen_failed'
	| 'purge_failed'
	| 'unknown';

export interface FriendlyError {
	code: ErrorCode;
	friendly: string;
	action: string;
	technical: string;
}

function extractTechnicalMessage( err: unknown ): string {
	if ( ! err ) {
		return 'Unknown error';
	}
	if ( typeof err === 'string' ) {
		return err;
	}
	if ( typeof err === 'object' ) {
		const record = err as Record< string, unknown >;
		const code = typeof record.code === 'string' ? `[${ record.code }] ` : '';
		const msg =
			typeof record.message === 'string'
				? record.message
				: err instanceof Error
				? err.message
				: 'Operation failed';
		const detail = ( record.data as { technical?: unknown } )?.technical;
		return `${ code }${ msg }${ typeof detail === 'string' && detail ? ` (${ detail })` : '' }`.trim();
	}
	return String( err );
}

export function toFriendlyError( err: unknown ): FriendlyError {
	const technical = extractTechnicalMessage( err );
	const lower = technical.toLowerCase();

	if (
		lower.includes( 'not a valid json response' ) ||
		lower.includes( 'unexpected token <' ) ||
		lower.includes( 'invalid_json' )
	) {
		return {
			code: 'invalid_json_response',
			friendly: 'Your server sent back an unexpected response while saving this photo.',
			action: 'Try again. If the problem continues, share the details below with your site administrator.',
			technical,
		};
	}

	if (
		lower.includes( 'image size limit' ) ||
		lower.includes( 'image_too_large' ) ||
		lower.includes( 'too large' ) ||
		lower.includes( '413' ) ||
		lower.includes( 'payload too large' ) ||
		lower.includes( 'entity too large' )
	) {
		return {
			code: 'file_too_large',
			friendly: 'This photo is too large to process.',
			action: 'Try a smaller copy of the photo.',
			technical,
		};
	}

	if (
		lower.includes( '401' ) ||
		lower.includes( '403' ) ||
		lower.includes( 'rest_forbidden' ) ||
		lower.includes( 'rest_cookie_invalid_nonce' ) ||
		lower.includes( 'cookie check failed' ) ||
		lower.includes( 'permission' )
	) {
		return {
			code: 'permission_denied',
			friendly: 'Your WordPress session expired or your account cannot modify media files.',
			action: 'Sign in again and retry. If you still cannot edit the image, ask its owner or your site administrator.',
			technical,
		};
	}

	if (
		lower.includes( '404' ) ||
		lower.includes( 'not found' ) ||
		lower.includes( 'missing_attachment' ) ||
		lower.includes( 'missing_source' )
	) {
		return {
			code: 'not_found',
			friendly: 'This image file could not be found on your server.',
			action: 'Check your media storage or recover the file from a site backup, then retry.',
			technical,
		};
	}

	if ( lower.includes( 'timed out' ) || lower.includes( 'abort' ) || lower.includes( '504' ) ) {
		return {
			code: 'timeout',
			friendly: 'This photo took too long to load or save.',
			action: 'Check your internet connection and try again.',
			technical,
		};
	}

	if (
		lower.includes( 'failed to fetch' ) ||
		lower.includes( 'networkerror' ) ||
		lower.includes( 'fetch_error' )
	) {
		return {
			code: 'network_error',
			friendly: 'Your browser could not reach your WordPress site.',
			action: 'Check your internet connection and confirm your site is online, then try again.',
			technical,
		};
	}

	if ( lower.includes( 'animated_image' ) ) {
		return {
			code: 'unsupported_format',
			friendly: 'This image contains animation.',
			action: 'Choose a still image to preview compression.',
			technical,
		};
	}

	if (
		lower.includes( 'unsupported' ) ||
		lower.includes( 'invalid_mime' ) ||
		lower.includes( 'decode' ) ||
		lower.includes( 'imagebitmap' )
	) {
		return {
			code: 'unsupported_format',
			friendly: 'This file format cannot be compressed in your browser.',
			action: 'Skip this file or re-save it as a standard JPEG or PNG.',
			technical,
		};
	}

	if ( lower.includes( 'clipboard_failed' ) ) {
		return {
			code: 'clipboard_failed',
			friendly: 'The summary could not be copied.',
			action: 'Check your browser clipboard permission and try again.',
			technical,
		};
	}

	if ( lower.includes( 'original_missing' ) ) {
		return {
			code: 'original_missing',
			friendly: 'WordPress could not open the original. Your optimized copy was kept.',
			action: 'Recover the original or ask your host to check that WordPress can open it, then try again.',
			technical,
		};
	}

	if ( lower.includes( 'source_changed' ) ) {
		return {
			code: 'source_changed',
			friendly: 'WordPress could not match the GIF to the file used for this preview.',
			action: 'Open the image again or optimize with your site settings.',
			technical,
		};
	}
	if ( lower.includes( 'kilobyte_io_locked' ) ) {
		return {
			code: 'busy',
			friendly: 'WordPress is still processing this image.',
			action: 'Wait for the current job to finish, then try again.',
			technical,
		};
	}

	if ( lower.includes( 'restore_failed' ) ) {
		return {
			code: 'restore_failed',
			friendly: 'The original image could not be restored.',
			action: 'Try again. If it still fails, share the details below with your site administrator.',
			technical,
		};
	}

	if ( lower.includes( 'restore_unfinished' ) ) {
		return {
			code: 'restore_unfinished',
			friendly: 'The original is back in the Media Library.',
			action: 'Retry the restore to finish updating its status.',
			technical,
		};
	}

	if ( lower.includes( 'regen_failed' ) ) {
		return {
			code: 'regen_failed',
			friendly: 'WordPress could not regenerate all thumbnails.',
			action: 'Check that WordPress can open the image and write to its uploads folder, then retry.',
			technical,
		};
	}

	if ( lower.includes( 'purge_failed' ) ) {
		return {
			code: 'purge_failed',
			friendly: 'WordPress could not clear the legacy backup references.',
			action: 'Check that WordPress can read its uploads folder and save settings, then retry.',
			technical,
		};
	}

	if ( lower.includes( 'write_failed' ) || lower.includes( 'could not write' ) ) {
		return {
			code: 'write_failed',
			friendly: 'WordPress could not save the optimized file to your uploads folder.',
			action: 'Check folder permissions or available disk space on your hosting account.',
			technical,
		};
	}

	return {
		code: 'unknown',
		friendly: 'Something went wrong while processing this photo.',
		action: 'Try again. If the issue repeats, share the technical details below with your developer or host.',
		technical,
	};
}
