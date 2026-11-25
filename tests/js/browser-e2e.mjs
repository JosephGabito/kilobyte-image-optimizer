import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { chromium } from 'playwright';

const rootDir = process.cwd();
const docsDir = path.join( rootDir, 'docs' );
const outDir = process.env.KILOBYTE_E2E_SCREENSHOT_DIR || path.join( os.tmpdir(), 'kilobyte-e2e-shots' );
fs.mkdirSync( outDir, { recursive: true } );

function resolveChromeExecutable() {
	if ( process.env.CHROME_PATH && fs.existsSync( process.env.CHROME_PATH ) ) {
		return process.env.CHROME_PATH;
	}
	const candidates = [
		'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		'/usr/bin/google-chrome',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
	];
	return candidates.find( ( c ) => fs.existsSync( c ) );
}

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
	'.txt': 'text/plain',
};

function startStaticServer() {
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
			const { port } = server.address();
			resolve( { server, baseUrl: `http://127.0.0.1:${ port }` } );
		} );
	} );
}

// Minimal valid 2-frame animated GIF89a (2x2).
const ANIMATED_GIF = Buffer.from(
	'47494638396102000200800000000000ffffff21f90400000000002c00000000020002000002024c510021f90400000000002c00000000020002000002024c51003b',
	'hex'
);

async function makeFixtures( page ) {
	const data = await page.evaluate( async () => {
		const toBase64 = async ( b ) => {
			const bytes = new Uint8Array( await b.arrayBuffer() );
			let bin = '';
			for ( let i = 0; i < bytes.length; i++ ) bin += String.fromCharCode( bytes[ i ] );
			return btoa( bin );
		};

		// 1. Photograph-like noisy gradient JPEG (1200x800).
		const c1 = document.createElement( 'canvas' );
		c1.width = 1200;
		c1.height = 800;
		const ctx1 = c1.getContext( '2d' );
		const g = ctx1.createLinearGradient( 0, 0, 1200, 800 );
		g.addColorStop( 0, '#1e3264' );
		g.addColorStop( 0.5, '#b87634' );
		g.addColorStop( 1, '#f5d9a6' );
		ctx1.fillStyle = g;
		ctx1.fillRect( 0, 0, 1200, 800 );
		for ( let i = 0; i < 400; i++ ) {
			ctx1.fillStyle = `rgba(${ ( i * 53 ) % 255 }, ${ ( i * 97 ) % 255 }, ${ ( i * 29 ) % 255 }, 0.22)`;
			ctx1.beginPath();
			ctx1.arc( ( i * 193 ) % 1200, ( i * 137 ) % 800, 14 + ( i * 17 ) % 85, 0, Math.PI * 2 );
			ctx1.fill();
		}
		const jpgBlob = await new Promise( ( r ) => c1.toBlob( r, 'image/jpeg', 0.96 ) );

		// 2. Transparent RGBA UI graphic PNG (600x400) with transparent corners (alpha=0).
		const c2 = document.createElement( 'canvas' );
		c2.width = 600;
		c2.height = 400;
		const ctx2 = c2.getContext( '2d' );
		ctx2.clearRect( 0, 0, 600, 400 );
		ctx2.fillStyle = 'rgba(56, 88, 233, 0.9)';
		ctx2.fillRect( 80, 60, 440, 280 );
		ctx2.fillStyle = 'rgba(255, 255, 255, 0.75)';
		ctx2.beginPath();
		ctx2.arc( 300, 200, 90, 0, Math.PI * 2 );
		ctx2.fill();
		const pngBlob = await new Promise( ( r ) => c2.toBlob( r, 'image/png' ) );

		// 3. Tiny 16x16 multi-color semi-transparent PNG (regression test for upng-js buffer overflow).
		const c3 = document.createElement( 'canvas' );
		c3.width = 16;
		c3.height = 16;
		const ctx3 = c3.getContext( '2d' );
		for ( let y = 0; y < 16; y++ ) {
			for ( let x = 0; x < 16; x++ ) {
				ctx3.fillStyle = `rgba(${ x * 16 }, ${ y * 16 }, ${ ( x + y ) * 8 }, ${ ( x + y ) / 32 })`;
				ctx3.fillRect( x, y, 1, 1 );
			}
		}
		const tinyPngBlob = await new Promise( ( r ) => c3.toBlob( r, 'image/png' ) );

		return {
			jpg: await toBase64( jpgBlob ),
			png: await toBase64( pngBlob ),
			tinyPng: await toBase64( tinyPngBlob ),
		};
	} );
	return {
		jpg: Buffer.from( data.jpg, 'base64' ),
		png: Buffer.from( data.png, 'base64' ),
		tinyPng: Buffer.from( data.tinyPng, 'base64' ),
	};
}

