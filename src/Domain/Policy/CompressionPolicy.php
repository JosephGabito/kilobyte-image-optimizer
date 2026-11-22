<?php
/**
 * Pure domain rules for image compression eligibility and savings.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Policy;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;

/**
 * Encapsulates compression eligibility rules.
 */
final class CompressionPolicy {

	/**
	 * Supported source image MIME types.
	 *
	 * @var array<int, string>
	 */
	public const SUPPORTED_SOURCE_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/avif',
		'image/bmp',
	);

	/**
	 * Check whether a source MIME type is eligible for optimization.
	 *
	 * @param string $mime Source MIME type.
	 * @return bool
	 */
	public function is_supported_source_mime( string $mime ): bool {
		return in_array( $mime, self::SUPPORTED_SOURCE_MIMES, true );
	}

	/**
	 * Keep the existing image when the encoded copy is no smaller.
	 *
	 * @param int                  $original_size  Original file size in bytes.
	 * @param int                  $candidate_size Encoded file size in bytes.
	 * @param OptimizationSettings $settings       Active settings.
	 * @return bool
	 */
	public function should_skip_output(
		int $original_size,
		int $candidate_size,
		OptimizationSettings $settings
	): bool {
		return $settings->skip_smaller() && $candidate_size >= $original_size;
	}
}
