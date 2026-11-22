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
			OperationFailure::MISSING_SOURCE     => array( 404, __( 'WordPress could not open the source file. Check your media storage or recover the file, then try again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::INVALID_IMAGE      => array( 422, __( 'WordPress could not read this image. Check that the original opens and try saving it as a JPEG or PNG.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::INVALID_MIME       => array( 415, __( 'This site cannot process this image format. Try WebP, JPEG, or PNG.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::ANIMATED_IMAGE     => array( 422, __( 'This image contains animation. Use a still image or keep the original.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::IMAGE_TOO_LARGE    => array( 422, __( 'This image exceeds the image processing limit. Try a smaller version.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::SERVER_IMAGE_LIMIT => array( 422, __( 'The server has too little memory to process this image. Try a smaller version or ask your host about the PHP memory limit.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::UPLOAD_TOO_LARGE   => array( 413, __( 'The optimized copy exceeds the site upload limit. Try a smaller copy or optimize with your site settings.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::MISSING_FILE       => array( 400, __( 'WordPress did not receive the optimized copy. Try saving it again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::UPLOAD_FAILED      => array( 400, __( 'WordPress could not receive the whole optimized copy. Try again or ask your host to check uploads.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::SOURCE_CHANGED     => array( 409, __( 'The source image could not be matched to the browser copy. Open the image again or optimize with your site settings.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::ORIGINAL_MISSING   => array( 409, __( 'WordPress could not verify the original. The optimized copy was kept. Recover the original or ask your host to check that WordPress can open it, then try again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::NO_BACKUP          => array( 404, __( 'There is no original to restore for this image. Check the Media Library for its current status.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::MISSING_BACKUP     => array( 404, __( 'The original backup file is missing. Recover it from your site backup, then try the restore again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::BACKUP_UNVERIFIED  => array( 422, __( 'WordPress could not verify the original backup. It kept the current image. Recover an intact backup or ask your host to check image processing limits, then retry.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::ENCODE_FAILED      => array( 422, __( 'The server could not compress this image. Try a different format in Preview or ask your host to check the WordPress image editor.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::WRITE_FAILED       => array( 500, __( 'Could not save the optimized copy. Check disk space and that WordPress can write to uploads, then try again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::RESTORE_FAILED     => array( 500, __( 'Could not finish restoring this image. Try again.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::RESTORE_UNFINISHED => array( 500, __( 'The original is back in the Media Library. Retry the restore to finish updating its status.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::BACKUPS_PROTECTED  => array( 409, __( 'The legacy backup folder still contains files. Recover any remaining originals and archive the kilobyte-backups folder before clearing its references.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::PURGE_FAILED       => array( 500, __( 'Could not clear the legacy backup references. Check that WordPress can read its uploads folder and save settings, then retry.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::REGEN_FAILED       => array( 422, __( 'Could not regenerate all thumbnails. Check that WordPress can open the image and write to its uploads folder, then retry.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::JOB_NOT_QUEUED     => array( 503, __( 'WordPress could not schedule optimization. Optimize the image manually or ask your host to check scheduled tasks.', 'kilobyte-image-optimizer' ) ),
			OperationFailure::JOB_INTERRUPTED    => array( 503, __( 'Background optimization was interrupted. Optimize the image manually or ask your host to check image processing limits.', 'kilobyte-image-optimizer' ) ),
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
