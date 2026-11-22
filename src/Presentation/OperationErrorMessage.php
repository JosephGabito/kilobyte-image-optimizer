<?php
/**
 * Translate operation failures for REST and WP-CLI.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use WP_Error;

/**
 * Maps domain failures to localized WordPress errors.
 */
final class OperationErrorMessage {

	/**
	 * @param OperationFailure $failure Failure returned by a use case.
	 * @return WP_Error
	 */
	public static function to_wp_error( OperationFailure $failure ): WP_Error {
		$reason                   = $failure->reason();
		$errors                   = array(
			OperationFailure::BUSY               => array( 409, __( 'WordPress could not lock this image for processing. Wait a moment and retry. If it keeps happening, ask your host to check database locks.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::MISSING_SOURCE     => array( 404, __( 'This image is unavailable or uses an unsupported format.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::SOURCE_CHANGED     => array( 409, __( 'The source image could not be matched to the browser copy. Open the image again or optimize with your site settings.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::ORIGINAL_MISSING   => array( 409, __( 'WordPress could not verify the original. The optimized copy was kept. Recover the original or ask your host to check that WordPress can open it, then try again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::NO_BACKUP          => array( 404, __( 'There is no original to restore for this image.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::MISSING_BACKUP     => array( 404, __( 'The original backup file is missing.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::ENCODE_FAILED      => array( 422, __( 'The server could not compress this image.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::WRITE_FAILED       => array( 500, __( 'Could not save the optimized copy.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::RESTORE_FAILED     => array( 500, __( 'Could not finish restoring this image. Try again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::RESTORE_UNFINISHED => array( 500, __( 'The original is back in the Media Library. Retry the restore to finish updating its status.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::BACKUPS_PROTECTED  => array( 409, __( 'The legacy backup folder still contains files. Recover any remaining originals and archive the kilobyte-backups folder before clearing its references.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::PURGE_FAILED       => array( 500, __( 'Could not clear the legacy backup references. Check that WordPress can read its uploads folder and save settings, then retry.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::REGEN_FAILED       => array( 422, __( 'Could not regenerate all thumbnails. Check that WordPress can open the image and write to its uploads folder, then retry.', 'kilobyte-image-optimizer' ) ),
		);
		list( $status, $message ) = $errors[ $reason ] ?? $errors[ OperationFailure::WRITE_FAILED ];
		return new WP_Error(
			'kilobyte_io_' . $reason,
			$message,
			array(
				'status'    => $status,
				'technical' => $failure->detail(),
			)
		);
	}

	/**
	 * Explain why optimization left an image alone.
	 *
	 * @param string $status Operation status.
	 * @return string
	 */
	public static function describe_skip( string $status ): string {
		switch ( $status ) {
			case OptimizationRecord::STATUS_ALREADY_OPTIMAL:
				return __( 'The new copy was no smaller than the original.', 'kilobyte-image-optimizer' );
			case OptimizationRecord::STATUS_ANIMATED:
				return __( 'The original was kept to preserve its animation.', 'kilobyte-image-optimizer' );
			case OptimizationRecord::STATUS_ORIGINAL_FORMAT_KEPT:
				return __( 'The Original format preference keeps this GIF unchanged.', 'kilobyte-image-optimizer' );
			case 'already_processed':
			case 'preserved_existing':
				return __( 'This image already has an optimized copy.', 'kilobyte-image-optimizer' );
			case 'gif_canvas_kept':
				return __( 'The full GIF image could not be preserved in a smaller copy.', 'kilobyte-image-optimizer' );
			case 'excluded':
				return __( 'A rule on this site excludes this image from optimization.', 'kilobyte-image-optimizer' );
			default:
				return __( 'The original was kept.', 'kilobyte-image-optimizer' );
		}
	}
}
