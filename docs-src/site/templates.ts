import { labelForMime } from '../../src-js/core/format';
import {
	GITHUB_URL,
	getToolBySlug,
	PLAYGROUND_URL,
	SITE_ORIGIN,
	SUPPORT_URL,
	TOOL_CATEGORIES,
	TOOLS,
	WP_ORG_URL,
	type ToolDefinition,
} from './catalog';
import { ICONS } from './icons';

const TRANSPARENT_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const DEFAULT_OG_IMAGE = '/assets/og-card-1200x630.jpg';
const DEFAULT_OG_ALT = 'Kilobyte — Free browser-powered WebP, AVIF, JPEG, and PNG image optimization tools and WordPress plugin.';

const AUTHOR_ENTITY = {
	'@type': 'Person',
	'@id': `${ SITE_ORIGIN }/#author`,
	name: 'Joseph Gabito',
	url: 'https://joseph.gabi.to',
};

export interface AssetManifest {
	siteCss: string;
	homeJs: string;
	toolJs: string;
	navJs: string;
	toolWorkerJs: string;
}

function esc( value: string ): string {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

function safeJsonEmbed( data: unknown ): string {
	return JSON.stringify( data )
		.replace( /</g, '\\u003c' )
		.replace( />/g, '\\u003e' )
		.replace( /&/g, '\\u0026' )
		.replace( /\u2028/g, '\\u2028' )
		.replace( /\u2029/g, '\\u2029' );
}

function jsonLdScript( data: Record< string, unknown > ): string {
	return `<script type="application/ld+json">${ safeJsonEmbed( data ) }</script>`;
}

interface PageMeta {
	title: string;
	description: string;
	canonicalPath?: string;
	ogImagePath?: string;
	ogImageAlt?: string;
	cssPath: string;
	scriptPath?: string;
	jsonLd?: Array< Record< string, unknown > >;
	noindex?: boolean;
	preloadBanner?: boolean;
}

function renderDocument( meta: PageMeta, bodyHtml: string ): string {
	const canonical = meta.canonicalPath && ! meta.noindex ? `${ SITE_ORIGIN }${ meta.canonicalPath }` : null;
	const ogImage = `${ SITE_ORIGIN }${ meta.ogImagePath ?? DEFAULT_OG_IMAGE }`;
	const ogImageAlt = meta.ogImageAlt ?? DEFAULT_OG_ALT;
	const robotsTag = meta.noindex ? '\n\t<meta name="robots" content="noindex, follow" />' : '';
	const canonicalTag = canonical ? `\n\t<link rel="canonical" href="${ esc( canonical ) }" />` : '';
	const ogUrlTag = canonical ? `\n\t<meta property="og:url" content="${ esc( canonical ) }" />` : '';
	const preloadTag = meta.preloadBanner
		? '\n\t<link rel="preload" as="image" href="/assets/banner-1544x500.webp" type="image/webp" />'
		: '';
	const ldTags = ( meta.jsonLd ?? [] ).map( ( item ) => `\n\t${ jsonLdScript( item ) }` ).join( '' );
	const scriptTag = meta.scriptPath ? `\n\t<script src="${ esc( meta.scriptPath ) }" defer></script>` : '';

	const csp = [
		"default-src 'self'",
		"script-src 'self' 'wasm-unsafe-eval'",
		"style-src 'self'",
		"img-src 'self' blob: data:",
		"font-src 'self'",
		"connect-src 'self' blob:",
		"worker-src 'self'",
		"object-src 'none'",
		"base-uri 'self'",
	].join( '; ' );

	return `<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="referrer" content="strict-origin-when-cross-origin" />
	<meta http-equiv="Content-Security-Policy" content="${ esc( csp ) }" />
	<title>${ esc( meta.title ) }</title>
	<meta name="description" content="${ esc( meta.description ) }" />${ robotsTag }${ canonicalTag }

	<meta property="og:type" content="website" />
	<meta property="og:site_name" content="Kilobyte" />${ ogUrlTag }
	<meta property="og:title" content="${ esc( meta.title ) }" />
	<meta property="og:description" content="${ esc( meta.description ) }" />
	<meta property="og:image" content="${ esc( ogImage ) }" />
	<meta property="og:image:width" content="1200" />
	<meta property="og:image:height" content="630" />
	<meta property="og:image:alt" content="${ esc( ogImageAlt ) }" />
	<meta name="twitter:card" content="summary_large_image" />
	<meta name="twitter:title" content="${ esc( meta.title ) }" />
	<meta name="twitter:description" content="${ esc( meta.description ) }" />
	<meta name="twitter:image" content="${ esc( ogImage ) }" />
	<meta name="twitter:image:alt" content="${ esc( ogImageAlt ) }" />

	<link rel="icon" type="image/png" href="/assets/icon-128x128.png" />
	<link rel="preload" as="font" href="/assets/fonts/eb-garamond-latin-wght-normal.woff2" type="font/woff2" crossorigin />
	<link rel="preload" as="font" href="/assets/fonts/inter-latin-wght-normal.woff2" type="font/woff2" crossorigin />${ preloadTag }
	<link rel="stylesheet" href="${ esc( meta.cssPath ) }" />${ ldTags }
</head>
<body>
<a class="kb-skip-link" href="#main-content">Skip to main content</a>
${ bodyHtml }${ scriptTag }
</body>
</html>
`;
}

function renderTopbar( currentPath: string ): string {
	const isHome = currentPath === '/';
	const navItems = isHome
		? [
			{ href: '/tools/', label: 'Tools' },
			{ href: '#demo', label: 'Try it here' },
			{ href: '#why', label: 'Why Kilobyte' },
			{ href: '#screens', label: 'Screens' },
			{ href: '#developers', label: 'Developers' },
			{ href: '#questions', label: 'Questions' },
		]
		: [
			{ href: '/tools/', label: 'All tools' },
			{ href: '/tools/compress-image/', label: 'Compress' },
			{ href: '/tools/#cat-convert', label: 'Convert' },
			{ href: '/tools/resize-image/', label: 'Resize' },
			{ href: '/tools/bulk-image-compressor/', label: 'Bulk' },
			{ href: '/', label: 'WordPress plugin' },
		];

	const renderLink = ( item: { href: string; label: string } ) => {
		const isCurrent = item.href === currentPath;
		return `<a href="${ esc( item.href ) }"${ isCurrent ? ' aria-current="page"' : '' }>${ esc( item.label ) }</a>`;
	};

	const desktopLinks = navItems.map( ( item ) => `\n\t\t\t${ renderLink( item ) }` ).join( '' );
	const mobileLinks = navItems.map( ( item ) => `\n\t\t\t\t${ renderLink( item ) }` ).join( '' );

	return `<header class="kb-topbar">
	<div class="kb-container kb-topbar-inner">
		<div class="kb-topbar-left">
			<a class="kb-brand" href="/" aria-label="Kilobyte home"${ isHome ? ' aria-current="page"' : '' }>Kilobyte</a>
			<nav class="kb-nav" aria-label="Primary navigation">${ desktopLinks }
			</nav>
		</div>
		<div class="kb-topbar-actions">
			<a class="kb-topbar-link kb-topbar-github" href="${ esc( GITHUB_URL ) }" target="_blank" rel="noopener noreferrer">GitHub</a>
			<a class="kb-btn kb-btn-primary kb-topbar-cta" href="${ esc( WP_ORG_URL ) }" target="_blank" rel="noopener noreferrer">Get WP plugin</a>
			<details class="kb-mobile-nav">
				<summary class="kb-mobile-nav-trigger" aria-label="Toggle navigation menu">${ ICONS.menu }<span>Menu</span></summary>
				<nav class="kb-mobile-nav-panel" aria-label="Mobile navigation">${ mobileLinks }
					<a href="${ esc( GITHUB_URL ) }" target="_blank" rel="noopener noreferrer">GitHub</a>
				</nav>
			</details>
		</div>
	</div>
</header>`;
}

function renderFooter(): string {
	return `<footer class="kb-footer">
	<div class="kb-container kb-footer-inner">
		<div class="kb-footer-brand">
			<img src="/assets/icon-128x128.png" alt="" width="24" height="24" />
			<p class="kb-small">Kilobyte is free, open-source image optimization software under the GPL, version 2 or later. All browser utilities run 100% locally on your device.</p>
		</div>
		<nav class="kb-footer-links kb-small" aria-label="Footer navigation">
			<a href="/">WordPress plugin</a>
			<a href="/tools/">Browser tools</a>
			<a href="${ esc( WP_ORG_URL ) }" target="_blank" rel="noopener noreferrer">WordPress.org listing</a>
			<a href="${ esc( SUPPORT_URL ) }" target="_blank" rel="noopener noreferrer">Support forum</a>
			<a href="${ esc( GITHUB_URL ) }" target="_blank" rel="noopener noreferrer">Source code on GitHub</a>
			<a href="/THIRD-PARTY-LICENSES.txt">Third-party licenses</a>
			<a href="https://joseph.gabi.to" target="_blank" rel="noopener noreferrer">Made by Joseph Gabito</a>
		</nav>
	</div>
</footer>`;
}

function renderToolWidgetHtml( config: {
	id: string;
	slug: string;
	title: string;
	headingTag?: 'h2' | 'h3';
	accept: string;
	acceptLabel: string;
	defaultOutputMime: string;
	allowedOutputMimes: string[];
	defaultQuality: number;
	showQuality: boolean;
	showPresets: boolean;
	showPngMode: boolean;
	defaultPngMode?: string;
	showResize: boolean;
	batchFocused: boolean;
	checkerboard: boolean;
	sampleUrl?: string;
	autoLoadSample?: boolean;
	workerUrl: string;
	showWpCalloutAfter: boolean;
} ): string {
	const headingTag = config.headingTag ?? 'h2';
	const defaultOutLabel = labelForMime( config.defaultOutputMime );
	const formatButtons = config.allowedOutputMimes.map( ( mime ) => {
		const active = mime === config.defaultOutputMime;
		return `<button type="button" class="kb-seg-btn${ active ? ' is-active' : '' }" data-role="format-btn" data-mime="${ esc( mime ) }" aria-pressed="${ active ? 'true' : 'false' }">${ esc( labelForMime( mime ) ) }</button>`;
	} ).join( '' );

	const formatControl = config.allowedOutputMimes.length > 1
		? `<div class="kb-control-group">
			<span class="kb-control-label">Output format</span>
			<div class="kb-seg" role="group" aria-label="Output format">${ formatButtons }</div>
		</div>`
		: `<div class="kb-control-group">
			<span class="kb-control-label">Output format</span>
			<div class="kb-format-fixed">${ esc( defaultOutLabel ) }</div>
		</div>`;

	const presets: Array< { label: string; q: number } > = [
		{ label: 'High', q: 90 },
		{ label: 'Balanced', q: 82 },
		{ label: 'Smallest', q: 72 },
	];

	const presetGroup = config.showPresets
		? `<div class="kb-control-group" data-role="preset-group">
			<span class="kb-control-label">Compression preset</span>
			<div class="kb-seg" role="group" aria-label="Compression preset">
				${ presets.map( ( p ) => {
					const active = config.defaultQuality === p.q;
					return `<button type="button" class="kb-seg-btn${ active ? ' is-active' : '' }" data-role="preset-btn" data-quality="${ p.q }" aria-pressed="${ active ? 'true' : 'false' }">${ p.label }</button>`;
				} ).join( '\n\t\t\t\t' ) }
			</div>
		</div>`
		: '';

	const qualityGroup = config.showQuality
		? `<div class="kb-control-group" data-role="quality-group">
			<label class="kb-control-label" for="${ esc( config.id ) }-quality">
				<span>Quality</span>
				<span class="kb-control-val" data-role="quality-val">${ config.defaultQuality }</span>
			</label>
			<input id="${ esc( config.id ) }-quality" class="kb-range" type="range" min="40" max="95" value="${ config.defaultQuality }" data-role="quality-input" />
		</div>`
		: '';

	const pngModeGroup = config.showPngMode
		? `<div class="kb-control-group" data-role="png-mode-group"${ config.defaultOutputMime === 'image/png' ? '' : ' hidden' }>
			<span class="kb-control-label">PNG mode</span>
			<div class="kb-seg" role="group" aria-label="PNG optimization mode">
				<button type="button" class="kb-seg-btn${ ( config.defaultPngMode ?? 'lossy' ) === 'lossy' ? ' is-active' : '' }" data-role="png-mode-btn" data-mode="lossy" aria-pressed="${ ( config.defaultPngMode ?? 'lossy' ) === 'lossy' ? 'true' : 'false' }">Palette (smaller)</button>
				<button type="button" class="kb-seg-btn${ config.defaultPngMode === 'lossless' ? ' is-active' : '' }" data-role="png-mode-btn" data-mode="lossless" aria-pressed="${ config.defaultPngMode === 'lossless' ? 'true' : 'false' }">Lossless</button>
			</div>
		</div>`
		: '';

	const halfScalePill = config.batchFocused
		? ''
		: '\n\t\t\t\t<button type="button" class="kb-pill-btn" data-role="size-preset" data-scale="0.5" data-single-only="1" hidden>50%</button>';

	const resizeBlock = config.showResize
		? `<div class="kb-resize-grid" data-role="resize-controls">
			<div class="kb-control-group">
				<label class="kb-control-label" for="${ esc( config.id ) }-width">${ config.batchFocused ? 'Max width (px)' : 'Width (px)' }</label>
				<input id="${ esc( config.id ) }-width" class="kb-input" type="number" min="1" max="12000" inputmode="numeric" placeholder="Auto" data-role="resize-width" />
			</div>
			<div class="kb-control-group">
				<label class="kb-control-label" for="${ esc( config.id ) }-height">${ config.batchFocused ? 'Max height (px)' : 'Height (px)' }</label>
				<input id="${ esc( config.id ) }-height" class="kb-input" type="number" min="1" max="12000" inputmode="numeric" placeholder="Auto" data-role="resize-height" />
			</div>
			<label class="kb-check-label">
				<input type="checkbox" checked data-role="resize-aspect" />
				<span>Keep aspect ratio</span>
			</label>
			<label class="kb-check-label">
				<input type="checkbox" checked data-role="resize-no-upscale" />
				<span>Never upscale</span>
			</label>
			<div class="kb-preset-pills" role="group" aria-label="Quick width presets">
				<button type="button" class="kb-pill-btn" data-role="size-preset" data-width="1920">1920px</button>
				<button type="button" class="kb-pill-btn" data-role="size-preset" data-width="1200">1200px</button>
				<button type="button" class="kb-pill-btn" data-role="size-preset" data-width="800">800px</button>${ halfScalePill }
				<button type="button" class="kb-pill-btn" data-role="size-preset" data-clear="1">Original size</button>
			</div>
		</div>`
		: '';

	const sampleButton = config.sampleUrl
		? `<button type="button" class="kb-btn kb-btn-ghost" data-role="sample-btn">${ ICONS.reset }<span>Use sample photo</span></button>`
		: '';

	const autoLoadSample = Boolean( config.sampleUrl && ( config.autoLoadSample ?? true ) && ! config.batchFocused );

	const initialConfigJson = safeJsonEmbed( {
		slug: config.slug,
		accept: config.accept,
		defaultOutputMime: config.defaultOutputMime,
		allowedOutputMimes: config.allowedOutputMimes,
		defaultQuality: config.defaultQuality,
		defaultPngMode: config.defaultPngMode ?? 'lossy',
		showResize: config.showResize,
		batchFocused: config.batchFocused,
		sampleUrl: config.sampleUrl ?? null,
		autoLoadSample,
		workerUrl: config.workerUrl,
	} );

	return `<div class="kb-card kb-lab-card" id="${ esc( config.id ) }" data-tool-widget>
	<script type="application/json" data-role="tool-config">${ initialConfigJson }</script>

	<div class="kb-card-header">
		<${ headingTag } class="kb-h3">${ esc( config.title ) }</${ headingTag }>
		<div class="kb-lab-buttons">
			<button type="button" class="kb-btn kb-btn-primary" data-role="pick-btn">${ ICONS.upload }<span>${ config.batchFocused ? 'Choose images' : 'Choose image' }</span></button>
			<input type="file" class="sr-only" tabindex="-1" aria-hidden="true" accept="${ esc( config.accept ) }" multiple data-role="file-input" />
			${ sampleButton }
			<button type="button" class="kb-btn kb-btn-danger" data-role="cancel-btn" hidden>Cancel</button>
			<button type="button" class="kb-btn kb-btn-ghost" data-role="clear-btn" hidden>Clear</button>
		</div>
	</div>

	<div class="kb-card-body kb-lab-body">
		<div class="kb-dropzone" data-role="dropzone" role="button" tabindex="0">
			<p class="kb-dropzone-title">Drop ${ config.batchFocused ? 'multiple images or a folder' : 'one or more images' } here, or click to browse</p>
			<p class="kb-small">Accepts ${ esc( config.acceptLabel ) }. Processed 100% locally in your browser.</p>
		</div>

		<div class="kb-lab-controls">
			${ formatControl }
			${ presetGroup }
			${ qualityGroup }
			${ pngModeGroup }
		</div>

		${ resizeBlock }

		<div class="kb-notice-wrap" aria-live="polite" aria-atomic="true">
			<div class="kb-notice" role="status" data-role="notice" hidden></div>
		</div>

		<div data-role="single-view"${ config.batchFocused ? ' hidden' : '' }>
			<dl class="kb-lab-stats">
				<div class="kb-lab-stat">
					<dt data-role="orig-label">Original</dt>
					<dd class="kb-lab-stat-value" data-role="orig-size">--</dd>
					<dd class="kb-lab-stat-note" data-role="orig-dims">Waiting for image</dd>
				</div>
				<div class="kb-lab-stat">
					<dt data-role="opt-label">Output (${ esc( defaultOutLabel ) })</dt>
					<dd class="kb-lab-stat-value" data-role="opt-size">--</dd>
					<dd class="kb-lab-stat-note" data-role="opt-meta">Local encoder ready</dd>
				</div>
				<div class="kb-lab-stat" data-role="savings-stat">
					<dt>Measured change</dt>
					<dd class="kb-lab-stat-value" data-role="savings-val">--</dd>
					<dd class="kb-lab-stat-note" data-role="savings-note">Select an image to measure</dd>
				</div>
			</dl>

			<div class="kb-compare-stage${ config.checkerboard ? ' kb-checkerboard' : '' }" data-role="compare-stage">
				<img src="${ TRANSPARENT_PIXEL }" width="1" height="1" alt="Original image preview" class="kb-compare-img kb-compare-before" data-role="before-img" hidden />
				<div class="kb-compare-after" data-role="after-wrap">
					<img src="${ TRANSPARENT_PIXEL }" width="1" height="1" alt="Optimized image preview" class="kb-compare-img" data-role="after-img" hidden />
				</div>
				<span class="kb-compare-tag kb-compare-tag-left" data-role="left-tag" hidden>Original</span>
				<span class="kb-compare-tag kb-compare-tag-right" data-role="right-tag" hidden>${ esc( defaultOutLabel ) }</span>
				<input type="range" min="0" max="100" value="50" class="kb-compare-range" data-role="split-range" aria-label="Slide to compare original and optimized image" disabled hidden />
				<div class="kb-compare-divider" data-role="split-divider" aria-hidden="true" hidden>
					<span class="kb-compare-handle">&harr;</span>
				</div>
				<div class="kb-compare-empty" data-role="empty-stage">
					${ autoLoadSample ? 'Loading sample photo locally...' : 'Drop or choose an image above to preview before and after locally.' }
				</div>
			</div>
		</div>

		<div class="kb-batch-wrap" data-role="batch-view"${ config.batchFocused ? '' : ' hidden' }>
			<div class="kb-batch-summary" aria-live="polite">
				<div>
					<strong data-role="batch-headline">No images queued yet.</strong>
					<p class="kb-small" data-role="batch-subline">Drop multiple files to process up to 3 concurrently in browser Web Workers.</p>
				</div>
				<div class="kb-lab-buttons">
					<button type="button" class="kb-btn kb-btn-primary" data-role="zip-btn" disabled>${ ICONS.download }<span>Download all (.zip)</span></button>
				</div>
			</div>
			<ul class="kb-batch-list" data-role="batch-list"></ul>
		</div>

		<noscript>
			<div class="kb-notice is-warning">
				Enable JavaScript to run the local browser image encoder on this page. Your images are never uploaded to any server.
			</div>
		</noscript>
	</div>

	<div class="kb-card-footer">
		<p class="kb-small" data-role="status-line" role="status" aria-live="polite">100% local browser processing. Your image files never leave your device.</p>
		<button type="button" class="kb-btn kb-btn-secondary" data-role="download-placeholder" disabled${ config.batchFocused ? ' hidden' : '' }>${ ICONS.download }<span data-role="download-placeholder-label">Download ${ esc( defaultOutLabel ) }</span></button>
		<a class="kb-btn kb-btn-secondary" data-role="download-btn" hidden>${ ICONS.download }<span data-role="download-label">Download ${ esc( defaultOutLabel ) }</span></a>
	</div>
</div>${ config.showWpCalloutAfter ? `
<aside class="kb-wp-callout" data-role="wp-callout" aria-label="Kilobyte for WordPress">
	<p><strong>Running a WordPress website?</strong> Kilobyte automatically optimizes your images as you upload them, keeping every original file and URL intact.</p>
	<div class="kb-lab-buttons">
		<a class="kb-btn kb-btn-primary" href="${ esc( WP_ORG_URL ) }" target="_blank" rel="noopener noreferrer">${ ICONS.download }<span>Get Kilobyte for WordPress</span></a>
		<a class="kb-btn kb-btn-secondary" href="/"><span>Learn how it works</span></a>
	</div>
</aside>` : '' }`;
}

function buildFaqSchema( items: Array< { q: string; a: string } > ): Record< string, unknown > {
	return {
		'@context': 'https://schema.org',
		'@type': 'FAQPage',
		mainEntity: items.map( ( item ) => ( {
			'@type': 'Question',
			name: item.q,
			acceptedAnswer: {
				'@type': 'Answer',
				text: item.a,
			},
		} ) ),
	};
}

export function renderHomePage( assets: AssetManifest ): string {
	const screens = [
		{
			id: 'preview',
			src: '/assets/screen-preview.webp',
			width: 1700,
			height: 944,
			title: 'Preview',
			body: 'Compare one photo before and after, side by side. Nothing is saved until you choose Optimize now.',
			alt: 'The Preview tab of Kilobyte, showing an original 2.03 MB photo next to a 115.5 KB WebP copy',
		},
		{
			id: 'selective',
			src: '/assets/screen-selective.webp',
			width: 1720,
			height: 832,
			title: 'Selective optimize',
			body: 'Choose specific photos from your Media Library and optimize only those.',
			alt: 'The Selective optimize tab of Kilobyte, with five Media Library images chosen for optimizing',
		},
		{
			id: 'bulk',
			src: '/assets/screen-bulk.webp',
			width: 1200,
			height: 1408,
			title: 'Bulk optimize',
			body: 'Optimize your whole Media Library in one run. The screen lists every image and has a Restore all button.',
			alt: 'The Bulk optimize tab of Kilobyte, showing 21 images processed and 8.61 MB saved',
		},
		{
			id: 'preference',
			src: '/assets/screen-preference.webp',
			width: 1136,
			height: 1360,
			title: 'Preference',
			body: 'Choose the image format and the compression level. Quality and maximum size are under Advanced.',
			alt: 'The Preference tab of Kilobyte, with format, compression level, and advanced quality settings',
		},
		{
			id: 'media-library',
			src: '/assets/screen-media-library.webp',
			width: 1488,
			height: 1084,
			title: 'Media Library',
			body: 'Each image in your Media Library shows how much smaller its copy is, next to a Restore original link.',
			alt: 'A WordPress Media Library image whose details show a 94.4 percent saving and a Restore original link',
		},
	];

	const codeTabs = [
		{
			name: 'abilities',
			title: 'Abilities API',
			filename: 'WordPress 6.9 and newer',
			snippet: `// Available in PHP, over REST at /wp-json/wp-abilities/v1/,
// and through MCP adapters.

$stats = wp_get_ability( 'kilobyte/get-stats' )->execute();

$queue = wp_get_ability( 'kilobyte/get-queue' )->execute(
	array(
		'mode'     => 'optimize',
		'per_page' => 25,
	)
);

$result = wp_get_ability( 'kilobyte/optimize-image' )->execute(
	array( 'attachment_id' => 42 )
);

// The other four abilities:
// kilobyte/get-settings      kilobyte/update-settings
// kilobyte/restore-image     kilobyte/regenerate-thumbnails`,
		},
		{
			name: 'cli',
			title: 'WP-CLI',
			filename: 'wp kilobyte',
			snippet: `# See how much your Media Library has saved.
wp kilobyte stats
wp kilobyte stats --reconcile

# Optimize the next 100 images that still need it.
wp kilobyte optimize --limit=100

# Restore one image, or every image.
wp kilobyte restore 42
wp kilobyte restore --all

# Clear old backup records after you archive them.
wp kilobyte purge-backups`,
		},
		{
			name: 'hooks',
			title: 'PHP hooks',
			filename: 'functions.php',
			snippet: `// Lock settings in code across client sites.
add_filter(
	'kilobyte_io_settings',
	static function ( array $settings ): array {
		$settings['format']  = 'webp';
		$settings['quality'] = 80;
		return $settings;
	}
);

// Skip chosen images.
add_filter(
	'kilobyte_io_should_optimize',
	static function ( bool $run, int $id ): bool {
		return $run && ! get_post_meta( $id, '_keep_original', true );
	},
	10,
	2
);

// Purge your page cache when a copy is created or restored.
add_action(
	'kilobyte_io_variant_changed',
	static function ( int $attachment_id ): void {
		// Call your host or cache plugin here.
	}
);`,
		},
	];

	const faq = [
		{
			q: 'Is Kilobyte really free and unlimited?',
			a: 'Yes. Kilobyte is free, has no limits, and needs no account. Images are compressed in your own browser, or by your WordPress site’s image editor, so there are no compression servers for anyone to pay for.',
		},
		{
			q: 'Will it overwrite my original photos or break cached pages?',
			a: 'No. Kilobyte keeps your original files and their links, and saves each smaller WebP or AVIF image as a separate copy. Browsers that cannot show the copy get the original. If you restore an image, the old copies stay available for at least 24 hours, so cached pages keep working. If your page cache keeps pages for longer than a day, clear the affected pages after you restore.',
		},
		{
			q: 'What happens to animated GIFs, APNGs, and animated WebPs?',
			a: 'Kilobyte checks each image before it compresses anything. Animated GIFs, APNGs, and animated WebPs are left exactly as they are, so the animation keeps working. Still GIFs can get smaller WebP or AVIF copies.',
		},
		{
			q: 'What does my site need to run Kilobyte?',
			a: 'WordPress 6.6 or newer and PHP 7.4 or newer. Your host also needs GD or Imagick, the standard WordPress image editors, which almost every host has. Kilobyte does not need exec() or any extra server software.',
		},
		{
			q: 'Can an AI assistant use Kilobyte?',
			a: 'Yes. On WordPress 6.9 and newer, Kilobyte registers seven abilities through the WordPress Abilities API. An AI assistant or MCP adapter connected to your site can read savings, list the images still waiting, optimize or restore an image, regenerate thumbnails, and change settings. WordPress checks the user’s permissions on every call.',
		},
	];

	const featuredTools = [
		getToolBySlug( 'compress-image' ),
		getToolBySlug( 'compress-png' ),
		getToolBySlug( 'jpg-to-webp' ),
		getToolBySlug( 'jpg-to-avif' ),
		getToolBySlug( 'resize-image' ),
		getToolBySlug( 'bulk-image-compressor' ),
	].filter( Boolean ) as ToolDefinition[];

	const body = `${ renderTopbar( '/' ) }
<main id="main-content">
	<section class="kb-hero">
		<div class="kb-band kb-hero-band">
			<div class="kb-container">
				<h1 class="kb-display">Shrink WordPress images to WebP <span class="kb-amp">&amp;</span> AVIF.</h1>
				<p class="kb-quiet">Your original files stay untouched.</p>
				<p class="kb-lead">Kilobyte makes smaller WebP and AVIF copies of the images in your Media Library. Your original files and existing links stay exactly as they are, and you can test one photo before you optimize the rest.</p>
				<div class="kb-actions">
					<a class="kb-btn kb-btn-primary kb-btn-lg" href="${ esc( WP_ORG_URL ) }" target="_blank" rel="noopener noreferrer">${ ICONS.download }<span>Download on WordPress.org</span></a>
					<a class="kb-btn kb-btn-secondary kb-btn-lg" href="${ esc( PLAYGROUND_URL ) }" target="_blank" rel="noopener noreferrer">${ ICONS.external }<span>Try it in a demo site</span></a>
				</div>
				<p class="kb-small">The demo site is a real WordPress site that runs in your browser, with Kilobyte and 3 sample images already set up.</p>
				<div class="kb-hero-alt">
					<a class="kb-hero-alt-trigger" href="/tools/" id="kb-open-tools-modal" aria-haspopup="dialog" aria-controls="kb-tools-dialog">
						<span class="kb-hero-alt-eyebrow">Not on WordPress?</span>
						<span class="kb-hero-alt-action">Use our free browser image tools ${ ICONS.arrowRight }</span>
					</a>
				</div>
			</div>
		</div>

		<div class="kb-container kb-hero-media">
			<div class="kb-banner">
				<img src="/assets/banner-1544x500.webp" alt="A Renaissance-style painting of a scholar who holds up a light 507 KB picture next to a heavy 3.81 MB stone slab bound in chains" width="1544" height="500" fetchpriority="high" />
			</div>

			<div class="kb-grid kb-tiles">
				<article class="kb-card kb-tile">
					<h2 class="kb-h3">Free, with no limits</h2>
					<p class="kb-body">Optimize as many images as you like. There is no account, no API key, and no monthly quota.</p>
				</article>
				<article class="kb-card kb-tile">
					<h2 class="kb-h3">Your originals are kept</h2>
					<p class="kb-body">Kilobyte saves each smaller image as a separate copy. Your original files and their links stay the same.</p>
				</article>
				<article class="kb-card kb-tile">
					<h2 class="kb-h3">Works with AI assistants</h2>
					<p class="kb-body">An AI assistant connected to your site can check savings, optimize images, and restore them for you.</p>
				</article>
			</div>
		</div>
	</section>

	<section id="demo" class="kb-section kb-section-wash">
		<div class="kb-container">
			<div class="kb-grid kb-head">
				<p class="kb-kicker">Try it on this page</p>
				<h2 class="kb-title">See how much smaller your own photo gets.</h2>
				<p class="kb-lead">Choose a photo, or drop one onto the card below. The compression runs here in your browser, so the photo never leaves your device. Drag the handle across the picture to compare the original with the smaller copy.</p>
			</div>

			${ renderToolWidgetHtml( {
				id: 'kb-home-lab',
				slug: 'home-demo',
				title: 'Quality preview',
				headingTag: 'h3',
				accept: 'image/jpeg,image/png,image/webp,image/avif,image/gif,image/bmp',
				acceptLabel: 'JPEG, PNG, WebP, AVIF, still GIF, or BMP (up to 24 MB / 40 MP)',
				defaultOutputMime: 'image/webp',
				allowedOutputMimes: [ 'image/webp', 'image/avif', 'image/jpeg', 'image/png' ],
				defaultQuality: 82,
				showQuality: false,
				showPresets: true,
				showPngMode: true,
				defaultPngMode: 'lossy',
				showResize: false,
				batchFocused: false,
				checkerboard: true,
				sampleUrl: '/assets/sample-photo.jpg',
				autoLoadSample: true,
				workerUrl: assets.toolWorkerJs,
				showWpCalloutAfter: false,
			} ) }
		</div>
	</section>

	<section class="kb-section">
		<div class="kb-container">
			<div class="kb-grid kb-head">
				<p class="kb-kicker">Standalone browser utilities</p>
				<h2 class="kb-title">Need a quick image tool without WordPress?</h2>
				<p class="kb-lead">Use Kilobyte’s free local image utilities directly in your browser—built on the same local browser core, extended with optional WebAssembly codecs for PNG, WebP, and AVIF. Zero uploads, no accounts, and batch ZIP export.</p>
			</div>

			<div class="kb-tools-grid">
				${ featuredTools.map( ( tool ) => `<a class="kb-tool-card" href="/tools/${ esc( tool.slug ) }/">
					<div>
						<h3 class="kb-h3">${ esc( tool.name ) }</h3>
						<p class="kb-body kb-tool-card-summary">${ esc( tool.cardSummary ) }</p>
					</div>
					<span class="kb-tool-card-meta"><span>Open tool</span>${ ICONS.arrowRight }</span>
				</a>` ).join( '\n' ) }
			</div>

			<div class="kb-tools-cta">
				<a class="kb-btn kb-btn-secondary" href="/tools/"><span>Browse all ${ TOOLS.length } browser tools</span>${ ICONS.arrowRight }</a>
			</div>
		</div>
	</section>

	<section id="why" class="kb-section kb-section-wash">
		<div class="kb-container">
			<div class="kb-grid kb-head">
				<p class="kb-kicker">Why Kilobyte</p>
				<h2 class="kb-title">Designed to feel like a missing WordPress Core feature.</h2>
				<p class="kb-lead">Kilobyte adds one screen under Media in your WordPress admin. It shows no ads, asks for no account, and never changes your original files.</p>
			</div>

			<div class="kb-grid kb-why">
				<div class="kb-why-item">
					${ ICONS.plugins }
					<h3 class="kb-h3">Works on activation</h3>
					<p class="kb-body">There is no account to create and no API key to paste. Images are compressed in your browser, or by the image editor your WordPress site already has.</p>
				</div>
				<div class="kb-why-item">
					${ ICONS.brush }
					<h3 class="kb-h3">No ads or upsells</h3>
					<p class="kb-body">Kilobyte shows no upgrade banners. Every setting you see is included and free.</p>
				</div>
				<div class="kb-why-item">
					${ ICONS.pages }
					<h3 class="kb-h3">Looks like WordPress</h3>
					<p class="kb-body">The Kilobyte screen is built from WordPress’s own interface components, so it looks and behaves like the rest of your admin.</p>
				</div>
				<div class="kb-why-item">
					${ ICONS.shield }
					<h3 class="kb-h3">Safe by default</h3>
					<p class="kb-body">Browsers that cannot show WebP or AVIF get your original image. If you restore an image, the old copies stay available for at least 24 hours, so cached pages keep working.</p>
				</div>
			</div>

			<dl class="kb-specs-strip" aria-label="WordPress plugin specifications">
				<div>
					<dt>Input formats</dt>
					<dd>JPEG, PNG, BMP, static GIF</dd>
				</div>
				<div>
					<dt>Output copies</dt>
					<dd>WebP &amp; AVIF (originals kept)</dd>
				</div>
				<div>
					<dt>Browser safety cap</dt>
					<dd>24 MB / 40 MP (server fallback)</dd>
				</div>
				<div>
					<dt>Requirements</dt>
					<dd>WordPress 6.6+, PHP 7.4+, GD/Imagick</dd>
				</div>
				<div>
					<dt>License &amp; privacy</dt>
					<dd>GPL-2.0+, zero third-party uploads</dd>
				</div>
			</dl>
		</div>
	</section>

	<section id="screens" class="kb-section">
		<div class="kb-container">
			<div class="kb-grid kb-head">
				<p class="kb-kicker">Inside the plugin</p>
				<h2 class="kb-title">See every Kilobyte screen before you install.</h2>
				<p class="kb-lead">Kilobyte has four tabs, and it adds one line to each image in your Media Library. Select a picture to view it full size.</p>
			</div>

			<div class="kb-grid">
				<ul class="kb-screen-list">
					${ screens.map( ( screen, idx ) => `<li>
						<button type="button" class="kb-screen-tab" data-screen-tab="${ esc( screen.id ) }" data-src="${ esc( screen.src ) }" data-alt="${ esc( screen.alt ) }" data-title="${ esc( screen.title ) }" data-body="${ esc( screen.body ) }" data-width="${ screen.width }" data-height="${ screen.height }" aria-pressed="${ idx === 0 ? 'true' : 'false' }">
							<span class="kb-h3">${ esc( screen.title ) }</span>
							<span class="kb-small">${ esc( screen.body ) }</span>
						</button>
						<button type="button" class="kb-screen-inline" data-open-modal="${ esc( screen.id ) }" aria-label="View the ${ esc( screen.title ) } screen full size">
							<img src="${ esc( screen.src ) }" alt="${ esc( screen.alt ) }" width="${ screen.width }" height="${ screen.height }" loading="lazy" />
						</button>
					</li>` ).join( '\n' ) }
				</ul>

				<button type="button" class="kb-screen-frame" id="kb-screen-frame" aria-label="View the ${ esc( screens[ 0 ].title ) } screen full size">
					<img id="kb-screen-frame-img" src="${ esc( screens[ 0 ].src ) }" alt="${ esc( screens[ 0 ].alt ) }" width="${ screens[ 0 ].width }" height="${ screens[ 0 ].height }" loading="lazy" />
				</button>
			</div>
		</div>
	</section>

	<section id="developers" class="kb-section kb-section-wash">
		<div class="kb-container kb-grid">
			<div class="kb-dev-text">
				<p class="kb-kicker">For developers and AI assistants</p>
				<h2 class="kb-title">Run Kilobyte from code, the command line, or an AI assistant.</h2>
				<p class="kb-lead">Kilobyte follows the WordPress VIP Go coding standards, loads no Composer packages in production, and is covered by more than 510 integration tests that run against real WordPress.</p>

				<dl class="kb-dev-list">
					<div>
						<dt>WordPress Abilities API</dt>
						<dd class="kb-body">On WordPress 6.9 and newer, Kilobyte registers seven abilities that AI assistants and MCP adapters can call. Each one has a JSON schema and checks the user’s permission for the image.</dd>
					</div>
					<div>
						<dt>WP-CLI</dt>
						<dd class="kb-body">Check savings, optimize in batches, restore images, and clear old backup records from your terminal or deploy scripts.</dd>
					</div>
					<div>
						<dt>PHP hooks</dt>
						<dd class="kb-body">Lock settings in code across client sites, skip chosen images, and purge your page cache when a copy is created or restored.</dd>
					</div>
				</dl>
			</div>

			<div class="kb-card kb-code-card" id="kb-code-tabs">
				<div class="kb-tabs" role="tablist" aria-label="Developer interfaces">
					${ codeTabs.map( ( tab, idx ) => `<button type="button" class="kb-tab-btn" role="tab" id="tab-${ esc( tab.name ) }" aria-controls="panel-${ esc( tab.name ) }" aria-selected="${ idx === 0 ? 'true' : 'false' }" tabindex="${ idx === 0 ? '0' : '-1' }" data-code-tab="${ esc( tab.name ) }">${ esc( tab.title ) }</button>` ).join( '' ) }
				</div>
				<div id="kb-copy-status" class="sr-only" role="status" aria-live="polite"></div>
				${ codeTabs.map( ( tab, idx ) => `<div class="kb-tab-panel" role="tabpanel" id="panel-${ esc( tab.name ) }" aria-labelledby="tab-${ esc( tab.name ) }" data-code-panel="${ esc( tab.name ) }"${ idx === 0 ? '' : ' hidden' }>
					<div class="kb-code-toolbar">
						<span class="kb-code-filename">${ esc( tab.filename ) }</span>
						<button type="button" class="kb-btn kb-btn-ghost kb-code-copy" data-copy-code="${ esc( tab.name ) }"><span data-icon="copy">${ ICONS.copy }</span><span data-icon="check" hidden>${ ICONS.check }</span><span>Copy code</span></button>
					</div>
					<pre class="kb-code-pre" tabindex="0" aria-label="${ esc( tab.title ) } code example"><code id="code-${ esc( tab.name ) }">${ esc( tab.snippet ) }</code></pre>
				</div>` ).join( '' ) }
			</div>
		</div>
	</section>

	<section id="questions" class="kb-section">
		<div class="kb-container kb-grid">
			<div class="kb-faq-head">
				<p class="kb-kicker">Questions</p>
				<h2 class="kb-title">What people ask before they install.</h2>
			</div>

			<div class="kb-faq-list">
				${ faq.map( ( item, idx ) => `<details class="kb-faq-item"${ idx === 0 ? ' open' : '' }>
					<summary>
						<span class="kb-h3">${ esc( item.q ) }</span>
						${ ICONS.chevronDown }
					</summary>
					<p class="kb-body">${ esc( item.a ) }</p>
				</details>` ).join( '\n' ) }
			</div>
		</div>
	</section>

	<section class="kb-band kb-close-band">
		<div class="kb-container">
			<h2 class="kb-title">Make your images smaller today, and keep every original.</h2>
			<p class="kb-lead">Install Kilobyte Image Optimizer from WordPress.org, or try it first in a demo WordPress site that runs in your browser.</p>
			<div class="kb-actions">
				<a class="kb-btn kb-btn-primary kb-btn-lg" href="${ esc( WP_ORG_URL ) }" target="_blank" rel="noopener noreferrer">${ ICONS.download }<span>Download on WordPress.org</span></a>
				<a class="kb-btn kb-btn-secondary kb-btn-lg" href="${ esc( PLAYGROUND_URL ) }" target="_blank" rel="noopener noreferrer">${ ICONS.external }<span>Try it in a demo site</span></a>
			</div>
		</div>
	</section>
</main>
${ renderFooter() }
<dialog class="kb-dialog" id="kb-screen-dialog" aria-labelledby="kb-dialog-title">
	<div class="kb-dialog-head">
		<h2 class="kb-h3" id="kb-dialog-title">Screen preview</h2>
		<button type="button" class="kb-btn kb-btn-ghost" id="kb-dialog-close" aria-label="Close preview">${ ICONS.close }<span>Close</span></button>
	</div>
	<div class="kb-dialog-body">
		<img id="kb-dialog-img" class="kb-modal-img" src="${ TRANSPARENT_PIXEL }" width="1" height="1" alt="" />
		<p class="kb-small" id="kb-dialog-caption"></p>
	</div>
</dialog>
<dialog class="kb-dialog kb-tools-dialog" id="kb-tools-dialog" aria-labelledby="kb-tools-dialog-title" aria-describedby="kb-tools-dialog-desc">
	<div class="kb-dialog-head kb-tools-dialog-head">
		<div class="kb-tools-dialog-intro">
			<p class="kb-kicker">Browser utilities · 100% local</p>
			<h2 class="kb-tools-dialog-title" id="kb-tools-dialog-title">What would you like to do?</h2>
			<p class="kb-small" id="kb-tools-dialog-desc">Every tool runs locally in your browser — no uploads, no account, and no file limits.</p>
		</div>
		<button type="button" class="kb-btn kb-btn-ghost kb-tools-dialog-close" id="kb-tools-dialog-close" aria-label="Close tools picker">${ ICONS.close }<span>Close</span></button>
	</div>
	<div class="kb-dialog-body kb-tools-dialog-body">
		${ [
			{
				label: 'Compress',
				items: TOOLS.filter( ( t ) => t.category === 'compress' ),
			},
			{
				label: 'Convert format',
				items: TOOLS.filter( ( t ) => t.category === 'convert' ),
			},
			{
				label: 'Resize & batch',
				items: TOOLS.filter( ( t ) => t.category === 'resize' || t.category === 'bulk' ),
			},
		].map( ( group ) => `<section class="kb-picker-group">
			<h3 class="kb-picker-group-label">${ esc( group.label ) }</h3>
			<div class="kb-picker-grid${ group.items.length === 3 ? ' is-three' : '' }">
				${ group.items.map( ( tool ) => {
					const badge = tool.slug === 'compress-image'
						? 'Any'
						: tool.category === 'bulk'
							? 'ZIP batch'
							: tool.category === 'resize'
								? 'Resize'
								: labelForMime( tool.defaultOutputMime );
					return `<a class="kb-picker-card" href="/tools/${ esc( tool.slug ) }/">
					<span class="kb-picker-card-top">
						<span class="kb-picker-card-title">${ esc( tool.name ) }</span>
						<span class="kb-picker-card-meta">
							<span class="kb-picker-badge">${ esc( badge ) }</span>
							<span class="kb-picker-arrow">${ ICONS.arrowRight }</span>
						</span>
					</span>
					<span class="kb-picker-card-desc">${ esc( tool.cardSummary ) }</span>
				</a>`;
				} ).join( '\n\t\t\t\t' ) }
			</div>
		</section>` ).join( '\n\t\t' ) }
	</div>
	<div class="kb-dialog-foot">
		<p class="kb-small kb-dialog-foot-note">${ ICONS.shield }<span>Files never leave your device.</span></p>
		<a class="kb-btn kb-btn-secondary" href="/tools/"><span>Browse all ${ TOOLS.length } tools</span>${ ICONS.arrowRight }</a>
	</div>
</dialog>`;

	const homeDescription = 'Free WordPress plugin and local browser tools to shrink images to WebP and AVIF. Keeps original Media Library files untouched with zero quotas or uploads.';

	return renderDocument(
		{
			title: 'Kilobyte — Free WebP & AVIF Image Optimizer for WordPress',
			description: homeDescription,
			canonicalPath: '/',
			cssPath: assets.siteCss,
			scriptPath: assets.homeJs,
			preloadBanner: true,
			jsonLd: [
				{
					'@context': 'https://schema.org',
					'@type': 'WebSite',
					'@id': `${ SITE_ORIGIN }/#website`,
					name: 'Kilobyte',
					url: `${ SITE_ORIGIN }/`,
					description: 'Free, browser-powered WebP, AVIF, JPEG, and PNG image optimization utilities and WordPress plugin.',
					publisher: AUTHOR_ENTITY,
				},
				{
					'@context': 'https://schema.org',
					'@type': 'SoftwareApplication',
					'@id': `${ SITE_ORIGIN }/#software`,
					name: 'Kilobyte Image Optimizer',
					description: homeDescription,
					softwareVersion: '1.1.0',
					applicationCategory: 'MultimediaApplication',
					operatingSystem: 'WordPress 6.6+, Web',
					url: `${ SITE_ORIGIN }/`,
					installUrl: WP_ORG_URL,
					image: `${ SITE_ORIGIN }${ DEFAULT_OG_IMAGE }`,
					sameAs: [ WP_ORG_URL, GITHUB_URL ],
					author: AUTHOR_ENTITY,
					isAccessibleForFree: true,
					license: 'https://www.gnu.org/licenses/gpl-2.0.html',
					offers: {
						'@type': 'Offer',
						price: '0',
						priceCurrency: 'USD',
					},
				},
				buildFaqSchema( faq ),
			],
		},
		body
	);
}

export function renderToolsDirectoryPage( assets: AssetManifest ): string {
	const groupsHtml = TOOL_CATEGORIES.map( ( cat ) => {
		const toolsInCategory = TOOLS.filter( ( t ) => t.category === cat.id );
		return `<section class="kb-tools-group" id="cat-${ esc( cat.id ) }">
			<div class="kb-tools-group-head">
				<h2 class="kb-title kb-tools-group-title">${ esc( cat.label ) }</h2>
				<p class="kb-body">${ esc( cat.description ) }</p>
			</div>
			<div class="kb-tools-grid">
				${ toolsInCategory.map( ( tool ) => `<a class="kb-tool-card" href="/tools/${ esc( tool.slug ) }/">
					<div>
						<h3 class="kb-h3">${ esc( tool.name ) }</h3>
						<p class="kb-body kb-tool-card-summary">${ esc( tool.cardSummary ) }</p>
					</div>
					<span class="kb-tool-card-meta"><span>Open ${ esc( tool.name ) }</span>${ ICONS.arrowRight }</span>
				</a>` ).join( '\n' ) }
			</div>
		</section>`;
	} ).join( '\n' );

	const body = `${ renderTopbar( '/tools/' ) }
<main id="main-content">
	<section class="kb-tool-hero">
		<div class="kb-container">
			<nav class="kb-breadcrumbs" aria-label="Breadcrumb">
				<a href="/">Kilobyte</a>
				<span aria-hidden="true">/</span>
				<span aria-current="page">Tools</span>
			</nav>
			<div class="kb-tool-hero-head">
				<h1>Free browser-based image optimization tools.</h1>
				<p class="kb-lead">Compress JPEG and PNG files, convert images to WebP and AVIF, resize exact pixel dimensions, or batch-process folders into a ZIP archive. Every utility runs locally on your device with zero uploads, no accounts, and no quotas.</p>
			</div>
		</div>
	</section>

	<section class="kb-section kb-section-compact">
		<div class="kb-container">
			${ groupsHtml }

			<aside class="kb-wp-callout kb-wp-callout-spaced" aria-label="Kilobyte for WordPress">
				<p><strong>Running a WordPress website?</strong> Kilobyte automatically optimizes your images as you upload them, keeping every original Media Library file and link intact.</p>
				<div class="kb-lab-buttons">
					<a class="kb-btn kb-btn-primary" href="${ esc( WP_ORG_URL ) }" target="_blank" rel="noopener noreferrer">${ ICONS.download }<span>Download on WordPress.org</span></a>
					<a class="kb-btn kb-btn-secondary" href="/"><span>Explore the WordPress plugin</span></a>
				</div>
			</aside>
		</div>
	</section>
</main>
${ renderFooter() }`;

	return renderDocument(
		{
			title: 'Free Online Image Optimization Tools | Kilobyte',
			description: 'Compress JPEG and PNG images, convert JPG and PNG to WebP or AVIF, resize exact dimensions, and batch compress into ZIP—locally with zero uploads.',
			canonicalPath: '/tools/',
			cssPath: assets.siteCss,
			scriptPath: assets.navJs,
			jsonLd: [
				{
					'@context': 'https://schema.org',
					'@type': 'CollectionPage',
					'@id': `${ SITE_ORIGIN }/tools/#collection`,
					name: 'Kilobyte Browser Image Optimization Tools',
					url: `${ SITE_ORIGIN }/tools/`,
					description: 'Standalone browser-based image compression, format conversion, resizing, and batch optimization utilities that run locally with zero uploads.',
					isPartOf: { '@id': `${ SITE_ORIGIN }/#website` },
					mainEntity: {
						'@type': 'ItemList',
						itemListElement: TOOLS.map( ( tool, index ) => ( {
							'@type': 'ListItem',
							position: index + 1,
							name: tool.name,
							url: `${ SITE_ORIGIN }/tools/${ tool.slug }/`,
						} ) ),
					},
				},
				{
					'@context': 'https://schema.org',
					'@type': 'BreadcrumbList',
					itemListElement: [
						{ '@type': 'ListItem', position: 1, name: 'Kilobyte', item: `${ SITE_ORIGIN }/` },
						{ '@type': 'ListItem', position: 2, name: 'Tools', item: `${ SITE_ORIGIN }/tools/` },
					],
				},
			],
		},
		body
	);
}

export function renderToolPage( tool: ToolDefinition, assets: AssetManifest ): string {
	const relatedTools = tool.relatedSlugs
		.map( ( slug ) => getToolBySlug( slug ) )
		.filter( Boolean ) as ToolDefinition[];

	const outputLabels = tool.allowedOutputMimes.map( ( m ) => labelForMime( m ) ).join( ', ' );
	const currentPath = `/tools/${ tool.slug }/`;

	const body = `${ renderTopbar( currentPath ) }
<main id="main-content">
	<section class="kb-tool-hero">
		<div class="kb-container">
			<nav class="kb-breadcrumbs" aria-label="Breadcrumb">
				<a href="/">Kilobyte</a>
				<span aria-hidden="true">/</span>
				<a href="/tools/">Tools</a>
				<span aria-hidden="true">/</span>
				<span aria-current="page">${ esc( tool.name ) }</span>
			</nav>

			<div class="kb-tool-hero-head">
				<h1>${ esc( tool.h1 ) }</h1>
				<p class="kb-lead">${ esc( tool.lead ) }</p>
			</div>

			${ renderToolWidgetHtml( {
				id: `kb-tool-${ tool.slug }`,
				slug: tool.slug,
				title: tool.name,
				headingTag: 'h2',
				accept: tool.accept,
				acceptLabel: tool.acceptLabel,
				defaultOutputMime: tool.defaultOutputMime,
				allowedOutputMimes: tool.allowedOutputMimes,
				defaultQuality: tool.defaultQuality,
				showQuality: tool.showQuality,
				showPresets: tool.showPresets,
				showPngMode: tool.showPngMode,
				defaultPngMode: tool.defaultPngMode,
				showResize: tool.showResize,
				batchFocused: tool.batchFocused,
				checkerboard: tool.checkerboard,
				sampleUrl: tool.sampleUrl,
				autoLoadSample: tool.autoLoadSample,
				workerUrl: assets.toolWorkerJs,
				showWpCalloutAfter: true,
			} ) }
		</div>
	</section>

	<section class="kb-section kb-section-compact">
		<div class="kb-container kb-guide-grid">
			<div class="kb-guide-main">
				<article class="kb-guide-block">
					<h2>How to use ${ esc( tool.name ) }</h2>
					<ol class="kb-steps-list">
						${ tool.howToSteps.map( ( step ) => `<li>${ esc( step ) }</li>` ).join( '\n' ) }
					</ol>
				</article>

				${ tool.guideSections.map( ( section ) => `<article class="kb-guide-block">
					<h2>${ esc( section.heading ) }</h2>
					${ section.paragraphs.map( ( p ) => `<p class="kb-body">${ esc( p ) }</p>` ).join( '\n' ) }
					${ section.bullets?.length
						? `<ul class="kb-bullets-list">${ section.bullets.map( ( b ) => `<li>${ esc( b ) }</li>` ).join( '' ) }</ul>`
						: '' }
				</article>` ).join( '\n' ) }

				<article class="kb-guide-block">
					<h2>Honest capabilities and browser limits</h2>
					<ul class="kb-bullets-list">
						${ tool.limitations.map( ( lim ) => `<li>${ esc( lim ) }</li>` ).join( '\n' ) }
					</ul>
				</article>
			</div>

			<aside class="kb-guide-aside" aria-label="${ esc( tool.name ) } specifications and related utilities">
				<div class="kb-aside-card">
					<h2>Tool specifications</h2>
					<dl class="kb-spec-list">
						<div>
							<dt>Accepted inputs</dt>
							<dd>${ esc( tool.acceptLabel ) }</dd>
						</div>
						<div>
							<dt>Output format${ tool.allowedOutputMimes.length > 1 ? 's' : '' }</dt>
							<dd>${ esc( outputLabels ) }</dd>
						</div>
						<div>
							<dt>Local encoder</dt>
							<dd>${ esc( tool.codecSummary ) }</dd>
						</div>
						<div>
							<dt>Privacy</dt>
							<dd>100% in-browser; zero uploads, accounts, or quotas</dd>
						</div>
						<div>
							<dt>Safety bounds</dt>
							<dd>24 MB, 40 MP, 16,384 px/side; 150 MB ZIP</dd>
						</div>
					</dl>
				</div>

				<div class="kb-aside-card">
					<h2>Related utilities</h2>
					<ul class="kb-related-links">
						${ relatedTools.map( ( rel ) => `<li><a href="/tools/${ esc( rel.slug ) }/"><span>${ esc( rel.name ) }</span>${ ICONS.arrowRight }</a></li>` ).join( '\n' ) }
						<li><a href="/tools/"><span>View all ${ TOOLS.length } tools</span>${ ICONS.arrowRight }</a></li>
					</ul>
				</div>
			</aside>
		</div>
	</section>

	<section class="kb-section kb-section-compact kb-section-wash">
		<div class="kb-container kb-grid">
			<div class="kb-faq-head">
				<p class="kb-kicker">Questions</p>
				<h2 class="kb-title">${ esc( tool.name ) } FAQ</h2>
			</div>
			<div class="kb-faq-list">
				${ tool.faq.map( ( item, idx ) => `<details class="kb-faq-item"${ idx === 0 ? ' open' : '' }>
					<summary>
						<span class="kb-h3">${ esc( item.q ) }</span>
						${ ICONS.chevronDown }
					</summary>
					<p class="kb-body">${ esc( item.a ) }</p>
				</details>` ).join( '\n' ) }
			</div>
		</div>
	</section>
</main>
${ renderFooter() }`;

	return renderDocument(
		{
			title: tool.title,
			description: tool.description,
			canonicalPath: currentPath,
			cssPath: assets.siteCss,
			scriptPath: assets.toolJs,
			jsonLd: [
				{
					'@context': 'https://schema.org',
					'@type': 'WebApplication',
					'@id': `${ SITE_ORIGIN }${ currentPath }#app`,
					name: `Kilobyte ${ tool.name }`,
					url: `${ SITE_ORIGIN }${ currentPath }`,
					description: tool.description,
					applicationCategory: 'MultimediaApplication',
					operatingSystem: 'Any (Web Browser)',
					browserRequirements: 'Requires JavaScript and HTML5 Canvas / WebAssembly support.',
					image: `${ SITE_ORIGIN }${ DEFAULT_OG_IMAGE }`,
					license: 'https://www.gnu.org/licenses/gpl-2.0.html',
					author: AUTHOR_ENTITY,
					isPartOf: { '@id': `${ SITE_ORIGIN }/#website` },
					featureList: [
						'100% local in-browser processing with zero file uploads',
						`Output formats: ${ outputLabels }`,
						`Encoder: ${ tool.codecSummary }`,
						'Real measured byte deltas and output signature verification',
					],
					isAccessibleForFree: true,
					offers: {
						'@type': 'Offer',
						price: '0',
						priceCurrency: 'USD',
					},
				},
				{
					'@context': 'https://schema.org',
					'@type': 'BreadcrumbList',
					itemListElement: [
						{ '@type': 'ListItem', position: 1, name: 'Kilobyte', item: `${ SITE_ORIGIN }/` },
						{ '@type': 'ListItem', position: 2, name: 'Tools', item: `${ SITE_ORIGIN }/tools/` },
						{ '@type': 'ListItem', position: 3, name: tool.name, item: `${ SITE_ORIGIN }${ currentPath }` },
					],
				},
				buildFaqSchema( tool.faq ),
			],
		},
		body
	);
}

export function renderNotFoundPage( assets: AssetManifest ): string {
	const body = `${ renderTopbar( '/404.html' ) }
<main id="main-content">
	<section class="kb-section">
		<div class="kb-container kb-notfound">
			<p class="kb-kicker">Error 404</p>
			<h1 class="kb-title kb-notfound-title">Page not found.</h1>
			<p class="kb-lead kb-notfound-lead">The URL you requested does not exist on Kilobyte. You can return to the WordPress plugin page or browse our free browser image utilities.</p>
			<div class="kb-lab-buttons kb-notfound-actions">
				<a class="kb-btn kb-btn-primary kb-btn-lg" href="/">Go to homepage</a>
				<a class="kb-btn kb-btn-secondary kb-btn-lg" href="/tools/">Browse image tools</a>
			</div>
		</div>
	</section>
</main>
${ renderFooter() }`;

	return renderDocument(
		{
			title: 'Page Not Found | Kilobyte',
			description: 'The requested page could not be found on Kilobyte.',
			cssPath: assets.siteCss,
			scriptPath: assets.navJs,
			noindex: true,
		},
		body
	);
}

export function renderSitemapXml( lastMod: string ): string {
	const urls = [
		`${ SITE_ORIGIN }/`,
		`${ SITE_ORIGIN }/tools/`,
		...TOOLS.map( ( tool ) => `${ SITE_ORIGIN }/tools/${ tool.slug }/` ),
	];
	const items = urls
		.map( ( loc ) => `\t<url>\n\t\t<loc>${ esc( loc ) }</loc>\n\t\t<lastmod>${ esc( lastMod ) }</lastmod>\n\t</url>` )
		.join( '\n' );
	return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${ items }
</urlset>
`;
}

export function renderRobotsTxt(): string {
	return `User-agent: *
Allow: /

Sitemap: ${ SITE_ORIGIN }/sitemap.xml
`;
}

export function renderLlmsTxt(): string {
	const toolLines = TOOLS.map(
		( t ) => `- [${ t.name }](${ SITE_ORIGIN }/tools/${ t.slug }/): ${ t.cardSummary }`
	).join( '\n' );

	return `# Kilobyte Image Optimizer

> Free, open-source (GPL-2.0+) browser-powered image optimization tools and WordPress plugin. Zero image uploads, no accounts, no quotas, and verified output file signatures.

## Overview
- Website: ${ SITE_ORIGIN }/
- WordPress.org Plugin: ${ WP_ORG_URL }
- Source Code: ${ GITHUB_URL }
- Version: 1.1.0 (WordPress 6.6+, PHP 7.4+, GD or Imagick)

## Privacy & Architecture
- Standalone web tools run 100% locally in browser Web Workers using native Canvas encoders plus lazy-loaded, self-hosted single-threaded WebAssembly codecs (UPNG, OxiPNG, libwebp, libaom AVIF).
- Enforced zero-upload policy: no image bytes leave the user's device.
- Resource safety caps: 24 MB, 40 megapixels, 16,384 px per side per image; 150 MiB per client-side ZIP archive.
- Animated GIFs, APNGs, and animated WebP/AVIF files are skipped rather than flattened.

## Standalone Browser Tools
- [Tools Directory](${ SITE_ORIGIN }/tools/): Overview of all ${ TOOLS.length } local browser utilities.
${ toolLines }

## WordPress Plugin Automation
- Non-destructive: keeps original Media Library files and URLs untouched, serving WebP/AVIF copies safely via <picture> fallbacks.
- WordPress Abilities API (WP 6.9+): \`kilobyte/get-stats\`, \`kilobyte/get-queue\`, \`kilobyte/optimize-image\`, \`kilobyte/restore-image\`, \`kilobyte/regenerate-thumbnails\`, \`kilobyte/get-settings\`, \`kilobyte/update-settings\`.
- WP-CLI: \`wp kilobyte stats\`, \`wp kilobyte optimize\`, \`wp kilobyte restore\`, \`wp kilobyte purge-backups\`.
- PHP Hooks: \`kilobyte_io_settings\`, \`kilobyte_io_should_optimize\`, \`kilobyte_io_variant_changed\`.
`;
}
