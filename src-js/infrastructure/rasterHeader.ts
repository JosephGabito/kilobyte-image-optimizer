/** Read image dimensions without asking the browser to decode its pixels. */
const HEADER_LIMIT = 256 * 1024;

type Dimensions = [ number, number ];

type Box = { type: string; start: number; end: number };

/** Parse complete 32-bit BMFF boxes only; uncommon layouts use server fallback. */
function boxes( view: DataView, start: number, end: number ): Box[] | null {
	const result: Box[] = [];
	while ( start < end ) {
		if ( start + 8 > end ) {
			return null;
		}
		const size = view.getUint32( start );
		if ( size < 8 || start + size > end ) {
			return null;
		}
		const type = String.fromCharCode( ...new Uint8Array( view.buffer, view.byteOffset + start + 4, 4 ) );
		result.push( { type, start: start + 8, end: start + size } );
		start += size;
	}
	return result;
}

function avifDimensions( data: Uint8Array, fileSize: number ): Dimensions | null {
	const view = new DataView( data.buffer, data.byteOffset, data.byteLength );
	// A large mdat may follow meta, so only require complete boxes through meta.
	let offset = 0;
	let hasBrand = false;
	let meta: Box | undefined;
	while ( offset + 8 <= data.length ) {
		let size = view.getUint32( offset );
		let headerSize = 8;
		if ( size === 1 ) {
			if ( offset + 16 > data.length ) { break; }
			size = view.getUint32( offset + 8 ) * 0x100000000 + view.getUint32( offset + 12 );
			headerSize = 16;
		}
		if ( ! Number.isSafeInteger( size ) || size < headerSize || offset + size > fileSize ) {
			return null;
		}
		const type = String.fromCharCode( ...data.subarray( offset + 4, offset + 8 ) );
		if ( offset + size > data.length ) {
			break;
		}
		const start = offset + headerSize;
		if ( type === 'ftyp' && size >= headerSize + 8 && ( size - headerSize - 8 ) % 4 === 0 ) {
			for ( let i = start; i + 4 <= offset + size; i += 4 ) {
				if ( i === start + 4 ) { continue; } // minor version, not a brand.
				if ( String.fromCharCode( ...data.subarray( i, i + 4 ) ) === 'avif' ) { hasBrand = true; }
			}
		}
		if ( type === 'meta' ) {
			if ( meta ) { return null; }
			meta = { type, start, end: offset + size };
		}
		offset += size;
	}
	if ( ! hasBrand || ! meta || meta.start + 4 > meta.end || view.getUint32( meta.start ) !== 0 ) { return null; }
	const children = boxes( view, meta.start + 4, meta.end );
	const primaries = children?.filter( ( box ) => box.type === 'pitm' ) ?? [];
	const properties = children?.filter( ( box ) => box.type === 'iprp' ) ?? [];
	if ( ! children || primaries.length !== 1 || properties.length !== 1 ) { return null; }
	const primary = primaries[ 0 ];
	if ( primary.start + 6 > primary.end ) { return null; }
	const version = data[ primary.start ];
	if ( version > 1 || ( version === 1 && primary.start + 8 > primary.end ) ) { return null; }
	const primaryId = version === 0 ? view.getUint16( primary.start + 4 ) : view.getUint32( primary.start + 4 );
	if ( ! primaryId ) { return null; }
	const propBoxes = boxes( view, properties[ 0 ].start, properties[ 0 ].end );
	const containers = propBoxes?.filter( ( box ) => box.type === 'ipco' ) ?? [];
	const associations = propBoxes?.filter( ( box ) => box.type === 'ipma' ) ?? [];
	if ( ! propBoxes || containers.length !== 1 || ! associations.length ) { return null; }
	const items = boxes( view, containers[ 0 ].start, containers[ 0 ].end );
	if ( ! items ) { return null; }
	let largest: Dimensions | null = null;
	const extents = new Set< number >();
	for ( let i = 0; i < items.length; i++ ) {
		const item = items[ i ];
		if ( item.type !== 'ispe' ) { continue; }
		if ( item.end - item.start !== 12 || view.getUint32( item.start ) !== 0 ) { return null; }
		const width = view.getUint32( item.start + 4 );
		const height = view.getUint32( item.start + 8 );
		if ( ! width || ! height ) { return null; }
		extents.add( i + 1 );
		if ( ! largest || width * height > largest[ 0 ] * largest[ 1 ] ) { largest = [ width, height ]; }
	}
	let primaryHasExtent = false;
	for ( const box of associations ) {
		if ( box.start + 8 > box.end ) { return null; }
		const flags = view.getUint32( box.start );
		const ipmaVersion = flags >>> 24;
		if ( ipmaVersion > 1 || ( flags & 0xfffffe ) !== 0 ) { return null; }
		const wide = Boolean( flags & 1 );
		const count = view.getUint32( box.start + 4 );
		let cursor = box.start + 8;
		for ( let i = 0; i < count; i++ ) {
			const idBytes = ipmaVersion === 0 ? 2 : 4;
			if ( cursor + idBytes + 1 > box.end ) { return null; }
			const id = idBytes === 2 ? view.getUint16( cursor ) : view.getUint32( cursor );
			cursor += idBytes;
			const associationCount = data[ cursor++ ];
			for ( let j = 0; j < associationCount; j++ ) {
				if ( cursor + ( wide ? 2 : 1 ) > box.end ) { return null; }
				const index = wide ? view.getUint16( cursor ) & 0x7fff : data[ cursor ] & 0x7f;
				cursor += wide ? 2 : 1;
				if ( index > items.length ) { return null; }
				if ( id === primaryId && extents.has( index ) ) { primaryHasExtent = true; }
			}
		}
		if ( cursor !== box.end ) { return null; }
	}
	return primaryHasExtent ? largest : null;
}

