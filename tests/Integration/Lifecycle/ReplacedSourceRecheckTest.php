<?php
/**
 * An image that was checked and left unchanged is checked again when WordPress replaces its file.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

final class ReplacedSourceRecheckTest extends LifecycleTestCase {

	private const ANIMATION_KEPT = 'The original was kept to preserve its animation.';

	/**
	 * Uploads an animated GIF and lets the server check it, which records that the animation was kept.
	 *
	 * @return int Attachment ID.
	 */
	private function checked_animation(): int {
		$id = $this->upload( Images::animated_gif() );
		$this->assertSame( 'animated', $this->assertRestOk( $this->optimize_on_server( $id ) )['status'] );
		$this->assertSame( self::ANIMATION_KEPT, $this->media_column( $id ) );
		return $id;
	}

	public function test_replacing_the_file_of_a_checked_image_makes_it_ready_to_optimize_again(): void {
		$id    = $this->checked_animation();
		$photo = dirname( (string) get_attached_file( $id ) ) . '/replacement.jpg';
		copy( Images::jpeg( 320, 240 ), $photo );

		update_attached_file( $id, $photo );

		$this->assertSame( 'Not optimized', $this->media_column( $id ) );
		$this->assertFalse( $this->assertRestOk( $this->optimize_on_server( $id ) )['skipped'] );
	}

	public function test_saving_unchanged_metadata_keeps_the_check_result(): void {
		$id = $this->checked_animation();

		wp_update_attachment_metadata( $id, array_merge( wp_get_attachment_metadata( $id ), array( 'image_meta' => array( 'title' => 'Renamed' ) ) ) );

		$this->assertSame( self::ANIMATION_KEPT, $this->media_column( $id ) );
	}

	public function test_editing_other_fields_keeps_the_check_result(): void {
		$id = $this->checked_animation();

		update_post_meta( $id, '_wp_attachment_image_alt', 'A spinning logo' );

		$this->assertSame( self::ANIMATION_KEPT, $this->media_column( $id ) );
	}

	public function test_an_optimized_image_keeps_its_copy_record_when_its_metadata_changes(): void {
		$id = $this->optimized_jpeg();

		wp_update_attachment_metadata( $id, array_merge( wp_get_attachment_metadata( $id ), array( 'image_meta' => array( 'title' => 'Renamed' ) ) ) );

		$this->assertSame( 1, $this->stats()['optimized_count'] );
	}
}
