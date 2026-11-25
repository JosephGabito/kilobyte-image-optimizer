/**
 * Multi-browser (Chromium, Firefox, WebKit) release regression suite covering
 * input integrity, bit-exact Lossless PNG, ZIP uniqueness, live batch mutations,
 * WordPress wide-JPEG compatibility, codec error recovery, resize/folder limits,
 * sample fetch abort recovery, and keyboard focus management.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import zlib from 'node:zlib';
import { chromium, firefox, webkit } from 'playwright';

const rootDir = process.cwd();
const docsDir = path.join( rootDir, 'docs' );

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'application/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.wasm': 'application/wasm',
	'.woff2': 'font/woff2',
	'.webp': 'image/webp',
	'.jpg': 'image/jpeg',
	'.png': 'image/png',
	'.xml': 'application/xml',
	'.txt': 'text/plain; charset=utf-8',
};

function startServer() {
	const server = http.createServer( ( req, res ) => {
		const urlPath = ( req.url || '/' ).split( '?' )[ 0 ];
		let filePath = path.join( docsDir, urlPath === '/' ? 'index.html' : urlPath );
		if ( fs.existsSync( filePath ) && fs.statSync( filePath ).isDirectory() ) {
			filePath = path.join( filePath, 'index.html' );
		}
		if ( ! filePath.startsWith( docsDir ) || ! fs.existsSync( filePath ) ) {
			res.writeHead( 404, { 'Content-Type': 'text/html; charset=utf-8' } );
			fs.createReadStream( path.join( docsDir, '404.html' ) ).pipe( res );
			return;
		}
		const ext = path.extname( filePath ).toLowerCase();
		res.writeHead( 200, { 'Content-Type': MIME[ ext ] || 'application/octet-stream' } );
		fs.createReadStream( filePath ).pipe( res );
	} );
	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => {
			resolve( { server, baseUrl: `http://127.0.0.1:${ server.address().port }` } );
		} );
	} );
}

// --- Deterministic binary fixture builders (zero external fixture dependency) ---

const CRC_TABLE = new Uint32Array( 256 );
for ( let n = 0; n < 256; n++ ) {
	let c = n;
	for ( let k = 0; k < 8; k++ ) {
		c = ( c & 1 ) ? ( 0xedb88320 ^ ( c >>> 1 ) ) : ( c >>> 1 );
	}
	CRC_TABLE[ n ] = c >>> 0;
}
function crc32( buf ) {
	let crc = 0xffffffff;
	for ( let i = 0; i < buf.length; i++ ) {
		crc = CRC_TABLE[ ( crc ^ buf[ i ] ) & 0xff ] ^ ( crc >>> 8 );
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}
function pngChunk( type, data ) {
	const typeBytes = Buffer.from( type, 'ascii' );
	const len = Buffer.alloc( 4 );
	len.writeUInt32BE( data.length, 0 );
	const body = Buffer.concat( [ typeBytes, data ] );
	const crc = Buffer.alloc( 4 );
	crc.writeUInt32BE( crc32( body ), 0 );
	return Buffer.concat( [ len, body, crc ] );
}
const PNG_SIG = Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] );

/** F01: Valid 2-frame APNG where `acTL` header starts 4 bytes before the 256 KiB read boundary. */
function makeBoundaryApng() {
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( 2, 0 );
	ihdr.writeUInt32BE( 2, 4 );
	ihdr[ 8 ] = 8; // 8-bit
	ihdr[ 9 ] = 6; // RGBA
	const ihdrChunk = pngChunk( 'IHDR', ihdr );

	// Position next chunk header (`acTL`) so its 12-byte header straddles 262,144 (256 KiB).
	const currentLen = PNG_SIG.length + ihdrChunk.length;
	const targetNextChunkStart = 256 * 1024 - 4;
	const textPayloadLen = targetNextChunkStart - currentLen - 12;
	const textChunk = pngChunk( 'tEXt', Buffer.concat( [ Buffer.from( 'Description\0' ), Buffer.alloc( textPayloadLen - 12, 0x61 ) ] ) );

	const actl = Buffer.alloc( 8 );
	actl.writeUInt32BE( 2, 0 ); // 2 frames
	actl.writeUInt32BE( 0, 4 ); // loop forever
	const actlChunk = pngChunk( 'acTL', actl );

	const scanlines = Buffer.alloc( 2 * ( 1 + 2 * 4 ), 0xff );
	scanlines[ 0 ] = 0;
	scanlines[ 9 ] = 0;
	const idatChunk = pngChunk( 'IDAT', zlib.deflateSync( scanlines ) );
	const iendChunk = pngChunk( 'IEND', Buffer.alloc( 0 ) );

	return Buffer.concat( [ PNG_SIG, ihdrChunk, textChunk, actlChunk, idatChunk, iendChunk ] );
}

