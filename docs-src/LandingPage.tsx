import { useState } from '@wordpress/element';
import { Button, Card, CardBody, Icon, Modal, TabPanel } from '@wordpress/components';
import {
	brush,
	check,
	chevronDown,
	copy,
	download,
	external,
	pages,
	plugins,
	shield,
} from '@wordpress/icons';
import { CompressionLab } from './CompressionLab';

const WP_ORG_URL = 'https://wordpress.org/plugins/kilobyte-image-optimizer/';
const PLAYGROUND_URL =
	'https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/JosephGabito/kilobyte-image-optimizer/main/.wordpress-org/blueprints/blueprint.json';
const GITHUB_URL = 'https://github.com/JosephGabito/kilobyte-image-optimizer';
const SUPPORT_URL = 'https://wordpress.org/support/plugin/kilobyte-image-optimizer/';

const TILES = [
	{
		title: 'Free, with no limits',
		body: 'Optimize as many images as you like. There is no account, no API key, and no monthly quota.',
	},
	{
		title: 'Your originals are kept',
		body: 'Kilobyte saves each smaller image as a separate copy. Your original files and their links stay the same.',
	},
	{
		title: 'Works with AI assistants',
		body: 'An AI assistant connected to your site can check savings, optimize images, and restore them for you.',
	},
];

const REASONS = [
	{
		icon: plugins,
		title: 'Works on activation',
		body: 'There is no account to create and no API key to paste. Images are compressed in your browser, or by the image editor your WordPress site already has.',
	},
	{
		icon: brush,
		title: 'No ads or upsells',
		body: 'Kilobyte shows no upgrade banners. Every setting you see is included and free.',
	},
	{
		icon: pages,
		title: 'Looks like WordPress',
		body: 'The Kilobyte screen is built from WordPress’s own interface components, so it looks and behaves like the rest of your admin.',
	},
	{
		icon: shield,
		title: 'Safe by default',
		body: 'Browsers that cannot show WebP or AVIF get your original image. If you restore an image, the old copies stay available for at least 24 hours, so cached pages keep working.',
	},
];

interface Screen {
	id: string;
	src: string;
	width: number;
	height: number;
	title: string;
	body: string;
	alt: string;
}

const SCREENS: Screen[] = [
	{
		id: 'preview',
		src: 'assets/screen-preview.webp',
		width: 1700,
		height: 944,
		title: 'Preview',
		body: 'Compare one photo before and after, side by side. Nothing is saved until you choose Optimize now.',
		alt: 'The Preview tab of Kilobyte, showing an original 2.03 MB photo next to a 115.5 KB WebP copy',
	},
	{
		id: 'selective',
		src: 'assets/screen-selective.webp',
		width: 1720,
		height: 832,
		title: 'Selective optimize',
		body: 'Choose specific photos from your Media Library and optimize only those.',
		alt: 'The Selective optimize tab of Kilobyte, with five Media Library images chosen for optimizing',
	},
	{
		id: 'bulk',
		src: 'assets/screen-bulk.webp',
		width: 1200,
		height: 1408,
		title: 'Bulk optimize',
		body: 'Optimize your whole Media Library in one run. The screen lists every image and has a Restore all button.',
		alt: 'The Bulk optimize tab of Kilobyte, showing 21 images processed and 8.61 MB saved',
	},
	{
		id: 'preference',
		src: 'assets/screen-preference.webp',
		width: 1136,
		height: 1360,
		title: 'Preference',
		body: 'Choose the image format and the compression level. Quality and maximum size are under Advanced.',
		alt: 'The Preference tab of Kilobyte, with format, compression level, and advanced quality settings',
	},
	{
		id: 'media-library',
		src: 'assets/screen-media-library.webp',
		width: 1488,
		height: 1084,
		title: 'Media Library',
		body: 'Each image in your Media Library shows how much smaller its copy is, next to a Restore original link.',
		alt: 'A WordPress Media Library image whose details show a 94.4 percent saving and a Restore original link',
	},
];

