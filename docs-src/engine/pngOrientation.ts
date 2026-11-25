/**
 * Bit-exact lossless PNG pixel orientation normalizer for EXIF Orientations 2..8.
 * Decodes PNG scanlines (non-interlaced and Adam7 interlaced, color types 0/2/3/4/6,
 * bit depths 1/2/4/8/16), applies the exact lossless coordinate transform on raw samples
 * without color-space or alpha conversion, strips `eXIf`, and preserves all color/palette
 * chunks (`PLTE`, `tRNS`, `iCCP`, `cHRM`, `gAMA`, `sBIT`, `sRGB`, `cICP`).
 */

type PakoModule = {
	inflate: ( input: Uint8Array ) => Uint8Array;
	deflate: ( input: Uint8Array ) => Uint8Array;
};

const CHANNELS_BY_COLOR_TYPE: Record< number, number > = {
	0: 1, // Grayscale
	2: 3, // Truecolor RGB
	3: 1, // Indexed palette
	4: 2, // Grayscale + Alpha
	6: 4, // Truecolor RGBA
};

const ADAM7_START_ROW = [ 0, 0, 4, 0, 2, 0, 1 ];
const ADAM7_START_COL = [ 0, 4, 0, 2, 0, 1, 0 ];
const ADAM7_ROW_INC = [ 8, 8, 8, 4, 4, 2, 2 ];
const ADAM7_COL_INC = [ 8, 8, 4, 4, 2, 2, 1 ];

const PNG_SIGNATURE = new Uint8Array( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] );

