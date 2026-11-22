import type { BrowserSupport, OutputFormat } from './types';

const SUPPORTED_INPUT_MIMES = new Set< string >( [
	'image/jpeg',
	'image/png',
	'image/webp',
	'image/avif',
	'image/bmp',
] );

export function isSupportedMime( mime: string ): boolean {
	return SUPPORTED_INPUT_MIMES.has( mime );
}

export function resolveTargetMime(
	requestedFormat: OutputFormat,
	inputMime: string,
	support: BrowserSupport
): { mime: string; ext: string } | null {
	if ( requestedFormat === 'avif' && support.avif ) {
		return { mime: 'image/avif', ext: 'avif' };
	}
	if ( requestedFormat !== 'original' ) {
		return support.webp ? { mime: 'image/webp', ext: 'webp' } : null;
	}

	switch ( inputMime ) {
		case 'image/jpeg':
			return { mime: inputMime, ext: 'jpg' };
		case 'image/png':
			return { mime: inputMime, ext: 'png' };
		case 'image/webp':
			return support.webp ? { mime: inputMime, ext: 'webp' } : null;
		case 'image/avif':
			return support.avif ? { mime: inputMime, ext: 'avif' } : null;
		default:
			return null;
	}
}

export function needsServerFallback(
	requestedFormat: OutputFormat,
	support: BrowserSupport
): boolean {
	if ( requestedFormat === 'webp' && ! support.webp ) {
		return true;
	}
	if ( requestedFormat === 'avif' && ! support.avif && ! support.webp ) {
		return true;
	}
	return false;
}

export function swapExtension( filename: string, newExt: string ): string {
	const clean = ( filename || 'image' ).replace( /\.[^.]+$/, '' );
	return `${ clean }.${ newExt }`;
}

export function formatBytes( bytes: number ): string {
	const n = Number( bytes || 0 );
	if ( n < 1024 ) {
		return `${ n } B`;
	}
	if ( n < 1024 * 1024 ) {
		return `${ ( n / 1024 ).toFixed( 1 ) } KB`;
	}
	if ( n < 1024 * 1024 * 1024 ) {
		return `${ ( n / ( 1024 * 1024 ) ).toFixed( 2 ) } MB`;
	}
	return `${ ( n / ( 1024 * 1024 * 1024 ) ).toFixed( 2 ) } GB`;
}