const CODE_TABS = [
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

const FAQ_ITEMS = [
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

function CallToActionButtons() {
	return (
		<div className="kb-actions">
			<Button
				variant="primary"
				icon={ download }
				href={ WP_ORG_URL }
				target="_blank"
				rel="noopener noreferrer"
				className="kb-cta"
			>
				Download on WordPress.org
			</Button>
			<Button
				variant="secondary"
				icon={ external }
				href={ PLAYGROUND_URL }
				target="_blank"
				rel="noopener noreferrer"
				className="kb-cta"
			>
				Try it in a demo site
			</Button>
		</div>
	);
}

export function LandingPage() {
	const [ activeScreen, setActiveScreen ] = useState< Screen >( SCREENS[ 0 ] );
	const [ modalScreen, setModalScreen ] = useState< Screen | null >( null );
	const [ copiedTab, setCopiedTab ] = useState< string | null >( null );

	const handleCopySnippet = async ( tabName: string, snippet: string ) => {
		try {
			await navigator.clipboard.writeText( snippet );
			setCopiedTab( tabName );
			window.setTimeout( () => {
				setCopiedTab( ( current ) => ( current === tabName ? null : current ) );
			}, 1800 );
		} catch {
			// The clipboard is unavailable on pages that are not served over HTTPS.
		}
	};

	return (
		<div id="top">
			<header className="kb-topbar">
				<div className="kb-container kb-topbar-inner">
					<div className="kb-topbar-left">
						<a
							className="kb-brand"
							href="#top"
							aria-label="Kilobyte Image Optimizer, back to the top of this page"
						>
							Kilobyte
						</a>

						<nav className="kb-nav" aria-label="Sections of this page">
							<a href="#demo">Try it here</a>
							<a href="#why">Why Kilobyte</a>
							<a href="#screens">Screens</a>
							<a href="#developers">For developers</a>
							<a href="#questions">Questions</a>
						</nav>
					</div>

					<div className="kb-topbar-actions">
						<a
							className="kb-topbar-link"
							href={ GITHUB_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							GitHub
						</a>
						<Button
							variant="primary"
							href={ WP_ORG_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							Download free
						</Button>
					</div>
				</div>
			</header>

			<main>
				<section className="kb-hero">
					<div className="kb-band kb-hero-band">
						<div className="kb-container">
							<h1 className="kb-display">
								Shrink WordPress images to WebP <span className="kb-amp">&amp;</span> AVIF.
							</h1>
							<p className="kb-quiet">Your original files stay untouched.</p>
							<p className="kb-lead">
								Kilobyte makes smaller WebP and AVIF copies of the images in your Media Library. Your original files and existing links stay exactly as they are, and you can test one photo before you optimize the rest.
							</p>

							<CallToActionButtons />

							<p className="kb-small">
								The demo site is a real WordPress site that runs in your browser, with Kilobyte and 3 sample images already set up.
							</p>
						</div>
					</div>

					<div className="kb-container kb-hero-media">
						<div className="kb-banner">
							<img
								src="assets/banner-1544x500.webp"
								alt="A Renaissance-style painting of a scholar who holds up a light 507 KB picture next to a heavy 3.81 MB stone slab bound in chains"
								width={ 1544 }
								height={ 500 }
							/>
						</div>

						<div className="kb-grid kb-tiles">
							{ TILES.map( ( tile ) => (
								<Card key={ tile.title } className="kb-tile">
									<CardBody>
										<h2 className="kb-h3">{ tile.title }</h2>
										<p className="kb-body">{ tile.body }</p>
									</CardBody>
								</Card>
							) ) }
						</div>
					</div>
				</section>

				<section id="demo" className="kb-section kb-section-wash">
					<div className="kb-container">
						<div className="kb-grid kb-head">
							<p className="kb-kicker">Try it on this page</p>
							<h2 className="kb-title">See how much smaller your own photo gets.</h2>
							<p className="kb-lead">
								Choose a photo, or drop one onto the card below. The compression runs here in your browser, so the photo never leaves your device. Drag the handle across the picture to compare the original with the smaller copy.
							</p>
						</div>

						<CompressionLab />
					</div>
				</section>

				<section id="why" className="kb-section">
					<div className="kb-container">
						<div className="kb-grid kb-head">
							<p className="kb-kicker">Why Kilobyte</p>
							<h2 className="kb-title">Designed to feel like a missing WordPress Core feature.</h2>
							<p className="kb-lead">
								Kilobyte adds one screen under Media in your WordPress admin. It shows no ads, asks for no account, and never changes your original files.
							</p>
						</div>

						<div className="kb-grid kb-why">
							{ REASONS.map( ( reason ) => (
								<div key={ reason.title } className="kb-why-item">
									<Icon icon={ reason.icon } size={ 24 } />
									<h3 className="kb-h3">{ reason.title }</h3>
									<p className="kb-body">{ reason.body }</p>
								</div>
							) ) }
						</div>
					</div>
				</section>

				<section id="screens" className="kb-section kb-section-wash">
					<div className="kb-container">
						<div className="kb-grid kb-head">
							<p className="kb-kicker">Inside the plugin</p>
							<h2 className="kb-title">See every Kilobyte screen before you install.</h2>
							<p className="kb-lead">
								Kilobyte has four tabs, and it adds one line to each image in your Media Library. Select a picture to view it full size.
							</p>
						</div>

						<div className="kb-grid">
							<ul className="kb-screen-list">
								{ SCREENS.map( ( screen ) => (
									<li key={ screen.id }>
										<button
											type="button"
											className="kb-screen-tab"
											aria-pressed={ activeScreen.id === screen.id }
											onClick={ () => setActiveScreen( screen ) }
										>
											<span className="kb-h3">{ screen.title }</span>
											<span className="kb-small">{ screen.body }</span>
										</button>

										<button
											type="button"
											className="kb-screen-inline"
											aria-label={ `View the ${ screen.title } screen full size` }
											onClick={ () => setModalScreen( screen ) }
										>
											<img
												src={ screen.src }
												alt={ screen.alt }
												width={ screen.width }
												height={ screen.height }
												loading="lazy"
											/>
										</button>
									</li>
								) ) }
							</ul>

							<button
								type="button"
								className="kb-screen-frame"
								aria-label={ `View the ${ activeScreen.title } screen full size` }
								onClick={ () => setModalScreen( activeScreen ) }
							>
								<img
									key={ activeScreen.id }
									src={ activeScreen.src }
									alt={ activeScreen.alt }
									width={ activeScreen.width }
									height={ activeScreen.height }
									loading="lazy"
								/>
							</button>
						</div>
					</div>
				</section>

				<section id="developers" className="kb-section">
					<div className="kb-container kb-grid">
						<div className="kb-dev-text">
							<p className="kb-kicker">For developers and AI assistants</p>
							<h2 className="kb-title">Run Kilobyte from code, the command line, or an AI assistant.</h2>
							<p className="kb-lead">
								Kilobyte follows the WordPress VIP Go coding standards, loads no Composer packages in production, and is covered by more than 470 integration tests that run against real WordPress.
							</p>

							<dl className="kb-dev-list">
								<div>
									<dt>WordPress Abilities API</dt>
									<dd className="kb-body">
										On WordPress 6.9 and newer, Kilobyte registers seven abilities that AI assistants and MCP adapters can call. Each one has a JSON schema and checks the user&rsquo;s permission for the image.
									</dd>
								</div>
								<div>
									<dt>WP-CLI</dt>
									<dd className="kb-body">
										Check savings, optimize in batches, restore images, and clear old backup records from your terminal or deploy scripts.
									</dd>
								</div>
								<div>
									<dt>PHP hooks</dt>
									<dd className="kb-body">
										Lock settings in code across client sites, skip chosen images, and purge your page cache when a copy is created or restored.
									</dd>
								</div>
							</dl>
						</div>

						<Card className="kb-code-card">
							<TabPanel tabs={ CODE_TABS }>
								{ ( tab ) => {
									const item = CODE_TABS.find( ( t ) => t.name === tab.name ) ?? CODE_TABS[ 0 ];
									const isCopied = copiedTab === item.name;
									return (
										<div>
											<div className="kb-code-toolbar">
												<span className="kb-code-filename">{ item.filename }</span>
												<Button
													variant="tertiary"
													size="small"
													icon={ isCopied ? check : copy }
													onClick={ () => void handleCopySnippet( item.name, item.snippet ) }
												>
													{ isCopied ? 'Copied' : 'Copy code' }
												</Button>
											</div>
											<pre className="kb-code-pre">
												<code>{ item.snippet }</code>
											</pre>
										</div>
									);
								} }
							</TabPanel>
						</Card>
					</div>
				</section>

				<section id="questions" className="kb-section kb-section-wash">
					<div className="kb-container kb-grid">
						<div className="kb-faq-head">
							<p className="kb-kicker">Questions</p>
							<h2 className="kb-title">What people ask before they install.</h2>
						</div>

						<div className="kb-faq-list">
							{ FAQ_ITEMS.map( ( item, index ) => (
								<details key={ item.q } className="kb-faq-item" open={ index === 0 }>
									<summary>
										<span className="kb-h3">{ item.q }</span>
										<Icon icon={ chevronDown } size={ 24 } />
									</summary>
									<p className="kb-body">{ item.a }</p>
								</details>
							) ) }
						</div>
					</div>
				</section>

				<section className="kb-band kb-close-band">
					<div className="kb-container">
						<h2 className="kb-title">Make your images smaller today, and keep every original.</h2>
						<p className="kb-lead">
							Install Kilobyte Image Optimizer from WordPress.org, or try it first in a demo WordPress site that runs in your browser.
						</p>

						<CallToActionButtons />
					</div>
				</section>
			</main>

			<footer className="kb-footer">
				<div className="kb-container kb-footer-inner">
					<div className="kb-footer-brand">
						<img src="assets/icon-128x128.png" alt="" width={ 24 } height={ 24 } />
						<p className="kb-small">
							Kilobyte Image Optimizer is free software under the GPL, version 2 or later.
						</p>
					</div>

					<div className="kb-footer-links kb-small">
						<a href={ WP_ORG_URL } target="_blank" rel="noopener noreferrer">
							Plugin page on WordPress.org
						</a>
						<a href={ SUPPORT_URL } target="_blank" rel="noopener noreferrer">
							Support forum
						</a>
						<a href={ GITHUB_URL } target="_blank" rel="noopener noreferrer">
							Source code on GitHub
						</a>
						<a href="https://joseph.gabi.to" target="_blank" rel="noopener noreferrer">
							Made by Joseph Gabito
						</a>
					</div>
				</div>
			</footer>

			{ modalScreen && (
				<Modal
					title={ `${ modalScreen.title } screen` }
					onRequestClose={ () => setModalScreen( null ) }
					className="kb-screenshot-modal"
				>
					<img
						src={ modalScreen.src }
						alt={ modalScreen.alt }
						width={ modalScreen.width }
						height={ modalScreen.height }
						className="kb-modal-img"
					/>
					<p className="kb-small">{ modalScreen.body }</p>
				</Modal>
			) }
		</div>
	);
}
