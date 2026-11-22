<?php
/**
 * Optimize an image on the server.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\OptimizationEventsInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\ServerCompressorInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Server fallback for uploads, bulk optimization, and WP-CLI.
 */
final class OptimizeOnServerUseCase {

	/** @var AttachmentRepositoryInterface */
	private AttachmentRepositoryInterface $attachments;

	/** @var OptimizationEventsInterface */
	private OptimizationEventsInterface $events;

	/** @var SettingsRepositoryInterface */
	private SettingsRepositoryInterface $settings;

	/** @var ServerCompressorInterface */
	private ServerCompressorInterface $compressor;

	/** @var CompressionPolicy */
	private CompressionPolicy $policy;

	/** @var SaveOptimizedVariant */
	private SaveOptimizedVariant $save_variant;

	/**
	 * @param AttachmentRepositoryInterface $attachments  Attachment repository.
	 * @param SettingsRepositoryInterface   $settings     Settings repository.
	 * @param ServerCompressorInterface      $compressor   Server encoder.
	 * @param CompressionPolicy             $policy       Compression policy.
	 * @param SaveOptimizedVariant           $save_variant Publication step.
	@param OptimizationEventsInterface    $events Integration events.
	 */
	public function __construct(
		AttachmentRepositoryInterface $attachments,
		OptimizationEventsInterface $events,
		SettingsRepositoryInterface $settings,
		ServerCompressorInterface $compressor,
		CompressionPolicy $policy,
		SaveOptimizedVariant $save_variant
	) {
		$this->attachments  = $attachments;
		$this->events       = $events;
		$this->settings     = $settings;
		$this->compressor   = $compressor;
		$this->policy       = $policy;
		$this->save_variant = $save_variant;
	}

	/**
	 * Encode and store a variant of the current attachment file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( int $attachment_id ) {
		$result = $this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id ) {
				return $this->optimize_image( $attachment_id );
			}
		);
		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}

	/**
	 * Encode an image while holding its lock.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>|OperationFailure
	 */
	private function optimize_image( int $id ) {
		if ( ! $this->events->should_optimize( $id ) ) {
			return array(
				'id'      => $id,
				'skipped' => true,
				'status'  => 'excluded',
			);
		}
		$image = $this->attachments->find_image( $id, true );
		if ( null === $image || ! $this->policy->is_supported_source_mime( (string) $image['mime_type'] ) ) {
			return new OperationFailure( OperationFailure::MISSING_SOURCE );
		}

		$existing = $this->attachments->get_record( $id );
		if ( null !== $existing && ! $existing->is_already_optimal() ) {
			return array(
				'id'      => $id,
				'skipped' => true,
				'status'  => 'already_processed',
			);
		}

		$settings = $this->settings->load();
		$encoded  = $this->compressor->compress_to_temp( (string) $image['file'], (string) $image['mime_type'], $settings );

		if ( isset( $encoded['error'] ) ) {
			return new OperationFailure( OperationFailure::ENCODE_FAILED, $encoded['error'] );
		}
		if ( isset( $encoded['skip_reason'] ) ) {
			return array(
				'id'      => $id,
				'skipped' => true,
				'status'  => $encoded['skip_reason'],
			);
		}

		try {
			if ( $this->policy->should_skip_output( (int) $image['file_size'], $encoded['size'], $settings ) ) {
				return $this->save_variant->record_unchanged( $image, OptimizationRecord::ENGINE_SERVER );
			}

			return $this->save_variant->execute( $image, $encoded['tmp_path'], $encoded['mime'], $settings, OptimizationRecord::ENGINE_SERVER );
		} finally {
			$this->compressor->release_temp( $encoded['tmp_path'] );
		}
	}
}
