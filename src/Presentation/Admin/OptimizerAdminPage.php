<?php
/**
 * Admin page controller under Media -> Kilobyte (Gutenberg @wordpress/components React UI).
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Admin;

use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpRasterImage;

/**
 * Mounts the native WordPress React admin screen under Media -> Kilobyte.
 */
final class OptimizerAdminPage {

	public const PAGE_SLUG = 'kilobyte-image-optimizer';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepositoryInterface
	 */
	private SettingsRepositoryInterface $settings;

	/**
	 * Plugin base URL.
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Plugin base directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepositoryInterface $settings   Settings repository.
	 * @param string                      $plugin_url Plugin URL.
	 * @param string                      $plugin_dir Plugin directory path.
	 * @param string                      $version    Plugin version.
	 */
	public function __construct(
		SettingsRepositoryInterface $settings,
		string $plugin_url,
		string $plugin_dir,
		string $version
	) {
		$this->settings   = $settings;
		$this->plugin_url = $plugin_url;
		$this->plugin_dir = $plugin_dir;
		$this->version    = $version;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register Media submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_media_page(
			__( 'Image optimization', 'kilobyte-image-optimizer' ),
			__( 'Optimization', 'kilobyte-image-optimizer' ),
			'upload_files',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue WordPress Core @wordpress/components bundle on the plugin admin screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'media_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_path = $this->plugin_dir . 'build/admin.asset.php';
		$asset      = is_readable( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				'version'      => $this->version,
			);

		wp_enqueue_media();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'kilobyte-io-fine-tuning',
			$this->plugin_url . 'assets/css/fine-tuning.css',
			array( 'wp-components' ),
			$this->version
		);

		$deps = is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();

		wp_enqueue_script(
			'kilobyte-io-admin',
			$this->plugin_url . 'build/admin.js',
			$deps,
			(string) $asset['version'],
			true
		);

		wp_set_script_translations( 'kilobyte-io-admin', 'kilobyte-image-optimizer' );
		wp_localize_script(
			'kilobyte-io-admin',
			'KilobyteIOAdmin',
			array(
				'workerUrl'   => esc_url_raw( $this->plugin_url . 'build/image-worker.js?ver=' . $this->version ),
				'outputMimes' => WpRasterImage::output_mimes(),
				'canManage'   => current_user_can( 'manage_options' ),
				'settings'    => $this->settings->load()->to_array(),
			)
		);
	}

	/**
	 * Render the React mount node.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kilobyte-image-optimizer' ) );
		}

		echo '<div id="kilobyte-io-root"></div>';
	}
}
