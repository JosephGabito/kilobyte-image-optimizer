<?php
/**
 * Uploads whose thumbnails WordPress makes in the browser (client-side media processing, WordPress 7.1+).
 *
 * The editor sends the original with generate_sub_sizes=false, sends each thumbnail
 * separately, then calls /wp/v2/media/{id}/finalize. Kilobyte waits for finalize before
 * it queues the background copy, so the copy is made from the finished attachment.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class CoreBrowserUploadTest extends UploadTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_is_client_side_media_processing_enabled' ) ) {
			$this->markTestSkipped( 'This WordPress version has no client-side media processing.' );
		}
		add_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
		// WordPress registers the sideload and finalize routes only on sites where this is
		// enabled, so the REST server is built again with it on.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own REST server global.
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own REST server global.
		parent::tear_down();
	}

	public function test_nothing_is_queued_until_the_browser_finalizes_the_upload(): void {
		$id = $this->upload_through_rest( Images::jpeg(), array( 'generate_sub_sizes' => false ) );

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_finalizing_the_upload_queues_the_copy_and_the_worker_makes_it(): void {
		$id = $this->upload_through_rest( Images::jpeg(), array( 'generate_sub_sizes' => false ) );

		$this->finalize( $id );

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
		$this->make_upload_job_due( $id );
		$this->run_background_worker();
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_finalizing_an_upload_made_while_optimization_was_off_queues_nothing(): void {
		$this->set_preferences( array( 'enabled' => false ) );
		$id = $this->upload_through_rest( Images::jpeg(), array( 'generate_sub_sizes' => false ) );
		$this->set_preferences( array( 'enabled' => true ) );

		$this->finalize( $id );

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_turning_optimization_off_before_finalize_drops_the_waiting_upload(): void {
		$id = $this->upload_through_rest( Images::jpeg(), array( 'generate_sub_sizes' => false ) );
		$this->set_preferences( array( 'enabled' => false ) );
		$this->finalize( $id );
		$this->set_preferences( array( 'enabled' => true ) );

		$this->finalize( $id );

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_a_preferences_filter_that_throws_while_saving_the_upload_does_not_break_it(): void {
		$thrown = false;
		add_filter(
			'kilobyte_io_settings',
			static function ( $settings ) use ( &$thrown ) {
				// Fail once, while WordPress finishes saving the upload through the REST API.
				if ( ! $thrown && doing_action( 'rest_after_insert_attachment' ) ) {
					$thrown = true;
					throw new \RuntimeException( 'An agency settings filter failed.' );
				}
				return $settings;
			}
		);

		$id = $this->upload_through_rest( Images::jpeg(), array( 'generate_sub_sizes' => false ) );
		$this->finalize( $id );

		$this->assertTrue( $thrown );
		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
	}

	public function test_a_failed_finalize_queues_nothing(): void {
		$id = $this->upload_through_rest( Images::jpeg(), array( 'generate_sub_sizes' => false ) );

		$response = $this->rest(
			'POST',
			"/wp/v2/media/{$id}/finalize",
			array(
				'sub_sizes' => array(
					array(
						'image_size' => 'thumbnail',
						'file'       => 'never-uploaded-150x150.jpg',
					),
				),
			)
		);

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
	}

	/**
	 * Tells WordPress the browser has sent every thumbnail, as the block editor does last.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function finalize( int $id ): void {
		$response = $this->rest( 'POST', "/wp/v2/media/{$id}/finalize" );
		$this->assertLessThan( 400, $response->get_status(), 'Finalize failed: ' . wp_json_encode( $response->get_data() ) );
	}
}
