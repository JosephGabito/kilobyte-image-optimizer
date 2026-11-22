<?php
/**
 * Save an optimized variant while keeping the Media Library original.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\OptimizationEventsInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\VariantRetirementInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\ImageFormat;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Shared publication step for browser and server encoders.
 */
final class SaveOptimizedVariant {

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepositoryInterface
	 */
	private AttachmentRepositoryInterface $attachments;

	/** @var OptimizationEventsInterface */
	private OptimizationEventsInterface $events;

	/** @var VariantRetirementInterface */
	private VariantRetirementInterface $retirement;

	/**
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	 * @param OptimizationEventsInterface    $events      Integration events.
	 * @param VariantRetirementInterface     $retirement  Deferred cleanup of replaced copies.
	 */
	public function __construct( AttachmentRepositoryInterface $attachments, OptimizationEventsInterface $events, VariantRetirementInterface $retirement ) {
		$this->attachments = $attachments;
		$this->events      = $events;
		$this->retirement  = $retirement;
	}

	/**
	 * Publish a variant while the caller holds the attachment lock.
	 *
	 * @param array<string, mixed> $image    Source image information.
	 * @param string               $path     Encoded local file.
	 * @param string               $mime     Verified output MIME.
	 * @param OptimizationSettings  $settings Active image settings.
	 * @param string               $engine   Encoder identifier.
	 * @param float|null           $deadline Time limit for optional thumbnail encodes.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( array $image, string $path, string $mime, OptimizationSettings $settings, string $engine, ?float $deadline = null ) {
		$id      = (int) $image['id'];
		$ext     = ImageFormat::extension_for_mime( $mime );
		$variant = null !== $ext ? $this->attachments->save_sibling_variant( $id, $path, $mime, $ext, $settings, $deadline ) : null;
		if ( null === $variant ) {
			return new OperationFailure( OperationFailure::WRITE_FAILED );
		}

		$record = new OptimizationRecord(
			(int) $image['file_size'],
			$variant['size'],
			$ext,
			(string) $image['filename'],
			'',
			gmdate( 'Y-m-d H:i:s' ),
			OptimizationRecord::STATUS_OPTIMIZED,
			$engine,
			OptimizationRecord::MODE_SIBLING,
			$variant['variant_path'],
			0,
			$variant['variants'],
			$variant['widths'],
			$image['source_revision'] ?? '',
			$variant['thumbnail_stamps']
		);
		if ( ! $this->source_is_current( $image ) ) {
			$this->attachments->discard_sibling_variant( $id, $record );
			return new OperationFailure( OperationFailure::SOURCE_CHANGED );
		}
		if ( ! $this->save_record( $id, $record, $image['source_hash'] ?? '' ) ) {
			$this->attachments->discard_sibling_variant( $id, $record );
			return new OperationFailure( OperationFailure::WRITE_FAILED );
		}

		$this->events->optimized( $id, $record );

		return array(
			'id'             => $id,
			'skipped'        => false,
			'original_size'  => $record->original_size(),
			'optimized_size' => $record->optimized_size(),
			'saved_bytes'    => $record->saved_bytes(),
			'saved_percent'  => $record->saved_percent(),
			'format'         => $record->format(),
			'delivery_mode'  => $record->delivery_mode(),
			'has_backup'     => true,
			'engine'         => $engine,
		);
	}
	/**
	 * Record a checked source that should stay unchanged.
	 *
	 * @param array<string, mixed> $image  Source image.
	 * @param string               $engine Encoder.
	 * @param string               $status Why the source was kept.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function record_unchanged( array $image, string $engine, string $status = OptimizationRecord::STATUS_ALREADY_OPTIMAL ) {
		if ( ! $this->source_is_current( $image ) ) {
			return new OperationFailure( OperationFailure::SOURCE_CHANGED );
		}
		$id     = (int) $image['id'];
		$size   = (int) $image['file_size'];
		$record = new OptimizationRecord(
			$size,
			$size,
			strtolower( pathinfo( $image['file'], PATHINFO_EXTENSION ) ),
			$image['filename'],
			'',
			gmdate( 'Y-m-d H:i:s' ),
			$status,
			$engine,
			OptimizationRecord::MODE_IN_PLACE,
			'',
			0,
			array(),
			array(),
			$image['source_revision']
		);
		if ( ! $this->save_record( $id, $record, $image['source_hash'] ?? '' ) ) {
			return new OperationFailure( OperationFailure::WRITE_FAILED );
		}
		return array(
			'id'      => $id,
			'skipped' => true,
			'status'  => $status,
		);
	}

	/**
	 * Preserve previous copies while updating the delivery record for an edited image.
	 *
	 * @param int                $id          Attachment ID.
	 * @param OptimizationRecord $record      New outcome.
	 * @param string             $source_hash Current original checksum.
	 * @return bool
	 */
	private function save_record( int $id, OptimizationRecord $record, string $source_hash ): bool {
		$previous   = $this->attachments->get_record( $id );
		$had_copy   = null !== $previous && $previous->is_sibling();
		$may_retire = $had_copy && $previous->matches_source_hash( $source_hash );
		if ( $had_copy && ! $may_retire && ! $this->retirement->preserve( $id, $previous ) ) {
			return false;
		}
		if ( ! $this->attachments->save_record( $id, $record ) ) {
			return false;
		}
		if ( $may_retire ) {
			$this->retirement->retire( $id, $previous );
		}
		if ( $had_copy && $record->is_unchanged() ) {
			$this->events->variant_changed( $id );
		}
		return true;
	}

	/**
	 * Recheck the source immediately before a record is published.
	 *
	 * @param array<string, mixed> $image Source seen at the start of the operation.
	 * @return bool
	 */
	private function source_is_current( array $image ): bool {
		$current = $this->attachments->find_image( (int) $image['id'], true );
		return ! empty( $image['source_revision'] ) && ( $current['source_revision'] ?? '' ) === $image['source_revision'] && $current['file'] === $image['file'];
	}
}
