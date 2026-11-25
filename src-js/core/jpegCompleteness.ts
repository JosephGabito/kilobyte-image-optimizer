/**
 * Pure TypeScript ITU-T T.81 JPEG bitstream completeness validator.
 * Verifies that every sequential (SOF0/SOF1) and progressive (SOF2) Huffman scan
 * decodes its exact expected number of MCUs/blocks without hitting premature EOF
 * or unexpected markers—catching truncated scan data even when an EOI (FF D9)
 * trailer is intact.
 */

interface HuffmanTable {
	/** Max code value (+1) for each bit length 1..16, or -1 if none. */
	maxCode: Int32Array;
	/** Offset into values[] for each bit length 1..16. */
	valOffset: Int32Array;
	values: Uint8Array;
}

interface FrameComponent {
	id: number;
	h: number;
	v: number;
	blocksPerLine: number;
	blockRows: number;
	totalBlocks: number;
}

class BitReader {
	private data: Uint8Array;
	public offset: number;
	private bitBuf = 0;
	private bitCount = 0;
	public hitUnexpectedMarker = false;
	public hitEof = false;

	constructor( data: Uint8Array, offset: number ) {
		this.data = data;
		this.offset = offset;
	}

	private readByte(): number {
		if ( this.offset >= this.data.length ) {
			this.hitEof = true;
			return -1;
		}
		const b = this.data[ this.offset++ ];
		if ( b !== 0xff ) {
			return b;
		}
		// Inside entropy-coded data, 0xFF must be followed by 0x00 (stuffed literal 0xFF)
		// or additional 0xFF fill bytes before a marker. Any other non-zero byte is a marker!
		while ( this.offset < this.data.length && this.data[ this.offset ] === 0xff ) {
			this.offset += 1;
		}
		if ( this.offset >= this.data.length ) {
			this.hitEof = true;
			return -1;
		}
		const next = this.data[ this.offset ];
		if ( next === 0x00 ) {
			this.offset += 1;
			return 0xff;
		}
		// Rewind to the 0xFF preceding the marker so the caller can inspect it.
		this.offset -= 1;
		this.hitUnexpectedMarker = true;
		return -1;
	}

	public readBit(): number {
		if ( this.bitCount === 0 ) {
			const b = this.readByte();
			if ( b < 0 ) {
				return -1;
			}
			this.bitBuf = b;
			this.bitCount = 8;
		}
		this.bitCount -= 1;
		return ( this.bitBuf >>> this.bitCount ) & 1;
	}

	public readBits( count: number ): number {
		let val = 0;
		for ( let i = 0; i < count; i++ ) {
			const bit = this.readBit();
			if ( bit < 0 ) {
				return -1;
			}
			val = ( val << 1 ) | bit;
		}
		return val;
	}

	public skipBits( count: number ): boolean {
		let rem = count;
		while ( rem > 0 ) {
			if ( this.bitCount === 0 ) {
				const b = this.readByte();
				if ( b < 0 ) {
					return false;
				}
				this.bitBuf = b;
				this.bitCount = 8;
			}
			const take = Math.min( rem, this.bitCount );
			this.bitCount -= take;
			rem -= take;
		}
		return true;
	}

	public decodeHuffman( table: HuffmanTable ): number {
		let code = 0;
		for ( let len = 1; len <= 16; len++ ) {
			const bit = this.readBit();
			if ( bit < 0 ) {
				return -1;
			}
			code = ( code << 1 ) | bit;
			if ( code <= table.maxCode[ len ] ) {
				const idx = table.valOffset[ len ] + code;
				return idx >= 0 && idx < table.values.length ? table.values[ idx ] : -1;
			}
		}
		return -1;
	}