function crc32( data: Uint8Array ): number {
	let crc = 0xffffffff;
	for ( let i = 0; i < data.length; i++ ) {
		crc ^= data[ i ];
		for ( let bit = 0; bit < 8; bit++ ) {
			crc = ( crc >>> 1 ) ^ ( crc & 1 ? 0xedb88320 : 0 );
		}
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}

function makeChunk( type: string, payload: Uint8Array ): Uint8Array {
	const out = new Uint8Array( 12 + payload.length );
	const view = new DataView( out.buffer );
	view.setUint32( 0, payload.length );
	for ( let i = 0; i < 4; i++ ) {
		out[ 4 + i ] = type.charCodeAt( i );
	}
	out.set( payload, 8 );
	view.setUint32( 8 + payload.length, crc32( out.subarray( 4, 8 + payload.length ) ) );
	return out;
}

function paethPredictor( a: number, b: number, c: number ): number {
	const p = a + b - c;
	const pa = Math.abs( p - a );
	const pb = Math.abs( p - b );
	const pc = Math.abs( p - c );
	if ( pa <= pb && pa <= pc ) {
		return a;
	}
	return pb <= pc ? b : c;
}

function unfilterPass(
	inflated: Uint8Array,
	offset: number,
	passWidth: number,
	passHeight: number,
	bppBits: number
): { raw: Uint8Array; nextOffset: number } {
	const rowBytes = Math.ceil( ( passWidth * bppBits ) / 8 );
	const filterStride = Math.max( 1, Math.ceil( bppBits / 8 ) );
	const raw = new Uint8Array( passHeight * rowBytes );
	let pos = offset;

	for ( let y = 0; y < passHeight; y++ ) {
		if ( pos + 1 + rowBytes > inflated.length ) {
			throw new Error( 'png_structure_unreadable' );
		}
		const filterType = inflated[ pos++ ];
		const rowStart = y * rowBytes;
		const prevStart = ( y - 1 ) * rowBytes;

		for ( let i = 0; i < rowBytes; i++ ) {
			const x = inflated[ pos++ ];
			const a = i >= filterStride ? raw[ rowStart + i - filterStride ] : 0;
			const b = y > 0 ? raw[ prevStart + i ] : 0;
			const c = y > 0 && i >= filterStride ? raw[ prevStart + i - filterStride ] : 0;
			let recon: number;
			switch ( filterType ) {
				case 0:
					recon = x;
					break;
				case 1:
					recon = ( x + a ) & 0xff;
					break;
				case 2:
					recon = ( x + b ) & 0xff;
					break;
				case 3:
					recon = ( x + ( ( a + b ) >>> 1 ) ) & 0xff;
					break;
				case 4:
					recon = ( x + paethPredictor( a, b, c ) ) & 0xff;
					break;
				default:
					throw new Error( 'png_structure_unreadable' );
			}
			raw[ rowStart + i ] = recon;
		}
	}

	return { raw, nextOffset: pos };
}

function readSubByteSample( raw: Uint8Array, rowBytes: number, x: number, y: number, bitDepth: number ): number {
	const perByte = 8 / bitDepth;
	const byteIdx = y * rowBytes + Math.floor( x / perByte );
	const shift = 8 - bitDepth * ( ( x % perByte ) + 1 );
	const mask = ( 1 << bitDepth ) - 1;
	return ( raw[ byteIdx ] >>> shift ) & mask;
}

function writeSubByteSample( raw: Uint8Array, rowBytes: number, x: number, y: number, bitDepth: number, val: number ): void {
	const perByte = 8 / bitDepth;
	const byteIdx = y * rowBytes + Math.floor( x / perByte );
	const shift = 8 - bitDepth * ( ( x % perByte ) + 1 );
	const mask = ( 1 << bitDepth ) - 1;
	raw[ byteIdx ] |= ( val & mask ) << shift;
}

function mapOrientationCoord(
	orientation: number,
	sx: number,
	sy: number,
	width: number,
	height: number
): [ number, number ] {
	switch ( orientation ) {
		case 2:
			return [ width - 1 - sx, sy ];
		case 3:
			return [ width - 1 - sx, height - 1 - sy ];
		case 4:
			return [ sx, height - 1 - sy ];
		case 5:
			return [ sy, sx ];
		case 6:
			return [ height - 1 - sy, sx ];
		case 7:
			return [ height - 1 - sy, width - 1 - sx ];
		case 8:
			return [ sy, width - 1 - sx ];
		default:
			return [ sx, sy ];
	}
}

export async function transformLosslessPngOrientation(
	strippedPngBytes: Uint8Array,
	orientation: number
): Promise< Uint8Array > {
	if ( orientation < 2 || orientation > 8 ) {
		return strippedPngBytes;
	}

	const view = new DataView( strippedPngBytes.buffer, strippedPngBytes.byteOffset, strippedPngBytes.byteLength );
	let ihdrData: Uint8Array | null = null;
	const preIdatChunks: Uint8Array[] = [];
	const postIdatChunks: Uint8Array[] = [];
	const idatParts: Uint8Array[] = [];
	let sawIdat = false;
	let offset = 8;

	while ( offset + 12 <= strippedPngBytes.length ) {
		const chunkLen = view.getUint32( offset );
		const end = offset + 12 + chunkLen;
		if ( chunkLen > 0x7fffffff || end > strippedPngBytes.length ) {
			throw new Error( 'png_structure_unreadable' );
		}
		const type = String.fromCharCode( ...strippedPngBytes.subarray( offset + 4, offset + 8 ) );
		if ( type === 'IHDR' ) {
			if ( chunkLen !== 13 ) {
				throw new Error( 'png_structure_unreadable' );
			}
			ihdrData = strippedPngBytes.subarray( offset + 8, offset + 21 );
		} else if ( type === 'IDAT' ) {
			sawIdat = true;
			idatParts.push( strippedPngBytes.subarray( offset + 8, offset + 8 + chunkLen ) );
		} else if ( type === 'IEND' ) {
			break;
		} else {
			( sawIdat ? postIdatChunks : preIdatChunks ).push( strippedPngBytes.subarray( offset, end ) );
		}
		offset = end;
	}

	if ( ! ihdrData || idatParts.length === 0 ) {
		throw new Error( 'png_structure_unreadable' );
	}

	const ihdrView = new DataView( ihdrData.buffer, ihdrData.byteOffset, ihdrData.byteLength );
	const width = ihdrView.getUint32( 0 );
	const height = ihdrView.getUint32( 4 );
	const bitDepth = ihdrData[ 8 ];
	const colorType = ihdrData[ 9 ];
	const compression = ihdrData[ 10 ];
	const filterMethod = ihdrData[ 11 ];
	const interlace = ihdrData[ 12 ];
	const channels = CHANNELS_BY_COLOR_TYPE[ colorType ];

	const validDepth =
		( colorType === 0 && [ 1, 2, 4, 8, 16 ].includes( bitDepth ) ) ||
		( colorType === 3 && [ 1, 2, 4, 8 ].includes( bitDepth ) ) ||
		( ( colorType === 2 || colorType === 4 || colorType === 6 ) && ( bitDepth === 8 || bitDepth === 16 ) );

	if (
		! width || ! height || ! channels ||
		compression !== 0 || filterMethod !== 0 ||
		( interlace !== 0 && interlace !== 1 ) ||
		! validDepth
	) {
		throw new Error( 'png_structure_unreadable' );
	}

	const bppBits = channels * bitDepth;
	const totalIdatLen = idatParts.reduce( ( sum, part ) => sum + part.length, 0 );
	const idatAll = new Uint8Array( totalIdatLen );
	let idatPos = 0;
	for ( const part of idatParts ) {
		idatAll.set( part, idatPos );
		idatPos += part.length;
	}

	const pakoMod = ( await import( /* webpackChunkName: "codec-upng" */ 'pako' ) ) as unknown as {
		default?: PakoModule;
		inflate?: PakoModule[ 'inflate' ];
		deflate?: PakoModule[ 'deflate' ];
	};
	const pako: PakoModule = pakoMod.inflate && pakoMod.deflate
		? { inflate: pakoMod.inflate, deflate: pakoMod.deflate }
		: pakoMod.default!;

	let inflated: Uint8Array;
	try {
		inflated = pako.inflate( idatAll );
	} catch {
		throw new Error( 'png_structure_unreadable' );
	}

	const srcRowBytes = Math.ceil( ( width * bppBits ) / 8 );
	let srcPixels: Uint8Array;

	if ( interlace === 0 ) {
		srcPixels = unfilterPass( inflated, 0, width, height, bppBits ).raw;
	} else {
		// Decode all 7 Adam7 interlace passes into a flat full-frame pixel buffer.
		srcPixels = new Uint8Array( height * srcRowBytes );
		let pos = 0;
		const pixelBytes = bppBits >= 8 ? bppBits / 8 : 0;
		for ( let pass = 0; pass < 7; pass++ ) {
			const passW = Math.ceil( ( width - ADAM7_START_COL[ pass ] ) / ADAM7_COL_INC[ pass ] );
			const passH = Math.ceil( ( height - ADAM7_START_ROW[ pass ] ) / ADAM7_ROW_INC[ pass ] );
			if ( passW <= 0 || passH <= 0 ) {
				continue;
			}
			const { raw: passRaw, nextOffset } = unfilterPass( inflated, pos, passW, passH, bppBits );
			pos = nextOffset;
			const passRowBytes = Math.ceil( ( passW * bppBits ) / 8 );

			for ( let py = 0; py < passH; py++ ) {
				const dy = ADAM7_START_ROW[ pass ] + py * ADAM7_ROW_INC[ pass ];
				for ( let px = 0; px < passW; px++ ) {
					const dx = ADAM7_START_COL[ pass ] + px * ADAM7_COL_INC[ pass ];
					if ( pixelBytes > 0 ) {
						const srcOff = py * passRowBytes + px * pixelBytes;
						const dstOff = dy * srcRowBytes + dx * pixelBytes;
						for ( let b = 0; b < pixelBytes; b++ ) {
							srcPixels[ dstOff + b ] = passRaw[ srcOff + b ];
						}
					} else {
						const sample = readSubByteSample( passRaw, passRowBytes, px, py, bitDepth );
						writeSubByteSample( srcPixels, srcRowBytes, dx, dy, bitDepth, sample );
					}
				}
			}
		}
	}

	const swapAxes = orientation >= 5 && orientation <= 8;
	const outW = swapAxes ? height : width;
	const outH = swapAxes ? width : height;
	const outRowBytes = Math.ceil( ( outW * bppBits ) / 8 );
	const outPixels = new Uint8Array( outH * outRowBytes );
	const pixelBytes = bppBits >= 8 ? bppBits / 8 : 0;

	for ( let sy = 0; sy < height; sy++ ) {
		for ( let sx = 0; sx < width; sx++ ) {
			const [ dx, dy ] = mapOrientationCoord( orientation, sx, sy, width, height );
			if ( pixelBytes > 0 ) {
				const srcOff = sy * srcRowBytes + sx * pixelBytes;
				const dstOff = dy * outRowBytes + dx * pixelBytes;
				for ( let b = 0; b < pixelBytes; b++ ) {
					outPixels[ dstOff + b ] = srcPixels[ srcOff + b ];
				}
			} else {
				const sample = readSubByteSample( srcPixels, srcRowBytes, sx, sy, bitDepth );
				writeSubByteSample( outPixels, outRowBytes, dx, dy, bitDepth, sample );
			}
		}
	}

	// Build filter-0 scanlines for deflate; oxipng will choose optimal row filters & DEFLATE next.
	const scanlines = new Uint8Array( outH * ( 1 + outRowBytes ) );
	for ( let y = 0; y < outH; y++ ) {
		const dstRow = y * ( 1 + outRowBytes );
		scanlines[ dstRow ] = 0;
		scanlines.set( outPixels.subarray( y * outRowBytes, ( y + 1 ) * outRowBytes ), dstRow + 1 );
	}
	const compressedIdat = pako.deflate( scanlines );

	const nextIhdr = new Uint8Array( 13 );
	const nextIhdrView = new DataView( nextIhdr.buffer );
	nextIhdrView.setUint32( 0, outW );
	nextIhdrView.setUint32( 4, outH );
	nextIhdr[ 8 ] = bitDepth;
	nextIhdr[ 9 ] = colorType;
	nextIhdr[ 10 ] = 0;
	nextIhdr[ 11 ] = 0;
	nextIhdr[ 12 ] = 0; // Non-interlaced output

	const chunks: Uint8Array[] = [
		PNG_SIGNATURE,
		makeChunk( 'IHDR', nextIhdr ),
		...preIdatChunks,
		makeChunk( 'IDAT', compressedIdat ),
		...postIdatChunks,
		makeChunk( 'IEND', new Uint8Array( 0 ) ),
	];

	const totalBytes = chunks.reduce( ( sum, c ) => sum + c.length, 0 );
	const result = new Uint8Array( totalBytes );
	let writePos = 0;
	for ( const c of chunks ) {
		result.set( c, writePos );
		writePos += c.length;
	}
	return result;
}
