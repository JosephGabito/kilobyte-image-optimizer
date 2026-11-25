import { formatBytes, isSupportedMime, swapExtension } from '../core/format';
import type { BrowserSupport, OutputFormat } from './types';

export { formatBytes, isSupportedMime, swapExtension };

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
