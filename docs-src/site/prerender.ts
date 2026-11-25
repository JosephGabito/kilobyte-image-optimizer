import fs from 'fs';
import path from 'path';
import { SITE_CONTENT_LASTMOD, TOOLS } from './catalog';
import {
	renderHomePage,
	renderLlmsTxt,
	renderNotFoundPage,
	renderRobotsTxt,
	renderSitemapXml,
	renderToolPage,
	renderToolsDirectoryPage,
	type AssetManifest,
} from './templates';

function writeOut( filePath: string, content: string ): void {
	fs.mkdirSync( path.dirname( filePath ), { recursive: true } );
	const normalized = content
		.split( '\n' )
		.map( ( line ) => line.replace( /[ \t]+$/, '' ) )
		.join( '\n' );
	fs.writeFileSync( filePath, normalized, 'utf8' );
}

function copyFile( src: string, dest: string, mode = 0o644 ): void {
	fs.mkdirSync( path.dirname( dest ), { recursive: true } );
	fs.copyFileSync( src, dest );
	fs.chmodSync( dest, mode );
}

function readAssetManifest( rootDir: string ): AssetManifest & { siteJsShim?: string | null } {
	const manifestPath = path.join( rootDir, '.cache/docs-ssg/manifest.json' );
	if ( ! fs.existsSync( manifestPath ) ) {
		throw new Error( `Missing asset manifest at ${ manifestPath }. Run webpack docs build first.` );
	}
	const raw = JSON.parse( fs.readFileSync( manifestPath, 'utf8' ) ) as {
		siteCss?: string | null;
		siteJsShim?: string | null;
		homeJs?: string | null;
		toolJs?: string | null;
		navJs?: string | null;
		toolWorkerJs?: string | null;
	};
	if ( ! raw.siteCss || ! raw.homeJs || ! raw.toolJs || ! raw.navJs || ! raw.toolWorkerJs ) {
		throw new Error( `Incomplete asset manifest: ${ JSON.stringify( raw ) }` );
	}
	return {
		siteCss: raw.siteCss,
		siteJsShim: raw.siteJsShim,
		homeJs: raw.homeJs,
		toolJs: raw.toolJs,
		navJs: raw.navJs,
		toolWorkerJs: raw.toolWorkerJs,
	};
}

function resolveLastModDate(): string {
	// Deterministic explicit release date independent of git checkout depth or file mtimes.
	return SITE_CONTENT_LASTMOD;
}

