<?php
/**
 * Port for WordPress media attachment persistence and aggregate library stats.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Repository;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Attachment repository contract.
 */
interface AttachmentRepositoryInterface {

	/**
	 * Return attachment metadata needed for processing, or null if invalid/missing on disk.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $for_encoding Capture a source revision before creating a copy.
	 * @return array<string, mixed>|null
	 */
	public function find_image( int $attachment_id, bool $for_encoding = false ): ?array;

	/**
	 * Check for animation before publishing a still copy.
	 *
	 * @param string $file Validated local source path.
	 * @param string $mime Source MIME type.
	 * @return bool
	 */
	public function source_is_animated( string $file, string $mime ): bool;

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether an original is present and readable.
	 */
	public function has_readable_original( int $attachment_id ): bool;

	/**
	 * Verify the current original against the fingerprint saved with its copy.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Stored optimization.
	 * @return bool
	 */
	public function has_verified_original( int $attachment_id, OptimizationRecord $record ): bool;

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether the attachment is a structurally complete animated GIF.
	 */
	public function has_animated_gif_original( int $attachment_id ): bool;

	/**
	 * Retrieve an attachment's optimization record, if present.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return OptimizationRecord|null
	 */
	public function get_record( int $attachment_id ): ?OptimizationRecord;

	/**
	 * Check whether a sibling copy describes an older version of the current source.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param OptimizationRecord        $record        Existing optimization.
	 * @param array<string, mixed>|null  $image         Source already read by an encoder, if available.
	 * @return bool
	 */
	public function has_changed_sibling_source( int $attachment_id, OptimizationRecord $record, ?array $image = null ): bool;

	/**
	 * Persist an attachment's optimization record and update aggregate counters.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Optimization record.
	 * @return bool
	 */
	public function save_record( int $attachment_id, OptimizationRecord $record ): bool;

	/**
	 * Remove an attachment's optimization record and adjust aggregate counters.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function delete_record( int $attachment_id ): bool;

	/**
	 * Restore an older backup to a new file while preserving published URLs.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_tmp_path Validated backup path.
	 * @param int    $expected_bytes  Recorded original size, if available.
	 * @return array{dest_file: string, size: int, thumbnails_ready: bool}|OperationFailure|null
	 */
	public function restore_file( int $attachment_id, string $source_tmp_path, int $expected_bytes );

	/**
	 * Write an optimized copy beside the original file.
	 *
	 * @param int    $attachment_id   Attachment ID.
	 * @param string $source_tmp_path Path to the encoded variant file.
	 * @param string $target_mime     Target MIME type.
	 * @param string $target_ext      Target extension (webp or avif).
	 * @param OptimizationSettings $settings Image settings.
	 * @param float|null           $deadline Time limit for optional thumbnail encodes.
	 * @return array{variant_path: string, size: int, variants: array<string, string>, widths: array<string, int>, thumbnail_stamps: array<string, string>}|null
	 */
	public function save_sibling_variant(
		int $attachment_id,
		string $source_tmp_path,
		string $target_mime,
		string $target_ext,
		OptimizationSettings $settings,
		?float $deadline = null
	): ?array;

	/**
	 * Queue cleanup of files created during an unsuccessful save.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Variant owner.
	 * @return void
	 */
	public function discard_sibling_variant( int $attachment_id, OptimizationRecord $record ): void;

	/**
	 * Run an operation under the attachment lock, or return null if busy.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param callable $operation     Operation to run.
	 * @return mixed
	 */
	public function with_attachment_lock( int $attachment_id, callable $operation );

	/**
	 * Run an operation with exclusive access to backups from earlier releases.
	 *
	 * @param callable $operation Operation to run.
	 * @return mixed
	 */
	public function with_backup_lock( callable $operation );

	/**
	 * Regenerate all registered thumbnail sub-sizes for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int|null Number of generated sizes, or null on failure.
	 */
	public function regenerate_thumbnails( int $attachment_id ): ?int;

	/**
	 * Return paginated queue items and unavailable attachments for this page.
	 *
	 * @param string $mode     Queue mode.
	 * @param int    $before   Return IDs below this cursor, or start at the latest image.
	 * @param int    $per_page Page size (1-100).
	 * @param bool   $check_permissions Require the current user to be able to edit each attachment.
	 * @return array<string, mixed>
	 */
	public function get_queue_page( string $mode, int $before, int $per_page, bool $check_permissions = true ): array;

	/**
	 * Reset persisted backup counters after a full backup purge.
	 *
	 * @return bool
	 */
	public function clear_backup_counters(): bool;

	/**
	 * Reconcile incremental counters against actual attachment metadata to eliminate concurrency drift.
	 *
	 * @return array<string, mixed>|null
	 */
	public function reconcile_stats(): ?array;

	/**
	 * Return O(1) aggregate library optimization statistics.
	 *
	 * @return array<string, mixed>
	 */
	public function get_library_stats(): array;
}
