import { sanitizeFilename } from './format';
import type { ZipInputEntry } from './types';

/** Explicit memory ceiling for client-side ZIP assembly (150 MiB). */
export const MAX_ZIP_BYTES = 150 * 1024 * 1024;
export const MAX_ZIP_ENTRIES = 0xffff;

const CRC_TABLE = new Uint32Array( 256 );
for ( let n = 0; n < 256; n++ ) {
	let c = n;
	for ( let k = 0; k < 8; k++ ) {
		c = ( c & 1 ) ? ( 0xedb88320 ^ ( c >>> 1 ) ) : ( c >>> 1 );
	}
	CRC_TABLE[ n ] = c >>> 0;
}

export function crc32( data: Uint8Array ): number {
	let crc = 0xffffffff;
	for ( let i = 0; i < data.length; i++ ) {
		crc = CRC_TABLE[ ( crc ^ data[ i ] ) & 0xff ] ^ ( crc >>> 8 );
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}

const UTF8_ENCODER = new TextEncoder();
const MAX_ZIP_NAME_BYTES = 200;

function truncateUtf8Bytes( value: string, maxBytes: number ): string {
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

function sanitizeZipEntryName( rawName: string ): string {
	return sanitizeFilename( rawName, 'image' );
}

function normalizedZipKey( name: string ): string {
	return name.normalize( 'NFC' ).toLowerCase();
}

function deduplicateEntryName( name: string, used: Set< string > ): string {
	const safe = sanitizeZipEntryName( name );
	const safeKey = normalizedZipKey( safe );
	if ( ! used.has( safeKey ) ) {
		used.add( safeKey );
		return safe;
	}
	const dot = safe.lastIndexOf( '.' );
	const hasExt = dot > 0 && safe.length - dot <= 16;
	const rawStem = hasExt ? safe.slice( 0, dot ) : safe;
	const ext = hasExt ? safe.slice( dot ) : '';
	const extBytes = UTF8_ENCODER.encode( ext ).length;

	let index = 2;
	while ( true ) {
		const suffix = `-${ index }`;
		const suffixBytes = UTF8_ENCODER.encode( suffix ).length;
		const maxStemBytes = Math.max( 1, MAX_ZIP_NAME_BYTES - suffixBytes - extBytes );
		const trimmedStem = truncateUtf8Bytes( rawStem, maxStemBytes ).replace( /[\s.]+$/, '' ) || 'image';
		const candidate = sanitizeZipEntryName( `${ trimmedStem }${ suffix }${ ext }` );
		const candidateKey = normalizedZipKey( candidate );
		if ( ! used.has( candidateKey ) ) {
			used.add( candidateKey );
			return candidate;
		}
		index += 1;
	}
}

function dosDateTime( rawDate: Date ): { time: number; date: number } {
	const date = Number.isNaN( rawDate.getTime() ) ? new Date() : rawDate;
	const year = Math.max( 1980, Math.min( 2107, date.getFullYear() ) );
	const month = date.getMonth() + 1;
	const day = date.getDate();
	const hours = date.getHours();
	const minutes = date.getMinutes();
	const seconds = Math.floor( date.getSeconds() / 2 );
	return {
		time: ( ( hours & 0x1f ) << 11 ) | ( ( minutes & 0x3f ) << 5 ) | ( seconds & 0x1f ),
		date: ( ( ( year - 1980 ) & 0x7f ) << 9 ) | ( ( month & 0x0f ) << 5 ) | ( day & 0x1f ),
	};
}

/**
 * Assemble a standards-compliant store-mode (method 0) ZIP archive.
 * Compressed image formats (WebP, AVIF, JPEG, PNG) do not shrink further under
 * DEFLATE, so store mode avoids wasted CPU and produces interoperable archives.
 */
export function createZipArchive( entries: ZipInputEntry[], maxBytes = MAX_ZIP_BYTES ): Uint8Array {
	if ( ! entries.length ) {
		throw new Error( 'zip_empty' );
	}
	if ( entries.length > MAX_ZIP_ENTRIES ) {
		throw new Error( 'zip_too_large' );
	}

	const effectiveMaxBytes = Math.min( Math.max( 0, maxBytes ), 0xffffffff );
	const encoder = new TextEncoder();
	const usedNames = new Set< string >();

	const prepared = entries.map( ( entry ) => {
		const uniqueName = deduplicateEntryName( entry.name, usedNames );
		const nameBytes = encoder.encode( uniqueName );
		if ( nameBytes.length > 0xffff ) {
			throw new Error( 'zip_invalid_name' );
		}
		const dos = dosDateTime( entry.modifiedAt ?? new Date() );
		return {
			nameBytes,
			data: entry.data,
			crc: crc32( entry.data ),
			dosTime: dos.time,
			dosDate: dos.date,
			localOffset: 0,
		};
	} );

	let localTotal = 0;
	let centralTotal = 0;
	for ( const item of prepared ) {
		item.localOffset = localTotal;
		localTotal += 30 + item.nameBytes.length + item.data.length;
		centralTotal += 46 + item.nameBytes.length;
		if ( localTotal + centralTotal + 22 > effectiveMaxBytes ) {
			throw new Error( 'zip_too_large' );
		}
	}

	const totalSize = localTotal + centralTotal + 22;
	const out = new Uint8Array( totalSize );
	const view = new DataView( out.buffer );

	// Write local file headers + data.
	let offset = 0;
	for ( const item of prepared ) {
		view.setUint32( offset, 0x04034b50, true ); // Local file header signature.
		view.setUint16( offset + 4, 20, true ); // Version needed to extract (2.0).
		view.setUint16( offset + 6, 0x0800, true ); // UTF-8 filename flag (bit 11).
		view.setUint16( offset + 8, 0, true ); // Compression method: 0 (store).
		view.setUint16( offset + 10, item.dosTime, true );
		view.setUint16( offset + 12, item.dosDate, true );
		view.setUint32( offset + 14, item.crc, true );
		view.setUint32( offset + 18, item.data.length, true );
		view.setUint32( offset + 22, item.data.length, true );
		view.setUint16( offset + 26, item.nameBytes.length, true );
		view.setUint16( offset + 28, 0, true ); // Extra field length.
		offset += 30;
		out.set( item.nameBytes, offset );
		offset += item.nameBytes.length;
		out.set( item.data, offset );
		offset += item.data.length;
	}

	// Write central directory entries.
	const centralStart = offset;
	for ( const item of prepared ) {
		view.setUint32( offset, 0x02014b50, true ); // Central file header signature.
		view.setUint16( offset + 4, 20, true ); // Version made by.
		view.setUint16( offset + 6, 20, true ); // Version needed to extract.
		view.setUint16( offset + 8, 0x0800, true ); // UTF-8 flag.
		view.setUint16( offset + 10, 0, true ); // Store.
		view.setUint16( offset + 12, item.dosTime, true );
		view.setUint16( offset + 14, item.dosDate, true );
		view.setUint32( offset + 16, item.crc, true );
		view.setUint32( offset + 20, item.data.length, true );
		view.setUint32( offset + 24, item.data.length, true );
		view.setUint16( offset + 28, item.nameBytes.length, true );
		view.setUint16( offset + 30, 0, true ); // Extra length.
		view.setUint16( offset + 32, 0, true ); // Comment length.
		view.setUint16( offset + 34, 0, true ); // Disk number start.
		view.setUint16( offset + 36, 0, true ); // Internal file attributes.
		view.setUint32( offset + 38, 0, true ); // External file attributes.
		view.setUint32( offset + 42, item.localOffset, true );
		offset += 46;
		out.set( item.nameBytes, offset );
		offset += item.nameBytes.length;
	}

	// End of central directory record (EOCD).
	view.setUint32( offset, 0x06054b50, true );
	view.setUint16( offset + 4, 0, true );
	view.setUint16( offset + 6, 0, true );
	view.setUint16( offset + 8, prepared.length, true );
	view.setUint16( offset + 10, prepared.length, true );
	view.setUint32( offset + 12, centralTotal, true );
	view.setUint32( offset + 16, centralStart, true );
	view.setUint16( offset + 20, 0, true );

	return out;
}

/**
 * Memory-efficient streaming store-mode ZIP builder for browser Blobs.
 * Reads one Blob at a time to compute CRC32 and assembles the final ZIP Blob
 * from header Uint8Arrays + original Blob references without copying all payloads
 * into a second contiguous Uint8Array.
 */
export async function createZipBlob(
	entries: Array< { name: string; blob: Blob; modifiedAt?: Date } >,
	maxBytes = MAX_ZIP_BYTES
): Promise< Blob > {
	if ( ! entries.length ) {
		throw new Error( 'zip_empty' );
	}
	if ( entries.length > MAX_ZIP_ENTRIES ) {
		throw new Error( 'zip_too_large' );
	}

	const effectiveMaxBytes = Math.min( Math.max( 0, maxBytes ), 0xffffffff );
	const usedNames = new Set< string >();

	const preparedMeta = entries.map( ( entry ) => {
		const uniqueName = deduplicateEntryName( entry.name, usedNames );
		const nameBytes = UTF8_ENCODER.encode( uniqueName );
		if ( nameBytes.length > 0xffff ) {
			throw new Error( 'zip_invalid_name' );
		}
		const dos = dosDateTime( entry.modifiedAt ?? new Date() );
		return {
			nameBytes,
			blob: entry.blob,
			size: entry.blob.size,
			dosTime: dos.time,
			dosDate: dos.date,
			crc: 0,
			localOffset: 0,
		};
	} );

	let localTotal = 0;
	let centralTotal = 0;
	for ( const item of preparedMeta ) {
		item.localOffset = localTotal;
		localTotal += 30 + item.nameBytes.length + item.size;
		centralTotal += 46 + item.nameBytes.length;
		if ( localTotal + centralTotal + 22 > effectiveMaxBytes ) {
			throw new Error( 'zip_too_large' );
		}
	}

	const parts: BlobPart[] = [];

	// Local headers + Blob payloads (one entry buffer in RAM at a time for CRC32).
	for ( const item of preparedMeta ) {
		const bytes = new Uint8Array( await item.blob.arrayBuffer() );
		item.crc = crc32( bytes );
		const localHeader = new Uint8Array( 30 + item.nameBytes.length );
		const view = new DataView( localHeader.buffer );
		view.setUint32( 0, 0x04034b50, true );
		view.setUint16( 4, 20, true );
		view.setUint16( 6, 0x0800, true );
		view.setUint16( 8, 0, true );
		view.setUint16( 10, item.dosTime, true );
		view.setUint16( 12, item.dosDate, true );
		view.setUint32( 14, item.crc, true );
		view.setUint32( 18, item.size, true );
		view.setUint32( 22, item.size, true );
		view.setUint16( 26, item.nameBytes.length, true );
		view.setUint16( 28, 0, true );
		localHeader.set( item.nameBytes, 30 );
		parts.push( localHeader, item.blob );
	}

	// Central directory + EOCD.
	const tail = new Uint8Array( centralTotal + 22 );
	const tailView = new DataView( tail.buffer );
	let offset = 0;
	for ( const item of preparedMeta ) {
		tailView.setUint32( offset, 0x02014b50, true );
		tailView.setUint16( offset + 4, 20, true );
		tailView.setUint16( offset + 6, 20, true );
		tailView.setUint16( offset + 8, 0x0800, true );
		tailView.setUint16( offset + 10, 0, true );
		tailView.setUint16( offset + 12, item.dosTime, true );
		tailView.setUint16( offset + 14, item.dosDate, true );
		tailView.setUint32( offset + 16, item.crc, true );
		tailView.setUint32( offset + 20, item.size, true );
		tailView.setUint32( offset + 24, item.size, true );
		tailView.setUint16( offset + 28, item.nameBytes.length, true );
		tailView.setUint16( offset + 30, 0, true );
		tailView.setUint16( offset + 32, 0, true );
		tailView.setUint16( offset + 34, 0, true );
		tailView.setUint16( offset + 36, 0, true );
		tailView.setUint32( offset + 38, 0, true );
		tailView.setUint32( offset + 42, item.localOffset, true );
		tail.set( item.nameBytes, offset + 46 );
		offset += 46 + item.nameBytes.length;
	}

	tailView.setUint32( offset, 0x06054b50, true );
	tailView.setUint16( offset + 4, 0, true );
	tailView.setUint16( offset + 6, 0, true );
	tailView.setUint16( offset + 8, preparedMeta.length, true );
	tailView.setUint16( offset + 10, preparedMeta.length, true );
	tailView.setUint32( offset + 12, centralTotal, true );
	tailView.setUint32( offset + 16, localTotal, true );
	tailView.setUint16( offset + 20, 0, true );

	parts.push( tail );
	return new Blob( parts, { type: 'application/zip' } );
}
