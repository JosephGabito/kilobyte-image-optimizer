<?php
/**
 * The messages and HTTP statuses that REST and WP-CLI give for each kind of failure,
 * and the reasons shown when an image is left alone.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Presentation\OperationErrorMessage;

require_once __DIR__ . '/LibraryTestCase.php';

final class ErrorMessagesTest extends LibraryTestCase {

	/**
	 * Every failure reason a use case can return, with its HTTP status and the start of its message.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public function failures(): array {
		return array(
			'locked'             => array( OperationFailure::BUSY, 409, 'WordPress could not lock this image' ),
			'missing source'     => array( OperationFailure::MISSING_SOURCE, 404, 'WordPress could not open the source file.' ),
			'invalid image'      => array( OperationFailure::INVALID_IMAGE, 422, 'WordPress could not read this image.' ),
			'invalid mime'       => array( OperationFailure::INVALID_MIME, 415, 'This site cannot process this image format.' ),
			'animated image'     => array( OperationFailure::ANIMATED_IMAGE, 422, 'This image contains animation.' ),
			'image too large'    => array( OperationFailure::IMAGE_TOO_LARGE, 422, 'This image exceeds the image processing limit.' ),
			'server image limit' => array( OperationFailure::SERVER_IMAGE_LIMIT, 422, 'The server has too little memory' ),
			'upload too large'   => array( OperationFailure::UPLOAD_TOO_LARGE, 413, 'The optimized copy exceeds the site upload limit.' ),
			'missing file'       => array( OperationFailure::MISSING_FILE, 400, 'WordPress did not receive the optimized copy.' ),
			'upload error'       => array( OperationFailure::UPLOAD_FAILED, 400, 'WordPress could not receive the whole optimized copy.' ),
			'source changed'     => array( OperationFailure::SOURCE_CHANGED, 409, 'The source image could not be matched' ),
			'original missing'   => array( OperationFailure::ORIGINAL_MISSING, 409, 'WordPress could not verify the original.' ),
			'no backup'          => array( OperationFailure::NO_BACKUP, 404, 'There is no original to restore' ),
			'missing backup'     => array( OperationFailure::MISSING_BACKUP, 404, 'The original backup file is missing.' ),
			'backup unverified'  => array( OperationFailure::BACKUP_UNVERIFIED, 422, 'WordPress could not verify the original backup.' ),
			'encode failed'      => array( OperationFailure::ENCODE_FAILED, 422, 'The server could not compress this image.' ),
			'write failed'       => array( OperationFailure::WRITE_FAILED, 500, 'Could not save the optimized copy.' ),
			'restore failed'     => array( OperationFailure::RESTORE_FAILED, 500, 'Could not finish restoring this image.' ),
			'restore unfinished' => array( OperationFailure::RESTORE_UNFINISHED, 500, 'The original is back in the Media Library.' ),
			'backups protected'  => array( OperationFailure::BACKUPS_PROTECTED, 409, 'The legacy backup folder still contains files.' ),
			'purge failed'       => array( OperationFailure::PURGE_FAILED, 500, 'Could not clear the legacy backup references.' ),
			'regen failed'       => array( OperationFailure::REGEN_FAILED, 422, 'Could not regenerate all thumbnails.' ),
			'job not queued'     => array( OperationFailure::JOB_NOT_QUEUED, 503, 'WordPress could not schedule optimization.' ),
			'job interrupted'    => array( OperationFailure::JOB_INTERRUPTED, 503, 'Background optimization was interrupted.' ),
		);
	}

	/**
	 * @dataProvider failures
	 *
	 * @param string $reason  Failure reason.
	 * @param int    $status  Expected HTTP status.
	 * @param string $message Expected start of the message.
	 */
	public function test_each_failure_has_its_own_status_and_plain_message( string $reason, int $status, string $message ): void {
		$error = OperationErrorMessage::to_wp_error( new OperationFailure( $reason, 'GD said no' ) );

		$this->assertSame( 'kilobyte_io_' . $reason, $error->get_error_code() );
		$this->assertStringStartsWith( $message, $error->get_error_message() );
		$this->assertSame(
			array(
				'status'    => $status,
				'technical' => 'GD said no',
			),
			$error->get_error_data()
		);
	}

	public function test_no_two_failures_share_a_message(): void {
		$messages = array_map(
			static fn ( array $failure ): string => OperationErrorMessage::to_wp_error( new OperationFailure( $failure[0] ) )->get_error_message(),
			$this->failures()
		);

		$this->assertSame( array_values( $messages ), array_values( array_unique( $messages ) ) );
	}

	public function test_a_reason_without_its_own_message_is_reported_as_a_save_failure_under_its_own_code(): void {
		$error = OperationErrorMessage::to_wp_error( new OperationFailure( 'something_new' ) );

		$this->assertSame( 'kilobyte_io_something_new', $error->get_error_code() );
		$this->assertStringStartsWith( 'Could not save the optimized copy.', $error->get_error_message() );
		$this->assertSame( 500, $error->get_error_data()['status'] );
		$this->assertSame( '', $error->get_error_data()['technical'] );
	}

	/**
	 * Every status an optimization can leave an image in without making a copy, with its explanation.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function skip_statuses(): array {
		return array(
			'already optimal'      => array( 'already_optimal', 'The new copy was no smaller than the original.' ),
			'animated'             => array( 'animated', 'The original was kept to preserve its animation.' ),
			'original format kept' => array( 'original_format_kept', 'The Original format preference keeps this GIF unchanged.' ),
			'already processed'    => array( 'already_processed', 'This image already has an optimized copy.' ),
			'preserved existing'   => array( 'preserved_existing', 'This image already has an optimized copy.' ),
			'gif canvas kept'      => array( 'gif_canvas_kept', 'The full GIF image could not be preserved in a smaller copy.' ),
			'excluded'             => array( 'excluded', 'A rule on this site excludes this image from optimization.' ),
			'anything else'        => array( 'not_smaller', 'The original was kept.' ),
		);
	}

	/**
	 * @dataProvider skip_statuses
	 *
	 * @param string $status  Status an optimization returned.
	 * @param string $message Expected explanation.
	 */
	public function test_each_reason_for_leaving_an_image_alone_is_explained( string $status, string $message ): void {
		$this->assertSame( $message, OperationErrorMessage::describe_skip( $status ) );
	}
}
