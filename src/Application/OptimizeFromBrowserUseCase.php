<?php
/**
 * Save an optimized image supplied by the browser.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\OptimizationEventsInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Apply attachment policy before publishing browser-encoded files.
 */
final class OptimizeFromBrowserUseCase {

	/** @var AttachmentRepositoryInterface */
	private AttachmentRepositoryInterface $attachments;

	/** @var OptimizationEventsInterface */
	private OptimizationEventsInterface $events;

	/** @var SettingsRepositoryInterface */
	private SettingsRepositoryInterface $settings;

	/** @var CompressionPolicy */
	private CompressionPolicy $policy;

	/** @var SaveOptimizedVariant */
	private SaveOptimizedVariant $save_variant;

	/**
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	 * @param SettingsRepositoryInterface   $settings    Settings repository.
	 * @param CompressionPolicy             $policy      Image policy.
	 * @param SaveOptimizedVariant           $save_variant Publication step.
	@param OptimizationEventsInterface    $events Integration events.
	 */
	public function __construct(
		AttachmentRepositoryInterface $attachments,
		OptimizationEventsInterface $events,
		SettingsRepositoryInterface $settings,
		CompressionPolicy $policy,
		SaveOptimizedVariant $save_variant
	) {
		$this->attachments  = $attachments;
		$this->events       = $events;
		$this->settings     = $settings;
		$this->policy       = $policy;
		$this->save_variant = $save_variant;
	}

	/**
	 * Save a validated browser upload.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $tmp_path      Uploaded temporary file.
	 * @param string $detected_mime Validated MIME type.
	 * @param int    $candidate_size Size of the validated upload.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( int $attachment_id, string $tmp_path, string $detected_mime, int $candidate_size ) {
		$result = $this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id, $tmp_path, $detected_mime, $candidate_size ) {
				if ( ! $this->events->should_optimize( $attachment_id ) ) {
					return array(
						'id'      => $attachment_id,
						'skipped' => true,
						'status'  => 'excluded',
					);
				}
				$image = $this->attachments->find_image( $attachment_id );
				if ( null === $image || ! $this->policy->is_supported_source_mime( (string) $image['mime_type'] ) ) {
					return new OperationFailure( OperationFailure::MISSING_SOURCE );
				}

				$existing = $this->attachments->get_record( $attachment_id );
				if ( null !== $existing && ! $existing->is_already_optimal() ) {
					return array(
						'id'      => $attachment_id,
						'skipped' => true,
						'status'  => 'already_processed',
					);
				}

				$settings = $this->settings->load();
				if ( $this->policy->should_skip_output( (int) $image['file_size'], $candidate_size, $settings ) ) {
					return $this->save_variant->record_unchanged( $image, OptimizationRecord::ENGINE_BROWSER );
				}

				return $this->save_variant->execute(
					$image,
					$tmp_path,
					$detected_mime,
					$settings,
					OptimizationRecord::ENGINE_BROWSER
				);
			}
		);

		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}
}
