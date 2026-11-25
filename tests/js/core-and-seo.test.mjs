import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const rootDir = process.cwd();
const docsDir = path.join( rootDir, 'docs' );
const core = require( path.join( rootDir, '.cache/docs-ssg/core-test-bundle.cjs' ) );

test( 'dimensions: aspect-locked resize, no-upscale bounds, and non-finite guards', () => {
	assert.deepEqual( core.computeScaledDimensions( 4000, 3000, 2000, 2000 ), {
		width: 2000,
		height: 1500,
		resized: true,
	} );
	assert.deepEqual(
		core.computeTargetDimensions( 1200, 800, { width: 600, maintainAspectRatio: true, preventUpscale: true } ),
		{ width: 600, height: 400, resized: true }
	);
	assert.deepEqual(
		core.computeTargetDimensions( 600, 400, { width: 1200, maintainAspectRatio: true, preventUpscale: true } ),
		{ width: 600, height: 400, resized: false }
	);
	assert.deepEqual(
		core.computeTargetDimensions( 600, 400, { width: 500, height: 500, maintainAspectRatio: false, preventUpscale: false } ),
		{ width: 500, height: 500, resized: true }
	);
	assert.deepEqual(
		core.computeTargetDimensions( 600, 400, { width: Number.POSITIVE_INFINITY, height: Number.NaN } ),
		{ width: 600, height: 400, resized: false }
	);
} );

test( 'format: magic-byte verification, PNG IEND completeness, and filename sanitization', () => {
	const pngStub = new Uint8Array( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 13 ] );
	const pngTruncated = new Uint8Array( 40 );
	pngTruncated.set( pngStub );
	const pngComplete = new Uint8Array( 40 );
	pngComplete.set( pngStub );
	pngComplete.set( [ 0x00, 0x00, 0x00, 0x00, 0x49, 0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82 ], 28 );

	const jpgBytes = new Uint8Array( [ 0xff, 0xd8, 0xff, 0xe0, 0, 16, 0x4a, 0x46, 0x49, 0x46, 0, 1 ] );
	const webpBytes = new Uint8Array( [
		0x52, 0x49, 0x46, 0x46, 0x20, 0, 0, 0, 0x57, 0x45, 0x42, 0x50,
	] );
	const avifBytes = new Uint8Array( [
		0, 0, 0, 20,
		0x66, 0x74, 0x79, 0x70, // ftyp
		0x61, 0x76, 0x69, 0x66, // avif
		0, 0, 0, 0,
		0x6d, 0x69, 0x66, 0x31, // mif1
	] );

	assert.equal( core.verifyFileSignature( pngStub, 'image/png' ), true );
	assert.equal( core.verifyPngComplete( pngTruncated ), false );
	assert.equal( core.verifyPngComplete( pngComplete ), true );
	assert.equal( core.verifyFileSignature( pngStub, 'image/webp' ), false );
	assert.equal( core.verifyFileSignature( jpgBytes, 'image/jpeg' ), true );
	assert.equal( core.verifyFileSignature( jpgBytes, 'image/png' ), false );
	assert.equal( core.verifyFileSignature( webpBytes, 'image/webp' ), true );
	assert.equal( core.verifyFileSignature( avifBytes, 'image/avif' ), true );
	assert.equal( core.verifyFileSignature( avifBytes, 'image/webp' ), false );

	// mimeFromFilename requires a dot.
	assert.equal( core.mimeFromFilename( 'png' ), '' );
	assert.equal( core.mimeFromFilename( 'photo.png' ), 'image/png' );

	// Hostile filenames sanitized cleanly.
	assert.equal( core.sanitizeFilename( '../../etc/passwd.png' ), 'passwd.png' );
	assert.equal( core.sanitizeFilename( '..' ), 'image' );
	assert.equal( core.sanitizeFilename( 'foo/..' ), 'image' );
	assert.equal( core.sanitizeFilename( '.' ), 'image' );
	assert.equal( core.sanitizeFilename( 'CON.png' ), '_CON.png' );
	assert.equal( core.sanitizeFilename( 'C:evil:ads.png' ), 'C-evil-ads.png' );
	assert.equal( core.sanitizeFilename( 'pic\u202egnp.exe' ), 'picgnp.exe' );
	assert.equal( core.swapExtension( '../.png', 'webp' ), 'image.webp' );
	assert.equal( core.swapExtension( 'CON.png', 'webp' ), '_CON.webp' );
} );

