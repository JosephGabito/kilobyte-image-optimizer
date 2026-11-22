<?php
/**
 * Keep retired variants available briefly for cached pages.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Repository;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;

/**
 * Queues cleanup of files after their delivery record is removed.
 */
interface VariantRetirementInterface {

	/**
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Record owning the files.
	 * @return bool
	 */
	public function retire( int $attachment_id, OptimizationRecord $record ): bool;
}
