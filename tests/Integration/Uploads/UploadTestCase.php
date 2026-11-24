<?php
/**
 * Shared steps for tests of what happens after an image is uploaded.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Presentation\Hooks\AutomaticUploadOptimization;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;

require_once __DIR__ . '/WorkerClock.php';
require_once __DIR__ . '/worker-clock-functions.php';

/**
 * Uploads images the way the block editor and Media Library do, runs the
 * background worker the way WP-Cron does, and reads the Media Library status.
 */
abstract class UploadTestCase extends TestCase {

	/**
	 * Status text the Media Library shows while a background copy is waiting, with its retry button.
	 */
	protected const QUEUED = 'Original saved. Optimization is queued. Optimize now';

	/**
	 * Status text the Media Library shows for an image with no copy and no job.
	 */
	protected const NOT_OPTIMIZED = 'Not optimized';

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function tear_down(): void {
		WorkerClock::reset();
		parent::tear_down();
	}

	/**
	 * Uploads a file through the REST API, as the block editor and Media Library do.
	 *
	 * @param string               $file    Image file to upload.
	 * @param array<string, mixed> $params  Extra request parameters.
	 * @param array<string, mixed> $headers Request headers.
	 * @return int Attachment ID.
	 */
	protected function upload_through_rest( string $file, array $params = array(), array $headers = array() ): int {
		$response = $this->rest(
			'POST',
			'/wp/v2/media',
			$params,
			array(
				'file' => array(
					'name'     => wp_basename( $file ),
					'type'     => (string) wp_get_image_mime( $file ),
					'tmp_name' => $file,
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $file ),
				),
			),
			$headers
		);
		$this->assertSame( 201, $response->get_status(), 'The upload failed: ' . wp_json_encode( $response->get_data() ) );
		return (int) $response->get_data()['id'];
	}

	/**
	 * Runs the background worker the way WP-Cron does: the due event is removed, then its hook runs.
	 *
	 * @return void
	 */
	protected function run_background_worker(): void {
		$next = wp_next_scheduled( AutomaticUploadOptimization::EVENT );
		if ( false !== $next ) {
			wp_unschedule_event( $next, AutomaticUploadOptimization::EVENT );
		}
		do_action( AutomaticUploadOptimization::EVENT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own cron hook.
	}

	/**
	 * Seconds until WordPress runs the background worker, or null when it is not scheduled.
	 *
	 * @return int|null
	 */
	protected function seconds_until_worker_runs(): ?int {
		$next = wp_next_scheduled( AutomaticUploadOptimization::EVENT );
		return false === $next ? null : $next - time();
	}

	/**
	 * The text of the Optimization cell in the Media Library list view.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	protected function media_library_status( int $id ): string {
		ob_start();
		do_action( 'manage_media_custom_column', 'kilobyte_io', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress fires this hook for each list view cell.
		return trim( wp_strip_all_tags( (string) ob_get_clean() ) );
	}

	/**
	 * Saves the site's optimization preferences, as the settings screen does.
	 *
	 * @param array<string, mixed> $settings Preferences to change.
	 * @return void
	 */
	protected function set_preferences( array $settings ): void {
		update_option( 'kilobyte_io_settings', $settings );
	}

	/**
	 * Stops WordPress's image editors from loading, as on a host whose GD or Imagick is broken.
	 *
	 * @return void
	 */
	protected function break_image_editors(): void {
		add_filter( 'wp_image_editors', '__return_empty_array' );
	}
}