test( 'zip: interoperable ZIP, hostile filename sanitization, entry count & memory ceilings', () => {
	const payloadA = new TextEncoder().encode( 'kilobyte-fixture-alpha' );
	const payloadB = new TextEncoder().encode( 'kilobyte-fixture-beta-duplicate' );
	const payloadC = new TextEncoder().encode( 'kilobyte-fixture-utf8-café' );
	const payloadD = new TextEncoder().encode( 'kilobyte-fixture-traversal' );

	const archive = core.createZipArchive( [
		{ name: 'photo.webp', data: payloadA },
		{ name: 'photo.webp', data: payloadB },
		{ name: 'café-image.avif', data: payloadC },
		{ name: 'foo/..', data: payloadD },
		{ name: '../../CON.png', data: payloadD },
	] );

	const tmpDir = fs.mkdtempSync( path.join( os.tmpdir(), 'kilobyte-zip-test-' ) );
	const zipPath = path.join( tmpDir, 'test.zip' );
	try {
		fs.writeFileSync( zipPath, archive );

		const unzipOut = execFileSync( 'unzip', [ '-t', zipPath ], { encoding: 'utf8' } );
		assert.match( unzipOut, /No errors detected/ );
		assert.match( unzipOut, /photo\.webp/ );
		assert.match( unzipOut, /photo-2\.webp/ );

		const pyOut = execFileSync(
			'python3',
			[
				'-c',
				`import zipfile, json; z=zipfile.ZipFile(${ JSON.stringify( zipPath ) }); assert z.testzip() is None; print(json.dumps({n: z.read(n).decode('utf-8') for n in z.namelist()}))`,
			],
			{ encoding: 'utf8' }
		);
		const parsed = JSON.parse( pyOut );
		assert.equal( parsed[ 'photo.webp' ], 'kilobyte-fixture-alpha' );
		assert.equal( parsed[ 'photo-2.webp' ], 'kilobyte-fixture-beta-duplicate' );
		assert.equal( parsed[ 'café-image.avif' ], 'kilobyte-fixture-utf8-café' );
		assert.equal( parsed[ 'image' ], 'kilobyte-fixture-traversal' );
		assert.equal( parsed[ '_CON.png' ], 'kilobyte-fixture-traversal' );
		assert.equal( Object.keys( parsed ).some( ( n ) => n.includes( '..' ) || n.includes( '/' ) ), false );
	} finally {
		fs.rmSync( tmpDir, { recursive: true, force: true } );
	}

	// Enforce explicit memory ceiling.
	assert.throws(
		() => core.createZipArchive( [ { name: 'big.bin', data: new Uint8Array( 2048 ) } ], 1024 ),
		/zip_too_large/
	);

	// Enforce 65,535-entry uint16 limit.
	const tooMany = Array.from( { length: 65536 }, () => ( { name: 'a.bin', data: new Uint8Array( 0 ) } ) );
	assert.throws( () => core.createZipArchive( tooMany ), /zip_too_large/ );

	// F04 regression: two different files sharing a 200-byte filename MUST receive unique ZIP names.
	const longBase = 'a'.repeat( 195 ) + '.webp'; // 200 bytes exact
	const longArchive = core.createZipArchive( [
		{ name: longBase, data: new TextEncoder().encode( 'first-image-payload' ) },
		{ name: longBase, data: new TextEncoder().encode( 'second-image-payload-different' ) },
		{ name: longBase, data: new TextEncoder().encode( 'third-image-payload-different' ) },
	] );
	const tmpLongDir = fs.mkdtempSync( path.join( os.tmpdir(), 'kilobyte-zip-long-' ) );
	const longZipPath = path.join( tmpLongDir, 'long.zip' );
	try {
		fs.writeFileSync( longZipPath, longArchive );
		const namesJson = execFileSync(
			'python3',
			[
				'-c',
				`import zipfile, json; z=zipfile.ZipFile(${ JSON.stringify( longZipPath ) }); assert z.testzip() is None; names=z.namelist(); print(json.dumps({"count": len(names), "unique": len(set(names)), "maxBytes": max(len(n.encode()) for n in names), "contents": [z.read(n).decode() for n in names]}))`,
			],
			{ encoding: 'utf8' }
		);
		const info = JSON.parse( namesJson );
		assert.equal( info.count, 3 );
		assert.equal( info.unique, 3, 'All 200-byte colliding ZIP entries must have distinct names' );
		assert.ok( info.maxBytes <= 200, `ZIP filename exceeded 200 bytes: ${ info.maxBytes }` );
		assert.deepEqual( info.contents, [
			'first-image-payload',
			'second-image-payload-different',
			'third-image-payload-different',
		] );
	} finally {
		fs.rmSync( tmpLongDir, { recursive: true, force: true } );
	}
	// F04 regression: NFC vs NFD colliding Unicode names (café vs cafe\u0301) MUST be deduplicated cleanly.
	const nfcNfdArchive = core.createZipArchive( [
		{ name: 'caf\u00e9.webp', data: new TextEncoder().encode( 'nfc-image-bytes' ) },
		{ name: 'cafe\u0301.webp', data: new TextEncoder().encode( 'nfd-image-bytes' ) },
	] );
	const tmpUniDir = fs.mkdtempSync( path.join( os.tmpdir(), 'kilobyte-zip-uni-' ) );
	const uniZipPath = path.join( tmpUniDir, 'uni.zip' );
	try {
		fs.writeFileSync( uniZipPath, nfcNfdArchive );
		const extractDir = path.join( tmpUniDir, 'out' );
		fs.mkdirSync( extractDir );
		execFileSync( 'python3', [
			'-c',
			`import zipfile; z=zipfile.ZipFile(${ JSON.stringify( uniZipPath ) }); assert z.testzip() is None; z.extractall(${ JSON.stringify( extractDir ) })`,
		] );
		const extractedFiles = fs.readdirSync( extractDir );
		assert.equal( extractedFiles.length, 2, `NFC/NFD ZIP must extract to 2 distinct files on disk, got: ${ extractedFiles.join( ', ' ) }` );
	} finally {
		fs.rmSync( tmpUniDir, { recursive: true, force: true } );
	}
} );

