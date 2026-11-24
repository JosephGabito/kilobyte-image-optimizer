<?php
/**
 * Optimizing an image with the server's image editor through the REST API.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Rest;

use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;

final class OptimizeOnServerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function test_a_jpeg_gets_a_smaller_webp_copy_and_the_original_stays_untouched(): void {
		$id            = $this->upload( Images::jpeg() );
		$original      = (string) get_attached_file( $id );
		$original_hash = hash_file( 'sha256', $original );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 'webp', $result['format'] );
		$this->assertSame( 'server', $result['engine'] );
		$this->assertLessThan( $result['original_size'], $result['optimized_size'] );
		$this->assertSame( $original, get_attached_file( $id ), 'The Media Library must keep pointing at the original.' );
		$this->assertSame( $original_hash, hash_file( 'sha256', $original ), 'The original file must not change.' );
	}

	public function test_visitors_are_offered_a_webp_copy_for_every_thumbnail_size(): void {
		$id = $this->upload( Images::jpeg() );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		foreach ( array( 'full', 'medium', 'thumbnail' ) as $size ) {
			$copies = $this->delivered_copies( $id, $size );
			$this->assertNotEmpty( $copies, "No WebP copy is offered for the {$size} size." );
			foreach ( $copies as $url ) {
				$this->assertSame( 'image/webp', wp_get_image_mime( $this->path_of( $url ) ) );
			}
		}
	}

	public function test_the_avif_preference_produces_an_avif_copy(): void {
		$this->requires_avif();
		update_option( 'kilobyte_io_settings', array( 'format' => 'avif' ) );
		$id = $this->upload( Images::jpeg() );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'avif', $result['format'] );
		// Only the thumbnail is checked: GD's AVIF copy of the 300px size can be larger than the
		// JPEG, which stops delivery for sizes whose srcset includes it.
		$this->assertSame( 'image/avif', wp_get_image_mime( $this->path_of( $this->delivered_copies( $id, 'thumbnail' )[0] ) ) );
	}

	public function test_a_second_optimize_reports_the_image_as_already_processed(): void {
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$again = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertTrue( $again['skipped'] );
		$this->assertSame( 'already_processed', $again['status'] );
	}

	public function test_restoring_stops_offering_the_copy(): void {
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$restored = $this->assertRestOk( $this->rest( 'POST', "/restore/{$id}" ) );

		$this->assertTrue( $restored['restored'] );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_a_copy_that_is_not_smaller_is_not_kept(): void {
		update_option( 'kilobyte_io_settings', array( 'quality' => 95 ) );
		$id = $this->upload( Images::webp( 64, 64 ) );
		add_filter( 'wp_editor_set_quality', static fn () => 100 );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'already_optimal', $result['status'] );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_an_animated_gif_is_left_alone(): void {
		$id = $this->upload( Images::animated_gif() );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'animated', $result['status'] );
	}

	public function test_another_request_working_on_the_same_image_makes_this_one_wait(): void {
		$id = $this->upload( Images::jpeg() );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->optimize_on_server( $id ) );
	}

	public function test_an_author_cannot_optimize_someone_elses_image(): void {
		$id = $this->upload( Images::jpeg() );
		$this->sign_in_as( 'author' );

		$this->assertSame( 403, $this->optimize_on_server( $id )->get_status() );
	}
}