	/** Align bit buffer to byte boundary and consume an expected RSTn marker (0xD0..0xD7). */
	public consumeRestartMarker( expectedRst: number ): boolean {
		this.bitBuf = 0;
		this.bitCount = 0;
		this.hitUnexpectedMarker = false;
		while ( this.offset < this.data.length && this.data[ this.offset ] === 0xff ) {
			this.offset += 1;
		}
		if ( this.offset >= this.data.length ) {
			this.hitEof = true;
			return false;
		}
		const marker = this.data[ this.offset ];
		if ( marker !== 0xd0 + ( expectedRst & 7 ) ) {
			return false;
		}
		this.offset += 1;
		return true;
	}

	/** Advance past remaining padding bits in the current byte to the next marker's 0xFF. */
	public finishScan(): number {
		this.bitBuf = 0;
		this.bitCount = 0;
		// Skip any zero/fill bytes until the next 0xFF marker, failing if non-marker garbage runs past.
		while ( this.offset < this.data.length ) {
			if ( this.data[ this.offset ] === 0xff ) {
				const start = this.offset;
				while ( this.offset < this.data.length && this.data[ this.offset ] === 0xff ) {
					this.offset += 1;
				}
				if ( this.offset >= this.data.length ) {
					return -1;
				}
				if ( this.data[ this.offset ] === 0x00 ) {
					this.offset += 1;
					continue;
				}
				return start;
			}
			// Some encoders emit a single trailing 0x00 or restart padding byte; walk forward safely.
			this.offset += 1;
		}
		return -1;
	}
}

function buildHuffmanTable( counts: Uint8Array, values: Uint8Array ): HuffmanTable | null {
	const maxCode = new Int32Array( 17 ).fill( -1 );
	const valOffset = new Int32Array( 17 );
	let code = 0;
	let valueIndex = 0;
	for ( let len = 1; len <= 16; len++ ) {
		const count = counts[ len - 1 ];
		if ( count > 0 ) {
			valOffset[ len ] = valueIndex - code;
			valueIndex += count;
			code += count;
			maxCode[ len ] = code - 1;
		}
		code <<= 1;
	}
	if ( valueIndex !== values.length ) {
		return null;
	}
	return { maxCode, valOffset, values };
}

function parseDhtSegment(
	data: Uint8Array,
	start: number,
	end: number,
	dcTables: Array< HuffmanTable | null >,
	acTables: Array< HuffmanTable | null >
): boolean {
	let pos = start;
	while ( pos < end ) {
		if ( pos + 17 > end ) {
			return false;
		}
		const info = data[ pos++ ];
		const tc = info >>> 4; // 0 = DC, 1 = AC
		const th = info & 0x0f; // table ID 0..3
		if ( ( tc !== 0 && tc !== 1 ) || th > 3 ) {
			return false;
		}
		const counts = data.subarray( pos, pos + 16 );
		pos += 16;
		let total = 0;
		for ( let i = 0; i < 16; i++ ) {
			total += counts[ i ];
		}
		if ( total > 256 || pos + total > end ) {
			return false;
		}
		const values = data.subarray( pos, pos + total );
		pos += total;
		const table = buildHuffmanTable( counts, values );
		if ( ! table ) {
			return false;
		}
		if ( tc === 0 ) {
			dcTables[ th ] = table;
		} else {
			acTables[ th ] = table;
		}
	}
	return pos === end;
}