test( 'jpeg & png structure guards: truncated JPEG (with or without EOI) rejected, valid JPEGs accepted', () => {
	const sampleJpeg = new Uint8Array( fs.readFileSync( path.join( docsDir, 'assets/sample-photo.jpg' ) ) );
	assert.equal( core.isCompleteJpegBytes( sampleJpeg ), true, 'Valid sample-photo.jpg must pass Huffman completeness check' );

	// Truncated without EOI.
	const truncatedNoEoi = sampleJpeg.subarray( 0, Math.floor( sampleJpeg.length * 0.5 ) );
	assert.equal( core.isCompleteJpegBytes( truncatedNoEoi ), false, 'Truncated JPEG without EOI must be rejected' );

	// F03 regression: Truncated scan data WITH intact FF D9 EOI appended!
	const truncatedWithEoi = new Uint8Array( truncatedNoEoi.length + 2 );
	truncatedWithEoi.set( truncatedNoEoi );
	truncatedWithEoi[ truncatedNoEoi.length ] = 0xff;
	truncatedWithEoi[ truncatedNoEoi.length + 1 ] = 0xd9;
	assert.equal( core.isCompleteJpegBytes( truncatedWithEoi ), false, 'Truncated JPEG with intact EOI must be rejected' );

	// F16 regression: EXIF Orientation 5..8 swaps displayed width/height for both big-endian (MM) and little-endian (II).
	const makeJpegWithExif = ( endian, orientation ) => {
		const le = endian === 'II';
		const u16 = ( v ) => ( le ? [ v & 0xff, ( v >>> 8 ) & 0xff ] : [ ( v >>> 8 ) & 0xff, v & 0xff ] );
		const u32 = ( v ) => ( le
			? [ v & 0xff, ( v >>> 8 ) & 0xff, ( v >>> 16 ) & 0xff, ( v >>> 24 ) & 0xff ]
			: [ ( v >>> 24 ) & 0xff, ( v >>> 16 ) & 0xff, ( v >>> 8 ) & 0xff, v & 0xff ] );
		const tiff = [
			...( le ? [ 0x49, 0x49 ] : [ 0x4d, 0x4d ] ),
			...u16( 42 ),
			...u32( 8 ),
			...u16( 1 ),
			...u16( 0x0112 ), ...u16( 3 ), ...u32( 1 ), ...u16( orientation ), 0x00, 0x00,
			...u32( 0 ),
		];
		const app1Len = 2 + 6 + tiff.length;
		const bytes = new Uint8Array( [
			0xff, 0xd8,
			0xff, 0xe1, ( app1Len >>> 8 ) & 0xff, app1Len & 0xff,
			0x45, 0x78, 0x69, 0x66, 0x00, 0x00,
			...tiff,
			// SOF0: 120 x 80 stored
			0xff, 0xc0, 0x00, 0x0b, 0x08, 0x00, 0x50, 0x00, 0x78, 0x01, 0x01, 0x11, 0x00,
			0xff, 0xda, 0x00, 0x08, 0x01, 0x01, 0x00, 0x00, 0x3f, 0x00, 0xff, 0xd9,
		] );
		return bytes;
	};
	assert.deepEqual( core.dimensionsFromHeader( makeJpegWithExif( 'MM', 6 ), 'image/jpeg', 100 ), [ 80, 120 ] );
	assert.deepEqual( core.dimensionsFromHeader( makeJpegWithExif( 'II', 8 ), 'image/jpeg', 100 ), [ 80, 120 ] );
	assert.deepEqual( core.dimensionsFromHeader( makeJpegWithExif( 'MM', 1 ), 'image/jpeg', 100 ), [ 120, 80 ] );
} );

