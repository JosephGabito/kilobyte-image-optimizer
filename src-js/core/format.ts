export const SUPPORTED_INPUT_MIMES = new Set< string >( [
	'image/jpeg',
	'image/png',
	'image/webp',
	'image/avif',
	'image/bmp',
	'image/gif',
] );

export function isSupportedMime( mime: string ): boolean {
	return SUPPORTED_INPUT_MIMES.has( mime );
}

export function mimeFromFilename( filename: string ): string {
	const raw = filename || '';
	const dot = raw.lastIndexOf( '.' );
	if ( dot < 0 || dot === raw.length - 1 ) {
		return '';
	}
	const ext = raw.slice( dot + 1 ).toLowerCase();
	switch ( ext ) {
		case 'jpg':
		case 'jpeg':
			return 'image/jpeg';
		case 'png':
			return 'image/png';
		case 'webp':
			return 'image/webp';
		case 'avif':
			return 'image/avif';
		case 'gif':
			return 'image/gif';
		case 'bmp':
			return 'image/bmp';
		default:
			return '';
	}
}

export function resolveInputMime( file: File ): string {
	if ( file.type && isSupportedMime( file.type ) ) {
		return file.type;
	}
	return mimeFromFilename( file.name );
}

export function extForMime( mime: string ): string {
	switch ( mime ) {
		case 'image/avif':
			return 'avif';
		case 'image/webp':
			return 'webp';
		case 'image/jpeg':
			return 'jpg';
		case 'image/png':
			return 'png';
		case 'image/gif':
			return 'gif';
		case 'image/bmp':
			return 'bmp';
		default:
			return 'bin';
	}
}

export function labelForMime( mime: string ): string {
	switch ( mime ) {
		case 'image/avif':
			return 'AVIF';
		case 'image/webp':
			return 'WebP';
		case 'image/jpeg':
			return 'JPEG';
		case 'image/png':
			return 'PNG';
		case 'image/gif':
			return 'GIF';
		case 'image/bmp':
			return 'BMP';
		default:
			return 'image';
	}
}

const WINDOWS_RESERVED_NAME = /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i;
const UTF8_ENCODER = new TextEncoder();

function truncateUtf8( value: string, maxBytes: number ): string {
	if ( UTF8_ENCODER.encode( value ).length <= maxBytes ) {
		return value;
	}
	let out = '';
	let used = 0;
	for ( const ch of value ) {
		const size = UTF8_ENCODER.encode( ch ).length;
		if ( used + size > maxBytes ) {
			break;
		}
		out += ch;
		used += size;
	}
	return out;
}

/**
 * Sanitize an untrusted filename for safe browser downloads and ZIP archive entries.
 * Strips directory components, dot-segments, control/BiDi characters, Windows reserved
 * characters/device names, and enforces a safe UTF-8 byte ceiling.
 */