function verifySequentialScan(
	reader: BitReader,
	scanComps: Array< { comp: FrameComponent; dc: HuffmanTable; ac: HuffmanTable } >,
	mcuCols: number,
	mcuRows: number,
	restartInterval: number
): boolean {
	const isInterleaved = scanComps.length > 1;
	const totalUnits = isInterleaved
		? mcuCols * mcuRows
		: scanComps[ 0 ].comp.blocksPerLine * scanComps[ 0 ].comp.blockRows;

	let rstCounter = 0;
	let unitsSinceRst = 0;

	for ( let u = 0; u < totalUnits; u++ ) {
		if ( restartInterval > 0 && unitsSinceRst === restartInterval ) {
			if ( ! reader.consumeRestartMarker( rstCounter++ ) ) {
				return false;
			}
			unitsSinceRst = 0;
		}

		for ( let c = 0; c < scanComps.length; c++ ) {
			const { comp, dc, ac } = scanComps[ c ];
			const blockCount = isInterleaved ? comp.h * comp.v : 1;
			for ( let b = 0; b < blockCount; b++ ) {
				const dcSym = reader.decodeHuffman( dc );
				if ( dcSym < 0 || dcSym > 15 ) {
					return false;
				}
				if ( dcSym > 0 && ! reader.skipBits( dcSym ) ) {
					return false;
				}
				let k = 1;
				while ( k < 64 ) {
					const rs = reader.decodeHuffman( ac );
					if ( rs < 0 ) {
						return false;
					}
					if ( rs === 0x00 ) {
						break; // EOB
					}
					const run = rs >>> 4;
					const size = rs & 0x0f;
					if ( size === 0 ) {
						if ( run === 0x0f ) {
							k += 16; // ZRL (16 zeros)
							if ( k > 64 ) {
								return false;
							}
							continue;
						}
						return false;
					}
					k += run + 1;
					if ( k > 64 ) {
						return false;
					}
					if ( ! reader.skipBits( size ) ) {
						return false;
					}
				}
			}
		}

		unitsSinceRst += 1;
	}

	return ! reader.hitUnexpectedMarker && ! reader.hitEof;
}

