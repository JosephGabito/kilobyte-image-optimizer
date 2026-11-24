<?php
/**
 * Regenerating an image's thumbnail sizes through the REST API.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LibraryTestCase.php';

final class RegenerateThumbnailsTest extends LibraryTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function tear_down(): void {
		remove_image_size( 'kilobyte-test-banner' );
		parent::tear_down();
	}

	public function test_a_deleted_thumbnail_is_made_again(): void {
		$id        = $this->upload( Images::jpeg() );
		$thumbnail = $this->size_path( $id, 'thumbnail' );
		unlink( $thumbnail ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$result = $this->assertRestOk( $this->regenerate( $id ) );

		// A 640x480 image fits the 150x150 thumbnail and 300x225 medium sizes, and no larger ones.
		$this->assertSame(
			array(
				'id'    => $id,
				'sizes' => 2,
			),
			$result
		);
		$remade = $this->size_path( $id, 'thumbnail' );
		$this->assertFileExists( $remade );
		$this->assertSame( array( 150, 150 ), array_slice( (array) wp_getimagesize( $remade ), 0, 2 ) );
		$this->assertSame( $remade, $this->path_of( (string) wp_get_attachment_image_src( $id, 'thumbnail' )[0] ) );
	}

	public function test_thumbnails_that_are_already_correct_are_kept_as_they_are(): void {
		$id     = $this->upload( Images::jpeg( 640, 480, 'kept.jpg' ) );
		$folder = dirname( (string) get_attached_file( $id ) );
		$before = wp_get_attachment_metadata( $id );
		$files  = $this->files_in( $folder );

		$this->assertSame( 2, $this->assertRestOk( $this->regenerate( $id ) )['sizes'] );

		$this->assertSame( $before, wp_get_attachment_metadata( $id ) );
		$this->assertSame( $files, $this->files_in( $folder ), 'No new file may be written and the work folder must be gone.' );
	}

	public function test_a_newly_registered_size_is_added(): void {
		$id = $this->upload( Images::jpeg() );
		add_image_size( 'kilobyte-test-banner', 200, 100, true );

		$this->assertSame( 3, $this->assertRestOk( $this->regenerate( $id ) )['sizes'] );

		$banner = wp_get_attachment_metadata( $id )['sizes']['kilobyte-test-banner'];
		$this->assertSame( 200, $banner['width'] );
		$this->assertSame( 100, $banner['height'] );
		$this->assertFileExists( $this->size_path( $id, 'kilobyte-test-banner' ) );
	}

	public function test_the_original_image_and_its_link_are_not_changed(): void {
		$id   = $this->upload( Images::jpeg() );
		$file = (string) get_attached_file( $id );
		$hash = hash_file( 'sha256', $file );
		$url  = wp_get_attachment_url( $id );
		unlink( $this->size_path( $id, 'medium' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$this->assertRestOk( $this->regenerate( $id ) );

		$this->assertSame( $file, get_attached_file( $id ) );
		$this->assertSame( $hash, hash_file( 'sha256', $file ) );
		$this->assertSame( $url, wp_get_attachment_url( $id ) );
	}

	public function test_an_image_whose_file_is_missing_cannot_be_regenerated(): void {
		$id = $this->upload( Images::jpeg() );
		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );
	}

	public function test_an_animated_gif_is_not_regenerated(): void {
		$id = $this->upload( Images::animated_gif() );

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );
	}

	public function test_a_file_that_is_not_an_image_cannot_be_regenerated(): void {
		$id = $this->upload( Images::jpeg() );
		copy( Images::not_an_image(), (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );
	}

	public function test_a_site_with_no_image_editor_reports_the_failure(): void {
		$id = $this->upload( Images::jpeg() );
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );
	}

	public function test_a_size_filter_that_breaks_the_size_list_reports_the_failure(): void {
		$id = $this->upload( Images::jpeg() );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'intermediate_image_sizes_advanced', '__return_false' );

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );
	}

	public function test_thumbnails_saved_by_another_request_meanwhile_are_kept(): void {
		$id = $this->upload( Images::jpeg() );
		unlink( $this->size_path( $id, 'thumbnail' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		$other_request = static function ( $sizes, array $metadata, int $attachment_id ) {
			// Another request, such as the image editor, saves new metadata while the sizes are being made.
			$current               = wp_get_attachment_metadata( $attachment_id, true );
			$current['image_meta'] = array( 'caption' => 'Saved by the image editor' );
			wp_update_attachment_metadata( $attachment_id, $current );
			return $sizes;
		};
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'intermediate_image_sizes_advanced', $other_request, 10, 3 );

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 'Saved by the image editor', $metadata['image_meta']['caption'] );
		$this->assertFileDoesNotExist( $this->size_path( $id, 'thumbnail' ), 'The thumbnail list from the other request must stay.' );
	}

	public function test_a_regeneration_that_loses_its_database_connection_saves_nothing(): void {
		$id       = $this->upload( Images::jpeg() );
		$metadata = wp_get_attachment_metadata( $id );
		unlink( $this->size_path( $id, 'thumbnail' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		$this->reconnect_database();

		$this->assertRestFailure( 'regen_failed', 422, $this->regenerate( $id ) );

		$this->assertSame( $metadata, wp_get_attachment_metadata( $id ) );
	}

	public function test_a_folder_wordpress_cannot_write_to_reports_the_failure(): void {
		$id     = $this->upload( Images::jpeg() );
		$folder = dirname( (string) get_attached_file( $id ) );
		$files  = $this->files_in( $folder );
		chmod( $folder, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod
		try {
			$response = $this->regenerate( $id );
		} finally {
			chmod( $folder, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod
		}

		$this->assertRestFailure( 'regen_failed', 422, $response );
		$this->assertSame( $files, $this->files_in( $folder ) );
	}

	public function test_another_request_working_on_the_image_makes_this_one_wait(): void {
		$id = $this->upload( Images::jpeg() );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->regenerate( $id ) );
	}

	public function test_an_author_cannot_regenerate_someone_elses_image(): void {
		$id = $this->upload( Images::jpeg() );
		$this->sign_in_as( 'author' );

		$this->assertSame( 403, $this->regenerate( $id )->get_status() );
	}

	public function test_an_id_that_is_not_an_attachment_is_refused(): void {
		$post = self::factory()->post->create();

		$this->assertSame( 403, $this->regenerate( $post )->get_status() );
	}

	/**
	 * Regenerates an attachment's thumbnails.
	 *
	 * @param int $id Attachment ID.
	 * @return \WP_REST_Response
	 */
	private function regenerate( int $id ): \WP_REST_Response {
		return $this->rest( 'POST', "/regenerate/{$id}" );
	}

	/**
	 * File path of one of the attachment's sizes, as WordPress records it.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Size name.
	 * @return string
	 */
	private function size_path( int $id, string $size ): string {
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertArrayHasKey( $size, $metadata['sizes'], "The {$size} size is missing." );
		return dirname( (string) get_attached_file( $id ) ) . '/' . $metadata['sizes'][ $size ]['file'];
	}
}