function buildThirdPartyNotices( rootDir: string ): string {
	const packages: Array< {
		name: string;
		role: string;
		licenseFiles: string[];
		embeddedCodecNotices?: string;
	} > = [
		{
			name: '@jsquash/avif',
			role: 'Lazy-loaded single-threaded AVIF WebAssembly encoder (bundles libavif + libaom)',
			licenseFiles: [ 'LICENSE' ],
			embeddedCodecNotices: [
				'[Embedded WASM codecs in @jsquash/avif/codec/enc/avif_enc.wasm]',
				'1. libavif (BSD 2-Clause License)',
				'   Copyright 2019 Joe Drago. All rights reserved.',
				'   Redistribution and use in source and binary forms, with or without modification,',
				'   are permitted provided that the following conditions are met:',
				'   1. Redistributions of source code must retain the above copyright notice, this',
				'      list of conditions and the following disclaimer.',
				'   2. Redistributions in binary form must reproduce the above copyright notice,',
				'      this list of conditions and the following disclaimer in the documentation',
				'      and/or other materials provided with the distribution.',
				'   THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"',
				'   AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE',
				'   IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE',
				'   DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE',
				'   FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL',
				'   DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR',
				'   SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER',
				'   CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,',
				'   OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE',
				'   OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.',
				'',
				'2. libaom (BSD 2-Clause License & Alliance for Open Media Patent License 1.0)',
				'   [LICENSE]',
				'   Copyright (c) 2016, Alliance for Open Media. All rights reserved.',
				'',
				'   Redistribution and use in source and binary forms, with or without',
				'   modification, are permitted provided that the following conditions',
				'   are met:',
				'',
				'   1. Redistributions of source code must retain the above copyright',
				'      notice, this list of conditions and the following disclaimer.',
				'',
				'   2. Redistributions in binary form must reproduce the above copyright',
				'      notice, this list of conditions and the following disclaimer in',
				'      the documentation and/or other materials provided with the',
				'      distribution.',
				'',
				'   THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS',
				'   "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT',
				'   LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS',
				'   FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE',
				'   COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,',
				'   INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,',
				'   BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;',
				'   LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER',
				'   CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT',
				'   LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN',
				'   ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE',
				'   POSSIBILITY OF SUCH DAMAGE.',
				'',
				'   [PATENTS]',
				'   Alliance for Open Media Patent License 1.0',
				'',
				'   1. License Terms.',
				'',
				'   1.1. Patent License. Subject to the terms and conditions of this License, each',
				'        Licensor, on behalf of itself and successors in interest and assigns,',
				'        grants Licensee a non-sublicensable, perpetual, worldwide, non-exclusive,',
				'        no-charge, royalty-free, irrevocable (except as expressly stated in this',
				'        License) patent license to its Necessary Claims to make, use, sell, offer',
				'        for sale, import or distribute any Implementation.',
				'',
				'   1.2. Conditions.',
				'',
				'   1.2.1. Availability. As a condition to the grant of rights to Licensee to make,',
				'          sell, offer for sale, import or distribute an Implementation under',
				'          Section 1.1, Licensee must make its Necessary Claims available under',
				'          this License, and must reproduce this License with any Implementation',
				'          as follows:',
				'',
				'          a. For distribution in source code, by including this License in the',
				'             root directory of the source code with its Implementation.',
				'',
				'          b. For distribution in any other form (including binary, object form,',
				'             and/or hardware description code (e.g., HDL, RTL, Gate Level Netlist,',
				'             GDSII, etc.)), by including this License in the documentation, legal',
				'             notices, and/or other written materials provided with the',
				'             Implementation.',
				'',
				'   1.2.2. Additional Conditions. This license is directly from Licensor to',
				'          Licensee. Licensee acknowledges as a condition of benefiting from it',
				'          that no rights from Licensor are received from suppliers, distributors,',
				'          or otherwise in connection with this License.',
				'',
				'   1.3. Defensive Termination. If any Licensee, its Affiliates, or its agents',
				'        initiates patent litigation or files, maintains, or voluntarily',
				'        participates in a lawsuit against another entity or any person asserting',
				'        that any Implementation infringes Necessary Claims, any patent licenses',
				'        granted under this License directly to the Licensee are immediately',
				'        terminated as of the date of the initiation of action unless 1) that suit',
				'        was in response to a corresponding suit regarding an Implementation first',
				'        brought against an initiating entity, or 2) that suit was brought to',
				'        enforce the terms of this License (including intervention in a third-party',
				'        action by a Licensee).',
				'',
				'   1.4. Disclaimers. The Reference Implementation and Specification are provided',
				'        "AS IS" and without warranty. The entire risk as to implementing or',
				'        otherwise using the Reference Implementation or Specification is assumed',
				'        by the implementer and user. Licensor expressly disclaims any warranties',
				'        (express, implied, or otherwise), including implied warranties of',
				'        merchantability, non-infringement, fitness for a particular purpose, or',
				'        title, related to the material. IN NO EVENT WILL LICENSOR BE LIABLE TO',
				'        ANY OTHER PARTY FOR LOST PROFITS OR ANY FORM OF INDIRECT, SPECIAL,',
				'        INCIDENTAL, OR CONSEQUENTIAL DAMAGES OF ANY CHARACTER FROM ANY CAUSES OF',
				'        ACTION OF ANY KIND WITH RESPECT TO THIS LICENSE, WHETHER BASED ON BREACH',
				'        OF CONTRACT, TORT (INCLUDING NEGLIGENCE), OR OTHERWISE, AND WHETHER OR',
				'        NOT THE OTHER PARTY HAS BEEN ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.',
				'',
				'   2. Definitions.',
				'',
				'   2.1. Affiliate. "Affiliate" means an entity that directly or indirectly',
				'        Controls, is Controlled by, or is under common Control of that party.',
				'',
				'   2.2. Control. "Control" means direct or indirect control of more than 50% of',
				'        the voting power to elect directors of that corporation, or for any other',
				'        entity, the power to direct management of such entity.',
				'',
				'   2.3. Decoder. "Decoder" means any decoder that conforms fully with all',
				'        non-optional portions of the Specification.',
				'',
				'   2.4. Encoder. "Encoder" means any encoder that produces a bitstream that can',
				'        be decoded by a Decoder only to the extent it produces such a bitstream.',
				'',
				'   2.5. Final Deliverable. "Final Deliverable" means the final version of a',
				'        deliverable approved by the Alliance for Open Media as a Final',
				'        Deliverable.',
				'',
				'   2.6. Implementation. "Implementation" means any implementation, including the',
				'        Reference Implementation, that is an Encoder and/or a Decoder. An',
				'        Implementation also includes components of an Implementation only to the',
				'        extent they are used as part of an Implementation.',
				'',
				'   2.7. License. "License" means this license.',
				'',
				'   2.8. Licensee. "Licensee" means any person or entity who exercises patent',
				'        rights granted under this License.',
				'',
				'   2.9. Licensor. "Licensor" means (i) any Licensee that makes, sells, offers',
				'        for sale, imports or distributes any Implementation, or (ii) a person',
				'        or entity that has a licensing obligation to the Implementation as a',
				'        result of its membership and/or participation in the Alliance for Open',
				'        Media working group that developed the Specification.',
				'',
				'   2.10. Necessary Claims. "Necessary Claims" means all claims of patents or',
				'         patent applications, (a) that currently or at any time in the future,',
				'         are owned or controlled by the Licensor, and (b) (i) would be an',
				'         Essential Claim as defined by the W3C Policy as of February 5, 2004',
				'         (https://www.w3.org/Consortium/Patent-Policy-20040205/#def-essential)',
				'         as if the Specification was a W3C Recommendation; or (ii) are infringed',
				'         by the Reference Implementation.',
				'',
				'   2.11. Reference Implementation. "Reference Implementation" means an Encoder',
				'         and/or Decoder released by the Alliance for Open Media as a Final',
				'         Deliverable.',
				'',
				'   2.12. Specification. "Specification" means the specification designated by',
				'         the Alliance for Open Media as a Final Deliverable for which this',
				'         License was issued.',
			].join( '\n' ),
		},
		{
			name: '@jsquash/webp',
			role: 'Lazy-loaded single-threaded WebP WebAssembly encoder (libwebp)',
			licenseFiles: [ 'LICENSE', 'codec/LICENSE.codec.md' ],
		},
		{
			name: '@jsquash/oxipng',
			role: 'Lazy-loaded single-threaded PNG WebAssembly optimizer (oxipng)',
			licenseFiles: [ 'LICENSE', 'codec/LICENSE.codec.md' ],
		},
		{
			name: 'upng-js',
			role: 'Client-side indexed RGBA PNG palette quantizer',
			licenseFiles: [ 'LICENSE' ],
		},
		{
			name: 'pako',
			role: 'DEFLATE library used by upng-js',
			licenseFiles: [ 'LICENSE' ],
			embeddedCodecNotices: [
				'[zlib notice in pako/lib/zlib/*]',
				'(C) 1995-2013 Jean-loup Gailly and Mark Adler',
				'(C) 2014-2017 Vitaly Puzrin and Andrey Tupitsin',
				'',
				"This software is provided 'as-is', without any express or implied",
				'warranty. In no event will the authors be held liable for any damages',
				'arising from the use of this software.',
				'',
				'Permission is granted to anyone to use this software for any purpose,',
				'including commercial applications, and to alter it and redistribute it',
				'freely, subject to the following restrictions:',
				'',
				'1. The origin of this software must not be misrepresented; you must not',
				'  claim that you wrote the original software. If you use this software',
				'  in a product, an acknowledgment in the product documentation would be',
				'  appreciated but is not required.',
				'2. Altered source versions must be plainly marked as such, and must not be',
				'  misrepresented as being the original software.',
				'3. This notice may not be removed or altered from any source distribution.',
			].join( '\n' ),
		},
		{
			name: '@fontsource-variable/eb-garamond',
			role: 'Self-hosted EB Garamond variable WOFF2 font',
			licenseFiles: [ 'LICENSE' ],
		},
		{
			name: '@fontsource-variable/inter',
			role: 'Self-hosted Inter variable WOFF2 font',
			licenseFiles: [ 'LICENSE' ],
		},
		{
			name: '@fontsource-variable/jetbrains-mono',
			role: 'Self-hosted JetBrains Mono variable WOFF2 font',
			licenseFiles: [ 'LICENSE' ],
		},
	];

	const sections: string[] = [
		'Kilobyte Standalone Web Utilities — Third-Party Software & Font Licenses',
		'=======================================================================',
		'',
		'Kilobyte itself is licensed under the GNU General Public License v2.0 or later.',
		'The published static site bundles or redistributes the following open-source',
		'components and fonts. Their copyright notices and license terms appear below.',
		'',
	];

	for ( const entry of packages ) {
		const pkgDir = path.join( rootDir, 'node_modules', entry.name );
		const pkgJson = JSON.parse( fs.readFileSync( path.join( pkgDir, 'package.json' ), 'utf8' ) ) as {
			version?: string;
			license?: string;
			homepage?: string;
		};
		sections.push( '-----------------------------------------------------------------------' );
		sections.push( `Package: ${ entry.name }@${ pkgJson.version ?? 'unknown' }` );
		sections.push( `License: ${ pkgJson.license ?? 'See notice below' }` );
		sections.push( `Role: ${ entry.role }` );
		if ( pkgJson.homepage ) {
			sections.push( `Homepage: ${ pkgJson.homepage }` );
		}
		sections.push( '' );

		for ( const relFile of entry.licenseFiles ) {
			const fullPath = path.join( pkgDir, relFile );
			if ( fs.existsSync( fullPath ) ) {
				sections.push( `[${ relFile }]` );
				sections.push( fs.readFileSync( fullPath, 'utf8' ).trim() );
				sections.push( '' );
			}
		}
		if ( entry.embeddedCodecNotices ) {
			sections.push( entry.embeddedCodecNotices );
			sections.push( '' );
		}
	}

	return sections.join( '\n' ) + '\n';
}

