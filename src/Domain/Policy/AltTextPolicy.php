<?php
/**
 * Pure domain policy for deriving human-readable alt text from filenames.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Policy;

/**
 * Generates clean alt text from image filenames.
 */
final class AltTextPolicy {

	/**
	 * Convert a filename stem into clean sentence-cased alt text.
	 *
	 * @param string $filename Full filename or basename.
	 * @return string
	 */
	public function from_filename( string $filename ): string {
		$stem  = pathinfo( $filename, PATHINFO_FILENAME );
		$clean = preg_replace( '/[-_]+/', ' ', $stem );
		$clean = preg_replace( '/\b(scaled|compressed|optimized|copy|\d{3,4}x\d{3,4})\b/i', '', (string) $clean );
		$clean = trim( (string) preg_replace( '/\s+/', ' ', (string) $clean ) );

		if ( '' === $clean ) {
			return '';
		}

		return ucfirst( $clean );
	}
}