function verifyProgressiveScan(
	reader: BitReader,
	scanComps: Array< { comp: FrameComponent; compIdx: number; dc: HuffmanTable | null; ac: HuffmanTable | null } >,
	mcuCols: number,
	mcuRows: number,
	ss: number,
	se: number,
	ah: number,
	restartInterval: number,
	nonZeroBitmaps: Uint8Array[]
): boolean {
	const isInterleaved = scanComps.length > 1;
	if ( ss === 0 && se !== 0 ) {
		return false; // Progressive scans cannot mix DC and AC.
	}
	if ( ss > 0 && isInterleaved ) {
		return false; // Progressive AC scans must be single-component.
	}

	const totalUnits = isInterleaved
		? mcuCols * mcuRows
		: scanComps[ 0 ].comp.blocksPerLine * scanComps[ 0 ].comp.blockRows;

	let rstCounter = 0;
	let unitsSinceRst = 0;
	let eobRun = 0;

	const maybeRestart = (): boolean => {
		if ( restartInterval > 0 && unitsSinceRst === restartInterval ) {
			if ( ! reader.consumeRestartMarker( rstCounter++ ) ) {
				return false;
			}
			unitsSinceRst = 0;
			eobRun = 0;
		}
		return true;
	};

	// DC scans (first or refinement)
	if ( ss === 0 ) {
		for ( let u = 0; u < totalUnits; u++ ) {
			if ( ! maybeRestart() ) {
				return false;
			}
			for ( let c = 0; c < scanComps.length; c++ ) {
				const { comp, dc } = scanComps[ c ];
				const blockCount = isInterleaved ? comp.h * comp.v : 1;
				for ( let b = 0; b < blockCount; b++ ) {
					if ( ah === 0 ) {
						if ( ! dc ) {
							return false;
						}
						const sym = reader.decodeHuffman( dc );
						if ( sym < 0 || sym > 15 ) {
							return false;
						}
						if ( sym > 0 && ! reader.skipBits( sym ) ) {
							return false;
						}
					} else if ( reader.readBit() < 0 ) {
						return false;
					}
				}
			}
			unitsSinceRst += 1;
		}
		return ! reader.hitUnexpectedMarker && ! reader.hitEof;
	}

	// AC scans (single component)
	const { ac, compIdx } = scanComps[ 0 ];
	if ( ! ac ) {
		return false;
	}
	const nz = nonZeroBitmaps[ compIdx ];

	if ( ah === 0 ) {
		// AC first scan
		for ( let u = 0; u < totalUnits; u++ ) {
			if ( ! maybeRestart() ) {
				return false;
			}
			if ( eobRun > 0 ) {
				eobRun -= 1;
				unitsSinceRst += 1;
				continue;
			}
			let k = ss;
			const blockByteBase = u * 8;
			while ( k <= se ) {
				const rs = reader.decodeHuffman( ac );
				if ( rs < 0 ) {
					return false;
				}
				const run = rs >>> 4;
				const size = rs & 0x0f;
				if ( size === 0 ) {
					if ( run === 0x0f ) {
						k += 16;
						if ( k > se + 1 ) {
							return false;
						}
						continue;
					}
					// EOBn (run = 0..14)
					const extra = run > 0 ? reader.readBits( run ) : 0;
					if ( extra < 0 ) {
						return false;
					}
					eobRun = ( 1 << run ) + extra - 1;
					break;
				}
				k += run;
				if ( k > se ) {
					return false;
				}
				if ( ! reader.skipBits( size ) ) {
					return false;
				}
				// Mark coefficient k as non-zero for subsequent AC refinement scans.
				nz[ blockByteBase + ( k >>> 3 ) ] |= 1 << ( k & 7 );
				k += 1;
			}
			unitsSinceRst += 1;
		}
		return ! reader.hitUnexpectedMarker && ! reader.hitEof;
	}

	// AC refinement scan (ah > 0)
	for ( let u = 0; u < totalUnits; u++ ) {
		if ( ! maybeRestart() ) {
			return false;
		}
		const blockByteBase = u * 8;
		let k = ss;

		const refineExisting = ( idx: number ): boolean => {
			if ( nz[ blockByteBase + ( idx >>> 3 ) ] & ( 1 << ( idx & 7 ) ) ) {
				return reader.readBit() >= 0;
			}
			return true;
		};

		if ( eobRun > 0 ) {
			for ( ; k <= se; k++ ) {
				if ( ! refineExisting( k ) ) {
					return false;
				}
			}
			eobRun -= 1;
			unitsSinceRst += 1;
			continue;
		}

		while ( k <= se ) {
			const rs = reader.decodeHuffman( ac );
			if ( rs < 0 ) {
				return false;
			}
			const run = rs >>> 4;
			const size = rs & 0x0f;
			if ( size === 0 ) {
				if ( run !== 0x0f ) {
					const extra = run > 0 ? reader.readBits( run ) : 0;
					if ( extra < 0 ) {
						return false;
					}
					eobRun = ( 1 << run ) + extra - 1;
					for ( ; k <= se; k++ ) {
						if ( ! refineExisting( k ) ) {
							return false;
						}
					}
					break;
				}
				// ZRL: skip 16 zero coefficients, refining any already non-zero coefficients along the way.
				let zerosToSkip = 16;
				while ( k <= se && zerosToSkip > 0 ) {
					if ( nz[ blockByteBase + ( k >>> 3 ) ] & ( 1 << ( k & 7 ) ) ) {
						if ( reader.readBit() < 0 ) {
							return false;
						}
					} else {
						zerosToSkip -= 1;
					}
					k += 1;
				}
				continue;
			}
			if ( size !== 1 ) {
				return false; // AC refinement new non-zero symbols always have size === 1 (sign bit).
			}
			if ( reader.readBit() < 0 ) {
				return false; // sign bit for newly non-zero coefficient
			}
			let zerosToSkip = run;
			let placed = false;
			while ( k <= se ) {
				if ( nz[ blockByteBase + ( k >>> 3 ) ] & ( 1 << ( k & 7 ) ) ) {
					if ( reader.readBit() < 0 ) {
						return false;
					}
				} else if ( zerosToSkip === 0 ) {
					nz[ blockByteBase + ( k >>> 3 ) ] |= 1 << ( k & 7 );
					placed = true;
					k += 1;
					break;
				} else {
					zerosToSkip -= 1;
				}
				k += 1;
			}
			if ( ! placed ) {
				return false;
			}
		}

		unitsSinceRst += 1;
	}

	return ! reader.hitUnexpectedMarker && ! reader.hitEof;
}

/**
 * Verify JPEG bitstream completeness including full Huffman MCU/scan verification
 * for sequential (SOF0/SOF1) and progressive (SOF2) JPEGs.
 */
