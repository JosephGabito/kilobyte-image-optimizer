<?php
/**
 * The checks a browser-made copy must pass before the plugin looks at it.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\UploadedFiles;
use WP_REST_Response;

final class BrowserUploadCheckTest extends OptimizeTestCase {

	/**
	 * Attachment that receives the copies.
	 *
	 * @var int
	 */
	private int $id;

	public function set_up(): void {
		parent::set_up();
		$this->id = $this->upload( Images::jpeg( 320, 240 ) );
	}

	public function test_php_rejecting_the_copy_for_its_size_is_reported_as_too_large(): void {
		foreach ( array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ) as $error ) {
			$this->assertRestFailure( 'upload_too_large', 413, $this->post_file( array( 'error' => $error ) ) );
		}
	}

	public function test_a_request_body_over_the_php_post_limit_is_reported_as_too_large(): void {
		$too_big = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) + 1;

		$response = $this->post_file( null, array( 'content-length' => (string) $too_big ) );

		$this->assertRestFailure( 'upload_too_large', 413, $response );
	}

	public function test_a_partial_upload_is_reported_with_the_php_error_code(): void {
		$response = $this->post_file( array( 'error' => UPLOAD_ERR_PARTIAL ) );

		$this->assertRestFailure( 'upload_error', 400, $response );
		$this->assertSame( 'PHP upload error 3', $response->get_data()['data']['technical'] );
	}

	public function test_a_request_without_a_file_is_reported_as_missing_the_copy(): void {
		$this->assertRestFailure( 'missing_file', 400, $this->post_file( null ) );
	}

	public function test_a_file_that_php_did_not_receive_as_an_upload_is_refused(): void {
		$copy = Images::webp( 320, 240 );

		$response = $this->post_file( array( 'tmp_name' => $copy ), array(), false );

		$this->assertRestFailure( 'missing_file', 400, $response );
	}

	public function test_a_file_named_like_a_webp_that_holds_no_image_is_refused(): void {
		$fake = $this->write_fixture( 'copy.webp', 'This is text.' );

		$this->assertRestFailure(
			'invalid_image',
			422,
			$this->post_file(
				array(
					'tmp_name' => $fake,
					'name'     => 'copy.webp',
				) 
			) 
		);
	}

	public function test_a_copy_in_a_format_the_plugin_does_not_serve_is_refused(): void {
		$gif = Images::still_gif( 32, 32 );

		$this->assertRestFailure(
			'invalid_mime',
			415,
			$this->post_file(
				array(
					'tmp_name' => $gif,
					'name'     => 'copy.gif',
				) 
			) 
		);
	}

	public function test_a_text_file_is_refused(): void {
		$text = $this->write_fixture( 'notes.txt', 'Plain text.' );

		$this->assertRestFailure(
			'invalid_mime',
			415,
			$this->post_file(
				array(
					'tmp_name' => $text,
					'name'     => 'notes.txt',
				) 
			) 
		);
	}

	public function test_a_copy_that_arrives_empty_is_refused(): void {
		$copy = Images::webp( 320, 240 );
		add_filter( 'wp_filesize', static fn ( int $size, string $path ): int => $path === $copy ? 0 : $size, 10, 2 );

		$this->assertRestFailure( 'invalid_image', 422, $this->post_file( array( 'tmp_name' => $copy ) ) );
	}

	public function test_a_copy_over_the_site_upload_limit_is_refused(): void {
		add_filter( 'upload_size_limit', static fn (): int => 100 );

		$this->assertRestFailure( 'upload_too_large', 413, $this->post_file( array( 'tmp_name' => Images::webp( 320, 240 ) ) ) );
	}

	public function test_a_copy_with_too_many_pixels_is_refused_without_decoding_it(): void {
		$huge = $this->png_header_only( 8000, 6000 );

		$this->assertRestFailure(
			'image_too_large',
			422,
			$this->post_file(
				array(
					'tmp_name' => $huge,
					'name'     => 'huge.png',
				) 
			) 
		);
	}

	public function test_an_animated_png_copy_is_refused(): void {
		$apng = Images::animated_png();

		$this->assertRestFailure(
			'animated_image',
			422,
			$this->post_file(
				array(
					'tmp_name' => $apng,
					'name'     => 'copy.png',
				) 
			) 
		);
	}

	public function test_an_animated_webp_copy_is_refused(): void {
		$webp = $this->animated_webp();

		$this->assertRestFailure(
			'animated_image',
			422,
			$this->post_file(
				array(
					'tmp_name' => $webp,
					'name'     => 'copy.webp',
				) 
			) 
		);
	}

	public function test_an_animated_avif_copy_is_refused(): void {
		$this->requires_avif();
		$avif = $this->animated_avif();

		$this->assertRestFailure(
			'animated_image',
			422,
			$this->post_file(
				array(
					'tmp_name' => $avif,
					'name'     => 'copy.avif',
				) 
			) 
		);
	}

	public function test_a_copy_is_refused_when_the_server_has_no_image_editor_for_it(): void {
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$this->assertRestFailure( 'invalid_mime', 415, $this->post_file( array( 'tmp_name' => Images::webp( 320, 240 ) ) ) );
	}

	public function test_a_copy_with_corrupt_pixel_data_is_refused(): void {
		$corrupt = $this->png_header_only( 16, 16, 'corrupt.png' );

		$response = $this->post_file(
			array(
				'tmp_name' => $corrupt,
				'name'     => 'corrupt.png',
			) 
		);

		$this->assertRestFailure( 'invalid_image', 422, $response );
		$this->assertNotSame( '', (string) ( $response->get_data()['data']['technical'] ?? '' ), 'The image editor, not the header check, must reject it.' );
	}

	public function test_an_author_can_send_a_copy_for_their_own_image(): void {
		$author = $this->sign_in_as( 'author' );
		$id     = $this->upload( Images::jpeg( 320, 240 ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $author,
			)
		);

		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );
	}

	public function test_a_contributor_cannot_send_a_copy(): void {
		$this->sign_in_as( 'contributor' );

		$this->assertSame( 403, $this->send_browser_copy( $this->id, Images::webp( 320, 240 ) )->get_status() );
	}

	public function test_a_copy_cannot_be_sent_for_a_post_that_is_not_an_attachment(): void {
		$post = self::factory()->post->create();

		$this->assertSame( 403, $this->rest( 'POST', "/optimize/{$post}" )->get_status() );
	}

	/**
	 * Posts a file to the browser-copy endpoint with chosen upload fields.
	 *
	 * @param array<string, mixed>|null $overrides Fields of the uploaded file, or null to send no file.
	 * @param array<string, string>     $headers   Request headers.
	 * @param bool                      $uploaded  Whether PHP received the file as an upload.
	 * @return WP_REST_Response
	 */
	private function post_file( ?array $overrides, array $headers = array(), bool $uploaded = true ): WP_REST_Response {
		$files = array();
		if ( null !== $overrides ) {
			$path = $overrides['tmp_name'] ?? '';
			$file = array_merge(
				array(
					'name'     => 'copy.webp',
					'type'     => 'image/webp',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => '' !== $path ? (int) filesize( $path ) : 0,
				),
				$overrides
			);
			if ( $uploaded && '' !== $path ) {
				UploadedFiles::receive( $path );
			}
			$files['file'] = $file;
		}
		return $this->rest(
			'POST',
			"/optimize/{$this->id}",
			array( 'source_hash' => hash_file( 'sha256', (string) get_attached_file( $this->id ) ) ),
			$files,
			$headers
		);
	}
}