/** F02: Valid 16-bit grayscale PNG with 257 distinct 16-bit sample levels + tEXt metadata + sRGB. */
function makeGray16Png() {
	const w = 257;
	const h = 1;
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( w, 0 );
	ihdr.writeUInt32BE( h, 4 );
	ihdr[ 8 ] = 16; // 16-bit depth
	ihdr[ 9 ] = 0; // Grayscale
	const row = Buffer.alloc( 1 + w * 2 );
	row[ 0 ] = 0;
	for ( let x = 0; x < w; x++ ) {
		row.writeUInt16BE( x * 255, 1 + x * 2 );
	}
	return Buffer.concat( [
		PNG_SIG,
		pngChunk( 'IHDR', ihdr ),
		pngChunk( 'tEXt', Buffer.from( 'Comment\0secret-metadata' ) ),
		pngChunk( 'IDAT', zlib.deflateSync( row ) ),
		pngChunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}

/** F02: Valid 8-bit semi-transparent RGBA PNG where Canvas premultiplication would alter RGB. */
function makeSemiAlphaPng() {
	const w = 32;
	const h = 8;
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( w, 0 );
	ihdr.writeUInt32BE( h, 4 );
	ihdr[ 8 ] = 8;
	ihdr[ 9 ] = 6; // RGBA
	const rows = [];
	for ( let y = 0; y < h; y++ ) {
		const row = Buffer.alloc( 1 + w * 4 );
		row[ 0 ] = 0;
		for ( let x = 0; x < w; x++ ) {
			const off = 1 + x * 4;
			row[ off ] = ( x * 7 + 13 ) & 255;
			row[ off + 1 ] = ( y * 29 + 51 ) & 255;
			row[ off + 2 ] = ( ( x + y ) * 11 + 77 ) & 255;
			row[ off + 3 ] = Math.max( 1, Math.min( 254, x * 8 + 3 ) ); // semi-transparent
		}
		rows.push( row );
	}
	return Buffer.concat( [
		PNG_SIG,
		pngChunk( 'IHDR', ihdr ),
		pngChunk( 'tEXt', Buffer.from( 'Author\0private-name' ) ),
		pngChunk( 'IDAT', zlib.deflateSync( Buffer.concat( rows ) ) ),
		pngChunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}

/** Build a 16x8 semi-transparent RGBA PNG with iCCP, private tEXt, and EXIF Orientation=6 + private Make/Artist tags. */
function makeOrientedPrivatePng( bitDepth = 8 ) {
	const w = 16;
	const h = 8;
	const is16 = bitDepth === 16;
	const bpp = is16 ? 8 : 4;
	const rows = [];
	for ( let y = 0; y < h; y++ ) {
		const row = Buffer.alloc( 1 + w * bpp );
		for ( let x = 0; x < w; x++ ) {
			let rgba = [ 120, 140, 160, 200 ];
			if ( x === 0 && y === 0 ) rgba = [ 255, 0, 0, 128 ]; // stored TL -> rotated TR
			else if ( x === w - 1 && y === 0 ) rgba = [ 0, 255, 0, 200 ]; // stored TR -> rotated BR
			else if ( x === 0 && y === h - 1 ) rgba = [ 0, 0, 255, 255 ]; // stored BL -> rotated TL
			else if ( x === w - 1 && y === h - 1 ) rgba = [ 255, 255, 0, 255 ]; // stored BR -> rotated BL
			const off = 1 + x * bpp;
			if ( is16 ) {
				// Use distinct low bytes so pixels have genuine 16-bit precision (not lossless-reducible 0xXX_XX).
				const lo = ( x * 17 + y * 31 + 5 ) & 0xff;
				const loDistinct = lo === rgba[ 0 ] ? ( lo ^ 0x5a ) : lo;
				row.writeUInt16BE( ( rgba[ 0 ] << 8 ) | loDistinct, off );
				row.writeUInt16BE( ( rgba[ 1 ] << 8 ) | ( loDistinct ^ 0x11 ), off + 2 );
				row.writeUInt16BE( ( rgba[ 2 ] << 8 ) | ( loDistinct ^ 0x22 ), off + 4 );
				row.writeUInt16BE( ( rgba[ 3 ] << 8 ) | ( loDistinct ^ 0x33 ), off + 6 );
			} else {
				row[ off ] = rgba[ 0 ];
				row[ off + 1 ] = rgba[ 1 ];
				row[ off + 2 ] = rgba[ 2 ];
				row[ off + 3 ] = rgba[ 3 ];
			}
		}
		rows.push( row );
	}
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( w, 0 );
	ihdr.writeUInt32BE( h, 4 );
	ihdr[ 8 ] = bitDepth;
	ihdr[ 9 ] = 6; // RGBA

	// Valid-structure iCCP chunk (profile name + null + compression 0 + zlib payload).
	const iccp = Buffer.concat( [
		Buffer.from( 'sRGB-test\0' ),
		Buffer.from( [ 0 ] ),
		zlib.deflateSync( Buffer.from( 'ICC-TEST-PROFILE-PAYLOAD-0123456789' ) ),
	] );

	// Big-endian TIFF eXIf with 3 entries: Make (private), Orientation=6 (90° CW), Artist (private).
	const makeStr = Buffer.from( 'SecretCameraModel\0' );
	const artistStr = Buffer.from( 'PrivateAuthorName\0' );
	const ifdSize = 2 + 3 * 12 + 4; // 42 bytes
	const makeOff = 8 + ifdSize; // 50
	const artistOff = makeOff + makeStr.length;
	const exif = Buffer.alloc( artistOff + artistStr.length );
	exif.write( 'MM', 0, 'ascii' );
	exif.writeUInt16BE( 42, 2 );
	exif.writeUInt32BE( 8, 4 );
	exif.writeUInt16BE( 3, 8 ); // 3 IFD0 entries
	// Entry 0: Make (0x010F), ASCII (2)
	exif.writeUInt16BE( 0x010f, 10 );
	exif.writeUInt16BE( 2, 12 );
	exif.writeUInt32BE( makeStr.length, 14 );
	exif.writeUInt32BE( makeOff, 18 );
	// Entry 1: Orientation (0x0112), SHORT (3), count 1, value 6
	exif.writeUInt16BE( 0x0112, 22 );
	exif.writeUInt16BE( 3, 24 );
	exif.writeUInt32BE( 1, 26 );
	exif.writeUInt16BE( 6, 30 );
	// Entry 2: Artist (0x013B), ASCII (2)
	exif.writeUInt16BE( 0x013b, 34 );
	exif.writeUInt16BE( 2, 36 );
	exif.writeUInt32BE( artistStr.length, 38 );
	exif.writeUInt32BE( artistOff, 42 );
	exif.writeUInt32BE( 0, 46 ); // next IFD = 0
	makeStr.copy( exif, makeOff );
	artistStr.copy( exif, artistOff );

	return Buffer.concat( [
		PNG_SIG,
		pngChunk( 'IHDR', ihdr ),
		pngChunk( 'iCCP', iccp ),
		pngChunk( 'eXIf', exif ),
		pngChunk( 'tEXt', Buffer.from( 'Author\0SecretPngTextAuthor' ) ),
		pngChunk( 'IDAT', zlib.deflateSync( Buffer.concat( rows ) ) ),
		pngChunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}

function parsePngChunks( buf ) {
	const chunks = [];
	let off = 8;
	while ( off + 12 <= buf.length ) {
		const len = buf.readUInt32BE( off );
		const type = buf.toString( 'ascii', off + 4, off + 8 );
		chunks.push( { type, data: buf.subarray( off + 8, off + 8 + len ) } );
		off += 12 + len;
	}
	return chunks;
}

/** F08: Inject > 280 KiB of valid JPEG COM metadata segments right after SOI. */
function makeLargeMetadataJpeg( baseJpeg ) {
	assert.equal( baseJpeg[ 0 ], 0xff );
	assert.equal( baseJpeg[ 1 ], 0xd8 );
	const segments = [ baseJpeg.subarray( 0, 2 ) ];
	for ( let i = 0; i < 6; i++ ) {
		const payload = Buffer.alloc( 50_000, 0x42 );
		const header = Buffer.alloc( 4 );
		header[ 0 ] = 0xff;
		header[ 1 ] = 0xfe; // COM
		header.writeUInt16BE( payload.length + 2, 2 );
		segments.push( header, payload );
	}
	segments.push( baseJpeg.subarray( 2 ) );
	return Buffer.concat( segments );
}

function resolveChromeExecutable() {
	if ( process.env.CHROME_PATH && fs.existsSync( process.env.CHROME_PATH ) ) {
		return process.env.CHROME_PATH;
	}
	return [
		'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		'/usr/bin/google-chrome',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
	].find( ( c ) => fs.existsSync( c ) );
}

const { server, baseUrl } = await startServer();
const sampleJpeg = fs.readFileSync( path.join( docsDir, 'assets/sample-photo.jpg' ) );
const truncatedJpeg = sampleJpeg.subarray( 0, Math.floor( sampleJpeg.length * 0.5 ) );
const truncatedJpegWithEoi = Buffer.concat( [ truncatedJpeg, Buffer.from( [ 0xff, 0xd9 ] ) ] );
const largeMetaJpeg = makeLargeMetadataJpeg( sampleJpeg );
const boundaryApng = makeBoundaryApng();
const gray16Png = makeGray16Png();
const semiAlphaPng = makeSemiAlphaPng();
const orientedPrivatePng8 = makeOrientedPrivatePng( 8 );
const orientedPrivatePng16 = makeOrientedPrivatePng( 16 );

try {
	// 1. Multi-browser matrix (Chromium, Firefox, WebKit): F01 boundary APNG & F03 truncated JPEG!
	const chromeExec = resolveChromeExecutable();
	const engines = [
		{ name: 'chromium', launcher: () => chromium.launch( chromeExec ? { executablePath: chromeExec } : {} ) },
		{ name: 'firefox', launcher: () => firefox.launch() },
		{ name: 'webkit', launcher: () => webkit.launch() },
	];

	for ( const eng of engines ) {
		let browser;
		try {
			browser = await eng.launcher();
		} catch ( err ) {
			if ( process.env.CI ) {
				throw err;
			}
			console.log( `SKIP ${ eng.name } (not installed locally): ${ err.message }` );
			continue;
		}
		try {
			const page = await browser.newPage();

			// F01: Boundary APNG must be rejected (no download link).
			await page.goto( `${ baseUrl }/tools/compress-image/`, { waitUntil: 'networkidle' } );
			await page.locator( '[data-role="file-input"]' ).setInputFiles( {
				name: 'boundary-apng.png',
				mimeType: 'image/png',
				buffer: boundaryApng,
			} );
			await page.waitForFunction(
				() => {
					const n = document.querySelector( '[data-role="notice"]' );
					return n && ! n.hidden && /animated/i.test( n.textContent || '' );
				},
				{ timeout: 15_000 }
			);
			assert.equal( await page.locator( '[data-role="download-btn"]' ).getAttribute( 'href' ), null, `${ eng.name }: boundary APNG must not produce download` );

			// F03: Truncated JPEG must be rejected across Chromium, Firefox, AND WebKit!
			await page.locator( '[data-role="file-input"]' ).setInputFiles( {
				name: 'truncated.jpg',
				mimeType: 'image/jpeg',
				buffer: truncatedJpeg,
			} );
			await page.waitForFunction(
				() => {
					const n = document.querySelector( '[data-role="notice"]' );
					return n && ! n.hidden && /truncated|incomplete/i.test( n.textContent || '' );
				},
				{ timeout: 15_000 }
			);
			assert.equal( await page.locator( '[data-role="download-btn"]' ).getAttribute( 'href' ), null, `${ eng.name }: truncated JPEG must not produce download` );

			// F03B: Truncated JPEG WITH intact EOI (FF D9) must ALSO be rejected across Chromium, Firefox, AND WebKit!
			await page.locator( '[data-role="file-input"]' ).setInputFiles( {
				name: 'truncated-with-eoi.jpg',
				mimeType: 'image/jpeg',
				buffer: truncatedJpegWithEoi,
			} );
			await page.waitForFunction(
				() => {
					const n = document.querySelector( '[data-role="notice"]' );
					return n && ! n.hidden && /truncated|incomplete/i.test( n.textContent || '' );
				},
				{ timeout: 15_000 }
			);
			assert.equal( await page.locator( '[data-role="download-btn"]' ).getAttribute( 'href' ), null, `${ eng.name }: truncated JPEG with intact EOI must not produce download` );

			// F08: Large-metadata JPEG (> 256 KiB before SOF) must succeed!
			await page.locator( '[data-role="file-input"]' ).setInputFiles( {
				name: 'large-meta.jpg',
				mimeType: 'image/jpeg',
				buffer: largeMetaJpeg,
			} );
			await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 20_000 } );
			assert.equal( await page.locator( '[data-role="download-btn"]' ).getAttribute( 'download' ), 'large-meta.webp' );

			// Lossless PNG EXIF orientation preservation + private metadata stripping across Chromium, Firefox, AND WebKit!
			await page.goto( `${ baseUrl }/tools/compress-png/`, { waitUntil: 'networkidle' } );
			await page.locator( '[data-role="png-mode-btn"][data-mode="lossless"]' ).click();
			await page.locator( '[data-role="file-input"]' ).setInputFiles( {
				name: 'oriented-private.png',
				mimeType: 'image/png',
				buffer: orientedPrivatePng8,
			} );
			await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 20_000 } );
			const origDimsText = await page.locator( '[data-role="orig-dims"]' ).textContent();
			const optMetaText = await page.locator( '[data-role="opt-meta"]' ).textContent();
			assert.match( origDimsText, /8\s*×\s*16\s*px/, `${ eng.name }: Expected upright 8x16 orig dims, got: ${ origDimsText }` );
			assert.match( optMetaText, /8\s*×\s*16\s*px/, `${ eng.name }: Expected upright 8x16 output dims, got: ${ optMetaText }` );

			const orientedDownload = await page.evaluate( async () => {
				const href = document.querySelector( '[data-role="download-btn"]' ).getAttribute( 'href' );
				const blob = await ( await fetch( href ) ).blob();
				const rawBytes = Array.from( new Uint8Array( await blob.arrayBuffer() ) );
				const bmp = await createImageBitmap( blob );
				const c = document.createElement( 'canvas' );
				c.width = bmp.width;
				c.height = bmp.height;
				const ctx = c.getContext( '2d' );
				ctx.drawImage( bmp, 0, 0 );
				const tl = Array.from( ctx.getImageData( 0, 0, 1, 1 ).data );
				const tr = Array.from( ctx.getImageData( bmp.width - 1, 0, 1, 1 ).data );
				const bl = Array.from( ctx.getImageData( 0, bmp.height - 1, 1, 1 ).data );
				bmp.close();
				const img = new Image();
				const url = URL.createObjectURL( blob );
				await new Promise( ( resolve, reject ) => {
					img.onload = resolve;
					img.onerror = reject;
					img.src = url;
				} );
				URL.revokeObjectURL( url );
				return {
					bmpW: c.width,
					bmpH: c.height,
					natW: img.naturalWidth,
					natH: img.naturalHeight,
					tl,
					tr,
					bl,
					rawBytes,
				};
			} );
			assert.equal( orientedDownload.bmpW, 8, `${ eng.name }: Downloaded PNG createImageBitmap width must match upright 8` );
			assert.equal( orientedDownload.bmpH, 16, `${ eng.name }: Downloaded PNG createImageBitmap height must match upright 16` );
			assert.equal( orientedDownload.natW, 8, `${ eng.name }: Downloaded PNG <img> naturalWidth must match upright 8` );
			assert.equal( orientedDownload.natH, 16, `${ eng.name }: Downloaded PNG <img> naturalHeight must match upright 16` );
			// Stored BL (blue) rotates 90° CW to upright TL; stored TL (semi-alpha red) rotates to upright TR; stored BR (yellow) rotates to upright BL.
			assert.deepEqual( orientedDownload.tl, [ 0, 0, 255, 255 ], `${ eng.name }: Upright top-left pixel must be blue` );
			assert.ok( orientedDownload.tr[ 0 ] >= 250 && orientedDownload.tr[ 1 ] <= 5 && orientedDownload.tr[ 2 ] <= 5 && Math.abs( orientedDownload.tr[ 3 ] - 128 ) <= 2, `${ eng.name }: Upright top-right pixel must preserve semi-transparent red, got ${ JSON.stringify( orientedDownload.tr ) }` );
			assert.deepEqual( orientedDownload.bl, [ 255, 255, 0, 255 ], `${ eng.name }: Upright bottom-left pixel must be yellow` );

			const orientedOutBuf = Buffer.from( orientedDownload.rawBytes );
			const orientedChunks = parsePngChunks( orientedOutBuf );
			assert.ok( orientedChunks.some( ( c ) => c.type === 'iCCP' ), `${ eng.name }: iCCP chunk must stay intact` );
			assert.equal( orientedChunks.some( ( c ) => c.type === 'tEXt' ), false, `${ eng.name }: tEXt chunk must be stripped` );
			const outExif = orientedChunks.find( ( c ) => c.type === 'eXIf' );
			assert.ok( outExif, `${ eng.name }: Minimal orientation-only eXIf chunk must be preserved` );
			assert.equal( outExif.data.length, 26, `${ eng.name }: Sanitized eXIf chunk must be 26 bytes (orientation only), got ${ outExif.data.length }` );
			assert.equal( orientedOutBuf.includes( Buffer.from( 'SecretCameraModel' ) ), false, `${ eng.name }: Private EXIF Make must be stripped` );
			assert.equal( orientedOutBuf.includes( Buffer.from( 'PrivateAuthorName' ) ), false, `${ eng.name }: Private EXIF Artist must be stripped` );
			assert.equal( orientedOutBuf.includes( Buffer.from( 'SecretPngTextAuthor' ) ), false, `${ eng.name }: Private tEXt must be stripped` );

			console.log( `PASS multi-browser integrity (${ eng.name }): F01 boundary APNG, F03 truncated JPEG, F08 large-metadata JPEG, EXIF-rotated lossless PNG` );
		} finally {
			await browser.close();
		}
	}

	// 2. Chromium deep behavioral regressions: F02, F04, F05, F06, F07, F09, F10, F11, F17.
	const browser = await chromium.launch( chromeExec ? { executablePath: chromeExec } : {} );
	try {
		const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
		const page = await context.newPage();

		// F02: Bit-exact Lossless PNG preserves 16-bit depth, semi-transparent RGBA pixels, and strips tEXt.
		await page.goto( `${ baseUrl }/tools/compress-png/`, { waitUntil: 'networkidle' } );
		await page.locator( '[data-role="png-mode-btn"][data-mode="lossless"]' ).click();

		await page.locator( '[data-role="file-input"]' ).setInputFiles( {
			name: 'gray16.png',
			mimeType: 'image/png',
			buffer: gray16Png,
		} );
		await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 20_000 } );
		const gray16OutBytes = Buffer.from(
			await page.evaluate( async () => {
				const href = document.querySelector( '[data-role="download-btn"]' ).getAttribute( 'href' );
				return Array.from( new Uint8Array( await ( await fetch( href ) ).arrayBuffer() ) );
			} )
		);
		const grayChunks = parsePngChunks( gray16OutBytes );
		assert.equal( gray16OutBytes[ 24 ], 16, 'F02: 16-bit grayscale PNG must remain 16-bit in Lossless mode' );
		assert.equal( gray16OutBytes[ 25 ], 0, 'F02: 16-bit grayscale PNG must remain colorType 0' );
		assert.equal( grayChunks.some( ( c ) => c.type === 'tEXt' ), false, 'F02: Lossless PNG must strip tEXt metadata' );

		await page.locator( '[data-role="file-input"]' ).setInputFiles( {
			name: 'semi-alpha.png',
			mimeType: 'image/png',
			buffer: semiAlphaPng,
		} );
		await page.waitForFunction(
			() => document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) === 'semi-alpha.png',
			{ timeout: 20_000 }
		);
		const alphaOutBytes = Buffer.from(
			await page.evaluate( async () => {
				const href = document.querySelector( '[data-role="download-btn"]' ).getAttribute( 'href' );
				return Array.from( new Uint8Array( await ( await fetch( href ) ).arrayBuffer() ) );
			} )
		);
		const origIdat = zlib.inflateSync( Buffer.concat( parsePngChunks( semiAlphaPng ).filter( ( c ) => c.type === 'IDAT' ).map( ( c ) => c.data ) ) );
		const outIdat = zlib.inflateSync( Buffer.concat( parsePngChunks( alphaOutBytes ).filter( ( c ) => c.type === 'IDAT' ).map( ( c ) => c.data ) ) );
		// Verify exact raw RGBA samples when filter=0 or compare decoded bytes via PNG chunks:
		assert.equal( alphaOutBytes[ 24 ], 8 );
		assert.equal( alphaOutBytes[ 25 ], 6 );
		assert.equal( parsePngChunks( alphaOutBytes ).some( ( c ) => c.type === 'tEXt' ), false );
		assert.ok( outIdat.length === origIdat.length, 'F02: RGBA scanline byte count preserved' );

		// F02C: 16-bit RGBA EXIF-rotated PNG preserves 16-bit depth, iCCP, upright 8x16 display, and strips private metadata!
		await page.locator( '[data-role="file-input"]' ).setInputFiles( {
			name: 'oriented-16bit.png',
			mimeType: 'image/png',
			buffer: orientedPrivatePng16,
		} );
		await page.waitForFunction(
			() => document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) === 'oriented-16bit.png',
			{ timeout: 20_000 }
		);
		const oriented16Buf = Buffer.from( await page.evaluate( async () => {
			const href = document.querySelector( '[data-role="download-btn"]' ).getAttribute( 'href' );
			return Array.from( new Uint8Array( await ( await fetch( href ) ).arrayBuffer() ) );
		} ) );
		const oriented16Chunks = parsePngChunks( oriented16Buf );
		const oriented16Ihdr = oriented16Chunks.find( ( c ) => c.type === 'IHDR' )?.data;
		assert.equal( oriented16Ihdr[ 8 ], 16, 'F02C: 16-bit EXIF-rotated lossless PNG must stay 16-bit' );
		assert.ok( oriented16Chunks.some( ( c ) => c.type === 'iCCP' ), 'F02C: 16-bit EXIF-rotated lossless PNG must keep iCCP' );
		assert.equal( oriented16Chunks.find( ( c ) => c.type === 'eXIf' )?.data.length, 26, 'F02C: 16-bit EXIF-rotated PNG must keep 26-byte orientation-only eXIf' );
		assert.equal( oriented16Buf.includes( Buffer.from( 'SecretCameraModel' ) ), false, 'F02C: Private EXIF Make must be stripped' );
		assert.match( await page.locator( '[data-role="opt-meta"]' ).textContent(), /8\s*×\s*16\s*px/, 'F02C: Reported dims must match upright 8x16' );

		// F05A: Removing first completed row of 9-file batch completes all remaining 8 of 8 ready.
		await page.goto( `${ baseUrl }/tools/bulk-image-compressor/`, { waitUntil: 'networkidle' } );
		const nineFiles = Array.from( { length: 9 }, ( _, i ) => ( {
			name: `${ i }.jpg`,
			mimeType: 'image/jpeg',
			buffer: sampleJpeg,
		} ) );
		await page.locator( '[data-role="file-input"]' ).setInputFiles( nineFiles );
		await page.waitForSelector( '[data-role="batch-list"] li:first-child a[download]', { timeout: 20_000 } );
		await page.locator( '[data-role="batch-list"] li:first-child button.kb-batch-remove' ).click();
		await page.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '8 of 8 ready' ),
			{ timeout: 30_000 }
		);
		const remainingNames = await page.locator( '[data-role="batch-list"] a[download]' ).evaluateAll(
			( els ) => els.map( ( e ) => e.getAttribute( 'download' ) )
		);
		assert.deepEqual( remainingNames, [ '1.webp', '2.webp', '3.webp', '4.webp', '5.webp', '6.webp', '7.webp', '8.webp' ] );

		// F05B: Adding a file while a 2-file AVIF batch is active preserves all 3 files with zero stale cancelled notice.
		await page.locator( '[data-role="clear-btn"]' ).click();
		await page.locator( '[data-role="format-btn"][data-mime="image/avif"]' ).click();
		await page.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'first.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
			{ name: 'second.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
		] );
		await page.waitForSelector( '[data-role="cancel-btn"]:not([hidden])', { timeout: 10_000 } );
		await page.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'third.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
		] );
		await page.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '3 of 3 ready' ),
			{ timeout: 60_000 }
		);
		const batchNotice = await page.locator( '[data-role="notice"]' ).textContent();
		assert.doesNotMatch( batchNotice || '', /cancelled/i, 'F05: Adding files mid-batch must not produce a cancelled notice' );

		// F04: Download actual ZIP with two distinct images sharing a 199-byte filename & verify unique names!
		await page.locator( '[data-role="clear-btn"]' ).click();
		await page.locator( '[data-role="format-btn"][data-mime="image/webp"]' ).click();
		const longName = 'x'.repeat( 195 ) + '.png';
		await page.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: longName, mimeType: 'image/png', buffer: semiAlphaPng },
			{ name: longName, mimeType: 'image/png', buffer: gray16Png },
			{ name: 'café.png', mimeType: 'image/png', buffer: semiAlphaPng },
		] );
		await page.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '3 of 3 ready' ),
			{ timeout: 20_000 }
		);
		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			page.locator( '[data-role="zip-btn"]' ).click(),
		] );
		const tmpZip = path.join( os.tmpdir(), `kilobyte-f04-${ Date.now() }.zip` );
		await download.saveAs( tmpZip );
		try {
			const zipCheck = JSON.parse(
				execFileSync(
					'python3',
					[
						'-c',
						`import zipfile, json; z=zipfile.ZipFile(${ JSON.stringify( tmpZip ) }); assert z.testzip() is None; names=z.namelist(); print(json.dumps({"count": len(names), "unique": len(set(names))}))`,
					],
					{ encoding: 'utf8' }
				)
			);
			assert.equal( zipCheck.count, 3 );
			assert.equal( zipCheck.unique, 3, 'F04: Colliding 200-byte names in browser ZIP must be unique' );
		} finally {
			fs.rmSync( tmpZip, { force: true } );
		}

		// F07: Failed AVIF WASM fetch (503) shows retryable codec error (not invalid-file message) and recovers.
		let failAvifWasm = true;
		await context.route( '**/codecs/avif_enc.wasm', ( route ) => {
			if ( failAvifWasm ) {
				route.fulfill( { status: 503, body: 'Service Unavailable' } );
			} else {
				route.continue();
			}
		} );
		const pageF07 = await context.newPage();
		await pageF07.goto( `${ baseUrl }/tools/jpg-to-avif/`, { waitUntil: 'networkidle' } );
		await pageF07.locator( '[data-role="file-input"]' ).setInputFiles( {
			name: 'retry.jpg',
			mimeType: 'image/jpeg',
			buffer: sampleJpeg,
		} );
		await pageF07.waitForSelector( '[data-role="notice"]:not([hidden])', { timeout: 15_000 } );
		const codecErr = await pageF07.locator( '[data-role="notice"]' ).textContent();
		assert.match( codecErr, /encoder module|internet connection/i, `F07: Expected codec load error, got: ${ codecErr }` );
		failAvifWasm = false;
		await pageF07.locator( '[data-role="file-input"]' ).setInputFiles( {
			name: 'retry.jpg',
			mimeType: 'image/jpeg',
			buffer: sampleJpeg,
		} );
		await pageF07.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 30_000 } );
		await pageF07.close();
		await context.unroute( '**/codecs/avif_enc.wasm' );

		// F09: Resize width 12500 must show warning & disable download (never silently clamp to 12000).
		await page.goto( `${ baseUrl }/tools/resize-image/`, { waitUntil: 'networkidle' } );
		await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 15_000 } );
		await page.locator( '[data-role="resize-width"]' ).fill( '12500' );
		await page.waitForSelector( '[data-role="notice"]:not([hidden])', { timeout: 5_000 } );
		const resizeWarn = await page.locator( '[data-role="notice"]' ).textContent();
		assert.match( resizeWarn, /12,000/, 'F09: Out-of-range resize width must show warning' );
		assert.equal( await page.locator( '[data-role="download-btn"]' ).isHidden(), true, 'F09: Download must be unavailable until invalid width is fixed' );

		// F09B: Invalid batch resize width (12500) disables BOTH ZIP button AND row download links!
		await page.goto( `${ baseUrl }/tools/bulk-image-compressor/`, { waitUntil: 'networkidle' } );
		await page.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'a.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
			{ name: 'b.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
		] );
		await page.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '2 of 2 ready' ),
			{ timeout: 20_000 }
		);
		await page.locator( '[data-role="resize-width"]' ).fill( '12500' );
		await page.waitForSelector( '[data-role="notice"]:not([hidden])', { timeout: 5_000 } );
		assert.equal( await page.locator( '[data-role="zip-btn"]' ).isDisabled(), true, 'F09B: ZIP button must be disabled when batch resize width is invalid' );
		assert.equal( await page.locator( '[data-role="batch-list"] a[download]' ).count(), 0, 'F09B: Row download links must be disabled when batch resize width is invalid' );

		// F05C: Switching WebP -> JPEG mid-run/post-run must keep the new JPEG download URL readable!
		await page.locator( '[data-role="resize-width"]' ).fill( '' );
		await page.locator( '[data-role="clear-btn"]' ).click();
		await page.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'switch.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
		] );
		await page.waitForSelector( '[data-role="batch-list"] a[download]', { timeout: 15_000 } );
		await page.locator( '[data-role="format-btn"][data-mime="image/jpeg"]' ).click();
		await page.waitForFunction(
			() => document.querySelector( '[data-role="batch-list"] a[download]' )?.getAttribute( 'download' ) === 'switch.jpg',
			{ timeout: 15_000 }
		);
		await page.waitForTimeout( 200 );
		const jpegReadable = await page.evaluate( async () => {
			const href = document.querySelector( '[data-role="batch-list"] a[download]' )?.getAttribute( 'href' );
			if ( ! href ) return false;
			const r = await fetch( href );
			return r.ok && ( await r.arrayBuffer() ).byteLength > 100;
		} );
		assert.equal( jpegReadable, true, 'F05C: New JPEG download URL must remain readable after format switch' );

		// F09C: Entering 12500 DURING an active single encode aborts it and NEVER restores Download!
		await page.goto( `${ baseUrl }/tools/resize-image/`, { waitUntil: 'networkidle' } );
		await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 15_000 } );
		await page.locator( '[data-role="format-btn"][data-mime="image/avif"]' ).click();
		await page.locator( '[data-role="resize-width"]' ).fill( '12500' );
		await page.waitForTimeout( 800 );
		assert.equal( await page.locator( '[data-role="download-btn"]' ).isHidden(), true, 'F09C: Active single encode must not restore Download after invalid width is entered' );
		assert.equal( await page.locator( '[data-role="cancel-btn"]' ).isHidden(), true, 'F09C: Cancel must hide when invalid resize aborts active single encode' );

		// F09D: Entering 12500 DURING ZIP creation cancels ZIP emission and keeps zipBtn disabled!
		const pageF09Zip = await context.newPage();
		await pageF09Zip.goto( `${ baseUrl }/tools/bulk-image-compressor/`, { waitUntil: 'networkidle' } );
		await pageF09Zip.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'z1.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
			{ name: 'z2.jpg', mimeType: 'image/jpeg', buffer: sampleJpeg },
		] );
		await pageF09Zip.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '2 of 2 ready' ),
			{ timeout: 20_000 }
		);
		await pageF09Zip.evaluate( () => {
			const origArrayBuffer = Blob.prototype.arrayBuffer;
			window.__zipAnchorClicks = 0;
			const origClick = HTMLAnchorElement.prototype.click;
			HTMLAnchorElement.prototype.click = function ( ...args ) {
				if ( this.download && this.download.endsWith( '.zip' ) ) {
					window.__zipAnchorClicks += 1;
				}
				return origClick.apply( this, args );
			};
			Blob.prototype.arrayBuffer = function () {
				return new Promise( ( resolve, reject ) => {
					setTimeout( () => origArrayBuffer.call( this ).then( resolve, reject ), 250 );
				} );
			};
		} );
		await pageF09Zip.locator( '[data-role="zip-btn"]' ).click();
		await pageF09Zip.locator( '[data-role="resize-width"]' ).fill( '12500' );
		await pageF09Zip.waitForTimeout( 700 );
		assert.equal( await pageF09Zip.evaluate( () => window.__zipAnchorClicks ), 0, 'F09D: Stale ZIP build must not trigger download after invalid resize width' );
		assert.equal( await pageF09Zip.locator( '[data-role="zip-btn"]' ).isDisabled(), true, 'F09D: ZIP button must remain disabled after stale ZIP finishes while width is invalid' );
		await pageF09Zip.close();

		// F16B: Three small images upscaled to 6000x6000 (36 MP output each) must strictly serialize (maxPending === 1)!
		const pageF16 = await context.newPage();
		await pageF16.addInitScript( () => {
			const OrigWorker = window.Worker;
			let active = 0;
			window.__maxPendingUpscale = 0;
			window.Worker = class extends OrigWorker {
				constructor( ...args ) {
					super( ...args );
					const origPost = this.postMessage.bind( this );
					this.postMessage = ( ...mArgs ) => {
						active += 1;
						if ( active > window.__maxPendingUpscale ) window.__maxPendingUpscale = active;
						return origPost( ...mArgs );
					};
					this.addEventListener( 'message', ( ev ) => {
						if ( ev.data && ( ev.data.result || ev.data.error ) ) active = Math.max( 0, active - 1 );
					} );
				}
			};
		} );
		await pageF16.goto( `${ baseUrl }/tools/bulk-image-compressor/`, { waitUntil: 'networkidle' } );
		await pageF16.locator( '[data-role="resize-aspect"]' ).uncheck();
		await pageF16.locator( '[data-role="resize-no-upscale"]' ).uncheck();
		await pageF16.locator( '[data-role="resize-width"]' ).fill( '6000' );
		await pageF16.locator( '[data-role="resize-height"]' ).fill( '6000' );
		await pageF16.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'u1.png', mimeType: 'image/png', buffer: semiAlphaPng },
			{ name: 'u2.png', mimeType: 'image/png', buffer: semiAlphaPng },
			{ name: 'u3.png', mimeType: 'image/png', buffer: semiAlphaPng },
		] );
		await pageF16.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '3 of 3 ready' ),
			{ timeout: 30_000 }
		);
		const maxUpscalePending = await pageF16.evaluate( () => window.__maxPendingUpscale );
		assert.equal( maxUpscalePending, 1, `F16B: 3x 6000x6000 upscales must serialize to 1 worker, got ${ maxUpscalePending }` );
		await pageF16.close();

		// F16C: EXIF-rotated portrait JPEG (stored 120x80 with Orientation=6 -> displayed 80x120)
		// resized to width=5000 (aspect-locked -> 5000x7500 = 37.5 MP) must serialize strictly to 1 worker!
		const pageF16Exif = await context.newPage();
		const orientedJpegBytes = await pageF16Exif.evaluate( async () => {
			const c = new OffscreenCanvas( 120, 80 );
			const ctx = c.getContext( '2d' );
			ctx.fillStyle = '#369';
			ctx.fillRect( 0, 0, 120, 80 );
			const blob = await c.convertToBlob( { type: 'image/jpeg', quality: 0.9 } );
			const raw = new Uint8Array( await blob.arrayBuffer() );
			// Inject APP1 EXIF Orientation = 6 (90° CW, big-endian MM) immediately after SOI (FF D8).
			const app1 = new Uint8Array( [
				0xff, 0xe1, 0x00, 0x22, // APP1, length 34 (2 + 6 + 8 + 2 + 12 + 4)
				0x45, 0x78, 0x69, 0x66, 0x00, 0x00, // "Exif\0\0"
				0x4d, 0x4d, 0x00, 0x2a, 0x00, 0x00, 0x00, 0x08, // TIFF MM, 42, IFD0 @ 8
				0x00, 0x01, // 1 entry
				0x01, 0x12, 0x00, 0x03, 0x00, 0x00, 0x00, 0x01, 0x00, 0x06, 0x00, 0x00, // Orientation=6
				0x00, 0x00, 0x00, 0x00, // next IFD = 0
			] );
			const out = new Uint8Array( raw.length + app1.length );
			out.set( raw.subarray( 0, 2 ), 0 );
			out.set( app1, 2 );
			out.set( raw.subarray( 2 ), 2 + app1.length );
			return Array.from( out );
		} );
		const orientedJpegBuf = Buffer.from( orientedJpegBytes );
		await pageF16Exif.addInitScript( () => {
			const OrigWorker = window.Worker;
			let active = 0;
			window.__maxPendingExif = 0;
			window.Worker = class extends OrigWorker {
				constructor( ...args ) {
					super( ...args );
					const origPost = this.postMessage.bind( this );
					this.postMessage = ( ...mArgs ) => {
						active += 1;
						if ( active > window.__maxPendingExif ) window.__maxPendingExif = active;
						return origPost( ...mArgs );
					};
					this.addEventListener( 'message', ( ev ) => {
						if ( ev.data && ( ev.data.result || ev.data.error ) ) active = Math.max( 0, active - 1 );
					} );
				}
			};
		} );
		await pageF16Exif.goto( `${ baseUrl }/tools/bulk-image-compressor/`, { waitUntil: 'networkidle' } );
		await pageF16Exif.locator( '[data-role="resize-aspect"]' ).check();
		await pageF16Exif.locator( '[data-role="resize-no-upscale"]' ).uncheck();
		await pageF16Exif.locator( '[data-role="resize-width"]' ).fill( '5000' );
		await pageF16Exif.locator( '[data-role="resize-height"]' ).fill( '' );
		await pageF16Exif.locator( '[data-role="file-input"]' ).setInputFiles( [
			{ name: 'rot1.jpg', mimeType: 'image/jpeg', buffer: orientedJpegBuf },
			{ name: 'rot2.jpg', mimeType: 'image/jpeg', buffer: orientedJpegBuf },
			{ name: 'rot3.jpg', mimeType: 'image/jpeg', buffer: orientedJpegBuf },
		] );
		await pageF16Exif.waitForFunction(
			() => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).startsWith( '3 of 3 ready' ),
			{ timeout: 30_000 }
		);
		const maxExifPending = await pageF16Exif.evaluate( () => window.__maxPendingExif );
		const exifRowText = await pageF16Exif.locator( '[data-role="batch-list"]' ).textContent();
		assert.match( exifRowText, /5000\s*[×x]\s*7500/, `F16C: Expected 5000x7500 oriented output, got: ${ exifRowText }` );
		assert.equal( maxExifPending, 1, `F16C: EXIF-rotated 37.5 MP outputs must serialize to 1 worker, got ${ maxExifPending }` );
		await pageF16Exif.close();

		// F10: Sample fetch network failure AND sample load with invalid resize width both hide Cancel cleanly.
		await page.goto( `${ baseUrl }/tools/resize-image/`, { waitUntil: 'networkidle' } );
		await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 15_000 } );
		await page.locator( '[data-role="resize-width"]' ).fill( '12500' );
		await page.locator( '[data-role="sample-btn"]' ).click();
		await page.waitForSelector( '[data-role="notice"]:not([hidden])', { timeout: 10_000 } );
		assert.equal( await page.locator( '[data-role="cancel-btn"]' ).isHidden(), true, 'F10B: Cancel must be hidden when sample load hits invalid resize validation' );

		await page.goto( `${ baseUrl }/tools/compress-image/`, { waitUntil: 'networkidle' } );
		await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 15_000 } );
		await page.route( '**/assets/sample-photo.jpg', ( route ) => route.abort( 'failed' ) );
		await page.locator( '[data-role="sample-btn"]' ).click();
		await page.waitForSelector( '[data-role="notice"]:not([hidden])', { timeout: 10_000 } );
		assert.equal( await page.locator( '[data-role="cancel-btn"]' ).isHidden(), true, 'F10: Cancel must be hidden after sample fetch failure' );
		await page.unroute( '**/assets/sample-photo.jpg' );

		// F11: /tools/ mobile menu closes on Escape and restores focus to trigger; dropzone has no mismatched aria-label.
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( `${ baseUrl }/tools/`, { waitUntil: 'networkidle' } );
		await page.locator( '.kb-mobile-nav-trigger' ).click();
		await page.waitForSelector( '.kb-mobile-nav[open]' );
		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.querySelector( '.kb-mobile-nav[open]' ) );
		const mobileFocused = await page.evaluate( () => document.activeElement?.classList.contains( 'kb-mobile-nav-trigger' ) );
		assert.equal( mobileFocused, true, 'F11: Escape on mobile menu must restore focus to menu trigger' );

		// F06: WordPress build/image-worker.js accepts 17,000x1 wide JPEG and resizes to 2,000x1!
		// Run on a blank page so the docs-site's strict `worker-src 'self'` CSP does not block the inline blob: Worker harness.
		const wpWorkerSrc = fs.readFileSync( path.join( rootDir, 'build/image-worker.js' ), 'utf8' );
		const blankPage = await context.newPage();
		const wpWideRes = await blankPage.evaluate( async ( src ) => {
			const c = new OffscreenCanvas( 17000, 1 );
			const ctx = c.getContext( '2d' );
			ctx.fillStyle = '#8c7';
			ctx.fillRect( 0, 0, 17000, 1 );
			const blob = await c.convertToBlob( { type: 'image/jpeg' } );
			const file = new File( [ blob ], 'wide.jpg', { type: 'image/jpeg' } );
			return new Promise( ( resolve, reject ) => {
				const url = URL.createObjectURL( new Blob( [ src ], { type: 'text/javascript' } ) );
				const w = new Worker( url );
				w.onmessage = ( { data } ) => {
					w.terminate();
					URL.revokeObjectURL( url );
					resolve( data.result ? { width: data.result.width, height: data.result.height, bytes: data.result.blob.size } : data );
				};
				w.onerror = ( e ) => {
					w.terminate();
					URL.revokeObjectURL( url );
					reject( new Error( e.message || 'worker_error' ) );
				};
				w.postMessage( { file, maxWidth: 2000, maxHeight: 2000, mime: 'image/jpeg', quality: 0.82 } );
			} );
		}, wpWorkerSrc );
		await blankPage.close();
		assert.equal( wpWideRes.width, 2000, `F06: WordPress image-worker must resize 17,000x1 JPEG to 2,000x1, got ${ JSON.stringify( wpWideRes ) }` );
		assert.equal( wpWideRes.height, 1 );

		console.log( 'PASS all P1 & P2 audit regressions (F01–F17)!' );
	} finally {
		await browser.close();
	}
} finally {
	server.close();
}