async function readBlobDownload( page, selector = '[data-role="download-btn"]' ) {
	return page.evaluate( async ( sel ) => {
		const el = document.querySelector( sel );
		const href = el?.getAttribute( 'href' );
		const download = el?.getAttribute( 'download' );
		if ( ! href || ! href.startsWith( 'blob:' ) ) {
			return null;
		}
		const res = await fetch( href );
		const buf = await res.arrayBuffer();
		const allBytes = new Uint8Array( buf );
		const bytes = Array.from( allBytes.slice( 0, 24 ) );
		const tail = Array.from( allBytes.slice( Math.max( 0, allBytes.length - 12 ) ) );
		const bitmap = await createImageBitmap( new Blob( [ buf ], { type: res.headers.get( 'content-type' ) || '' } ) );
		const c = document.createElement( 'canvas' );
		c.width = bitmap.width;
		c.height = bitmap.height;
		const ctx = c.getContext( '2d' );
		ctx.drawImage( bitmap, 0, 0 );
		const cornerAlpha = ctx.getImageData( 0, 0, 1, 1 ).data[ 3 ];
		bitmap.close();
		return {
			download,
			size: buf.byteLength,
			head: bytes,
			tail,
			width: c.width,
			height: c.height,
			cornerAlpha,
		};
	}, selector );
}

function hasMagic( head, expected ) {
	if ( expected === 'image/jpeg' ) return head[ 0 ] === 0xff && head[ 1 ] === 0xd8 && head[ 2 ] === 0xff;
	if ( expected === 'image/png' ) return head[ 0 ] === 0x89 && head[ 1 ] === 0x50 && head[ 2 ] === 0x4e && head[ 3 ] === 0x47;
	if ( expected === 'image/webp' ) {
		return String.fromCharCode( ...head.slice( 0, 4 ) ) === 'RIFF' && String.fromCharCode( ...head.slice( 8, 12 ) ) === 'WEBP';
	}
	if ( expected === 'image/avif' ) {
		return String.fromCharCode( ...head.slice( 4, 8 ) ) === 'ftyp';
	}
	return false;
}

function hasPngIend( tail ) {
	const expected = [ 0x00, 0x00, 0x00, 0x00, 0x49, 0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82 ];
	return tail.length === 12 && expected.every( ( b, i ) => tail[ i ] === b );
}

const { server, baseUrl } = await startStaticServer();
const executablePath = resolveChromeExecutable();
const browser = await chromium.launch( executablePath ? { executablePath } : {} );