function dimensionsFromHeader( data: Uint8Array, mime: string, fileSize: number ): Dimensions | null {
	const view = new DataView( data.buffer, data.byteOffset, data.byteLength );
	const text = ( offset: number, length: number ) => String.fromCharCode( ...data.subarray( offset, offset + length ) );
	const u24 = ( offset: number ) => data[ offset ] + data[ offset + 1 ] * 256 + data[ offset + 2 ] * 65536;

	if ( mime === 'image/png' && data.length >= 24 && text( 0, 8 ) === '\x89PNG\r\n\x1a\n'
		&& view.getUint32( 8 ) === 13 && text( 12, 4 ) === 'IHDR' ) {
		return [ view.getUint32( 16 ), view.getUint32( 20 ) ];
	}
	if ( mime === 'image/gif' && data.length >= 13 && [ 'GIF87a', 'GIF89a' ].includes( text( 0, 6 ) ) ) {
		return [ view.getUint16( 6, true ), view.getUint16( 8, true ) ];
	}
	if ( mime === 'image/bmp' && data.length >= 26 && text( 0, 2 ) === 'BM' ) {
		const dib = view.getUint32( 14, true );
		if ( dib === 12 ) {
			return [ view.getUint16( 18, true ), view.getUint16( 20, true ) ];
		}
		if ( dib >= 40 ) {
			return [ view.getInt32( 18, true ), Math.abs( view.getInt32( 22, true ) ) ];
		}
	}
	if ( mime === 'image/webp' && data.length >= 25 && text( 0, 4 ) === 'RIFF' && text( 8, 4 ) === 'WEBP' ) {
		const chunk = text( 12, 4 );
		if ( chunk === 'VP8X' && data.length >= 30 && view.getUint32( 16, true ) === 10 ) {
			return [ 1 + u24( 24 ), 1 + u24( 27 ) ];
		}
		if ( chunk === 'VP8L' && data[ 20 ] === 0x2f ) {
			const bits = view.getUint32( 21, true );
			return [ 1 + ( bits & 0x3fff ), 1 + ( ( bits >>> 14 ) & 0x3fff ) ];
		}
		if ( chunk === 'VP8 ' && data.length >= 30 && !( data[ 20 ] & 1 ) && text( 23, 3 ) === '\x9d\x01\x2a' ) {
			return [ view.getUint16( 26, true ) & 0x3fff, view.getUint16( 28, true ) & 0x3fff ];
		}
	}
	if ( mime === 'image/jpeg' && data.length >= 4 && data[ 0 ] === 0xff && data[ 1 ] === 0xd8 ) {
		let offset = 2;
		while ( offset + 4 <= data.length && data[ offset++ ] === 0xff ) {
			while ( offset < data.length && data[ offset ] === 0xff ) {
				offset++;
			}
			const marker = data[ offset++ ];
			if ( marker === 0xda || marker === 0xd9 || offset + 2 > data.length ) {
				break;
			}
			const length = view.getUint16( offset );
			if ( length < 2 || offset + length > data.length ) {
				break;
			}
			if ( [ 0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf ].includes( marker ) && length >= 8 ) {
				return [ view.getUint16( offset + 5 ), view.getUint16( offset + 3 ) ];
			}
			offset += length;
		}
	}
	return mime === 'image/avif' ? avifDimensions( data, fileSize ) : null;
}

export async function assertBoundedRaster( file: File, maxPixels: number ): Promise< void > {
	const bytes = new Uint8Array( await file.slice( 0, HEADER_LIMIT ).arrayBuffer() );
	const dimensions = dimensionsFromHeader( bytes, file.type, file.size );
	if ( ! dimensions || dimensions[ 0 ] <= 0 || dimensions[ 1 ] <= 0 ) {
		throw new Error( 'Cannot safely read image dimensions in the browser.' );
	}
	if ( dimensions[ 0 ] * dimensions[ 1 ] > maxPixels ) {
		throw new Error( 'image_too_large' );
	}
}
