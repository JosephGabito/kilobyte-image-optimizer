<?php
/**
 * The bulk queue: the pages of images the admin screen works through for Optimize,
 * Regenerate thumbnails and Restore all.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LibraryTestCase.php';

final class QueueTest extends LibraryTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function test_the_optimize_queue_lists_images_without_a_copy_newest_first(): void {
		$oldest    = $this->upload( Images::jpeg() );
		$optimized = $this->upload( Images::jpeg( 320, 240 ) );
		$newest    = $this->upload( Images::png( 64, 64 ) );
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );

		$page = $this->queue( 'optimize' );

		$this->assertSame( array( $newest, $oldest ), $this->ids_in( $page ) );
		$this->assertSame( 0, $page['next_before'], 'Everything fits on one page.' );
		$this->assertSame( array(), $page['unavailable_items'] );
		$this->assertSame( 0, $page['skipped_missing'] );
	}

	public function test_each_queued_image_carries_what_the_browser_needs_to_encode_it(): void {
		$id   = $this->upload( Images::jpeg( 640, 480, 'beach.jpg' ) );
		$file = (string) get_attached_file( $id );

		$item = $this->queue( 'optimize' )['items'][0];

		$this->assertSame(
			array(
				'id'        => $id,
				'title'     => 'beach.jpg',
				'filename'  => 'beach.jpg',
				'url'       => wp_get_attachment_url( $id ),
				'mime_type' => 'image/jpeg',
				'file_size' => filesize( $file ),
				'width'     => 640,
				'height'    => 480,
			),
			$item
		);
	}

	public function test_the_queue_is_read_in_pages_using_the_last_id_as_a_cursor(): void {
		$first  = $this->upload( Images::jpeg() );
		$second = $this->upload( Images::jpeg() );
		$third  = $this->upload( Images::jpeg() );

		$page_one = $this->queue( 'optimize', array( 'per_page' => 2 ) );
		$page_two = $this->queue(
			'optimize',
			array(
				'per_page' => 2,
				'before'   => $page_one['next_before'],
			)
		);

		$this->assertSame( array( $third, $second ), $this->ids_in( $page_one ) );
		$this->assertSame( $second, $page_one['next_before'] );
		$this->assertSame( array( $first ), $this->ids_in( $page_two ) );
		$this->assertSame( 0, $page_two['next_before'] );
	}

	public function test_a_page_size_of_zero_still_returns_one_image(): void {
		$this->upload( Images::jpeg() );
		$newest = $this->upload( Images::jpeg() );

		$page = $this->queue( 'optimize', array( 'per_page' => 0 ) );

		$this->assertSame( array( $newest ), $this->ids_in( $page ) );
		$this->assertSame( $newest, $page['next_before'] );
	}

	public function test_a_page_never_holds_more_than_one_hundred_images(): void {
		for ( $i = 0; $i < 101; $i++ ) {
			self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		}

		$page = $this->queue( 'regenerate', array( 'per_page' => 500 ) );

		$this->assertCount( 100, $page['unavailable_items'] );
		$this->assertNotSame( 0, $page['next_before'], 'The 101st image is on the next page.' );
	}

	public function test_an_unknown_mode_is_rejected(): void {
		$response = $this->rest( 'GET', '/queue', array( 'mode' => 'delete' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public function test_the_regenerate_queue_lists_every_image_including_optimized_ones(): void {
		$plain     = $this->upload( Images::jpeg() );
		$optimized = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );

		$this->assertSame( array( $optimized, $plain ), $this->ids_in( $this->queue( 'regenerate' ) ) );
	}

	public function test_the_restore_queue_lists_only_images_that_can_be_restored(): void {
		$plain     = $this->upload( Images::jpeg() );
		$optimized = $this->upload( Images::jpeg( 320, 240 ) );
		$checked   = $this->upload( Images::png( 64, 64 ) );
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );
		$this->assertRestOk( $this->rest( 'POST', "/skip/{$checked}", array( 'source_hash' => hash_file( 'sha256', (string) get_attached_file( $checked ) ) ) ) );

		$this->assertSame( array( $optimized ), $this->ids_in( $this->queue( 'restore' ) ) );
		$this->assertNotContains( $plain, $this->ids_in( $this->queue( 'restore' ) ) );
	}

	public function test_the_restore_queue_keeps_an_image_whose_original_file_is_missing(): void {
		$id = $this->upload( Images::jpeg( 640, 480, 'lost.jpg' ) );
		$this->arrange_legacy_in_place_record( $id );
		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$page = $this->queue( 'restore' );

		$this->assertSame( array(), $page['unavailable_items'] );
		$this->assertSame( $id, $page['items'][0]['id'] );
		$this->assertSame( 'lost.jpg', $page['items'][0]['filename'] );
		$this->assertSame( '', $page['items'][0]['mime_type'] );
		$this->assertSame( 0, $page['items'][0]['file_size'] );
	}

	public function test_an_image_whose_file_is_missing_is_listed_as_unavailable(): void {
		$present = $this->upload( Images::jpeg() );
		$missing = $this->upload( Images::jpeg( 640, 480, 'gone.jpg' ) );
		unlink( (string) get_attached_file( $missing ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$page = $this->queue( 'optimize' );

		$this->assertSame( array( $present ), $this->ids_in( $page ) );
		$this->assertSame( 1, $page['skipped_missing'] );
		$this->assertSame( $missing, $page['unavailable_items'][0]['id'] );
		$this->assertSame( 'gone.jpg', $page['unavailable_items'][0]['filename'] );
		$this->assertSame( 'gone.jpg', $page['unavailable_items'][0]['title'] );
		$this->assertStringContainsString( "post={$missing}&action=edit", $page['unavailable_items'][0]['edit_url'] );
	}

	public function test_an_image_with_no_file_path_is_named_from_its_metadata(): void {
		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Imported photo',
			)
		);
		wp_update_attachment_metadata( $id, array( 'file' => '2020/01/imported.jpg' ) );

		$unavailable = $this->queue( 'optimize' )['unavailable_items'][0];

		$this->assertSame( $id, $unavailable['id'] );
		$this->assertSame( 'imported.jpg', $unavailable['filename'] );
		$this->assertSame( 'Imported photo', $unavailable['title'] );
	}

	public function test_an_image_checked_as_already_optimal_leaves_the_queue_until_its_file_changes(): void {
		$id   = $this->upload( Images::jpeg() );
		$file = (string) get_attached_file( $id );
		$this->assertRestOk( $this->rest( 'POST', "/skip/{$id}", array( 'source_hash' => hash_file( 'sha256', $file ) ) ) );
		$this->assertSame( array(), $this->ids_in( $this->queue( 'optimize' ) ) );

		// Another tool overwrites the original on disk, outside WordPress.
		copy( Images::jpeg( 320, 240 ), $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'optimize' ) ) );
	}

	public function test_an_optimized_image_returns_to_the_queue_when_its_original_is_replaced(): void {
		$id   = $this->upload( Images::jpeg() );
		$file = (string) get_attached_file( $id );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$this->assertSame( array(), $this->ids_in( $this->queue( 'optimize' ) ) );

		copy( Images::jpeg( 320, 240 ), $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'optimize' ) ) );
	}

	public function test_a_gif_kept_in_its_original_format_is_queued_again_once_webp_is_chosen(): void {
		update_option( 'kilobyte_io_settings', array( 'format' => 'original' ) );
		$id = $this->upload( Images::still_gif( 64, 64 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$this->assertSame( array(), $this->ids_in( $this->queue( 'optimize' ) ) );

		update_option( 'kilobyte_io_settings', array( 'format' => 'webp' ) );

		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'optimize' ) ) );
	}

	public function test_an_author_sees_only_their_own_images_but_the_cursor_moves_past_the_others(): void {
		$admins = $this->upload( Images::jpeg() );
		$this->sign_in_as( 'author' );
		$own = $this->upload( Images::jpeg() );
		$this->sign_in_as( 'administrator' );
		$admins_newest = $this->upload( Images::jpeg() );
		wp_set_current_user( (int) get_post_field( 'post_author', $own ) );

		$page = $this->queue( 'optimize', array( 'per_page' => 2 ) );

		$this->assertSame( array( $own ), $this->ids_in( $page ) );
		$this->assertSame( $own, $page['next_before'] );
		$this->assertNotContains( $admins, $this->ids_in( $page ) );
		$this->assertNotContains( $admins_newest, $this->ids_in( $page ) );
	}
}
