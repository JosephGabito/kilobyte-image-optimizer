<?php
/**
 * The library totals shown on the dashboard, read through the stats endpoint.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LibraryTestCase.php';

final class StatsTest extends LibraryTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function test_an_empty_library_reports_no_images_and_nothing_to_rebuild(): void {
		$this->assertSame(
			array(
				'total_images'     => 0,
				'optimized_count'  => 0,
				'remaining_count'  => 0,
				'total_original'   => 0,
				'total_optimized'  => 0,
				'total_saved'      => 0,
				'saved_percent'    => 0.0,
				'backup_count'     => 0,
				'backup_bytes'     => 0,
				'restorable_count' => 0,
				'needs_reconcile'  => false,
			),
			$this->stats()
		);
	}

	public function test_optimizing_an_image_adds_its_sizes_to_the_totals(): void {
		$this->upload( Images::png( 64, 64 ) );
		$id            = $this->upload( Images::jpeg() );
		$original_size = filesize( (string) get_attached_file( $id ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );
		$stats  = $this->stats();

		$this->assertSame( 2, $stats['total_images'] );
		$this->assertSame( 1, $stats['optimized_count'] );
		$this->assertSame( 1, $stats['remaining_count'] );
		$this->assertSame( $original_size, $stats['total_original'] );
		$this->assertSame( $result['optimized_size'], $stats['total_optimized'] );
		$this->assertSame( $original_size - $result['optimized_size'], $stats['total_saved'] );
		$this->assertSame( 1, $stats['restorable_count'], 'The WebP copy can be removed again with Restore.' );
		$this->assertSame( 0, $stats['backup_count'], 'A WebP copy never moves the original into a backup.' );
	}

	public function test_restoring_an_image_takes_it_back_out_of_the_totals(): void {
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertRestOk( $this->rest( 'POST', "/restore/{$id}" ) );
		$stats = $this->stats();

		$this->assertSame( 0, $stats['optimized_count'] );
		$this->assertSame( 1, $stats['remaining_count'] );
		$this->assertSame( 0, $stats['total_original'] );
		$this->assertSame( 0, $stats['total_optimized'] );
		$this->assertSame( 0, $stats['restorable_count'] );
		$this->assertFalse( $stats['needs_reconcile'] );
	}

	public function test_an_image_optimized_again_after_a_restore_is_counted_once(): void {
		$id            = $this->upload( Images::jpeg() );
		$original_size = filesize( (string) get_attached_file( $id ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$this->assertRestOk( $this->rest( 'POST', "/restore/{$id}" ) );

		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$stats = $this->stats();

		$this->assertSame( 1, $stats['optimized_count'] );
		$this->assertSame( $original_size, $stats['total_original'] );
		$this->assertSame( 1, $stats['restorable_count'] );
	}

	public function test_deleting_an_optimized_image_takes_it_out_of_the_totals(): void {
		$kept    = $this->upload( Images::jpeg() );
		$deleted = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $kept ) );
		$this->assertRestOk( $this->optimize_on_server( $deleted ) );
		$kept_size = filesize( (string) get_attached_file( $kept ) );

		$this->assertNotFalse( wp_delete_attachment( $deleted, true ) );
		$stats = $this->stats();

		$this->assertSame( 1, $stats['total_images'] );
		$this->assertSame( 1, $stats['optimized_count'] );
		$this->assertSame( $kept_size, $stats['total_original'] );
		$this->assertSame( 1, $stats['restorable_count'] );
		$this->assertFalse( $stats['needs_reconcile'] );
	}

	public function test_an_image_checked_as_already_optimal_counts_as_done_with_nothing_saved(): void {
		$id   = $this->upload( Images::jpeg() );
		$file = (string) get_attached_file( $id );

		$this->assertRestOk( $this->rest( 'POST', "/skip/{$id}", array( 'source_hash' => hash_file( 'sha256', $file ) ) ) );
		$stats = $this->stats();

		$this->assertSame( 1, $stats['optimized_count'] );
		$this->assertSame( 0, $stats['remaining_count'] );
		$this->assertSame( filesize( $file ), $stats['total_original'] );
		$this->assertSame( filesize( $file ), $stats['total_optimized'] );
		$this->assertSame( 0, $stats['total_saved'] );
		$this->assertSame( 0.0, $stats['saved_percent'] );
		$this->assertSame( 0, $stats['restorable_count'] );
	}

	public function test_a_gif_kept_in_its_original_format_counts_as_done_only_while_that_preference_is_on(): void {
		update_option( 'kilobyte_io_settings', array( 'format' => 'original' ) );
		$id = $this->upload( Images::still_gif( 64, 64 ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );
		$this->assertSame( 'original_format_kept', $result['status'] );
		$kept = $this->stats();
		update_option( 'kilobyte_io_settings', array( 'format' => 'webp' ) );
		$converting = $this->stats();

		$this->assertSame( 1, $kept['optimized_count'] );
		$this->assertSame( 0, $kept['total_original'], 'A GIF left unchanged adds no sizes to the savings.' );
		$this->assertSame( 0, $converting['optimized_count'], 'With WebP chosen, the GIF still needs a copy.' );
		$this->assertSame( 1, $converting['remaining_count'] );
	}

	public function test_reconcile_rebuilds_wrong_totals_from_the_image_records(): void {
		$first  = $this->upload( Images::jpeg() );
		$second = $this->upload( Images::jpeg( 320, 240 ) );
		$this->arrange_legacy_in_place_record( $first );
		$this->arrange_legacy_in_place_record(
			$second,
			array(
				'original_size'  => 2000,
				'optimized_size' => 600,
				'backup_bytes'   => 2000,
			)
		);
		update_option(
			WpAttachmentRepository::STATS_OPTION,
			array(
				'optimized_count'  => 9,
				'total_original'   => 123,
				'total_optimized'  => 456,
				'backup_count'     => 7,
				'backup_bytes'     => 8,
				'restorable_count' => 0,
			)
		);

		$this->run_cli_stats_reconcile();

		$this->assertSame(
			array(
				'total_images'     => 2,
				'optimized_count'  => 2,
				'remaining_count'  => 0,
				'total_original'   => 3000,
				'total_optimized'  => 1000,
				'total_saved'      => 2000,
				'saved_percent'    => 66.7,
				'backup_count'     => 2,
				'backup_bytes'     => 3000,
				'restorable_count' => 2,
				'needs_reconcile'  => false,
			),
			$this->stats()
		);
	}

	public function test_reconcile_counts_records_beyond_the_first_hundred_images(): void {
		for ( $i = 0; $i < 101; $i++ ) {
			$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
			$this->arrange_legacy_in_place_record(
				$id,
				array(
					'original_size'  => 10,
					'optimized_size' => 4,
					'backup_path'    => '',
				)
			);
		}

		$this->run_cli_stats_reconcile();
		$stats = $this->stats();

		$this->assertSame( 101, $stats['optimized_count'] );
		$this->assertSame( 1010, $stats['total_original'] );
		$this->assertSame( 404, $stats['total_optimized'] );
		$this->assertSame( 0, $stats['restorable_count'], 'An in-place record without a backup cannot be restored.' );
	}

	public function test_a_reconcile_that_loses_its_database_connection_leaves_the_totals_as_they_were(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );
		update_option( WpAttachmentRepository::STATS_OPTION, array( 'total_original' => 123 ) );
		$this->reconnect_database();

		$this->run_cli_stats_reconcile( true );

		$this->assertSame( 123, $this->stats()['total_original'] );
	}

	public function test_deleting_one_of_several_gifs_kept_in_their_original_format_keeps_the_count_right(): void {
		update_option( 'kilobyte_io_settings', array( 'format' => 'original' ) );
		$deleted = $this->upload( Images::still_gif( 64, 64 ) );
		$kept    = $this->upload( Images::still_gif( 32, 32 ) );
		$photo   = $this->upload( Images::jpeg() );
		foreach ( array( $deleted, $kept, $photo ) as $id ) {
			$this->assertRestOk( $this->optimize_on_server( $id ) );
		}

		$this->assertNotFalse( wp_delete_attachment( $deleted, true ) );
		$this->assertSame( 2, $this->stats()['optimized_count'] );
		update_option( 'kilobyte_io_settings', array( 'format' => 'webp' ) );

		$this->assertSame( 1, $this->stats()['optimized_count'], 'Only the photo has a copy once WebP is chosen; the kept GIF still needs one.' );
	}

	public function test_reconcile_clears_the_out_of_date_warning(): void {
		// Set when an image with a record was deleted while the database could not lock it.
		update_option( WpAttachmentRepository::RECONCILE_OPTION, true );
		$this->assertTrue( $this->stats()['needs_reconcile'] );

		$this->run_cli_stats_reconcile();

		$this->assertFalse( $this->stats()['needs_reconcile'] );
	}

	public function test_a_count_left_unfinished_by_an_earlier_request_stays_flagged_after_later_work(): void {
		// An earlier request stopped between updating the totals and saving its image record.
		update_option( 'kilobyte_io_counter_write_pending', true );
		$id = $this->upload( Images::jpeg() );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertTrue( $this->stats()['needs_reconcile'], 'Only a reconcile can repair the earlier request.' );
		$this->run_cli_stats_reconcile();
		$this->assertFalse( $this->stats()['needs_reconcile'] );
	}

	public function test_totals_that_cannot_be_read_are_shown_as_zero(): void {
		$this->upload( Images::jpeg() );
		update_option( WpAttachmentRepository::STATS_OPTION, 'not a list of totals' );

		$stats = $this->stats();

		$this->assertSame( 0, $stats['optimized_count'] );
		$this->assertSame( 0, $stats['total_original'] );
		$this->assertSame( 1, $stats['remaining_count'] );
	}

	public function test_negative_totals_are_shown_as_zero(): void {
		update_option(
			WpAttachmentRepository::STATS_OPTION,
			array(
				'optimized_count'       => -3,
				'original_format_count' => -1,
				'total_original'        => -100,
				'total_optimized'       => -50,
				'backup_count'          => -2,
				'backup_bytes'          => -9,
				'restorable_count'      => -4,
			)
		);

		$stats = $this->stats();

		foreach ( array( 'optimized_count', 'total_original', 'total_optimized', 'total_saved', 'backup_count', 'backup_bytes', 'restorable_count' ) as $key ) {
			$this->assertSame( 0, $stats[ $key ], "{$key} must not go below zero." );
		}
	}

	public function test_the_optimized_count_never_exceeds_the_images_in_the_library(): void {
		$this->upload( Images::jpeg() );
		update_option( WpAttachmentRepository::STATS_OPTION, array( 'optimized_count' => 50 ) );

		$stats = $this->stats();

		$this->assertSame( 1, $stats['optimized_count'] );
		$this->assertSame( 0, $stats['remaining_count'] );
	}

	public function test_media_files_that_are_not_images_are_not_counted(): void {
		$this->upload( Images::jpeg() );
		self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );
		self::factory()->attachment->create( array( 'post_mime_type' => 'image/svg+xml' ) );

		$this->assertSame( 1, $this->stats()['total_images'] );
	}

	public function test_a_subscriber_cannot_read_the_totals_or_the_queue(): void {
		$this->sign_in_as( 'subscriber' );

		$this->assertSame( 403, $this->rest( 'GET', '/stats' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/queue' )->get_status() );
	}

	public function test_a_visitor_who_is_not_signed_in_cannot_read_the_totals(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->rest( 'GET', '/stats' )->get_status() );
	}

	public function test_an_author_can_read_the_totals(): void {
		$this->upload( Images::jpeg() );
		$this->sign_in_as( 'author' );

		$this->assertSame( 1, $this->stats()['total_images'] );
	}
}
