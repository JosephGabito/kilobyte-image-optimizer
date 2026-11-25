/** Read image dimensions without asking the browser to decode its pixels. */
import { resolveInputMime } from './format';
import { isCompleteJpegBytes } from './jpegCompleteness';

export { isCompleteJpegBytes };

const HEADER_LIMIT = 256 * 1024;
export const MAX_IMAGE_DIMENSION = 16_384;

export type RasterDimensions = [ number, number ];

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

function isAxisSwappingOrientation( orientation: number ): boolean {
	return orientation >= 5 && orientation <= 8;
}

/** Parse TIFF IFD0 Orientation tag (0x0112), tolerating an optional leading `Exif\0\0` prefix. */
export function parseTiffOrientation( data: Uint8Array, start: number, end: number ): number {
	let tiffStart = start;
	if (
		tiffStart + 6 <= end &&
		data[ tiffStart ] === 0x45 && data[ tiffStart + 1 ] === 0x78 &&
		data[ tiffStart + 2 ] === 0x69 && data[ tiffStart + 3 ] === 0x66 &&
		data[ tiffStart + 4 ] === 0x00 && data[ tiffStart + 5 ] === 0x00
	) {
		tiffStart += 6;
	}
	if ( tiffStart + 8 > end ) {
		return 1;
	}
	const b0 = data[ tiffStart ];
	const b1 = data[ tiffStart + 1 ];
	let littleEndian: boolean;
	if ( b0 === 0x49 && b1 === 0x49 ) {
		littleEndian = true;
	} else if ( b0 === 0x4d && b1 === 0x4d ) {
		littleEndian = false;
	} else {
		return 1;
	}
	const view = new DataView( data.buffer, data.byteOffset, data.byteLength );
	if ( view.getUint16( tiffStart + 2, littleEndian ) !== 0x002a ) {
		return 1;
	}
	const ifd0Offset = view.getUint32( tiffStart + 4, littleEndian );
	if ( ifd0Offset < 8 || tiffStart + ifd0Offset + 2 > end ) {
		return 1;
	}
	const entryCount = Math.min( view.getUint16( tiffStart + ifd0Offset, littleEndian ), 256 );
	for ( let i = 0; i < entryCount; i++ ) {
		const entryPos = tiffStart + ifd0Offset + 2 + i * 12;
		if ( entryPos + 12 > end ) {
			break;
		}
		const tag = view.getUint16( entryPos, littleEndian );
		if ( tag === 0x0112 ) {
			const type = view.getUint16( entryPos + 2, littleEndian );
			const count = view.getUint32( entryPos + 4, littleEndian );
			if ( type === 3 && count === 1 ) {
				return view.getUint16( entryPos + 8, littleEndian );
			}
			return 1;
		}
	}
	return 1;
}

function avifDimensions( data: Uint8Array, fileSize: number ): RasterDimensions | null {
	const view = new DataView( data.buffer, data.byteOffset, data.byteLength );
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
				if ( i === start + 4 ) { continue; }
				const brand = String.fromCharCode( ...data.subarray( i, i + 4 ) );
				if ( brand === 'avif' || brand === 'avis' ) { hasBrand = true; }
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
	let largest: RasterDimensions | null = null;
	const extents = new Set< number >();
	const rotations = new Map< number, number >();
	for ( let i = 0; i < items.length; i++ ) {
		const item = items[ i ];
		if ( item.type === 'irot' && item.end - item.start === 1 ) {
			rotations.set( i + 1, data[ item.start ] & 3 );
			continue;
		}
		if ( item.type !== 'ispe' ) { continue; }
		if ( item.end - item.start !== 12 || view.getUint32( item.start ) !== 0 ) { return null; }
		const width = view.getUint32( item.start + 4 );
		const height = view.getUint32( item.start + 8 );
		if ( ! width || ! height ) { return null; }
		extents.add( i + 1 );
		if ( ! largest || width * height > largest[ 0 ] * largest[ 1 ] ) { largest = [ width, height ]; }
	}
	let primaryHasExtent = false;
	let swapAxes = false;
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
				if ( id === primaryId ) {
					if ( extents.has( index ) ) { primaryHasExtent = true; }
					const angle = rotations.get( index );
					if ( angle === 1 || angle === 3 ) { swapAxes = true; }
				}
			}
		}
		if ( cursor !== box.end ) { return null; }
	}
	if ( ! primaryHasExtent || ! largest ) { return null; }
	return swapAxes ? [ largest[ 1 ], largest[ 0 ] ] : largest;
}

