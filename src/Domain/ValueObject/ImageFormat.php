<?php
/**
 * Image format value object.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\ValueObject;

/**
 * Represents a supported target image format.
 */
final class ImageFormat {

	public const WEBP     = 'webp';
	public const AVIF     = 'avif';
	public const ORIGINAL = 'original';

	/**
	 * Allowed format slugs.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED = array(
		self::WEBP,
		self::AVIF,
		self::ORIGINAL,
	);

	/**
	 * MIME type to file extension mapping.
	 *
	 * @var array<string, string>
	 */
	private const MIME_TO_EXTENSION = array(
		'image/webp' => 'webp',
		'image/avif' => 'avif',
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
	);

	/**
	 * Normalized format value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Constructor.
	 *
	 * @param string $value Format identifier.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Create a format value object with safe fallback to WebP.
	 *
	 * @param string $raw Raw format string.
	 * @return self
	 */
	public static function from_string( string $raw ): self {
		$normalized = strtolower( trim( $raw ) );
		if ( ! in_array( $normalized, self::ALLOWED, true ) ) {
			return new self( self::WEBP );
		}
		return new self( $normalized );
	}

	/**
	 * Return string value.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Check whether the given MIME type already matches this target format.
	 *
	 * @param string $mime Source MIME type.
	 * @return bool
	 */
	public function matches_mime( string $mime ): bool {
		if ( self::WEBP === $this->value && 'image/webp' === $mime ) {
			return true;
		}
		if ( self::AVIF === $this->value && 'image/avif' === $mime ) {
			return true;
		}
		return false;
	}

	/**
	 * Map a validated output MIME type to its canonical file extension.
	 *
	 * @param string $mime Output MIME type.
	 * @return string|null
	 */
	public static function extension_for_mime( string $mime ): ?string {
		return self::MIME_TO_EXTENSION[ $mime ] ?? null;
	}

	/**
	 * Check whether a MIME type is an allowed output format.
	 *
	 * @param string $mime Output MIME type.
	 * @return bool
	 */
	public static function is_allowed_output_mime( string $mime ): bool {
		return isset( self::MIME_TO_EXTENSION[ $mime ] );
	}
}
