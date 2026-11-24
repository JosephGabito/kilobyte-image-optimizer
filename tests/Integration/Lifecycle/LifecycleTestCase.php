<?php
/**
 * Shared steps for tests about restoring originals and cleaning up old copies.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;

/**
 * Helpers for restore, retirement and deletion tests.
 */
abstract class LifecycleTestCase extends TestCase {

	/**
	 * Where the plugin stores retired copies and their due times (see WpVariantRetirement::PENDING).
	 */
	protected const RETIRED_META = '_kilobyte_io_retired_variants';

	/**
	 * Name prefix of the options holding cleanup work for files with no record (see WpRemovedVariantQueue::PREFIX).
	 */
	protected const BATCH_PREFIX = 'kilobyte_io_cleanup_batch_';

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	/**
	 * Uploads a JPEG and makes its WebP copies on the server.
	 *
	 * @return int Attachment ID.
	 */
	protected function optimized_jpeg(): int {
		$id = $this->upload( \Kilobyte\ImageOptimizer\Tests\Support\Images::jpeg( 320, 240 ) );
		$this->assertFalse( $this->assertRestOk( $this->optimize_on_server( $id ) )['skipped'] );
		return $id;
	}

	/**
	 * Copies Kilobyte wrote for an attachment, found on disk by the attachment marker in their names.
	 *
	 * @param int    $id        Attachment ID.
	 * @param string $extension File extension of the copies.
	 * @return string[] Absolute paths, sorted.
	 */
	protected function copies_on_disk( int $id, string $extension = 'webp' ): array {
		$uploads = wp_upload_dir( null, false );
		$files   = glob( $uploads['basedir'] . "/*/*/*.kilobyte-{$id}-*.{$extension}" );
		$files   = is_array( $files ) ? $files : array();
		sort( $files );
		return $files;
	}

	/**
	 * Restores an attachment through the REST API.
	 *
	 * @param int $id Attachment ID.
	 * @return \WP_REST_Response
	 */
	protected function restore( int $id ): \WP_REST_Response {
		return $this->rest( 'POST', "/restore/{$id}" );
	}

	/**
	 * Moves the due time of every retired copy of an attachment into the past, as if the 24 hours had passed.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	protected function make_retired_copies_due( int $id ): void {
		$pending = get_post_meta( $id, self::RETIRED_META, true );
		$this->assertIsArray( $pending, 'The attachment has no retired copies.' );
		foreach ( $pending as $path => $entry ) {
			if ( is_array( $entry ) ) {
				$pending[ $path ]['due'] = time() - 1;
			}
		}
		update_post_meta( $id, self::RETIRED_META, $pending );
	}

	/**
	 * Moves every stored cleanup batch to a due time in the past.
	 *
	 * The due time is part of each batch's option name, so the batch is saved again under the earlier name.
	 *
	 * @return int Number of batches moved.
	 */
	protected function make_cleanup_batches_due(): int {
		$keys = $this->cleanup_batches();
		foreach ( $keys as $key ) {
			$job = get_option( $key );
			delete_option( $key );
			add_option( self::BATCH_PREFIX . sprintf( '%012d', time() - 1 ) . substr( $key, strlen( self::BATCH_PREFIX ) + 12 ), $job, '', false );
		}
		return count( $keys );
	}

	/**
	 * Option names of the stored cleanup batches.
	 *
	 * @return string[]
	 */
	protected function cleanup_batches(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::BATCH_PREFIX ) . '%' ) );
	}

	/**
	 * Runs the hourly cleanup event the way WordPress cron does.
	 *
	 * @return void
	 */
	protected function run_cleanup_cron(): void {
		do_action( WpVariantRetirement::EVENT );
	}

	/**
	 * Runs the hourly cleanup event in a new PHP request, which has not yet measured the Media Library.
	 *
	 * @return void
	 */
	protected function run_cleanup_cron_in_new_request(): void {
		( new WpVariantRetirement( new WpAttachmentRepository() ) )->clean_due_files();
	}

	/**
	 * Asserts every file exists.
	 *
	 * @param string[] $files   Absolute paths.
	 * @param string   $message Failure message.
	 * @return void
	 */
	protected function assertFilesExist( array $files, string $message = '' ): void {
		$this->assertNotEmpty( $files, 'No files to check.' );
		foreach ( $files as $file ) {
			$this->assertFileExists( $file, $message );
		}
	}

	/**
	 * Asserts no file exists.
	 *
	 * @param string[] $files   Absolute paths.
	 * @param string   $message Failure message.
	 * @return void
	 */
	protected function assertFilesGone( array $files, string $message = '' ): void {
		$this->assertNotEmpty( $files, 'No files to check.' );
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file, $message );
		}
	}

	/**
	 * Media Library list-view text in the Optimization column.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	protected function media_column( int $id ): string {
		ob_start();
		do_action( 'manage_media_custom_column', 'kilobyte_io', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		return trim( wp_strip_all_tags( (string) ob_get_clean() ) );
	}

	/**
	 * Dashboard counters from the stats endpoint.
	 *
	 * @return array<string, mixed>
	 */
	protected function stats(): array {
		return $this->assertRestOk( $this->rest( 'GET', '/stats' ) );
	}
}
