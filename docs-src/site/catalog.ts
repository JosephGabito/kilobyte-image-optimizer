import type { EncodableOutputMime, PngOptimizationMode } from '../../src-js/core/types';

export type ToolCategory = 'compress' | 'convert' | 'resize' | 'bulk';

export interface ToolGuideSection {
	heading: string;
	paragraphs: string[];
	bullets?: string[];
}

export interface ToolFaqItem {
	q: string;
	a: string;
}

export interface ToolDefinition {
	slug: string;
	name: string;
	shortName: string;
	category: ToolCategory;
	title: string;
	description: string;
	h1: string;
	lead: string;
	cardSummary: string;
	accept: string;
	acceptLabel: string;
	codecSummary: string;
	defaultOutputMime: EncodableOutputMime;
	allowedOutputMimes: EncodableOutputMime[];
	defaultQuality: number;
	showQuality: boolean;
	showPresets: boolean;
	showPngMode: boolean;
	defaultPngMode?: PngOptimizationMode;
	showResize: boolean;
	batchFocused: boolean;
	checkerboard: boolean;
	sampleUrl?: string;
	autoLoadSample?: boolean;
	relatedSlugs: string[];
	howToSteps: string[];
	guideSections: ToolGuideSection[];
	limitations: string[];
	faq: ToolFaqItem[];
}

export const SITE_ORIGIN = 'https://kilobyte.gabi.to';
/** Explicit deterministic content release date for sitemap.xml <lastmod>. */
export const SITE_CONTENT_LASTMOD = '2026-11-25';
export const WP_ORG_URL = 'https://wordpress.org/plugins/kilobyte-image-optimizer/';
export const PLAYGROUND_URL =
	'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/JosephGabito/kilobyte-image-optimizer/main/.wordpress-org/blueprints/blueprint.json';
export const GITHUB_URL = 'https://github.com/JosephGabito/kilobyte-image-optimizer';
export const SUPPORT_URL = 'https://wordpress.org/support/plugin/kilobyte-image-optimizer/';

export const TOOL_CATEGORIES: Array< { id: ToolCategory; label: string; description: string } > = [
	{
		id: 'compress',
		label: 'Compress',
		description: 'Reduce image file size while keeping visual quality intact.',
	},
	{
		id: 'convert',
		label: 'Convert',
		description: 'Convert JPEG and PNG images into smaller modern WebP and AVIF files.',
	},
	{
		id: 'resize',
		label: 'Resize',
		description: 'Scale photos and graphics to exact pixel dimensions with aspect-ratio lock.',
	},
	{
		id: 'bulk',
		label: 'Batch',
		description: 'Optimize dozens of images locally with bounded concurrency and ZIP export.',
	},
];