try {
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	const page = await context.newPage();

	const errors = [];
	const externalRequests = [];
	const nonGetRequests = [];
	const wasmRequestsOnAvifLoad = [];
	let trackAvifLoad = false;

	page.on( 'pageerror', ( err ) => errors.push( `PAGEERROR: ${ err.message }` ) );
	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) errors.push( `CONSOLE: ${ msg.text() }` );
	} );
	page.on( 'request', ( req ) => {
		const u = req.url();
		if ( trackAvifLoad && u.endsWith( '.wasm' ) ) {
			wasmRequestsOnAvifLoad.push( u );
		}
		if ( u.startsWith( 'http' ) && ! u.startsWith( baseUrl ) ) {
			externalRequests.push( u );
		}
		if ( u.startsWith( baseUrl ) && req.method() !== 'GET' ) {
			nonGetRequests.push( `${ req.method() } ${ u }` );
		}
	} );

	// 1. Homepage verification.
	await page.goto( `${ baseUrl }/`, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 15_000 } );
	const homeOut = await readBlobDownload( page );
	assert.ok( homeOut && hasMagic( homeOut.head, 'image/webp' ), 'Homepage sample should encode WebP' );
	await page.screenshot( { path: path.join( outDir, 'v3-home-desktop.png' ) } );

	// Screen dialog focus restore.
	await page.locator( '#kb-screen-frame' ).click();
	await page.waitForSelector( '#kb-screen-dialog[open]' );
	await page.keyboard.press( 'Escape' );
	await page.waitForFunction( () => ! document.getElementById( 'kb-screen-dialog' )?.hasAttribute( 'open' ) );
	const focusedAfterClose = await page.evaluate( () => document.activeElement?.id );
	assert.equal( focusedAfterClose, 'kb-screen-frame', 'Dialog should restore focus to trigger' );

	const fixtures = await makeFixtures( page );

	// 2. Tools directory.
	await page.goto( `${ baseUrl }/tools/`, { waitUntil: 'networkidle' } );
	const toolLinks = await page.locator( '.kb-tool-card' ).count();
	assert.equal( toolLinks, 9, 'Tools directory should list all 9 tools' );
	await page.screenshot( { path: path.join( outDir, 'v3-tools-dir.png' ) } );

	// 3. Compress PNG: empty stage hides split handle, lossy + lossless + tiny 16x16 PNG completeness!
	await page.goto( `${ baseUrl }/tools/compress-png/`, { waitUntil: 'networkidle' } );
	const rangeHiddenEmpty = await page.locator( '[data-role="split-range"]' ).isHidden();
	assert.equal( rangeHiddenEmpty, true, 'Split range must be hidden when comparison stage is empty' );

	// Tiny 16x16 multi-color semi-transparent PNG regression test (must NOT truncate IEND).
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'tiny-favicon.png',
		mimeType: 'image/png',
		buffer: fixtures.tinyPng,
	} );
	await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 20_000 } );
	const tinyOut = await readBlobDownload( page );
	assert.ok( tinyOut && hasMagic( tinyOut.head, 'image/png' ) && hasPngIend( tinyOut.tail ), 'Tiny 16x16 PNG must be complete with IEND trailer' );
	assert.equal( tinyOut.width, 16 );
	assert.equal( tinyOut.height, 16 );

	// Standard 600x400 transparent PNG lossy + lossless + beforeImg clip-path check.
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'transparent-ui.png',
		mimeType: 'image/png',
		buffer: fixtures.png,
	} );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) || '' ) === 'transparent-ui.png', { timeout: 20_000 } );
	const pngLossy = await readBlobDownload( page );
	assert.ok( pngLossy && hasMagic( pngLossy.head, 'image/png' ) && hasPngIend( pngLossy.tail ), 'Lossy PNG must have PNG signature and IEND' );
	assert.equal( pngLossy.cornerAlpha, 0, 'Lossy PNG must preserve alpha transparency' );
	assert.ok( pngLossy.size < fixtures.png.length, `Lossy PNG (${ pngLossy.size }) should be smaller than source (${ fixtures.png.length })` );
	const beforeClip = await page.locator( '[data-role="before-img"]' ).evaluate( ( el ) => getComputedStyle( el ).clipPath );
	assert.match( beforeClip, /inset\(0(px)? 50% 0(px)? 0(px)?\)/, 'Before preview must be clipped to prevent transparent double-compositing' );
	await page.screenshot( { path: path.join( outDir, 'v3-compress-png.png' ) } );

	// Lossless PNG disables quality slider cleanly.
	await page.locator( '[data-role="png-mode-btn"][data-mode="lossless"]' ).click();
	await page.waitForFunction( () => ( document.querySelector( '[data-role="opt-meta"]' )?.textContent || '' ).includes( 'OxiPNG lossless' ), { timeout: 20_000 } );
	assert.equal( await page.locator( '[data-role="quality-input"]' ).isDisabled(), true, 'Quality slider should be disabled in Lossless PNG mode' );
	const pngLossless = await readBlobDownload( page );
	assert.ok( pngLossless && hasMagic( pngLossless.head, 'image/png' ) && hasPngIend( pngLossless.tail ), 'Lossless PNG must have valid PNG signature and IEND' );
	assert.equal( pngLossless.cornerAlpha, 0, 'Lossless PNG must preserve alpha transparency' );

	// 4. Compress JPEG.
	await page.goto( `${ baseUrl }/tools/compress-jpeg/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'camera.jpg',
		mimeType: 'image/jpeg',
		buffer: fixtures.jpg,
	} );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) || '' ) === 'camera.jpg', { timeout: 15_000 } );
	const jpgOut = await readBlobDownload( page );
	assert.ok( jpgOut && hasMagic( jpgOut.head, 'image/jpeg' ), 'Compress JPEG must output valid JPEG' );
	assert.ok( jpgOut.size < fixtures.jpg.length, 'Compress JPEG should shrink high-q photo' );

	// 5. JPG to WebP & PNG to WebP (alpha preserved).
	await page.goto( `${ baseUrl }/tools/jpg-to-webp/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'hero.jpg',
		mimeType: 'image/jpeg',
		buffer: fixtures.jpg,
	} );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) || '' ) === 'hero.webp', { timeout: 15_000 } );
	const j2w = await readBlobDownload( page );
	assert.ok( j2w && hasMagic( j2w.head, 'image/webp' ), 'JPG to WebP must output valid WebP' );

	await page.goto( `${ baseUrl }/tools/png-to-webp/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'logo.png',
		mimeType: 'image/png',
		buffer: fixtures.png,
	} );
	await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 15_000 } );
	const p2w = await readBlobDownload( page );
	assert.ok( p2w && hasMagic( p2w.head, 'image/webp' ), 'PNG to WebP must output valid WebP' );
	assert.equal( p2w.cornerAlpha, 0, 'PNG to WebP must preserve transparency' );

	// 6. JPG to AVIF: verify ZERO .wasm fetched on initial page load, then convert!
	trackAvifLoad = true;
	await page.goto( `${ baseUrl }/tools/jpg-to-avif/`, { waitUntil: 'networkidle' } );
	trackAvifLoad = false;
	assert.deepEqual( wasmRequestsOnAvifLoad, [], '/tools/jpg-to-avif/ must fetch 0 .wasm files on initial page load' );

	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'photo.jpg',
		mimeType: 'image/jpeg',
		buffer: fixtures.jpg,
	} );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) || '' ) === 'photo.avif', { timeout: 45_000 } );
	const j2a = await readBlobDownload( page );
	assert.ok( j2a && hasMagic( j2a.head, 'image/avif' ), 'JPG to AVIF must output valid AVIF' );

	await page.goto( `${ baseUrl }/tools/png-to-avif/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'badge.png',
		mimeType: 'image/png',
		buffer: fixtures.png,
	} );
	await page.waitForSelector( '[data-role="download-btn"][href^="blob:"]', { timeout: 45_000 } );
	const p2a = await readBlobDownload( page );
	assert.ok( p2a && hasMagic( p2a.head, 'image/avif' ), 'PNG to AVIF must output valid AVIF' );
	assert.equal( p2a.cornerAlpha, 0, 'PNG to AVIF must preserve transparency' );

	// 7. Resize image (1200x800 -> width 600 -> exact 600x400).
	await page.goto( `${ baseUrl }/tools/resize-image/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'wide.jpg',
		mimeType: 'image/jpeg',
		buffer: fixtures.jpg,
	} );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="download-btn"]' )?.getAttribute( 'download' ) || '' ) === 'wide.webp', { timeout: 15_000 } );
	await page.locator( '[data-role="resize-width"]' ).fill( '600' );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="opt-meta"]' )?.textContent || '' ).includes( '600 × 400' ), { timeout: 15_000 } );
	const resized = await readBlobDownload( page );
	assert.equal( resized.width, 600 );
	assert.equal( resized.height, 400 );

	// 8. Bulk image compressor (mixed valid + animated GIF + ZIP enabled + no raw File thumb DoS).
	await page.goto( `${ baseUrl }/tools/bulk-image-compressor/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( [
		{ name: 'one.jpg', mimeType: 'image/jpeg', buffer: fixtures.jpg },
		{ name: 'two.png', mimeType: 'image/png', buffer: fixtures.png },
		{ name: 'anim.gif', mimeType: 'image/gif', buffer: ANIMATED_GIF },
	] );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="batch-headline"]' )?.textContent || '' ).includes( '2 of 3 ready' ), { timeout: 30_000 } );
	const zipDisabled = await page.locator( '[data-role="zip-btn"]' ).isDisabled();
	assert.equal( zipDisabled, false, 'ZIP download button must enable after batch succeeds' );
	await page.screenshot( { path: path.join( outDir, 'v3-bulk-tool.png' ) } );

	// 9. Empty 0-byte file & corrupt file recovery on /tools/compress-image/.
	await page.goto( `${ baseUrl }/tools/compress-image/`, { waitUntil: 'networkidle' } );
	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'empty.png',
		mimeType: 'image/png',
		buffer: Buffer.alloc( 0 ),
	} );
	await page.waitForSelector( '[data-role="notice"]:not([hidden])', { timeout: 10_000 } );
	const emptyNotice = await page.locator( '[data-role="notice"]' ).textContent();
	assert.match( emptyNotice, /empty \(0 bytes\)/i, `0-byte file should show empty-file message, got: ${ emptyNotice }` );

	await page.locator( '[data-role="file-input"]' ).setInputFiles( {
		name: 'broken.jpg',
		mimeType: 'image/jpeg',
		buffer: Buffer.from( 'not-a-real-jpeg-file' ),
	} );
	await page.waitForFunction( () => ( document.querySelector( '[data-role="notice"]' )?.textContent || '' ).includes( 'Could not decode' ), { timeout: 10_000 } );
	const dlHiddenOnError = await page.locator( '[data-role="download-btn"]' ).isHidden();
	assert.equal( dlHiddenOnError, true, 'Download link must be hidden on invalid file error' );
	const dlPlaceholderDisabled = await page.locator( '[data-role="download-placeholder"]' ).isDisabled();
	assert.equal( dlPlaceholderDisabled, true, 'Download placeholder button must remain disabled on error' );

	// 10. Genuine 404 status check on a separate page so valid-route console errors stay strictly 0.
	const page404 = await context.newPage();
	const res404 = await page404.goto( `${ baseUrl }/does-not-exist/` );
	assert.equal( res404.status(), 404 );
	await page404.close();

	// 11. Mobile 360px & 390px horizontal overflow check + mobile nav menu.
	for ( const vw of [ 360, 390 ] ) {
		await page.setViewportSize( { width: vw, height: 844 } );
		await page.goto( `${ baseUrl }/tools/compress-png/`, { waitUntil: 'networkidle' } );
		const mobileOverflow = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 );
		assert.equal( mobileOverflow, false, `Mobile ${ vw }px tool page must have zero horizontal overflow` );
	}
	await page.locator( '.kb-mobile-nav-trigger' ).click();
	await page.waitForSelector( '.kb-mobile-nav[open]' );
	await page.keyboard.press( 'Escape' );
	await page.waitForFunction( () => ! document.querySelector( '.kb-mobile-nav[open]' ) );
	await page.screenshot( { path: path.join( outDir, 'v3-tool-mobile.png' ) } );

	// 12. JS-disabled initial HTML crawlability check.
	const noJsCtx = await browser.newContext( { javaScriptEnabled: false } );
	const noJsPage = await noJsCtx.newPage();
	await noJsPage.goto( `${ baseUrl }/tools/jpg-to-webp/`, { waitUntil: 'domcontentloaded' } );
	const noJsH1 = await noJsPage.locator( 'h1' ).textContent();
	const noJsTextLen = await noJsPage.evaluate( () => document.body.innerText.trim().length );
	assert.match( noJsH1, /JPG to WebP/i );
	assert.ok( noJsTextLen > 1200, `JS-disabled HTML must carry full readable content (got ${ noJsTextLen } chars)` );
	await noJsCtx.close();

	assert.deepEqual( errors, [], `Console/page errors detected: ${ errors.join( '; ' ) }` );
	assert.deepEqual( externalRequests, [], `Zero external network requests allowed: ${ externalRequests.join( ', ' ) }` );
	assert.deepEqual( nonGetRequests, [], `Zero upload/POST requests allowed: ${ nonGetRequests.join( ', ' ) }` );

	console.log( JSON.stringify( {
		passed: true,
		homeWebpBytes: homeOut.size,
		tinyPngBytes: tinyOut.size,
		pngSourceBytes: fixtures.png.length,
		pngLossyBytes: pngLossy.size,
		pngLosslessBytes: pngLossless.size,
		jpgSourceBytes: fixtures.jpg.length,
		jpgCompressedBytes: jpgOut.size,
		jpgToWebpBytes: j2w.size,
		jpgToAvifBytes: j2a.size,
		resizedDims: `${ resized.width }x${ resized.height }`,
		externalRequests: 0,
		uploadRequests: 0,
		errors: 0,
	}, null, 2 ) );
} finally {
	await browser.close();
	server.close();
}
