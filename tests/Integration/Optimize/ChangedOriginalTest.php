<?php
/**
 * Optimizing again after the original or its thumbnails changed on disk.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class ChangedOriginalTest extends OptimizeTestCase {

	public function test_a_replaced_original_stops_its_old_copy_and_gets_a_new_one_on_the_server(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$old = $this->delivered_copies( $id );
		$this->replace_original_with( $id, Images::jpeg( 300, 200 ) );
		$this->assertSame( array(), $this->delivered_copies( $id ), 'The copy of the old photo must not be served for the new one.' );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$new = $this->delivered_copies( $id );
		$this->assertNotEmpty( $new );
		$this->assertSame( array(), array_intersect( $old, $new ) );
		$size = wp_getimagesize( $this->path_of( $new[0] ) );
		$this->assertSame( 300, $size[0], 'The new copy is made from the new photo.' );
	}

	public function test_a_replaced_original_accepts_a_new_browser_copy(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );
		$this->replace_original_with( $id, Images::jpeg( 300, 200 ) );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_a_regenerated_thumbnail_gets_a_new_copy_while_the_original_keeps_its_fingerprint(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$old_copies = $this->copies_next_to( $id );
		$thumbnail  = dirname( (string) get_attached_file( $id ) ) . '/photo-150x150.jpg';
		file_put_contents( $thumbnail, file_get_contents( Images::jpeg( 150, 150 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$served = $this->delivered_copies( $id, 'thumbnail' );
		$this->assertCount( 1, $served );
		$this->assertNotContains( basename( $served[0] ), $old_copies );
	}

	public function test_a_deleted_thumbnail_lets_the_image_be_optimized_again(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		unlink( dirname( (string) get_attached_file( $id ) ) . '/photo-150x150.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
	}

	public function test_an_unchanged_image_is_reported_as_already_processed_after_a_thumbnail_check(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$again = $this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertSame( 'already_processed', $again['status'] );
	}

	public function test_an_attachment_pointed_at_a_new_file_gets_a_new_copy(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$moved = dirname( (string) get_attached_file( $id ) ) . '/photo-replaced.jpg';
		copy( (string) get_attached_file( $id ), $moved ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_attached_file( $id, $moved );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertNotEmpty( preg_grep( '/^photo-replaced\.kilobyte-/', $this->copies_next_to( $id ) ) );
	}

	public function test_an_attachment_pointed_at_its_own_copy_is_not_optimized_again(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$copy = $this->path_of( $this->delivered_copies( $id )[0] );
		update_attached_file( $id, $copy );

		$again = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'already_processed', $again['status'] );
	}

	public function test_the_bulk_queue_leaves_out_an_unchanged_image_and_offers_a_replaced_one(): void {
		$unchanged = $this->upload( Images::jpeg( 320, 240 ) );
		$replaced  = $this->upload( Images::jpeg( 320, 240, 'other.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $unchanged ) );
		$this->assertRestOk( $this->optimize_on_server( $replaced ) );
		$this->replace_original_with( $replaced, Images::jpeg( 300, 200 ) );

		$queue = $this->optimize_queue();

		$this->assertNotContains( $unchanged, $queue );
		$this->assertContains( $replaced, $queue );
	}

	public function test_the_bulk_queue_notices_an_edit_that_keeps_the_file_size(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$original         = (string) get_attached_file( $id );
		$bytes            = (string) file_get_contents( $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$middle           = intdiv( strlen( $bytes ), 2 );
		$bytes[ $middle ] = chr( ord( $bytes[ $middle ] ) ^ 0x01 );
		file_put_contents( $original, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( strlen( $bytes ), (int) filesize( $original ) );

		$this->assertContains( $id, $this->optimize_queue() );
	}
}
