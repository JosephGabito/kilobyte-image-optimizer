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

	/**
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	@param OptimizationEventsInterface    $events Integration events.
	 */
	public function __construct( AttachmentRepositoryInterface $attachments, OptimizationEventsInterface $events ) {
		$this->attachments = $attachments;
		$this->events      = $events;
	}

	/**
	 * Publish a variant while the caller holds the attachment lock.
	 *
	 * @param array<string, mixed> $image    Source image information.
	 * @param string               $path     Encoded local file.
	 * @param string               $mime     Verified output MIME.
	 * @param OptimizationSettings  $settings Active image settings.
	 * @param string               $engine   Encoder identifier.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( array $image, string $path, string $mime, OptimizationSettings $settings, string $engine ) {
		$id      = (int) $image['id'];
		$ext     = ImageFormat::extension_for_mime( $mime );
		$variant = null !== $ext ? $this->attachments->save_sibling_variant( $id, $path, $mime, $ext, $settings ) : null;
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
			$image['source_revision'] ?? ''
		);
		if ( ! $this->attachments->save_record( $id, $record ) ) {
			$this->attachments->delete_sibling_variant( $id, $record );
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
	 * Record a file that did not benefit from compression.
	 *
	 * @param array<string, mixed> $image  Source image.
	 * @param string               $engine Encoder.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function record_unchanged( array $image, string $engine ) {
		$id     = (int) $image['id'];
		$size   = (int) $image['file_size'];
		$record = new OptimizationRecord(
			$size,
			$size,
			strtolower( pathinfo( $image['file'], PATHINFO_EXTENSION ) ),
			$image['filename'],
			'',
			gmdate( 'Y-m-d H:i:s' ),
			OptimizationRecord::STATUS_ALREADY_OPTIMAL,
			$engine
		);
		if ( ! $this->attachments->save_record( $id, $record ) ) {
			return new OperationFailure( OperationFailure::WRITE_FAILED );
		}
		return array(
			'id'      => $id,
			'skipped' => true,
			'status'  => OptimizationRecord::STATUS_ALREADY_OPTIMAL,
		);
	}
}
