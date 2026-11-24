<?php
/**
 * The `wp kilobyte` commands a site owner or developer runs in a terminal.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Presentation;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Presentation\Cli\OptimizerCliCommand;
use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;
use Kilobyte\ImageOptimizer\Tests\Support\WpCliStopped;
use WP_CLI;

require_once dirname( __DIR__, 2 ) . '/Support/WpCli.php';

final class CliCommandTest extends TestCase {

	/**
	 * The `wp kilobyte` command object the plugin registered.
	 *
	 * @var OptimizerCliCommand
	 */
	private OptimizerCliCommand $command;

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
		WP_CLI::$commands = array();
		kilobyte_io_bootstrap();
		$this->assertInstanceOf( OptimizerCliCommand::class, WP_CLI::$commands['kilobyte'] ?? null, 'The plugin did not register the kilobyte command.' );
		$this->command = WP_CLI::$commands['kilobyte'];
		WP_CLI::reset();
	}

	public function test_stats_lists_images_copies_savings_and_backups(): void {
		$optimized = $this->upload( Images::jpeg() );
		$this->upload( Images::jpeg( 640, 480, 'second.jpg' ) );
		$result = $this->assertRestOk( $this->optimize_on_server( $optimized ) );

		$lines = $this->run_command( 'stats' );

		$this->assertSame(
			array(
				'log: Total images:     2',
				'log: Optimized:        1',
				'log: Remaining:        1',
				sprintf( 'log: Transfer savings: %s (%s%%)', size_format( $result['saved_bytes'], 1 ), $result['saved_percent'] ),
				'log: Backups:          0 (0.0 B)',
			),
			$lines
		);
	}

	public function test_stats_warns_when_totals_may_be_stale_and_reconcile_rebuilds_them(): void {
		$kept    = $this->upload( Images::jpeg() );
		$deleted = $this->upload( Images::jpeg( 640, 480, 'deleted.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $kept ) );
		$this->assertRestOk( $this->optimize_on_server( $deleted ) );
		// The database cannot answer lock requests while this image is deleted, so the plugin cannot update its totals.
		$no_locks = static fn ( $sql ) => is_string( $sql ) && 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ? 'SELECT NULL, CONNECTION_ID()' : $sql;
		add_filter( 'query', $no_locks );
		wp_delete_attachment( $deleted, true );
		remove_filter( 'query', $no_locks );

		$stale = $this->run_command( 'stats' );
		WP_CLI::reset();
		$rebuilt = $this->run_command( 'stats', array(), array( 'reconcile' => true ) );

		$this->assertContains( 'warning: The image totals may be out of date. Run wp kilobyte stats --reconcile to rebuild them.', $stale );
		$this->assertContains( 'log: Total images:     1', $rebuilt );
		$this->assertContains( 'log: Optimized:        1', $rebuilt );
		$this->assertSame( array(), preg_grep( '/^warning:/', $rebuilt ), 'Rebuilding the totals must clear the warning.' );
		WP_CLI::reset();
		$this->assertSame( array(), preg_grep( '/^warning:/', $this->run_command( 'stats' ) ) );
	}

	public function test_stats_reconcile_stops_with_an_error_when_the_totals_cannot_be_saved(): void {
		$this->reconnect_database();

		$this->assertCommandStops(
			'Could not refresh image totals. Retry after current jobs finish. If it repeats, check that WordPress can save settings.',
			'stats',
			array(),
			array( 'reconcile' => true )
		);
	}

	public function test_optimize_makes_a_copy_of_every_remaining_image(): void {
		$first  = $this->upload( Images::jpeg() );
		$second = $this->upload( Images::jpeg( 640, 480, 'second.jpg' ) );

		$lines = $this->run_command( 'optimize' );

		$this->assertMatchesRegularExpression( "/^log: #{$first} photo\\.jpg -> WEBP \\(-\\d+(\\.\\d)?%\\)$/", $lines[1] );
		$this->assertMatchesRegularExpression( "/^log: #{$second} second\\.jpg -> WEBP \\(-\\d+(\\.\\d)?%\\)$/", $lines[0] );
		$this->assertMatchesRegularExpression( '/^success: Processed 2 images\. Estimated transfer savings: \d+(\.\d)? KB\.$/', $lines[2] );
		$this->assertCount( 3, $lines );
		$this->assertNotEmpty( $this->delivered_copies( $first ) );
		$this->assertNotEmpty( $this->delivered_copies( $second ) );
	}

	public function test_optimize_stops_at_the_limit_and_skips_images_that_already_have_a_copy(): void {
		$oldest  = $this->upload( Images::jpeg( 640, 480, 'oldest.jpg' ) );
		$middle  = $this->upload( Images::jpeg( 640, 480, 'middle.jpg' ) );
		$newest  = $this->upload( Images::jpeg( 640, 480, 'newest.jpg' ) );
		$another = $this->upload( Images::jpeg( 640, 480, 'already-done.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $another ) );

		$lines = $this->run_command( 'optimize', array(), array( 'limit' => '2' ) );

		$this->assertStringStartsWith( "log: #{$newest} newest.jpg -> WEBP", $lines[0] );
		$this->assertStringStartsWith( "log: #{$middle} middle.jpg -> WEBP", $lines[1] );
		$this->assertStringStartsWith( 'success: Processed 2 images.', $lines[2] );
		$this->assertCount( 3, $lines );
		$this->assertSame( array(), $this->delivered_copies( $oldest ), 'The image past the limit must wait for the next run.' );
	}

	public function test_optimize_reports_images_whose_files_cannot_be_read(): void {
		$broken = $this->upload( Images::not_an_image( 'broken.jpg' ) );

		$lines = $this->run_command( 'optimize' );

		$this->assertSame(
			array(
				"log: #{$broken} broken.jpg. WordPress could not open the source file. Check your media storage or recover the file, then try again.",
				'log: Skipped 1 unavailable files. Check that WordPress can read your media storage.',
				'success: Processed 1 images. Estimated transfer savings: 0.0 B.',
			),
			$lines
		);
	}

	public function test_optimize_explains_an_image_it_leaves_alone(): void {
		$animated = $this->upload( Images::animated_gif() );

		$lines = $this->run_command( 'optimize' );

		$this->assertSame(
			array(
				"log: #{$animated} animated.gif: The original was kept to preserve its animation.",
				'success: Processed 1 images. Estimated transfer savings: 0.0 B.',
			),
			$lines
		);
	}

	public function test_optimize_ends_with_an_error_when_an_image_fails(): void {
		$locked = $this->upload( Images::jpeg() );
		$this->hold_lock_in_other_request( "image:{$locked}" );

		$lines = $this->assertCommandStops( 'Processed 1 images; 1 failed. Estimated transfer savings: 0.0 B.', 'optimize' );

		$this->assertSame(
			"warning: #{$locked} photo.jpg. WordPress could not lock this image for processing. Wait a moment and retry. If it keeps happening, ask your host to check database locks.",
			$lines[0]
		);
	}

	public function test_restore_by_id_stops_offering_the_copy(): void {
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$lines = $this->run_command( 'restore', array( (string) $id ) );

		$this->assertSame( array( "success: Restored attachment #{$id}." ), $lines );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_restore_by_id_explains_why_an_image_cannot_be_restored(): void {
		$id = $this->upload( Images::jpeg() );

		$this->assertCommandStops(
			"#{$id} photo.jpg. There is no original to restore for this image. Check the Media Library for its current status.",
			'restore',
			array( (string) $id )
		);
	}

	public function test_restore_without_an_id_or_all_asks_for_one(): void {
		$this->assertCommandStops( 'Provide an attachment ID or pass --all.', 'restore' );
	}

	public function test_restore_all_restores_every_optimized_image(): void {
		$first  = $this->upload( Images::jpeg() );
		$second = $this->upload( Images::jpeg( 640, 480, 'second.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $first ) );
		$this->assertRestOk( $this->optimize_on_server( $second ) );

		$lines = $this->run_command( 'restore', array(), array( 'all' => true ) );

		$this->assertSame( array( 'success: Restored 2 images.' ), $lines );
		$this->assertSame( array(), $this->delivered_copies( $first ) );
		$this->assertSame( array(), $this->delivered_copies( $second ) );
	}

	public function test_restore_all_ends_with_an_error_when_an_image_fails(): void {
		$restorable = $this->upload( Images::jpeg() );
		$locked     = $this->upload( Images::jpeg( 640, 480, 'locked.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $restorable ) );
		$this->assertRestOk( $this->optimize_on_server( $locked ) );
		$this->hold_lock_in_other_request( "image:{$locked}" );

		$lines = $this->assertCommandStops( 'Restored 1 images; 1 failed.', 'restore', array(), array( 'all' => true ) );

		$this->assertSame(
			"warning: #{$locked} locked.jpg. WordPress could not lock this image for processing. Wait a moment and retry. If it keeps happening, ask your host to check database locks.",
			$lines[0]
		);
		$this->assertSame( array(), $this->delivered_copies( $restorable ) );
	}

	public function test_restore_all_works_through_more_than_one_page_of_images(): void {
		// Records written for 51 images whose files are gone: each restore fails quickly and is reported.
		$ids = self::factory()->attachment->create_many( 51, array( 'post_mime_type' => 'image/jpeg' ) );
		foreach ( $ids as $id ) {
			update_post_meta(
				$id,
				WpAttachmentRepository::META_KEY,
				array(
					'original_size'  => 1000,
					'optimized_size' => 500,
					'format'         => 'webp',
					'original_name'  => "gone-{$id}.jpg",
					'variant_path'   => "2026/11/gone-{$id}.jpg.webp",
					'delivery_mode'  => 'sibling',
				)
			);
		}

		$lines = $this->assertCommandStops( 'Restored 0 images; 51 failed.', 'restore', array(), array( 'all' => true ) );

		$this->assertCount( 51, preg_grep( '/^warning: #\d+ .*WordPress could not verify the original\./', $lines ) );
	}

	public function test_restore_reports_a_legacy_backup_left_in_place(): void {
		$id = $this->legacy_in_place_image();

		$lines = $this->run_command( 'restore', array( (string) $id ) );

		$this->assertSame(
			array(
				"success: Restored attachment #{$id}.",
				"log: #{$id}: The legacy backup is still in kilobyte-backups under uploads.",
			),
			$lines
		);
		$this->assertSame( 'image/jpeg', wp_get_image_mime( (string) get_attached_file( $id ) ) );
	}

	public function test_restore_warns_when_thumbnails_could_not_be_made(): void {
		$id = $this->legacy_in_place_image();
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$lines = $this->run_command( 'restore', array( (string) $id ) );

		$this->assertContains(
			"warning: #{$id}: Some thumbnails are missing. Try Regenerate thumbnails in Media > Optimization, or use the full image if your host cannot make smaller sizes.",
			$lines
		);
	}

	public function test_purge_backups_clears_references_when_the_backup_folder_is_empty(): void {
		$this->assertSame( array( 'success: Cleared the legacy backup references.' ), $this->run_command( 'purge_backups' ) );
	}

	public function test_purge_backups_refuses_while_backed_up_originals_remain(): void {
		$this->legacy_in_place_image();

		$this->assertCommandStops(
			'The legacy backup folder still contains files. Recover any remaining originals and archive the kilobyte-backups folder before clearing its references.',
			'purge_backups'
		);
	}

	/**
	 * Runs a command and returns the lines it printed.
	 *
	 * @param string               $method     Command method.
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return string[]
	 */
	private function run_command( string $method, array $args = array(), array $assoc_args = array() ): array {
		if ( 'purge_backups' === $method ) {
			$this->command->purge_backups();
		} else {
			$this->command->{$method}( $args, $assoc_args );
		}
		return WP_CLI::$lines;
	}

	/**
	 * Asserts the command ends with this error and returns every line it printed.
	 *
	 * @param string               $message    Expected error.
	 * @param string               $method     Command method.
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return string[]
	 */
	private function assertCommandStops( string $message, string $method, array $args = array(), array $assoc_args = array() ): array {
		try {
			$this->run_command( $method, $args, $assoc_args );
		} catch ( WpCliStopped $stopped ) {
			$this->assertSame( 'error: ' . $message, end( WP_CLI::$lines ) );
			return WP_CLI::$lines;
		}
		$this->fail( 'The command finished without an error. It printed: ' . implode( "\n", WP_CLI::$lines ) );
	}

	/**
	 * An image an older plugin version converted in place: the Media Library file is a WebP
	 * and the original JPEG sits in the legacy kilobyte-backups folder.
	 *
	 * @return int Attachment ID.
	 */
	private function legacy_in_place_image(): int {
		$id      = $this->upload( Images::webp( 640, 480, 'converted.webp' ) );
		$uploads = wp_upload_dir( null, false );
		$jpeg    = Images::jpeg();
		wp_mkdir_p( $uploads['basedir'] . '/kilobyte-backups' );
		copy( $jpeg, $uploads['basedir'] . '/kilobyte-backups/converted.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		update_post_meta(
			$id,
			WpAttachmentRepository::META_KEY,
			array(
				'original_size'  => filesize( $jpeg ),
				'optimized_size' => filesize( (string) get_attached_file( $id ) ),
				'format'         => 'webp',
				'original_name'  => 'converted.jpg',
				'backup_path'    => 'kilobyte-backups/converted.jpg',
				'backup_bytes'   => filesize( $jpeg ),
				'delivery_mode'  => 'in_place',
				'optimized_at'   => gmdate( 'Y-m-d H:i:s' ),
				'status'         => 'optimized',
				'engine'         => 'browser',
			)
		);
		return $id;
	}
}