test( 'SEO, CSP, GEO, bundle hygiene & static HTML audit across all routes', () => {
	const expectedRoutes = [
		'/',
		'/tools/',
		'/tools/compress-image/',
		'/tools/compress-jpeg/',
		'/tools/compress-png/',
		'/tools/jpg-to-webp/',
		'/tools/png-to-webp/',
		'/tools/jpg-to-avif/',
		'/tools/png-to-avif/',
		'/tools/resize-image/',
		'/tools/bulk-image-compressor/',
	];

	const titles = new Set();
	const descriptions = new Set();

	for ( const route of expectedRoutes ) {
		const filePath = route === '/'
			? path.join( docsDir, 'index.html' )
			: path.join( docsDir, route.slice( 1 ), 'index.html' );
		assert.ok( fs.existsSync( filePath ), `Missing static HTML for ${ route }` );

		const html = fs.readFileSync( filePath, 'utf8' );
		const titleMatch = html.match( /<title>([^<]+)<\/title>/ );
		const descMatch = html.match( /<meta name="description" content="([^"]+)"/ );
		const canonMatch = html.match( /<link rel="canonical" href="([^"]+)"/ );
		const h1Matches = [ ...html.matchAll( /<h1[\s>]/g ) ];

		assert.ok( titleMatch, `Missing <title> on ${ route }` );
		assert.ok( descMatch, `Missing meta description on ${ route }` );
		assert.ok( canonMatch, `Missing canonical on ${ route }` );
		assert.equal( canonMatch[ 1 ], `https://kilobyte.gabi.to${ route }`, `Canonical mismatch on ${ route }` );
		assert.equal( h1Matches.length, 1, `Expected exactly 1 <h1> on ${ route }, got ${ h1Matches.length }` );
		const decodedTitle = titleMatch[ 1 ].replace( /&amp;/g, '&' ).replace( /&quot;/g, '"' ).replace( /&#39;/g, "'" );
		const decodedDesc = descMatch[ 1 ].replace( /&amp;/g, '&' ).replace( /&quot;/g, '"' ).replace( /&#39;/g, "'" );
		assert.ok( decodedTitle.length <= 60, `Title exceeds 60 chars on ${ route } (${ decodedTitle.length }): ${ decodedTitle }` );
		assert.ok( decodedDesc.length <= 160, `Description exceeds 160 chars on ${ route } (${ decodedDesc.length })` );

		assert.equal( titles.has( titleMatch[ 1 ] ), false, `Duplicate title on ${ route }: ${ titleMatch[ 1 ] }` );
		assert.equal( descriptions.has( descMatch[ 1 ] ), false, `Duplicate description on ${ route }` );
		titles.add( titleMatch[ 1 ] );
		descriptions.add( descMatch[ 1 ] );

		// Strict CSP, referrer policy, OG 1200x630 metadata, skip-link, zero inline style attributes.
		assert.match( html, /http-equiv="Content-Security-Policy"/, `Missing CSP meta on ${ route }` );
		assert.match( html, /name="referrer" content="strict-origin-when-cross-origin"/, `Missing referrer policy on ${ route }` );
		assert.match( html, /og:image" content="https:\/\/kilobyte\.gabi\.to\/assets\/og-card-1200x630\.jpg"/, `Missing 1200x630 og:image on ${ route }` );
		assert.match( html, /og:image:width" content="1200"/, `Missing og:image:width on ${ route }` );
		assert.match( html, /og:image:height" content="630"/, `Missing og:image:height on ${ route }` );
		assert.match( html, /class="kb-skip-link" href="#main-content"/, `Missing skip link on ${ route }` );
		assert.match( html, /<main id="main-content">/, `Missing #main-content landmark on ${ route }` );
		assert.equal( /\sstyle="/.test( html ), false, `Inline style attribute found on ${ route }` );
		assert.equal( /<img(?![^>]*\bsrc=)[^>]*>/i.test( html ), false, `Empty <img> without src found on ${ route }` );

		// Zero third-party font or analytics scripts.
		assert.equal( /fonts\.googleapis\.com|googletagmanager|google-analytics|plausible/i.test( html ), false, `Unexpected third-party request in ${ route }` );

		// Validate JSON-LD blocks and assert zero fabricated review/rating fields.
		const ldBlocks = [ ...html.matchAll( /<script type="application\/ld\+json">([^<]+)<\/script>/g ) ];
		assert.ok( ldBlocks.length >= 1, `Missing JSON-LD on ${ route }` );
		for ( const block of ldBlocks ) {
			const parsed = JSON.parse( block[ 1 ] );
			const raw = JSON.stringify( parsed );
			assert.equal( /aggregateRating|ratingValue|reviewCount/i.test( raw ), false, `Fabricated rating found on ${ route }` );
		}

		// Verify all root-relative internal links and assets resolve to existing files in docs/.
		for ( const attrMatch of html.matchAll( /(?:href|src)="(\/[^"#?]*)"/g ) ) {
			const target = attrMatch[ 1 ];
			const resolved = target.endsWith( '/' )
				? path.join( docsDir, target.slice( 1 ), 'index.html' )
				: path.join( docsDir, target.slice( 1 ) );
			assert.ok( fs.existsSync( resolved ), `Broken internal path "${ target }" on ${ route } -> ${ resolved }` );
		}
	}

	// Verify 404.html has noindex and NO canonical/og:url.
	const notFoundHtml = fs.readFileSync( path.join( docsDir, '404.html' ), 'utf8' );
	assert.match( notFoundHtml, /name="robots" content="noindex, follow"/ );
	assert.equal( /rel="canonical"/.test( notFoundHtml ), false, '404.html should omit rel="canonical"' );
	assert.equal( /property="og:url"/.test( notFoundHtml ), false, '404.html should omit og:url' );

	// Verify sitemap.xml matches all 11 canonical routes with <lastmod>.
	const sitemap = fs.readFileSync( path.join( docsDir, 'sitemap.xml' ), 'utf8' );
	const locs = [ ...sitemap.matchAll( /<loc>([^<]+)<\/loc>/g ) ].map( ( m ) => m[ 1 ] );
	const lastmods = [ ...sitemap.matchAll( /<lastmod>(\d{4}-\d{2}-\d{2})<\/lastmod>/g ) ];
	assert.deepEqual(
		locs,
		expectedRoutes.map( ( r ) => `https://kilobyte.gabi.to${ r }` )
	);
	assert.equal( lastmods.length, expectedRoutes.length, 'Every sitemap <url> must include YYYY-MM-DD <lastmod>' );

	// Verify robots.txt, llms.txt, and published third-party license notices (F14).
	const robots = fs.readFileSync( path.join( docsDir, 'robots.txt' ), 'utf8' );
	assert.match( robots, /Sitemap: https:\/\/kilobyte\.gabi\.to\/sitemap\.xml/ );
	const llms = fs.readFileSync( path.join( docsDir, 'llms.txt' ), 'utf8' );
	assert.match( llms, /Kilobyte Image Optimizer/ );
	assert.match( llms, /https:\/\/kilobyte\.gabi\.to\/tools\/compress-png\// );
	const notices = fs.readFileSync( path.join( docsDir, 'THIRD-PARTY-LICENSES.txt' ), 'utf8' );
	assert.match( notices, /@jsquash\/webp@/ );
	assert.match( notices, /@jsquash\/avif@/ );
	assert.match( notices, /libavif \(BSD 2-Clause License\)/ );
	assert.match( notices, /libaom \(BSD 2-Clause License/ );
	assert.match( notices, /IN NO EVENT SHALL THE\n\s+COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE/ );
	assert.match( notices, /Alliance for Open Media Patent License 1\.0/ );
	assert.match( notices, /1\.3\. Defensive Termination\./ );
	assert.match( notices, /OF CONTRACT, TORT \(INCLUDING NEGLIGENCE\), OR OTHERWISE, AND WHETHER OR/ );
	assert.match( notices, /2\.12\. Specification\./ );
	assert.match( notices, /Jean-loup Gailly and Mark Adler/ );
	assert.match( notices, /@jsquash\/oxipng@/ );
	assert.match( notices, /upng-js@/ );
	assert.match( notices, /@fontsource-variable\/eb-garamond@/ );

	// F17 regression: no visible <a> without href on any tool page.
	const avifHtml = fs.readFileSync( path.join( docsDir, 'tools/jpg-to-avif/index.html' ), 'utf8' );
	assert.match( avifHtml, /data-role="download-placeholder" disabled/ );
	assert.match( avifHtml, /data-role="download-btn" hidden/ );

	// Verify zero leaked developer filesystem paths in public JS bundles and content-hashed filenames.
	const distFiles = fs.readdirSync( path.join( docsDir, 'dist' ) );
	assert.ok( distFiles.some( ( f ) => /^site\.[a-f0-9]{8}\.css$/.test( f ) ), 'site CSS must be content-hashed' );
	assert.ok( distFiles.some( ( f ) => /^tool\.[a-f0-9]{8}\.js$/.test( f ) ), 'tool JS must be content-hashed' );
	assert.ok( distFiles.some( ( f ) => /^tool-worker\.[a-f0-9]{8}\.js$/.test( f ) ), 'tool-worker JS must be content-hashed' );
	for ( const f of distFiles ) {
		if ( f.endsWith( '.js' ) || f.endsWith( '.css' ) ) {
			const contents = fs.readFileSync( path.join( docsDir, 'dist', f ), 'utf8' );
			assert.equal( /file:\/\/\//i.test( contents ), false, `Local file:// path leaked in docs/dist/${ f }` );
		}
	}
} );