export function buildStaticSite( rootDir: string ): void {
	const docsDir = path.join( rootDir, 'docs' );
	const assets = readAssetManifest( rootDir );
	const lastMod = resolveLastModDate();

	// 1. Copy self-hosted Latin variable .woff2 fonts (zero third-party font requests).
	const fontCopies: Array< [ string, string ] > = [
		[
			'node_modules/@fontsource-variable/eb-garamond/files/eb-garamond-latin-wght-normal.woff2',
			'docs/assets/fonts/eb-garamond-latin-wght-normal.woff2',
		],
		[
			'node_modules/@fontsource-variable/eb-garamond/files/eb-garamond-latin-wght-italic.woff2',
			'docs/assets/fonts/eb-garamond-latin-wght-italic.woff2',
		],
		[
			'node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2',
			'docs/assets/fonts/inter-latin-wght-normal.woff2',
		],
		[
			'node_modules/@fontsource-variable/jetbrains-mono/files/jetbrains-mono-latin-wght-normal.woff2',
			'docs/assets/fonts/jetbrains-mono-latin-wght-normal.woff2',
		],
	];
	for ( const [ relSrc, relDest ] of fontCopies ) {
		copyFile( path.join( rootDir, relSrc ), path.join( rootDir, relDest ) );
	}

	// 2. Copy self-hosted single-threaded WASM codecs with normalized 0644 permissions.
	const codecCopies: Array< [ string, string ] > = [
		[
			'node_modules/@jsquash/oxipng/codec/pkg/squoosh_oxipng_bg.wasm',
			'docs/codecs/squoosh_oxipng_bg.wasm',
		],
		[
			'node_modules/@jsquash/webp/codec/enc/webp_enc.wasm',
			'docs/codecs/webp_enc.wasm',
		],
		[
			'node_modules/@jsquash/avif/codec/enc/avif_enc.wasm',
			'docs/codecs/avif_enc.wasm',
		],
	];
	for ( const [ relSrc, relDest ] of codecCopies ) {
		copyFile( path.join( rootDir, relSrc ), path.join( rootDir, relDest ), 0o644 );
	}

	// 3. Remove empty webpack JS shim generated for the CSS-only entry point.
	if ( assets.siteJsShim ) {
		const shimPath = path.join( docsDir, assets.siteJsShim.replace( /^\/+/, '' ) );
		if ( fs.existsSync( shimPath ) ) {
			fs.unlinkSync( shimPath );
		}
	}

	// 4. Pre-render every public HTML page, 404, sitemap.xml, robots.txt, llms.txt, and third-party notices.
	writeOut( path.join( docsDir, 'index.html' ), renderHomePage( assets ) );
	writeOut( path.join( docsDir, 'tools/index.html' ), renderToolsDirectoryPage( assets ) );

	for ( const tool of TOOLS ) {
		writeOut( path.join( docsDir, `tools/${ tool.slug }/index.html` ), renderToolPage( tool, assets ) );
	}

	writeOut( path.join( docsDir, '404.html' ), renderNotFoundPage( assets ) );
	writeOut( path.join( docsDir, 'sitemap.xml' ), renderSitemapXml( lastMod ) );
	writeOut( path.join( docsDir, 'robots.txt' ), renderRobotsTxt() );
	writeOut( path.join( docsDir, 'llms.txt' ), renderLlmsTxt() );
	writeOut( path.join( docsDir, 'THIRD-PARTY-LICENSES.txt' ), buildThirdPartyNotices( rootDir ) );

	// Ensure .nojekyll stays present for GitHub Pages.
	const nojekyll = path.join( docsDir, '.nojekyll' );
	if ( ! fs.existsSync( nojekyll ) ) {
		fs.writeFileSync( nojekyll, '' );
	}

	console.log( `Pre-rendered homepage, /tools/, ${ TOOLS.length } standalone tools, 404.html, sitemap.xml, robots.txt, llms.txt, and THIRD-PARTY-LICENSES.txt.` );
}

if ( require.main === module ) {
	buildStaticSite( process.cwd() );
}
