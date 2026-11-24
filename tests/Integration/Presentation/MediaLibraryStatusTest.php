<?php
/**
 * The optimization status a person sees for each image in the Media Library list and in the image details window.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Tests fire WordPress core hooks the way WordPress does.

namespace Kilobyte\ImageOptimizer\Tests\Integration\Presentation;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;

final class MediaLibraryStatusTest extends TestCase {

	private const QUEUED  = 'Original saved. Optimization is queued.';
	private const OVERDUE = 'Original saved. Background optimization is overdue. Optimize this image now or ask your host to check scheduled tasks.';

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
		set_current_screen( 'upload' );
	}

	public function test_the_media_library_list_gets_an_optimization_column(): void {
		$columns = apply_filters( 'manage_media_columns', array( 'title' => 'File' ) );

		$this->assertSame(
			array(
				'title'       => 'File',
				'kilobyte_io' => 'Optimization',
			),
			$columns 
		);
	}

	public function test_other_columns_get_no_optimization_status(): void {
		$id = $this->upload( Images::jpeg() );

		$this->assertSame( '', $this->column( $id, 'author' ) );
	}

	public function test_a_file_that_is_not_an_image_shows_a_dash_and_no_details_field(): void {
		$pdf = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
				'file'           => 'guide.pdf',
			) 
		);

		$this->assertSame( '<span style="color:#646970;">-</span>', $this->column( $pdf ) );
		$this->assertNull( $this->details_field( $pdf ) );
	}

	public function test_a_new_upload_shows_that_optimization_is_queued_with_an_optimize_now_button(): void {
		$id     = $this->upload( Images::jpeg() );
		$button = sprintf( '<button type="button" class="button-link kilobyte-optimize-retry-btn" data-id="%d">Optimize now</button>', $id );

		$this->assertSame(
			sprintf( '<div style="color:#646970;"><div class="kilobyte-upload-status" data-id="%d">%s %s</div></div>', $id, self::QUEUED, $button ),
			$this->column( $id )
		);
		$this->assertSame(
			array(
				'label' => 'Optimization',
				'input' => 'html',
				'html'  => sprintf( '<div class="kilobyte-upload-status" data-id="%d">%s %s</div>', $id, self::QUEUED, $button ),
			),
			$this->details_field( $id )
		);
	}

	public function test_an_upload_waiting_too_long_says_background_optimization_is_overdue(): void {
		$id = $this->upload( Images::jpeg() );
		// The background job was due six minutes ago and has not run.
		update_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, time() - 6 * MINUTE_IN_SECONDS );

		$this->assertStringContainsString( self::OVERDUE, $this->column( $id ) );
		$this->assertStringContainsString( 'Optimize now', $this->column( $id ) );
	}

	public function test_an_upload_whose_job_could_not_be_scheduled_explains_what_to_do(): void {
		add_filter( 'pre_schedule_event', '__return_false' );

		$id = $this->upload( Images::jpeg() );

		$this->assertStringContainsString(
			'Original saved. WordPress could not schedule optimization. Optimize the image manually or ask your host to check scheduled tasks.',
			$this->details_field( $id )['html']
		);
	}

	public function test_an_upload_excluded_by_a_site_rule_says_so_without_a_button(): void {
		add_filter( 'kilobyte_io_should_optimize', '__return_false' );
		$id = $this->upload( Images::jpeg() );
		$this->make_upload_job_due( $id );

		do_action( 'kilobyte_io_optimize_pending_uploads' );

		$this->assertSame( '<div style="color:#646970;">A rule on this site excludes this image from optimization.</div>', $this->column( $id ) );
		$this->assertSame( 'A rule on this site excludes this image from optimization.', $this->details_field( $id )['html'] );
	}

	public function test_an_upload_made_while_optimization_is_turned_off_shows_not_optimized(): void {
		update_option( 'kilobyte_io_settings', array( 'enabled' => false ) );

		$id = $this->upload( Images::jpeg() );

		$this->assertSame( '<div style="color:#646970;">Not optimized</div>', $this->column( $id ) );
	}

	public function test_an_optimized_image_shows_its_savings_and_a_restore_button(): void {
		$id     = $this->upload( Images::jpeg() );
		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );
		$sizes  = size_format( $result['original_size'], 1 ) . ' &rarr; ' . size_format( $result['optimized_size'], 1 );

		$column  = $this->column( $id );
		$details = $this->details_field( $id )['html'];

		$this->assertStringContainsString( "<strong style=\"color:#007017;\">-{$result['saved_percent']}%</strong> <span style=\"color:#50575e;\">(WEBP)</span>", $column );
		$this->assertStringContainsString( $sizes, $column );
		$this->assertStringContainsString( "<button type=\"button\" class=\"button-link kilobyte-restore-btn\" data-id=\"{$id}\" style=\"color:#b32d2e;font-size:12px;\">Restore original</button>", $column );
		$this->assertSame(
			"<strong>-{$result['saved_percent']}%</strong> (WEBP) &middot; {$sizes} <button type=\"button\" class=\"button-link kilobyte-restore-btn\" data-id=\"{$id}\">Restore original</button>",
			$details
		);
	}

	public function test_someone_who_cannot_edit_the_image_sees_its_status_without_buttons(): void {
		$optimized = $this->upload( Images::jpeg() );
		$queued    = $this->upload( Images::jpeg( 640, 480, 'queued.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );
		$this->sign_in_as( 'author' );

		$this->assertStringContainsString( '(WEBP)', $this->column( $optimized ) );
		$this->assertStringNotContainsString( 'Restore original', $this->column( $optimized ) );
		$this->assertStringNotContainsString( 'Restore original', $this->details_field( $optimized )['html'] );
		$this->assertSame( sprintf( '<div class="kilobyte-upload-status" data-id="%d">%s</div>', $queued, self::QUEUED ), $this->details_field( $queued )['html'] );
	}

	public function test_an_image_kept_unchanged_explains_why(): void {
		$id = $this->upload( Images::animated_gif() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( '<span style="color:#2271b1;font-weight:500;">The original was kept to preserve its animation.</span>', $this->column( $id ) );
		$this->assertSame( 'The original was kept to preserve its animation.', $this->details_field( $id )['html'] );
	}

	/**
	 * The Media Library list cell for an attachment.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $column Column slug.
	 * @return string
	 */
	private function column( int $id, string $column = 'kilobyte_io' ): string {
		ob_start();
		do_action( 'manage_media_custom_column', $column, $id );
		return (string) ob_get_clean();
	}

	/**
	 * The Optimization field in the image details window, or null when there is none.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, string>|null
	 */
	private function details_field( int $id ): ?array {
		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $id ) );
		return $fields['kilobyte_io'] ?? null;
	}
}