export function dimensionsFromHeader( data: Uint8Array, mime: string, fileSize: number ): RasterDimensions | null {
	const view = new DataView( data.buffer, data.byteOffset, data.byteLength );
	const text = ( offset: number, length: number ) => String.fromCharCode( ...data.subarray( offset, offset + length ) );
	const u24 = ( offset: number ) => data[ offset ] + data[ offset + 1 ] * 256 + data[ offset + 2 ] * 65536;

	if ( mime === 'image/png' && data.length >= 24 && text( 0, 8 ) === '\x89PNG\r\n\x1a\n'
		&& view.getUint32( 8 ) === 13 && text( 12, 4 ) === 'IHDR' ) {
		const w = view.getUint32( 16 );
		const h = view.getUint32( 20 );
		let swapAxes = false;
		let pos = 33;
		while ( pos + 12 <= data.length ) {
			const len = view.getUint32( pos );
			const type = text( pos + 4, 4 );
			if ( type === 'IEND' ) {
				break;
			}
			const nextPos = pos + 12 + len;
			if ( len > 0x7fffffff || nextPos > data.length ) {
				break;
			}
			if ( type === 'eXIf' ) {
				swapAxes = isAxisSwappingOrientation( parseTiffOrientation( data, pos + 8, pos + 8 + len ) );
				break;
			}
			pos = nextPos;
		}
		return swapAxes ? [ h, w ] : [ w, h ];
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
			const w = 1 + u24( 24 );
			const h = 1 + u24( 27 );
			let swapAxes = false;
			if ( data[ 20 ] & 0x08 ) {
				let pos = 12;
				while ( pos + 8 <= data.length ) {
					const cType = text( pos, 4 );
					const cSize = view.getUint32( pos + 4, true );
					const dataStart = pos + 8;
					const dataEnd = dataStart + cSize;
					if ( dataEnd > data.length ) {
						break;
					}
					if ( cType === 'EXIF' ) {
						swapAxes = isAxisSwappingOrientation( parseTiffOrientation( data, dataStart, dataEnd ) );
						break;
					}
					pos = dataEnd + ( cSize & 1 );
				}
			}
			return swapAxes ? [ h, w ] : [ w, h ];
		}
		if ( chunk === 'VP8L' && data[ 20 ] === 0x2f ) {
			const bits = view.getUint32( 21, true );
			return [ 1 + ( bits & 0x3fff ), 1 + ( ( bits >>> 14 ) & 0x3fff ) ];
		}
		if ( chunk === 'VP8 ' && data.length >= 30 && ! ( data[ 20 ] & 1 ) && text( 23, 3 ) === '\x9d\x01\x2a' ) {
			return [ view.getUint16( 26, true ) & 0x3fff, view.getUint16( 28, true ) & 0x3fff ];
		}
	}
	if ( mime === 'image/jpeg' && data.length >= 4 && data[ 0 ] === 0xff && data[ 1 ] === 0xd8 ) {
		let offset = 2;
		let rawDims: RasterDimensions | null = null;
		let foundExif = false;
		let swapAxes = false;
		let truncatedBeforeSos = false;
		while ( offset + 2 <= data.length && data[ offset++ ] === 0xff ) {
			while ( offset < data.length && data[ offset ] === 0xff ) {
				offset++;
			}
			if ( offset >= data.length ) {
				truncatedBeforeSos = true;
				break;
			}
			const marker = data[ offset++ ];
			if ( marker === 0xda || marker === 0xd9 ) {
				break;
			}
			if ( marker === 0x01 || ( marker >= 0xd0 && marker <= 0xd7 ) ) {
				continue;
			}
			if ( offset + 2 > data.length ) {
				truncatedBeforeSos = true;
				break;
			}
			const length = view.getUint16( offset );
			if ( length < 2 ) {
				break;
			}
			if ( offset + length > data.length ) {
				truncatedBeforeSos = true;
				break;
			}
			if (
				! foundExif &&
				marker === 0xe1 &&
				length >= 16 &&
				data[ offset + 2 ] === 0x45 && data[ offset + 3 ] === 0x78 &&
				data[ offset + 4 ] === 0x69 && data[ offset + 5 ] === 0x66 &&
				data[ offset + 6 ] === 0x00 && data[ offset + 7 ] === 0x00
			) {
				foundExif = true;
				swapAxes = isAxisSwappingOrientation( parseTiffOrientation( data, offset + 2, offset + length ) );
				if ( rawDims ) {
					break;
				}
			} else if (
				! rawDims &&
				[ 0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf ].includes( marker ) &&
				length >= 8
			) {
				rawDims = [ view.getUint16( offset + 5 ), view.getUint16( offset + 3 ) ];
				if ( foundExif ) {
					break;
				}
			}
			offset += length;
		}
		if ( rawDims && ( ! truncatedBeforeSos || foundExif || fileSize <= data.length ) ) {
			return swapAxes ? [ rawDims[ 1 ], rawDims[ 0 ] ] : rawDims;
		}
		return null;
	}
	return mime === 'image/avif' ? avifDimensions( data, fileSize ) : null;
}