export function isCompleteJpegBytes( data: Uint8Array ): boolean {
	if ( data.length < 4 || data[ 0 ] !== 0xff || data[ 1 ] !== 0xd8 ) {
		return false;
	}

	const dcTables: Array< HuffmanTable | null > = [ null, null, null, null ];
	const acTables: Array< HuffmanTable | null > = [ null, null, null, null ];
	let sofMarker = 0;
	let width = 0;
	let height = 0;
	let maxH = 1;
	let maxV = 1;
	let mcuCols = 0;
	let mcuRows = 0;
	const components: FrameComponent[] = [];
	const dcSeenComps = new Set< number >();
	let nonZeroBitmaps: Uint8Array[] | null = null;
	let restartInterval = 0;
	let hasSos = false;

	let offset = 2;
	const len = data.length;

	while ( offset < len ) {
		if ( data[ offset ] !== 0xff ) {
			return false;
		}
		while ( offset < len && data[ offset ] === 0xff ) {
			offset += 1;
		}
		if ( offset >= len ) {
			return false;
		}
		const marker = data[ offset++ ];
		if ( marker === 0x00 ) {
			return false;
		}
		if ( marker === 0xd9 ) {
			// EOI reached: require frame, at least one scan, and (for progressive) DC for every component.
			if ( ! sofMarker || ! hasSos ) {
				return false;
			}
			if ( sofMarker === 0xc2 && dcSeenComps.size < components.length ) {
				return false;
			}
			return true;
		}
		if ( marker === 0x01 || ( marker >= 0xd0 && marker <= 0xd7 ) ) {
			continue;
		}
		if ( offset + 2 > len ) {
			return false;
		}
		const segLen = ( data[ offset ] << 8 ) | data[ offset + 1 ];
		if ( segLen < 2 || offset + segLen > len ) {
			return false;
		}
		const segStart = offset + 2;
		const segEnd = offset + segLen;

		if ( marker === 0xc4 ) {
			// DHT
			if ( ! parseDhtSegment( data, segStart, segEnd, dcTables, acTables ) ) {
				return false;
			}
		} else if ( marker === 0xdd ) {
			// DRI
			if ( segLen !== 4 ) {
				return false;
			}
			restartInterval = ( data[ segStart ] << 8 ) | data[ segStart + 1 ];
		} else if (
			[ 0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf ].includes( marker )
		) {
			if ( segLen < 8 ) {
				return false;
			}
			sofMarker = marker;
			height = ( data[ segStart + 1 ] << 8 ) | data[ segStart + 2 ];
			width = ( data[ segStart + 3 ] << 8 ) | data[ segStart + 4 ];
			const nf = data[ segStart + 5 ];
			if ( width <= 0 || height <= 0 || nf < 1 || segStart + 6 + nf * 3 > segEnd ) {
				return false;
			}
			components.length = 0;
			maxH = 1;
			maxV = 1;
			for ( let i = 0; i < nf; i++ ) {
				const id = data[ segStart + 6 + i * 3 ];
				const hv = data[ segStart + 6 + i * 3 + 1 ];
				const h = hv >>> 4;
				const v = hv & 0x0f;
				if ( h < 1 || h > 4 || v < 1 || v > 4 ) {
					return false;
				}
				if ( h > maxH ) { maxH = h; }
				if ( v > maxV ) { maxV = v; }
				components.push( { id, h, v, blocksPerLine: 0, blockRows: 0, totalBlocks: 0 } );
			}
			mcuCols = Math.ceil( width / ( maxH * 8 ) );
			mcuRows = Math.ceil( height / ( maxV * 8 ) );
			for ( const comp of components ) {
				comp.blocksPerLine = Math.ceil( ( width * comp.h ) / ( maxH * 8 ) );
				comp.blockRows = Math.ceil( ( height * comp.v ) / ( maxV * 8 ) );
				comp.totalBlocks = comp.blocksPerLine * comp.blockRows;
			}
			if ( sofMarker === 0xc2 ) {
				nonZeroBitmaps = components.map( ( c ) => new Uint8Array( c.totalBlocks * 8 ) );
			}
		} else if ( marker === 0xda ) {
			// SOS
			if ( ! sofMarker || components.length === 0 ) {
				return false;
			}
			const ns = data[ segStart ];
			if ( ns < 1 || ns > components.length || segStart + 1 + ns * 2 + 3 !== segEnd ) {
				return false;
			}
			const ss = data[ segStart + 1 + ns * 2 ];
			const se = data[ segStart + 1 + ns * 2 + 1 ];
			const ahal = data[ segStart + 1 + ns * 2 + 2 ];
			const ah = ahal >>> 4;

			hasSos = true;

			if ( sofMarker === 0xc0 || sofMarker === 0xc1 ) {
				const scanComps: Array< { comp: FrameComponent; dc: HuffmanTable; ac: HuffmanTable } > = [];
				for ( let i = 0; i < ns; i++ ) {
					const cid = data[ segStart + 1 + i * 2 ];
					const tdta = data[ segStart + 1 + i * 2 + 1 ];
					const td = tdta >>> 4;
					const ta = tdta & 0x0f;
					const comp = components.find( ( c ) => c.id === cid );
					const dc = td < 4 ? dcTables[ td ] : null;
					const ac = ta < 4 ? acTables[ ta ] : null;
					if ( ! comp || ! dc || ! ac ) {
						return false;
					}
					scanComps.push( { comp, dc, ac } );
				}
				const reader = new BitReader( data, segEnd );
				if ( ! verifySequentialScan( reader, scanComps, mcuCols, mcuRows, restartInterval ) ) {
					return false;
				}
				const nextMarkerOffset = reader.finishScan();
				if ( nextMarkerOffset < 0 ) {
					return false;
				}
				offset = nextMarkerOffset;
				continue;
			}

			if ( sofMarker === 0xc2 && nonZeroBitmaps ) {
				const scanComps: Array< { comp: FrameComponent; compIdx: number; dc: HuffmanTable | null; ac: HuffmanTable | null } > = [];
				for ( let i = 0; i < ns; i++ ) {
					const cid = data[ segStart + 1 + i * 2 ];
					const tdta = data[ segStart + 1 + i * 2 + 1 ];
					const td = tdta >>> 4;
					const ta = tdta & 0x0f;
					const compIdx = components.findIndex( ( c ) => c.id === cid );
					if ( compIdx < 0 ) {
						return false;
					}
					if ( ss === 0 ) {
						dcSeenComps.add( cid );
					}
					scanComps.push( {
						comp: components[ compIdx ],
						compIdx,
						dc: td < 4 ? dcTables[ td ] : null,
						ac: ta < 4 ? acTables[ ta ] : null,
					} );
				}
				const reader = new BitReader( data, segEnd );
				if ( ! verifyProgressiveScan( reader, scanComps, mcuCols, mcuRows, ss, se, ah, restartInterval, nonZeroBitmaps ) ) {
					return false;
				}
				const nextMarkerOffset = reader.finishScan();
				if ( nextMarkerOffset < 0 ) {
					return false;
				}
				offset = nextMarkerOffset;
				continue;
			}

			// Fallback marker scan for uncommon SOF types (lossless/arithmetic).
			offset = segEnd;
			let found = false;
			while ( offset < len ) {
				if ( data[ offset ] !== 0xff ) {
					offset += 1;
					continue;
				}
				const mStart = offset;
				while ( offset < len && data[ offset ] === 0xff ) {
					offset += 1;
				}
				if ( offset >= len ) {
					return false;
				}
				const nb = data[ offset ];
				if ( nb === 0x00 || ( nb >= 0xd0 && nb <= 0xd7 ) ) {
					offset += 1;
					continue;
				}
				offset = mStart;
				found = true;
				break;
			}
			if ( ! found ) {
				return false;
			}
			continue;
		}

		offset = segEnd;
	}

	return false;
}
