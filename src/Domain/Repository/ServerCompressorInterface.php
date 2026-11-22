<?php
/**
 * Port for server-side image compression fallback.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Repository;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;

/**
 * Server image compressor contract.
 */
interface ServerCompressorInterface {

	/**
	 * Compress an image file on the server into a temporary file.
	 *
	 * @param string               $source_file Absolute path to the source image.
	 * @param string               $source_mime Source MIME type.
	 * @param OptimizationSettings $settings    Active optimization settings.
	 * @return array{tmp_path: string, mime: string, size: int}|array{error: string}|array{skip_reason: string}
	 */
	public function compress_to_temp(
		string $source_file,
		string $source_mime,
		OptimizationSettings $settings
	): array;
	/**
	 * Release the temporary file returned by the compressor.
	 *
	 * @param string $path Temporary file.
	 * @return void
	 */
	public function release_temp( string $path ): void;
}