async function readJpegDimensionsSeek( file: File ): Promise< RasterDimensions | null > {
	// Seek past large APPn/COM metadata segments without loading the entire file into RAM,
	// tracking both SOF dimensions and APP1 EXIF Orientation.
	let offset = 2;
	let segments = 0;
	const MAX_SEGMENTS = 512;
	let rawDims: RasterDimensions | null = null;
	let foundExif = false;
	let swapAxes = false;

	while ( offset + 4 <= file.size && segments < MAX_SEGMENTS ) {
		segments += 1;
		const probe = new Uint8Array( await file.slice( offset, Math.min( file.size, offset + 64 ) ).arrayBuffer() );
		if ( probe.length < 2 || probe[ 0 ] !== 0xff ) {
			break;
		}
		let local = 1;
		while ( local < probe.length && probe[ local ] === 0xff ) {
			local += 1;
		}
		if ( local >= probe.length ) {
			offset += local;
			continue;
		}
		const marker = probe[ local++ ];
		if ( marker === 0xd9 || marker === 0xda ) {
			break;
		}
		if ( marker === 0x01 || ( marker >= 0xd0 && marker <= 0xd7 ) ) {
			offset += local;
			continue;
		}
		if ( local + 2 > probe.length ) {
			break;
		}
		const segLen = ( probe[ local ] << 8 ) | probe[ local + 1 ];
		if ( segLen < 2 || offset + local + segLen > file.size ) {
			break;
		}
		if ( ! foundExif && marker === 0xe1 && segLen >= 16 ) {
			const payloadLen = Math.min( segLen - 2, 65536 );
			const app1 = new Uint8Array(
				await file.slice( offset + local + 2, offset + local + 2 + payloadLen ).arrayBuffer()
			);
			if (
				app1.length >= 14 &&
				app1[ 0 ] === 0x45 && app1[ 1 ] === 0x78 &&
				app1[ 2 ] === 0x69 && app1[ 3 ] === 0x66 &&
				app1[ 4 ] === 0x00 && app1[ 5 ] === 0x00
			) {
				foundExif = true;
				swapAxes = isAxisSwappingOrientation( parseTiffOrientation( app1, 0, app1.length ) );
				if ( rawDims ) {
					break;
				}
			}
		} else if (
			! rawDims &&
			[ 0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf ].includes( marker ) &&
			segLen >= 8
		) {
			const sof = local + 7 <= probe.length
				? probe.subarray( local, local + 8 )
				: new Uint8Array( await file.slice( offset + local, offset + local + 8 ).arrayBuffer() );
			if ( sof.length >= 7 ) {
				const height = ( sof[ 3 ] << 8 ) | sof[ 4 ];
				const width = ( sof[ 5 ] << 8 ) | sof[ 6 ];
				if ( width > 0 && height > 0 ) {
					rawDims = [ width, height ];
					if ( foundExif ) {
						break;
					}
				}
			}
		}
		offset += local + segLen;
	}
	if ( ! rawDims ) {
		return null;
	}
	return swapAxes ? [ rawDims[ 1 ], rawDims[ 0 ] ] : rawDims;
}

async function readWebpExifSwapSeek( file: File ): Promise< boolean > {
	let pos = 12;
	let chunks = 0;
	while ( pos + 8 <= file.size && chunks < 128 ) {
		chunks += 1;
		const hdr = new Uint8Array( await file.slice( pos, pos + 8 ).arrayBuffer() );
		if ( hdr.length < 8 ) {
			return false;
		}
		const view = new DataView( hdr.buffer, hdr.byteOffset, hdr.byteLength );
		const cType = String.fromCharCode( hdr[ 0 ], hdr[ 1 ], hdr[ 2 ], hdr[ 3 ] );
		const cSize = view.getUint32( 4, true );
		const dataStart = pos + 8;
		const dataEnd = dataStart + cSize;
		if ( dataEnd > file.size ) {
			return false;
		}
		if ( cType === 'EXIF' ) {
			const exifBytes = new Uint8Array(
				await file.slice( dataStart, Math.min( dataEnd, dataStart + 65536 ) ).arrayBuffer()
			);
			return isAxisSwappingOrientation( parseTiffOrientation( exifBytes, 0, exifBytes.length ) );
		}
		pos = dataEnd + ( cSize & 1 );
	}
	return false;
}

