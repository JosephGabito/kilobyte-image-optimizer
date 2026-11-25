/**
 * Reproducible client-side compression benchmark suite.
 * Runs deterministic fixtures (photograph, illustration, UI screenshot,
 * transparent RGBA graphic, and already-optimized JPEG) through Kilobyte's
 * real browser workers and self-hosted WASM codecs, measuring:
 *   - Input & output byte sizes + real % savings
 *   - Perceptual fidelity (PSNR dB computed from RGBA pixels)
 *   - Encoding wall-clock duration (ms)
 *   - Browser JS heap usage
 */
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { chromium } from 'playwright';

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
};

const server = http.createServer( ( req, res ) => {
	const urlPath = ( req.url || '/' ).split( '?' )[ 0 ];
	let filePath = path.join( docsDir, urlPath === '/' ? 'index.html' : urlPath );
	if ( fs.existsSync( filePath ) && fs.statSync( filePath ).isDirectory() ) {
		filePath = path.join( filePath, 'index.html' );
	}
	if ( ! filePath.startsWith( docsDir ) || ! fs.existsSync( filePath ) ) {
		res.writeHead( 404 );
		res.end();
		return;
	}
	res.writeHead( 200, { 'Content-Type': MIME[ path.extname( filePath ) ] || 'application/octet-stream' } );
	fs.createReadStream( filePath ).pipe( res );
} );

await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
const baseUrl = `http://127.0.0.1:${ server.address().port }`;

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

const manifest = JSON.parse( fs.readFileSync( path.join( rootDir, '.cache/docs-ssg/manifest.json' ), 'utf8' ) );
const workerPath = manifest.toolWorkerJs;

const executablePath = resolveChromeExecutable();
const browser = await chromium.launch( executablePath ? { executablePath } : {} );

