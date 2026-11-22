<?php
/**
 * Plugin Name:       Kilobyte Image Optimizer
 * Description:       Compress images to WebP or AVIF, keep original media links, and regenerate thumbnails.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Joseph Gabito
 * Author URI:        https://joseph.gabi.to
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kilobyte-image-optimizer
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KILOBYTE_IO_VERSION', '1.0.0' );
define( 'KILOBYTE_IO_FILE', __FILE__ );
define( 'KILOBYTE_IO_DIR', plugin_dir_path( __FILE__ ) );
define( 'KILOBYTE_IO_URL', plugin_dir_url( __FILE__ ) );

// Zero-dependency PSR-4 autoloader for Kilobyte\ImageOptimizer\.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Kilobyte\\ImageOptimizer\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$path     = KILOBYTE_IO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

use Kilobyte\ImageOptimizer\Application\MarkAlreadyOptimalUseCase;
use Kilobyte\ImageOptimizer\Application\OptimizeFromBrowserUseCase;
use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Application\PurgeBackupsUseCase;
use Kilobyte\ImageOptimizer\Application\RegenerateThumbnailsUseCase;
use Kilobyte\ImageOptimizer\Application\RestoreAttachmentUseCase;
use Kilobyte\ImageOptimizer\Application\SaveOptimizedVariant;
use Kilobyte\ImageOptimizer\Domain\Policy\AltTextPolicy;
use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\OptionSettingsRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpFilesystemBackupStorage;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpImageEditorCompressor;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpOptimizationEvents;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Presentation\Admin\OptimizerAdminPage;
use Kilobyte\ImageOptimizer\Presentation\Cli\OptimizerCliCommand;
use Kilobyte\ImageOptimizer\Presentation\Hooks\FrontendImageDelivery;
use Kilobyte\ImageOptimizer\Presentation\Hooks\MediaHooks;
use Kilobyte\ImageOptimizer\Presentation\Rest\OptimizerRestController;

/**
 * Composition root: wire DDD layers and register WordPress adapters.
 *
 * @return void
 */
function kilobyte_io_bootstrap(): void {
	$settings_repo   = new OptionSettingsRepository();
	$attachment_repo = new WpAttachmentRepository();
	$backup_storage  = new WpFilesystemBackupStorage();
	$server_editor   = new WpImageEditorCompressor();

	$alt_policy         = new AltTextPolicy();
	$compression_policy = new CompressionPolicy();

	$events           = new WpOptimizationEvents();
	$save_variant     = new SaveOptimizedVariant( $attachment_repo, $events );
	$optimize_browser = new OptimizeFromBrowserUseCase(
		$attachment_repo,
		$events,
		$settings_repo,
		$compression_policy,
		$save_variant
	);
	$optimize_server  = new OptimizeOnServerUseCase(
		$attachment_repo,
		$events,
		$settings_repo,
		$server_editor,
		$compression_policy,
		$save_variant
	);
	$retirement       = new WpVariantRetirement( $attachment_repo );
	$restore          = new RestoreAttachmentUseCase( $attachment_repo, $events, $backup_storage, $retirement );
	$regenerate       = new RegenerateThumbnailsUseCase( $attachment_repo );
	$mark_optimal     = new MarkAlreadyOptimalUseCase( $attachment_repo, $events, $save_variant );
	$purge_backups    = new PurgeBackupsUseCase( $backup_storage, $attachment_repo );

	$rest_controller = new OptimizerRestController(
		$attachment_repo,
		$settings_repo,
		$optimize_browser,
		$optimize_server,
		$restore,
		$regenerate,
		$mark_optimal,
		$purge_backups
	);
	$media_hooks     = new MediaHooks(
		$settings_repo,
		$attachment_repo,
		$retirement,
		$optimize_server,
		$alt_policy,
		$compression_policy,
		KILOBYTE_IO_URL,
		KILOBYTE_IO_DIR,
		KILOBYTE_IO_VERSION
	);
	$frontend_serve  = new FrontendImageDelivery( $attachment_repo );
	$admin_page      = new OptimizerAdminPage(
		$settings_repo,
		KILOBYTE_IO_URL,
		KILOBYTE_IO_DIR,
		KILOBYTE_IO_VERSION
	);
	$cli_command     = new OptimizerCliCommand(
		$attachment_repo,
		$optimize_server,
		$restore,
		$purge_backups
	);

	$rest_controller->register();
	$media_hooks->register();
	$frontend_serve->register();
	add_action( WpVariantRetirement::EVENT, array( $retirement, 'clean_due_files' ) );
	kilobyte_io_resume_cleanup();
	$admin_page->register();
	$cli_command->register();
}
add_action( 'plugins_loaded', 'kilobyte_io_bootstrap' );

/**
 * Initialize default settings on plugin activation.
 *
 * @return void
 */
function kilobyte_io_activate(): void {
	( new OptionSettingsRepository() )->ensure_defaults();
	kilobyte_io_resume_cleanup();
}
register_activation_hook( __FILE__, 'kilobyte_io_activate' );

/**
 * Resume scheduled cleanup for this site after reinstalling the plugin.
 *
 * @return void
 */
function kilobyte_io_resume_cleanup(): void {
	$option = 'kilobyte_io_resume_cleanup';
	if ( ! get_option( $option ) ) {
		return;
	}

	$hook = WpVariantRetirement::EVENT;
	if ( wp_next_scheduled( $hook ) || wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $hook ) ) {
		delete_option( $option );
	}
}

/**
 * Add direct Optimize images link in the Plugins list.
 *
 * @param array<int, string> $links Existing action links.
 * @return array<int, string>
 */
function kilobyte_io_plugin_action_links( array $links ): array {
	$url = admin_url( 'upload.php?page=' . OptimizerAdminPage::PAGE_SLUG );
	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Optimize images', 'kilobyte-image-optimizer' )
		)
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'kilobyte_io_plugin_action_links' );