async function readPngExifSwapSeek( file: File ): Promise< boolean > {
	let pos = 8;
	let chunks = 0;
	while ( pos + 12 <= file.size && chunks < 1024 ) {
		chunks += 1;
		const hdr = new Uint8Array( await file.slice( pos, pos + 8 ).arrayBuffer() );
		if ( hdr.length < 8 ) {
			return false;
		}
		const view = new DataView( hdr.buffer, hdr.byteOffset, hdr.byteLength );
		const len = view.getUint32( 0 );
		const type = String.fromCharCode( hdr[ 4 ], hdr[ 5 ], hdr[ 6 ], hdr[ 7 ] );
		const nextPos = pos + 12 + len;
		if ( len > 0x7fffffff || nextPos > file.size ) {
			return false;
		}
		if ( type === 'IEND' ) {
			return false;
		}
		if ( type === 'eXIf' ) {
			const payload = new Uint8Array(
				await file.slice( pos + 8, Math.min( pos + 8 + len, pos + 8 + 65536 ) ).arrayBuffer()
			);
			return isAxisSwappingOrientation( parseTiffOrientation( payload, 0, payload.length ) );
		}
		pos = nextPos;
	}
	return false;
}

export async function assertCompleteJpeg( file: File ): Promise< void > {
	const bytes = new Uint8Array( await file.arrayBuffer() );
	if ( ! isCompleteJpegBytes( bytes ) ) {
		throw new Error( 'truncated_image' );
	}
}

export async function readRasterDimensions( file: File ): Promise< RasterDimensions | null > {
	const mime = resolveInputMime( file );
	if ( ! mime ) {
		return null;
	}
	const bytes = new Uint8Array( await file.slice( 0, HEADER_LIMIT ).arrayBuffer() );
	const dims = dimensionsFromHeader( bytes, mime, file.size );
	if ( dims ) {
		// PNG allows an `eXIf` chunk after `IDAT`, which can sit past HEADER_LIMIT on large PNGs.
		if ( mime === 'image/png' && file.size > HEADER_LIMIT && bytes.length >= 33 ) {
			const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );
			let pos = 33;
			let sawExifOrIend = false;
			while ( pos + 12 <= bytes.length ) {
				const len = view.getUint32( pos );
				const type = String.fromCharCode( ...bytes.subarray( pos + 4, pos + 8 ) );
				if ( type === 'eXIf' || type === 'IEND' ) {
					sawExifOrIend = true;
					break;
				}
				const nextPos = pos + 12 + len;
				if ( len > 0x7fffffff || nextPos > bytes.length ) {
					break;
				}
				pos = nextPos;
			}
			if ( ! sawExifOrIend && ( await readPngExifSwapSeek( file ) ) ) {
				return [ dims[ 1 ], dims[ 0 ] ];
			}
		}
		// WebP extended files often place the EXIF chunk after VP8/VP8L pixel data (past 256 KiB).
		if (
			mime === 'image/webp' &&
			file.size > HEADER_LIMIT &&
			bytes.length >= 30 &&
			String.fromCharCode( ...bytes.subarray( 12, 16 ) ) === 'VP8X' &&
			Boolean( bytes[ 20 ] & 0x08 )
		) {
			// Check whether EXIF was already parsed inside HEADER_LIMIT; if not, seek for it.
			const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );
			let pos = 12;
			let sawExifInHeader = false;
			while ( pos + 8 <= bytes.length ) {
				const cType = String.fromCharCode( ...bytes.subarray( pos, pos + 4 ) );
				const cSize = view.getUint32( pos + 4, true );
				const dataEnd = pos + 8 + cSize;
				if ( dataEnd > bytes.length ) {
					break;
				}
				if ( cType === 'EXIF' ) {
					sawExifInHeader = true;
					break;
				}
				pos = dataEnd + ( cSize & 1 );
			}
			if ( ! sawExifInHeader && ( await readWebpExifSwapSeek( file ) ) ) {
				return [ dims[ 1 ], dims[ 0 ] ];
			}
		}
		return dims;
	}
	if ( mime === 'image/jpeg' && file.size > HEADER_LIMIT && bytes.length >= 2 && bytes[ 0 ] === 0xff && bytes[ 1 ] === 0xd8 ) {
		return readJpegDimensionsSeek( file );
	}
	return null;
}

export async function assertBoundedRaster(
	file: File,
	maxPixels: number
): Promise< void > {
	const dimensions = await readRasterDimensions( file );
	if ( ! dimensions || dimensions[ 0 ] <= 0 || dimensions[ 1 ] <= 0 ) {
		throw new Error( 'Cannot safely read image dimensions in the browser.' );
	}
	if ( dimensions[ 0 ] * dimensions[ 1 ] > maxPixels ) {
		throw new Error( 'image_too_large' );
	}
}