export const TOOLS: ToolDefinition[] = [
	{
		slug: 'compress-image',
		name: 'Compress Image',
		shortName: 'Compress image',
		category: 'compress',
		title: 'Compress Image Online Free — Browser Tool | Kilobyte',
		description: 'Compress JPEG, PNG, WebP, AVIF, static GIF, and BMP images locally in your browser. Zero uploads, no account, real measured savings, and ZIP export.',
		h1: 'Compress images locally in your browser.',
		lead: 'Shrink JPEG, PNG, WebP, AVIF, static GIF, and BMP files without uploading a single byte. Compare visual detail side by side, choose your output format, and download immediately.',
		cardSummary: 'Optimize JPEG, PNG, WebP, AVIF, GIF, or BMP locally with live before/after inspection.',
		accept: 'image/jpeg,image/png,image/webp,image/avif,image/gif,image/bmp',
		acceptLabel: 'JPEG, PNG, WebP, AVIF, still GIF, or BMP (up to 24 MB / 40 MP)',
		codecSummary: 'Native Canvas + lazy WASM (UPNG, OxiPNG, libwebp, libaom)',
		defaultOutputMime: 'image/webp',
		allowedOutputMimes: [ 'image/webp', 'image/avif', 'image/jpeg', 'image/png' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: true,
		defaultPngMode: 'lossy',
		showResize: false,
		batchFocused: false,
		checkerboard: true,
		sampleUrl: '/assets/sample-photo.jpg',
		autoLoadSample: true,
		relatedSlugs: [ 'compress-jpeg', 'compress-png', 'bulk-image-compressor', 'resize-image' ],
		howToSteps: [
			'Drop one or more images onto the tool above, or click Choose image to pick files from your device.',
			'Pick your target format (WebP, AVIF, JPEG, or PNG) and adjust compression quality.',
			'Inspect the live before-and-after comparison and actual file size delta, then download.',
		],
		guideSections: [
			{
				heading: 'Choosing the right format for smaller files',
				paragraphs: [
					'Format choice usually saves more bytes than aggressively lowering quality. Moving a standard JPEG photograph to WebP or AVIF typically cuts transfer size significantly at comparable perceptual sharpness, while PNG graphics with sharp edges or transparency benefit from indexed palette quantization or WebP.',
					'Kilobyte always reports the real measured byte difference. If a selected file is already tightly compressed, the tool tells you plainly rather than inflating numbers or silently changing your selected format.',
				],
			},
			{
				heading: 'Private by architecture, not just by policy',
				paragraphs: [
					'Traditional web compressors upload your files to remote queues, apply hourly credit limits, and keep temporary copies on third-party servers. Kilobyte runs its encoders directly inside a Web Worker on your own device using native browser codecs and self-hosted WebAssembly modules.',
				],
				bullets: [
					'Zero image bytes leave your browser—all decoding and encoding runs locally on your device with no upload endpoints (verifiable in DevTools Network tab).',
					'No accounts, watermarks, ads, or artificial daily file caps.',
					'Single-image side-by-side slider and automatic batch mode when you drop multiple files.',
				],
			},
		],
		limitations: [
			'Browser memory safety bounds cap individual files at 24 MB, 40 megapixels, and 16,384 px per output side.',
			'Animated GIFs, APNGs, and animated WebP/AVIF files are rejected to avoid stripping animation frames.',
			'Canvas re-encoding strips camera EXIF and GPS metadata; bit-exact Lossless PNG preserves source samples and embedded ICC profiles while stripping EXIF and text chunks.',
		],
		faq: [
			{
				q: 'Are my photos uploaded to a server?',
				a: 'No. Every decode, resize, quantization, and encode step runs locally inside your browser. Native formats (such as JPEG and native WebP) work offline immediately after the page loads; optional WebAssembly codecs (OxiPNG, libwebp fallback, and AVIF) are downloaded on first use and cached by your browser for subsequent offline runs.',
			},
			{
				q: 'Why did one of my images not shrink much?',
				a: 'Images exported from modern tools or already converted to WebP/AVIF may already be near optimal density at quality 82. Try lowering the quality slider, switching from JPEG/PNG to WebP or AVIF, or resizing oversized dimensions.',
			},
			{
				q: 'Can I compress multiple files at once here?',
				a: 'Yes. Drop multiple images at once and the tool switches into batch mode with individual downloads and a single ZIP download. For large batches, the dedicated Bulk Image Compressor offers extra batch controls.',
			},
		],
	},
	{
		slug: 'compress-jpeg',
		name: 'Compress JPEG',
		shortName: 'Compress JPEG',
		category: 'compress',
		title: 'Compress JPEG & JPG Online Free | Kilobyte',
		description: 'Shrink JPG and JPEG photographs locally in your browser with quality presets, split-view comparison, and real byte measurements. No uploads or quotas.',
		h1: 'Compress JPEG & JPG photos in your browser.',
		lead: 'Reduce camera exports, product shots, and hero photos to web-ready JPEG sizes without uploading your originals. Tune quality from 40 to 95 while inspecting fine texture live.',
		cardSummary: 'Optimize JPG and JPEG photographs with live quality control and side-by-side detail check.',
		accept: 'image/jpeg,.jpg,.jpeg',
		acceptLabel: 'JPG or JPEG images (up to 24 MB / 40 MP)',
		codecSummary: 'Native browser JPEG encoder (optional WebP/AVIF comparison)',
		defaultOutputMime: 'image/jpeg',
		allowedOutputMimes: [ 'image/jpeg', 'image/webp', 'image/avif' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: false,
		showResize: false,
		batchFocused: false,
		checkerboard: false,
		sampleUrl: '/assets/sample-photo.jpg',
		autoLoadSample: true,
		relatedSlugs: [ 'jpg-to-webp', 'jpg-to-avif', 'resize-image', 'bulk-image-compressor' ],
		howToSteps: [
			'Drop a JPG or JPEG photograph into the optimizer, or click Choose image to pick files from your computer.',
			'Start with Balanced (82) or adjust the quality slider while dragging the comparison handle across textured areas.',
			'Click Download JPEG to save your copy, or switch to WebP or AVIF to compare next-generation savings.',
		],
		guideSections: [
			{
				heading: 'How JPEG compression works and where the sweet spot sits',
				paragraphs: [
					'Camera JPEGs and design exports are frequently saved at quality 95 to 100 with embedded color profiles, EXIF thumbnails, and high-frequency noise that add megabytes without visible improvement on web displays.',
					'Re-encoding between quality 75 and 85 removes imperceptible chroma detail and strips bulky metadata, often cutting 40% to 75% off unoptimized camera photos while preserving natural skin tones and edges.',
				],
			},
			{
				heading: 'When to keep JPEG vs. switching to WebP or AVIF',
				paragraphs: [
					'Keep JPEG when compatibility with legacy email clients, desktop apps, print workflows, or older CMS pipelines is required. For websites and blogs, WebP and AVIF deliver substantially smaller transfers at equal clarity and are supported across all modern browsers.',
				],
				bullets: [
					'Avoid repeatedly saving and re-compressing the same JPEG; always start from the highest-quality original.',
					'If a photograph is wider than 2560 px for a blog or storefront, resizing first saves more bytes than lowering quality alone.',
					'On WordPress, Kilobyte keeps your original JPEG file untouched and serves smaller WebP or AVIF copies automatically.',
				],
			},
		],
		limitations: [
			'JPEG does not support transparency; any transparent input converted to JPEG is composited onto white.',
			'Already tightly compressed JPEGs (for example from social media downloads) may not shrink further at high quality settings.',
			'EXIF metadata such as camera model, GPS coordinates, and copyright tags is stripped during browser re-encoding.',
		],
		faq: [
			{
				q: 'What quality setting should I use for web JPEGs?',
				a: 'Quality 78 to 84 is the sweet spot for most photography. Use High (90) for portfolio artwork with subtle gradients, or Smallest (72) for thumbnails and content grids.',
			},
			{
				q: 'Does compressing a JPEG reduce its pixel dimensions?',
				a: 'No. By default this tool preserves your original width and height unless you use the Resize Image tool. Only file size is reduced.',
			},
			{
				q: 'Can I compress 50 JPEGs at once?',
				a: 'Yes. Select or drop multiple JPG files at once and download them individually or as a single ZIP archive, all processed locally.',
			},
		],
	},
	{
		slug: 'compress-png',
		name: 'Compress PNG',
		shortName: 'Compress PNG',
		category: 'compress',
		title: 'Compress PNG Online — Lossy & Lossless | Kilobyte',
		description: 'Shrink PNG files locally with indexed RGBA palette quantization or bit-exact lossless OxiPNG. Preserves full alpha transparency with zero uploads.',
		h1: 'Compress PNG images with full transparency.',
		lead: 'Choose indexed RGBA palette quantization for high-ratio PNG reductions, or bit-exact lossless OxiPNG optimization when every pixel must remain identical. Runs 100% locally in your browser.',
		cardSummary: 'Shrink PNG screenshots, logos, and transparent graphics via palette quantization or lossless OxiPNG.',
		accept: 'image/png,.png',
		acceptLabel: 'PNG images (up to 24 MB / 40 MP)',
		codecSummary: 'UPNG indexed RGBA quantizer + self-hosted OxiPNG WASM',
		defaultOutputMime: 'image/png',
		allowedOutputMimes: [ 'image/png', 'image/webp', 'image/avif' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: true,
		defaultPngMode: 'lossy',
		showResize: false,
		batchFocused: false,
		checkerboard: true,
		relatedSlugs: [ 'png-to-webp', 'png-to-avif', 'compress-image', 'bulk-image-compressor' ],
		howToSteps: [
			'Drop your PNG screenshot, UI mockup, logo, or transparent graphic onto the card above.',
			'Choose Palette (smaller) for indexed RGBA quantization, or Lossless for pixel-identical OxiPNG optimization.',
			'Inspect transparent edges against the checkerboard preview and click Download PNG.',
		],
		guideSections: [
			{
				heading: 'Lossy palette quantization vs. lossless PNG optimization',
				paragraphs: [
					'Standard browser Canvas encoding writes uncompressed or weakly deflated 32-bit RGBA PNGs and ignores quality sliders altogether. Kilobyte avoids that trap by using two dedicated local PNG engines.',
					'In Palette mode, Kilobyte reduces 24-bit and 32-bit PNGs to an optimized indexed RGBA color palette (64 to 256 colors) while preserving full semi-transparent alpha channels, then runs an OxiPNG WebAssembly deflate pass for additional lossless compaction. In Lossless mode, PNG inputs are optimized directly by OxiPNG without Canvas conversion—preserving bit-exact pixel values, 16-bit precision whenever samples require more than 8 bits, semi-transparent RGBA values, and embedded ICC color profiles while stripping EXIF and text metadata chunks.',
				],
				bullets: [
					'Palette quantization excels on UI screenshots, icons, charts, and web illustrations.',
					'Lossless OxiPNG is ideal for pixel art, strict brand assets, and smooth photographic gradients where banding must never occur.',
					'Every output is verified against both the 8-byte PNG signature and complete IEND trailer before download.',
				],
			},
			{
				heading: 'When PNG should actually be WebP or AVIF',
				paragraphs: [
					'If your PNG is a large photograph or a hero image with soft shadows, even a quantized 8-bit PNG will stay larger than a WebP or AVIF file. You can switch output format right in the toolbar to compare PNG against WebP or AVIF on your exact image.',
				],
			},
		],
		limitations: [
			'Animated PNGs (APNGs) are skipped so multi-frame animation data is never destroyed.',
			'Lossy quantization maps colors to at most 256 palette entries; subtle 24-bit photographic gradients may show mild banding at lower quality—switch to Lossless mode or WebP/AVIF if you spot banding.',
			'On first use, Kilobyte lazy-loads a ~160 KB self-hosted OxiPNG WebAssembly module from this site; images never leave your browser.',
		],
		faq: [
			{
				q: 'Does PNG compression keep my transparent background?',
				a: 'Yes. Both Palette quantization (indexed RGBA with tRNS alpha table) and Lossless OxiPNG preserve full alpha transparency, including semi-transparent shadows and anti-aliased edges.',
			},
			{
				q: 'How is this different from browser Canvas PNG export?',
				a: 'HTML5 Canvas cannot quantize PNG palettes or optimize DEFLATE streams, so it often makes PNGs bigger. Kilobyte uses UPNG palette quantization paired with the Rust-based OxiPNG WebAssembly optimizer.',
			},
			{
				q: 'Why does Lossless mode disable the quality slider?',
				a: 'Lossless optimization cannot discard any color information—it preserves exact RGBA pixels while stripping ancillary metadata and searching for tighter DEFLATE/filter encodings.',
			},
		],
	},
	{
		slug: 'jpg-to-webp',
		name: 'JPG to WebP',
		shortName: 'JPG to WebP',
		category: 'convert',
		title: 'JPG to WebP Converter — Free Browser Tool | Kilobyte',
		description: 'Convert JPG and JPEG images to WebP locally in your browser with native and WASM fallback encoding. Zero uploads, live preview, and batch support.',
		h1: 'Convert JPG to WebP in your browser.',
		lead: 'Turn heavy JPEG photographs into lightweight WebP files locally with zero server uploads. Works across Chrome, Firefox, and Safari with automatic WebAssembly fallback when needed.',
		cardSummary: 'Convert JPEG photographs into lightweight WebP copies with real measured savings.',
		accept: 'image/jpeg,.jpg,.jpeg',
		acceptLabel: 'JPG or JPEG images (up to 24 MB / 40 MP)',
		codecSummary: 'Native WebP encoder + self-hosted libwebp WASM fallback',
		defaultOutputMime: 'image/webp',
		allowedOutputMimes: [ 'image/webp' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: false,
		showResize: false,
		batchFocused: false,
		checkerboard: false,
		sampleUrl: '/assets/sample-photo.jpg',
		autoLoadSample: true,
		relatedSlugs: [ 'png-to-webp', 'jpg-to-avif', 'compress-jpeg', 'bulk-image-compressor' ],
		howToSteps: [
			'Drop one or more JPG/JPEG files onto the converter above.',
			'Choose Balanced (82), High (90), or Smallest (72), or dial in an exact quality value.',
			'Inspect the split-view comparison and click Download WebP (or Download all (.zip) for multiple files).',
		],
		guideSections: [
			{
				heading: 'Why convert JPG to WebP for the web',
				paragraphs: [
					'WebP uses more advanced predictive coding than classic JPEG, which lets it store photographs at noticeably smaller byte sizes for equivalent visual fidelity. Smaller images directly improve Largest Contentful Paint (LCP) and reduce mobile bandwidth.',
					'Every mainstream browser—Chrome, Safari, Firefox, and Edge on desktop and mobile—has supported WebP for years, making it the most practical default web format today.',
				],
			},
			{
				heading: 'Cross-browser local encoding with signature verification',
				paragraphs: [
					'Some browsers (including Safari) do not expose native WebP encoding through HTML5 Canvas and silently fall back to PNG. Kilobyte probes native capabilities once, checks actual RIFF/WEBP file signatures on every encode, and automatically lazy-loads a self-hosted libwebp WebAssembly encoder whenever native encoding is unavailable.',
				],
			},
		],
		limitations: [
			'WebP is lossy here; converting an already heavily compressed small JPEG may yield modest savings or slightly larger output at high quality.',
			'Email newsletters and some older desktop apps still expect JPEG—keep original JPEGs archived if you distribute files outside the web.',
		],
		faq: [
			{
				q: 'Does JPG to WebP work in Safari?',
				a: 'Yes. When Safari cannot encode WebP through Canvas natively, Kilobyte automatically lazy-loads its self-hosted libwebp WebAssembly codec and verifies the RIFF/WEBP file header before enabling download.',
			},
			{
				q: 'Will converting JPG to WebP hurt visual quality?',
				a: 'At quality 80 to 85, WebP copies are visually nearly indistinguishable from the source JPEG while typically weighing 25% to 50% less. Drag the live slider across faces and fine text to verify before downloading.',
			},
			{
				q: 'How can I automate JPG to WebP on WordPress?',
				a: 'Install the free Kilobyte WordPress plugin. It converts uploads to WebP automatically in your browser (with server fallback) while keeping original JPEG files and URLs intact.',
			},
		],
	},
	{
		slug: 'png-to-webp',
		name: 'PNG to WebP',
		shortName: 'PNG to WebP',
		category: 'convert',
		title: 'PNG to WebP Converter — Keep Transparency | Kilobyte',
		description: 'Convert transparent PNG images to WebP locally in your browser. Shrink UI screenshots, logos, and product cutouts with zero uploads or accounts.',
		h1: 'Convert PNG to WebP without losing transparency.',
		lead: 'Replace bloated 32-bit PNG screenshots, logos, and transparent product cutouts with compact WebP files—processed entirely on your device with full alpha channel preservation.',
		cardSummary: 'Convert transparent PNGs and UI screenshots to WebP while preserving crisp alpha edges.',
		accept: 'image/png,.png',
		acceptLabel: 'PNG images (up to 24 MB / 40 MP)',
		codecSummary: 'Native RGBA WebP encoder + self-hosted libwebp WASM fallback',
		defaultOutputMime: 'image/webp',
		allowedOutputMimes: [ 'image/webp' ],
		defaultQuality: 85,
		showQuality: true,
		showPresets: true,
		showPngMode: false,
		showResize: false,
		batchFocused: false,
		checkerboard: true,
		relatedSlugs: [ 'compress-png', 'png-to-avif', 'jpg-to-webp', 'bulk-image-compressor' ],
		howToSteps: [
			'Drop one or more PNG files (with or without transparent backgrounds) into the dropzone.',
			'Inspect crisp text and transparent borders on the checkerboard comparison stage.',
			'Adjust quality if needed and click Download WebP (or Download all (.zip) for batches).',
		],
		guideSections: [
			{
				heading: 'Why PNG to WebP delivers some of the biggest web savings',
				paragraphs: [
					'Unoptimized PNGs—especially Retina screenshots, product cutouts on transparent backgrounds, and Figma exports—frequently weigh 1 MB to 5 MB each. Unlike JPEG, WebP natively supports full 8-bit alpha transparency alongside modern lossy compression, often shrinking heavy PNGs by 70% to 90%.',
				],
			},
			{
				heading: 'Quality tips for UI screenshots and logos',
				paragraphs: [
					'For crisp typography, thin interface borders, and brand marks, keep quality at 85 to 92 so text stays sharp without ringing artifacts. If your asset must remain strictly pixel-identical, compare against Kilobyte’s Lossless PNG optimizer.',
				],
			},
		],
		limitations: [
			'Animated PNGs (APNGs) are skipped to protect animation frames.',
			'For very small simple icons (under 2 KB), an indexed PNG can occasionally be smaller than WebP—Kilobyte shows exact byte counts so you can choose.',
			'Ancillary PNG metadata chunks (EXIF, text comments) are stripped during conversion.',
		],
		faq: [
			{
				q: 'Does WebP keep transparent backgrounds from PNG files?',
				a: 'Yes. WebP supports full RGBA transparency, including soft drop shadows and semi-transparent anti-aliased edges.',
			},
			{
				q: 'When should I use PNG to WebP vs. Compress PNG?',
				a: 'Use PNG to WebP for websites and web apps where you want the smallest possible file with transparency. Use Compress PNG when you specifically need a .png file for desktop tools, app stores, or legacy systems.',
			},
			{
				q: 'Does PNG to WebP work in Safari?',
				a: 'Yes. Safari does not encode WebP natively via Canvas, so Kilobyte automatically lazy-loads a self-hosted libwebp WebAssembly encoder with alpha compression enabled and verifies the RIFF/WEBP header.',
			},
		],
	},
	{
		slug: 'jpg-to-avif',
		name: 'JPG to AVIF',
		shortName: 'JPG to AVIF',
		category: 'convert',
		title: 'JPG to AVIF Converter — Free Browser Tool | Kilobyte',
		description: 'Convert JPG and JPEG photos to AVIF locally in your browser. Native encoding with lazy-loaded WebAssembly libaom fallback for Safari and Firefox.',
		h1: 'Convert JPG to AVIF locally in your browser.',
		lead: 'Get next-generation AV1 image compression from your JPEG photos with zero server uploads. Runs natively where supported and falls back automatically to self-hosted AVIF WebAssembly in Safari and Firefox.',
		cardSummary: 'Convert JPEG photos to compact AVIF files with native speed and WASM fallback.',
		accept: 'image/jpeg,.jpg,.jpeg',
		acceptLabel: 'JPG or JPEG images (up to 24 MB / 40 MP)',
		codecSummary: 'Native AVIF encoder + lazy self-hosted libaom AVIF WASM',
		defaultOutputMime: 'image/avif',
		allowedOutputMimes: [ 'image/avif' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: false,
		showResize: false,
		batchFocused: false,
		checkerboard: false,
		sampleUrl: '/assets/sample-photo.jpg',
		autoLoadSample: false,
		relatedSlugs: [ 'png-to-avif', 'jpg-to-webp', 'compress-jpeg', 'bulk-image-compressor' ],
		howToSteps: [
			'Drop a JPG or JPEG image onto the converter, or click Use sample photo to test on a built-in photograph.',
			'Pick a quality level (Balanced 82 is tuned for strong perceptual detail with compact AVIF bitstreams).',
			'Wait for the local encoder to finish (native or WebAssembly), inspect the result, and click Download AVIF.',
		],
		guideSections: [
			{
				heading: 'When AVIF beats WebP and JPEG',
				paragraphs: [
					'AVIF is derived from the AV1 video codec and typically compresses photographs 20% to 40% smaller than WebP and 50%+ smaller than original JPEGs at comparable visual quality, especially on smooth skies, gradients, and high-resolution hero images.',
					'Chrome, Safari, Firefox, and Edge all decode AVIF natively. When serving AVIF on production sites, pairing it with a <picture> fallback ensures older crawlers and legacy tools still receive a valid image.',
				],
			},
			{
				heading: 'Honest note on encoding speed and browser support',
				paragraphs: [
					'AVIF compression does substantially more mathematical work than JPEG or WebP. On browsers that support native AVIF Canvas encoding, Kilobyte uses the native encoder for speed. On browsers that do not, Kilobyte lazy-loads a self-hosted libaom WebAssembly encoder inside a background Web Worker only when you start an encode—large photos can take a second or two in WebAssembly.',
				],
			},
		],
		limitations: [
			'AVIF encoding is slower than WebP, particularly when running through the single-threaded WebAssembly fallback on large high-resolution photos.',
			'When the WASM fallback is needed, the browser downloads a ~3.3 MB (roughly 1 MB compressed) self-hosted avif_enc.wasm codec on first encode and caches it locally; your images are never uploaded.',
			'Every output is verified against the ISO BMFF ftyp/avif container signature before download is enabled.',
		],
		faq: [
			{
				q: 'Does JPG to AVIF work on Safari and Firefox?',
				a: 'Yes. Even when a browser cannot encode AVIF natively through Canvas, Kilobyte lazy-loads a self-hosted libaom WebAssembly encoder inside a Web Worker and verifies the AVIF container header.',
			},
			{
				q: 'Why does AVIF take longer to encode than WebP?',
				a: 'AV1 intra-frame compression evaluates far more block partitions and prediction modes than WebP or JPEG. That extra computation during encoding is what produces smaller files for visitors to download.',
			},
			{
				q: 'Should I use AVIF or WebP on my WordPress site?',
				a: 'AVIF gives the smallest files for hero photos and image-heavy pages, while WebP encodes much faster for large bulk runs. Kilobyte for WordPress supports both and serves them safely through <picture> tags with original fallbacks.',
			},
		],
	},
	{
		slug: 'png-to-avif',
		name: 'PNG to AVIF',
		shortName: 'PNG to AVIF',
		category: 'convert',
		title: 'PNG to AVIF Converter — Transparent AVIF | Kilobyte',
		description: 'Convert PNG images with alpha transparency to AVIF locally in your browser. Native encoding with self-hosted WebAssembly fallback and zero uploads.',
		h1: 'Convert PNG to AVIF with transparency preserved.',
		lead: 'Shrink heavy transparent PNGs, illustrations, and UI assets into modern AVIF files locally in your browser—with automatic WebAssembly fallback on browsers without native AVIF encoding.',
		cardSummary: 'Convert transparent PNG graphics and screenshots into ultra-compact AVIF files.',
		accept: 'image/png,.png',
		acceptLabel: 'PNG images (up to 24 MB / 40 MP)',
		codecSummary: 'Native RGBA AVIF encoder + lazy self-hosted libaom AVIF WASM',
		defaultOutputMime: 'image/avif',
		allowedOutputMimes: [ 'image/avif' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: false,
		showResize: false,
		batchFocused: false,
		checkerboard: true,
		relatedSlugs: [ 'png-to-webp', 'compress-png', 'jpg-to-avif', 'bulk-image-compressor' ],
		howToSteps: [
			'Drop your PNG file onto the tool above, or click Choose image.',
			'Verify transparency against the checkerboard preview and compare visual sharpness with the split slider.',
			'Click Download AVIF to save the verified .avif file—no uploads, no account.',
		],
		guideSections: [
			{
				heading: 'Transparent PNGs in AVIF format',
				paragraphs: [
					'AVIF stores an independent alpha plane alongside the AV1 image plane, preserving smooth anti-aliased edges and translucent shadows at a fraction of the byte size of 32-bit PNG.',
					'For large high-DPI screenshots and transparent hero artwork, PNG to AVIF frequently achieves 75% to 90%+ file size reductions compared to unoptimized PNG exports.',
				],
			},
			{
				heading: 'Sharp UI text and graphics considerations',
				paragraphs: [
					'Because default AVIF uses 4:2:0 chroma subsampling, thin colored 1px lines or small colored text on contrasting backgrounds can soften slightly at low quality settings. Keep quality at 85+ for UI screenshots, or compare directly against PNG to WebP or Lossless PNG.',
				],
			},
		],
		limitations: [
			'Animated PNGs (APNGs) are skipped to avoid discarding animation frames.',
			'Encoding large PNGs via the WebAssembly fallback takes a second or two inside a background Web Worker; you can cancel at any time.',
			'When the WASM fallback is needed, the browser lazy-loads a self-hosted avif_enc.wasm codec on first encode and caches it locally.',
		],
		faq: [
			{
				q: 'Does PNG to AVIF keep transparent backgrounds?',
				a: 'Yes. Both native browser AVIF encoders and Kilobyte’s self-hosted libaom WebAssembly fallback encode the full alpha channel.',
			},
			{
				q: 'What if an older browser cannot display AVIF?',
				a: 'Wrap the AVIF image in an HTML <picture> element with a WebP or PNG fallback. On WordPress, Kilobyte generates that <picture> markup automatically while leaving your original PNG intact.',
			},
			{
				q: 'Why is PNG to AVIF slower than PNG to WebP?',
				a: 'AVIF encodes separate AV1 luma/chroma and alpha bitstreams using significantly more search heuristics than WebP. For rapid bulk batches on mobile devices, PNG to WebP finishes much faster.',
			},
		],
	},
	{
		slug: 'resize-image',
		name: 'Resize Image',
		shortName: 'Resize image',
		category: 'resize',
		title: 'Resize Image Online — Exact Pixels & Lock | Kilobyte',
		description: 'Resize JPEG, PNG, WebP, AVIF, static GIF, and BMP images in your browser with aspect-ratio lock, no-upscale protection, and zero server uploads.',
		h1: 'Resize images to exact pixel dimensions.',
		lead: 'Scale down oversized camera photos, blog headers, and product images locally with high-quality resampling, aspect-ratio locking, and optional format conversion.',
		cardSummary: 'Resize photos to exact width/height or common web sizes with aspect-ratio lock and high-quality scaling.',
		accept: 'image/jpeg,image/png,image/webp,image/avif,image/gif,image/bmp',
		acceptLabel: 'JPEG, PNG, WebP, AVIF, still GIF, or BMP (up to 24 MB / 40 MP)',
		codecSummary: 'High-quality Canvas resampling + WebP, JPEG, PNG, AVIF encoders',
		defaultOutputMime: 'image/webp',
		allowedOutputMimes: [ 'image/webp', 'image/jpeg', 'image/png', 'image/avif' ],
		defaultQuality: 85,
		showQuality: true,
		showPresets: false,
		showPngMode: true,
		defaultPngMode: 'lossy',
		showResize: true,
		batchFocused: false,
		checkerboard: true,
		sampleUrl: '/assets/sample-photo.jpg',
		autoLoadSample: true,
		relatedSlugs: [ 'compress-image', 'bulk-image-compressor', 'compress-jpeg', 'jpg-to-webp' ],
		howToSteps: [
			'Drop an image or click Choose image to read its original pixel dimensions.',
			'Enter a target width, height, or click a quick preset (1920px, 1200px, 800px, or 50%).',
			'Keep aspect ratio locked to avoid distortion, pick your output format, and download the resized copy.',
		],
		guideSections: [
			{
				heading: 'Why resizing before upload is the biggest performance win',
				paragraphs: [
					'A 4000 × 3000 smartphone photo contains 12 million pixels—more than 6 times the pixels needed for a 1600 px web container. Even at aggressive compression, shipping 6x too many pixels wastes bandwidth and slows browser image decoding on mobile devices.',
					'Kilobyte uses high-quality Canvas resampling (imageSmoothingQuality set to high) inside a Web Worker and enforces no-upscale protection by default so small images are never accidentally stretched blurry.',
				],
				bullets: [
					'Blog featured images & hero banners: 1600 px to 1920 px wide.',
					'Content column photos: 1200 px wide (covers 2× Retina at 600 px CSS width).',
					'Open Graph / social cards: 1200 × 630 px.',
				],
			},
			{
				heading: 'Preserving aspect ratio vs. exact dimensions',
				paragraphs: [
					'With Keep aspect ratio enabled, entering either width or height calculates the matching dimension proportionally; entering both fits the image cleanly inside that bounding box without cropping or stretching. Uncheck Keep aspect ratio only when you intentionally want exact non-proportional dimensions.',
				],
			},
		],
		limitations: [
			'Input and output dimensions are bounded to 40 megapixels total and 16,384 px per side to protect browser memory.',
			'Animated GIFs, APNGs, and animated WebPs are not resized here because single-frame canvas scaling would lose animation.',
		],
		faq: [
			{
				q: 'Will resizing stretch or distort my photo?',
				a: 'Not with Keep aspect ratio checked (the default). Kilobyte computes proportional dimensions automatically so images stay sharp and undistorted.',
			},
			{
				q: 'What does "Never upscale" do?',
				a: 'If your source photo is 900 px wide and you request 1600 px, Kilobyte keeps it at 900 px rather than enlarging it into a blurry, heavier file. You can uncheck Never upscale if you explicitly need upscaling.',
			},
			{
				q: 'Can I resize and convert to WebP in one step?',
				a: 'Yes. Choose WebP, AVIF, JPEG, or PNG in the output format control and Kilobyte resizes and encodes in a single local pass.',
			},
		],
	},
	{
		slug: 'bulk-image-compressor',
		name: 'Bulk Image Compressor',
		shortName: 'Bulk compressor',
		category: 'bulk',
		title: 'Bulk Image Compressor — Batch Optimize & ZIP | Kilobyte',
		description: 'Compress and convert multiple images at once locally in your browser with bounded Web Workers, cancellation, and instant ZIP export. Zero uploads.',
		h1: 'Compress multiple images locally and export as ZIP.',
		lead: 'Drop multiple files or a folder of JPEGs, PNGs, WebPs, or BMPs and optimize them in parallel inside browser Web Workers. No 20-file cap, no uploads, and one-click ZIP download.',
		cardSummary: 'Batch compress and convert dozens of photos in parallel with live totals and ZIP export.',
		accept: 'image/jpeg,image/png,image/webp,image/avif,image/gif,image/bmp',
		acceptLabel: 'multiple JPEG, PNG, WebP, AVIF, still GIF, or BMP files (up to 24 MB each)',
		codecSummary: 'Pooled Web Workers (3 concurrent) + store-mode ZIP archive',
		defaultOutputMime: 'image/webp',
		allowedOutputMimes: [ 'image/webp', 'image/jpeg', 'image/png', 'image/avif' ],
		defaultQuality: 82,
		showQuality: true,
		showPresets: true,
		showPngMode: true,
		defaultPngMode: 'lossy',
		showResize: true,
		batchFocused: true,
		checkerboard: true,
		relatedSlugs: [ 'compress-image', 'jpg-to-webp', 'compress-png', 'resize-image' ],
		howToSteps: [
			'Drop multiple photos or a folder onto the tool, or click Choose images to select multiple files at once.',
			'Choose your target format, quality preset, and optional maximum width/height.',
			'Monitor live progress with bounded concurrency (click Cancel anytime), then download individual files or click Download all (.zip).',
		],
		guideSections: [
			{
				heading: 'Built for real batches without crashing your tab',
				paragraphs: [
					'Naive browser tools decode every dropped image simultaneously, spiking RAM and freezing the page. Kilobyte runs a bounded reusable worker pool (up to 3 concurrent workers), validates image header dimensions before decoding pixels, and releases intermediate ImageBitmaps and canvas memory immediately.',
				],
				bullets: [
					'No artificial 20-image quota or paid batch tier—only real device memory bounds.',
					'Safe ZIP export uses standards-compliant store mode (since images are already compressed) with automatic duplicate-filename disambiguation and an explicit 150 MB archive safety cap.',
					'Failed or unsupported files (like animated GIFs or corrupted files) report clear per-file reasons without halting the rest of your batch.',
				],
			},
			{
				heading: 'Automating bulk optimization inside WordPress',
				paragraphs: [
					'If you are preparing images for a WordPress site, you do not need to manually batch-convert and re-upload them. Kilobyte’s free WordPress plugin includes both Bulk optimize and Selective optimize right inside Media → Optimization, with live ETA, resume support, and one-click Restore all.',
				],
			},
		],
		limitations: [
			'Each individual file is capped at 24 MB, 40 megapixels, and 16,384 px per output side; combined single-ZIP export is capped at 150 MB to stay safely within browser memory.',
			'Recursive folder drops admit up to 200 files per drop (with a clear notice if additional files are skipped); you can drop or select additional files anytime.',
			'For batches of 50+ photos using AVIF on Safari/Firefox (WASM fallback), WebP encodes substantially faster.',
		],
		faq: [
			{
				q: 'Is there a limit on how many images I can compress?',
				a: 'There is no commercial quota or file-count paywall. Practical batch size depends only on your device memory; single ZIP downloads are bounded to 150 MB combined output, and individual file downloads always remain available.',
			},
			{
				q: 'What happens if two dropped files end up with the same name?',
				a: 'When building the ZIP archive, Kilobyte automatically appends -2, -3, and so on so no file overwrites another during extraction.',
			},
			{
				q: 'Can I cancel a batch halfway through?',
				a: 'Yes. Click Cancel at any time—active workers terminate immediately, and any images already finished remain downloadable.',
			},
		],
	},
];

export function getToolBySlug( slug: string ): ToolDefinition | undefined {
	return TOOLS.find( ( tool ) => tool.slug === slug );
}
