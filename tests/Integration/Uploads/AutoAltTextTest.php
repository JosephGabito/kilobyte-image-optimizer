<?php
/**
 * Alt text written from the file name when an image is uploaded and the preference is on.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class AutoAltTextTest extends UploadTestCase {

	public function test_an_upload_gets_alt_text_from_its_file_name_without_size_and_copy_words(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );

		$id = $this->upload_through_rest( Images::jpeg( 640, 480, 'summer-picnic-1200x800_by-the__lake-copy.jpg' ) );

		$this->assertSame( 'Summer picnic by the lake', $this->alt_text_on_page( $id ) );
	}

	public function test_scaled_compressed_and_optimized_are_dropped_from_the_alt_text(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );

		$id = $this->upload( Images::jpeg( 640, 480, 'team-photo-scaled-COMPRESSED-optimized.jpg' ) );

		$this->assertSame( 'Team photo', $this->alt_text_on_page( $id ) );
	}

	public function test_a_file_name_made_only_of_size_words_gives_no_alt_text(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );

		$id = $this->upload( Images::jpeg( 640, 480, '1920x1080-copy.jpg' ) );

		$this->assertSame( '', $this->alt_text_on_page( $id ) );
	}

	public function test_no_alt_text_is_written_while_the_preference_is_off(): void {
		$id = $this->upload_through_rest( Images::jpeg( 640, 480, 'summer-picnic.jpg' ) );

		$this->assertSame( '', $this->alt_text_on_page( $id ) );
	}

	public function test_alt_text_the_uploader_already_wrote_is_kept(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );
		$file = Images::jpeg( 640, 480, 'img-0042.jpg' );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Img 0042',
				'meta_input'     => array( '_wp_attachment_image_alt' => 'Maria cuts the ribbon at the new library' ),
			),
			$file
		);

		$this->assertSame( 'Maria cuts the ribbon at the new library', $this->alt_text_on_page( $id ) );
	}

	public function test_a_document_upload_gets_no_alt_text(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );
		$file = Images::not_an_image( 'annual-report.txt' );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Annual report',
			),
			$file
		);

		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The alt attribute a visitor's browser gets for the image.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function alt_text_on_page( int $id ): string {
		$html = wp_get_attachment_image( $id, 'full' );
		$this->assertSame( 1, preg_match( '/\salt="([^"]*)"/', $html, $match ), "No alt attribute in: {$html}" );
		return html_entity_decode( $match[1] );
	}

	public function test_a_file_name_ending_in_a_size_gives_alt_text_without_a_number(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );

		$id = $this->upload_through_rest( Images::jpeg( 640, 480, 'summer-picnic-1200x800.jpg' ) );

		$this->assertSame( 'Summer picnic', $this->alt_text_on_page( $id ) );
	}

	public function test_a_file_name_made_only_of_a_size_and_scaled_gives_no_alt_text(): void {
		$this->set_preferences( array( 'auto_alt_text' => true ) );

		$id = $this->upload_through_rest( Images::jpeg( 640, 480, '1920x1080-scaled.jpg' ) );

		$this->assertSame( '', $this->alt_text_on_page( $id ) );
	}
}
