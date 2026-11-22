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

/**
 * Attachment repository contract.
 */
interface AttachmentRepositoryInterface {

	/**
	 * Return attachment metadata needed for processing, or null if invalid/missing on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	public function find_image( int $attachment_id ): ?array;

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether an original is present and readable.
	 */
	public function has_readable_original( int $attachment_id ): bool;

	/**
	 * Retrieve an attachment's optimization record, if present.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return OptimizationRecord|null
	 */
	public function get_record( int $attachment_id ): ?OptimizationRecord;

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
	 * @return array{dest_file: string, size: int, thumbnails_ready: bool}|null
	 */
	public function restore_file( int $attachment_id, string $source_tmp_path ): ?array;

	/**
	 * Write an optimized copy beside the original file.
	 *
	 * @param int    $attachment_id   Attachment ID.
	 * @param string $source_tmp_path Path to the encoded variant file.
	 * @param string $target_mime     Target MIME type.
	 * @param string $target_ext      Target extension (webp or avif).
	 * @param OptimizationSettings $settings Image settings.
	 * @return array{variant_path: string, size: int, variants: array<string, string>, widths: array<string, int>}|null
	 */
	public function save_sibling_variant(
		int $attachment_id,
		string $source_tmp_path,
		string $target_mime,
		string $target_ext,
		OptimizationSettings $settings
	): ?array;

	/**
	 * Remove only the images owned by a variant record.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Variant owner.
	 * @return bool
	 */
	public function delete_sibling_variant( int $attachment_id, OptimizationRecord $record ): bool;

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
	 * Return paginated queue items for 'optimize', 'regenerate', or 'restore'.
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