export function sanitizeFilename( rawName: string, fallback = 'image' ): string {
	const parts = ( rawName || '' )
		.normalize( 'NFC' )
		.replace( /\\/g, '/' )
		.split( '/' )
		.filter( Boolean );
	const base = parts.length ? parts[ parts.length - 1 ] : '';
	let cleaned = base
		.replace( /[\x00-\x1f\x7f-\x9f\u200e\u200f\u202a-\u202e\u2066-\u2069]/g, '' )
		.replace( /[<>:"/\\|?*]/g, '-' )
		.replace( /^[\s.]+|[\s.]+$/g, '' )
		.replace( /\s+/g, ' ' );

	if ( ! cleaned || cleaned === '.' || cleaned === '..' ) {
		cleaned = fallback;
	}

	if ( WINDOWS_RESERVED_NAME.test( cleaned ) ) {
		cleaned = `_${ cleaned }`;
	}

	if ( UTF8_ENCODER.encode( cleaned ).length > 200 ) {
		const dot = cleaned.lastIndexOf( '.' );
		const hasExt = dot > 0 && cleaned.length - dot <= 16;
		if ( hasExt ) {
			const ext = cleaned.slice( dot );
			const extBytes = UTF8_ENCODER.encode( ext ).length;
			const stem = truncateUtf8( cleaned.slice( 0, dot ), Math.max( 1, 200 - extBytes ) ) || fallback;
			cleaned = `${ stem }${ ext }`;
		} else {
			cleaned = truncateUtf8( cleaned, 200 ) || fallback;
		}
	}

	return cleaned;
}

export function swapExtension( filename: string, newExt: string ): string {
	const safeExt = ( newExt || 'bin' ).replace( /[^a-z0-9]/gi, '' ).toLowerCase() || 'bin';
	const parts = ( filename || '' ).replace( /\\/g, '/' ).split( '/' ).filter( Boolean );
	const rawBase = parts.length ? parts[ parts.length - 1 ] : '';
	const strippedExt = rawBase.replace( /\.[^.]+$/, '' );
	const cleanStem = sanitizeFilename( strippedExt, 'image' );
	return sanitizeFilename( `${ cleanStem }.${ safeExt }`, `image.${ safeExt }` );
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

function asciiAt( bytes: Uint8Array, offset: number, length: number ): string {
	if ( offset + length > bytes.length ) {
		return '';
	}
	return String.fromCharCode( ...bytes.subarray( offset, offset + length ) );
}

const PNG_IEND_TRAILER = [ 0x00, 0x00, 0x00, 0x00, 0x49, 0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82 ];

export function hasPngIendTrailer( bytes: Uint8Array ): boolean {
	if ( ! bytes || bytes.length < PNG_IEND_TRAILER.length ) {
		return false;
	}
	const start = bytes.length - PNG_IEND_TRAILER.length;
	for ( let i = 0; i < PNG_IEND_TRAILER.length; i++ ) {
		if ( bytes[ start + i ] !== PNG_IEND_TRAILER[ i ] ) {
			return false;
		}
	}
	return true;
}

export function verifyPngComplete( bytes: Uint8Array ): boolean {
	return bytes.length >= 33 && verifyFileSignature( bytes, 'image/png' ) && hasPngIendTrailer( bytes );
}

/**
 * Verify that encoded output bytes match the claimed image MIME signature.
 * Prevents silently returning PNG bytes under a WebP or AVIF extension.
 */
export function verifyFileSignature( bytes: Uint8Array, expectedMime: string ): boolean {
	if ( ! bytes || bytes.length < 12 ) {
		return false;
	}

	switch ( expectedMime ) {
		case 'image/jpeg':
			return bytes[ 0 ] === 0xff && bytes[ 1 ] === 0xd8 && bytes[ 2 ] === 0xff;
		case 'image/png':
			return (
				bytes[ 0 ] === 0x89 &&
				bytes[ 1 ] === 0x50 &&
				bytes[ 2 ] === 0x4e &&
				bytes[ 3 ] === 0x47 &&
				bytes[ 4 ] === 0x0d &&
				bytes[ 5 ] === 0x0a &&
				bytes[ 6 ] === 0x1a &&
				bytes[ 7 ] === 0x0a
			);
		case 'image/webp':
			return asciiAt( bytes, 0, 4 ) === 'RIFF' && asciiAt( bytes, 8, 4 ) === 'WEBP';
		case 'image/avif': {
			if ( asciiAt( bytes, 4, 4 ) !== 'ftyp' ) {
				return false;
			}
			const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );
			let boxSize = view.getUint32( 0 );
			let brandStart = 8;
			if ( boxSize === 1 ) {
				if ( bytes.length < 24 ) {
					return false;
				}
				boxSize = view.getUint32( 8 ) * 0x100000000 + view.getUint32( 12 );
				brandStart = 16;
			}
			if ( ! Number.isSafeInteger( boxSize ) || boxSize < brandStart + 8 || boxSize > bytes.length ) {
				return false;
			}
			const minorVersionOffset = brandStart + 4;
			for ( let i = brandStart; i + 4 <= boxSize; i += 4 ) {
				if ( i === minorVersionOffset ) {
					continue;
				}
				const brand = asciiAt( bytes, i, 4 );
				if ( brand === 'avif' || brand === 'avis' ) {
					return true;
				}
			}
			return false;
		}
		case 'image/gif': {
			const header = asciiAt( bytes, 0, 6 );
			return header === 'GIF87a' || header === 'GIF89a';
		}
		case 'image/bmp':
			return bytes[ 0 ] === 0x42 && bytes[ 1 ] === 0x4d;
		default:
			return false;
	}
}

export async function verifyBlobSignature( blob: Blob, expectedMime: string ): Promise< boolean > {
	if ( ! blob || blob.size < 12 ) {
		return false;
	}
	if ( blob.type && blob.type !== expectedMime ) {
		return false;
	}
	const headSize = expectedMime === 'image/avif' ? 512 : 64;
	const head = new Uint8Array( await blob.slice( 0, headSize ).arrayBuffer() );
	if ( ! verifyFileSignature( head, expectedMime ) ) {
		return false;
	}
	if ( expectedMime === 'image/png' ) {
		if ( blob.size < 33 ) {
			return false;
		}
		const tail = new Uint8Array( await blob.slice( blob.size - PNG_IEND_TRAILER.length ).arrayBuffer() );
		return hasPngIendTrailer( tail );
	}
	return true;
}