try {
	const page = await browser.newPage();
	await page.goto( `${ baseUrl }/tools/compress-image/`, { waitUntil: 'networkidle' } );

	const rows = await page.evaluate( async ( { origin, workerRel } ) => {
		const workerUrl = `${ origin }${ workerRel }`;
		const codecBaseUrl = `${ origin }/codecs`;

		// Reuse a single pooled worker across benchmark cases to reflect production worker caching.
		const pooledWorker = new Worker( workerUrl );
		let seq = 0;
		function runWorker( request ) {
			return new Promise( ( resolve, reject ) => {
				const id = ++seq;
				pooledWorker.onmessage = ( { data } ) => {
					if ( data.id !== id ) return;
					if ( data.result ) resolve( data.result );
					else reject( new Error( data.error || 'failed' ) );
				};
				pooledWorker.onerror = ( e ) => {
					reject( new Error( e.message || 'worker error' ) );
				};
				pooledWorker.postMessage( { id, request } );
			} );
		}

		async function decodeRgba( blob ) {
			const bmp = await createImageBitmap( blob );
			const c = document.createElement( 'canvas' );
			c.width = bmp.width;
			c.height = bmp.height;
			const ctx = c.getContext( '2d' );
			ctx.drawImage( bmp, 0, 0 );
			bmp.close();
			return ctx.getImageData( 0, 0, c.width, c.height ).data;
		}

		function computePsnr( origRgba, outRgba ) {
			let mse = 0;
			const len = Math.min( origRgba.length, outRgba.length );
			for ( let i = 0; i < len; i++ ) {
				const d = origRgba[ i ] - outRgba[ i ];
				mse += d * d;
			}
			mse /= len;
			if ( mse === 0 ) return Infinity;
			return 10 * Math.log10( ( 255 * 255 ) / mse );
		}

		// Deterministic pseudo-random helper.
		let seed = 1337;
		const rand = () => {
			seed = ( seed * 1664525 + 1013904223 ) % 4294967296;
			return seed / 4294967296;
		};

		// Build 5 fixtures.
		async function buildFixture( name, width, height, mime, quality, draw ) {
			const c = document.createElement( 'canvas' );
			c.width = width;
			c.height = height;
			const ctx = c.getContext( '2d' );
			draw( ctx, width, height );
			const blob = await new Promise( ( r ) => c.toBlob( r, mime, quality ) );
			return new File( [ blob ], name, { type: mime } );
		}

		const photo = await buildFixture( 'photo.jpg', 1600, 1066, 'image/jpeg', 0.96, ( ctx, w, h ) => {
			const g = ctx.createLinearGradient( 0, 0, w, h );
			g.addColorStop( 0, '#1c2b56' );
			g.addColorStop( 0.55, '#c27b3a' );
			g.addColorStop( 1, '#f4d6a0' );
			ctx.fillStyle = g;
			ctx.fillRect( 0, 0, w, h );
			for ( let i = 0; i < 650; i++ ) {
				ctx.fillStyle = `rgba(${ Math.floor( rand() * 255 ) },${ Math.floor( rand() * 255 ) },${ Math.floor( rand() * 255 ) },0.16)`;
				ctx.beginPath();
				ctx.arc( rand() * w, rand() * h, 10 + rand() * 90, 0, Math.PI * 2 );
				ctx.fill();
			}
		} );

		const illustration = await buildFixture( 'illustration.png', 1200, 900, 'image/png', 1, ( ctx, w, h ) => {
			ctx.fillStyle = '#f6f7f7';
			ctx.fillRect( 0, 0, w, h );
			const colors = [ '#3858e9', '#1e1e1e', '#007017', '#dba617', '#d63638' ];
			for ( let i = 0; i < 40; i++ ) {
				ctx.fillStyle = colors[ i % colors.length ];
				ctx.fillRect( ( i * 73 ) % ( w - 180 ), ( i * 59 ) % ( h - 140 ), 180, 120 );
			}
		} );

		const screenshot = await buildFixture( 'ui-screenshot.png', 1440, 900, 'image/png', 1, ( ctx, w, h ) => {
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, w, h );
			ctx.fillStyle = '#1e1e1e';
			ctx.fillRect( 0, 0, w, 64 );
			ctx.fillStyle = '#3858e9';
			ctx.fillRect( 0, 64, w, 260 );
			ctx.fillStyle = '#ffffff';
			ctx.font = 'bold 48px sans-serif';
			ctx.fillText( 'Kilobyte Benchmark UI Screenshot', 80, 210 );
			ctx.fillStyle = '#f6f7f7';
			for ( let row = 0; row < 4; row++ ) {
				ctx.fillRect( 80, 360 + row * 120, w - 160, 92 );
				ctx.fillStyle = '#1e1e1e';
				ctx.font = '20px sans-serif';
				ctx.fillText( `Row ${ row + 1 } — Deterministic crisp interface typography`, 104, 412 + row * 120 );
				ctx.fillStyle = '#f6f7f7';
			}
		} );

		const transparent = await buildFixture( 'transparent-badge.png', 1000, 1000, 'image/png', 1, ( ctx, w, h ) => {
			ctx.clearRect( 0, 0, w, h );
			const g = ctx.createRadialGradient( 500, 500, 60, 500, 500, 420 );
			g.addColorStop( 0, 'rgba(56, 88, 233, 1)' );
			g.addColorStop( 0.7, 'rgba(56, 88, 233, 0.65)' );
			g.addColorStop( 1, 'rgba(56, 88, 233, 0)' );
			ctx.fillStyle = g;
			ctx.beginPath();
			ctx.arc( 500, 500, 420, 0, Math.PI * 2 );
			ctx.fill();
		} );

		const alreadyOptimized = await buildFixture( 'already-optimized.jpg', 1200, 800, 'image/jpeg', 0.65, ( ctx, w, h ) => {
			const g = ctx.createLinearGradient( 0, 0, w, h );
			g.addColorStop( 0, '#2c3338' );
			g.addColorStop( 1, '#50575e' );
			ctx.fillStyle = g;
			ctx.fillRect( 0, 0, w, h );
		} );

		const cases = [
			{ label: 'Photograph (1600×1066 JPG)', file: photo, outputMime: 'image/jpeg', quality: 80 },
			{ label: 'Photograph (1600×1066 JPG)', file: photo, outputMime: 'image/webp', quality: 82 },
			{ label: 'Photograph (1600×1066 JPG)', file: photo, outputMime: 'image/avif', quality: 80, preferWasm: true },
			{ label: 'Illustration (1200×900 PNG)', file: illustration, outputMime: 'image/png', quality: 82, pngMode: 'lossy' },
			{ label: 'Illustration (1200×900 PNG)', file: illustration, outputMime: 'image/png', quality: 82, pngMode: 'lossless' },
			{ label: 'Illustration (1200×900 PNG)', file: illustration, outputMime: 'image/webp', quality: 85 },
			{ label: 'UI Screenshot (1440×900 PNG)', file: screenshot, outputMime: 'image/png', quality: 82, pngMode: 'lossy' },
			{ label: 'UI Screenshot (1440×900 PNG)', file: screenshot, outputMime: 'image/webp', quality: 85 },
			{ label: 'Transparent Graphic (1000×1000 PNG)', file: transparent, outputMime: 'image/png', quality: 82, pngMode: 'lossy' },
			{ label: 'Transparent Graphic (1000×1000 PNG)', file: transparent, outputMime: 'image/webp', quality: 85, preferWasm: true },
			{ label: 'Transparent Graphic (1000×1000 PNG)', file: transparent, outputMime: 'image/avif', quality: 82, preferWasm: true },
			{ label: 'Already Optimized (1200×800 JPG q65)', file: alreadyOptimized, outputMime: 'image/jpeg', quality: 82 },
			{ label: 'Already Optimized (1200×800 JPG q65)', file: alreadyOptimized, outputMime: 'image/webp', quality: 82 },
		];

		const rgbaCache = new Map();
		const output = [];
		for ( const c of cases ) {
			if ( ! rgbaCache.has( c.file.name ) ) {
				rgbaCache.set( c.file.name, await decodeRgba( c.file ) );
			}
			const heapBefore = performance.memory ? performance.memory.usedJSHeapSize : 0;
			const res = await runWorker( {
				file: c.file,
				outputMime: c.outputMime,
				quality: c.quality,
				pngMode: c.pngMode,
				codecBaseUrl,
				preferWasm: Boolean( c.preferWasm ),
			} );
			const heapAfter = performance.memory ? performance.memory.usedJSHeapSize : 0;
			const outRgba = await decodeRgba( res.blob );
			const psnr = computePsnr( rgbaCache.get( c.file.name ), outRgba );
			output.push( {
				fixture: c.label,
				target: `${ c.outputMime.replace( 'image/', '' ).toUpperCase() }${ c.pngMode ? ` (${ c.pngMode })` : '' }`,
				encoder: res.encoder,
				inKB: +( res.originalSize / 1024 ).toFixed( 1 ),
				outKB: +( res.optimizedSize / 1024 ).toFixed( 1 ),
				deltaPct: `${ res.savedBytes >= 0 ? '-' : '+' }${ Math.abs( res.savedPercent ).toFixed( 1 ) }%`,
				psnr: Number.isFinite( psnr ) ? `${ psnr.toFixed( 1 ) } dB` : 'Lossless (Inf)',
				ms: res.durationMs,
				heapMB: heapAfter ? `${ ( heapAfter / ( 1024 * 1024 ) ).toFixed( 1 ) } MB` : 'n/a',
				heapDeltaKB: heapBefore ? Math.round( ( heapAfter - heapBefore ) / 1024 ) : 0,
			} );
		}
		pooledWorker.terminate();
		return output;
	}, { origin: baseUrl, workerRel: workerPath } );

	console.log( '| Fixture | Target | Encoder | Input | Output | Change | PSNR | Time | JS Heap |' );
	console.log( '|---|---|---|---:|---:|---:|---:|---:|---:|' );
	for ( const r of rows ) {
		console.log( `| ${ r.fixture } | ${ r.target } | ${ r.encoder } | ${ r.inKB } KB | ${ r.outKB } KB | ${ r.deltaPct } | ${ r.psnr } | ${ r.ms } ms | ${ r.heapMB } |` );
	}
} finally {
	await browser.close();
	server.close();
}
