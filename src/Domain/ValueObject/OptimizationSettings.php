<?php
/**
 * Immutable optimization settings value object.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\ValueObject;

/**
 * Encapsulates validated plugin settings.
 */
final class OptimizationSettings {

	public const MIN_QUALITY     = 40;
	public const MAX_QUALITY     = 95;
	public const MAX_DIMENSION   = 8000;
	public const MIN_CONCURRENCY = 1;
	public const MAX_CONCURRENCY = 6;

	/**
	 * Whether automatic upload optimization is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Target image format.
	 *
	 * @var ImageFormat
	 */
	private ImageFormat $format;

	/**
	 * Encoding quality (40-95).
	 *
	 * @var int
	 */
	private int $quality;

	/**
	 * Maximum width in pixels (0 disables width scaling).
	 *
	 * @var int
	 */
	private int $max_width;

	/**
	 * Maximum height in pixels (0 disables height scaling).
	 *
	 * @var int
	 */
	private int $max_height;

	/**
	 * Whether to keep original backups before replacing files.
	 *
	 * @var bool
	 */
	private bool $keep_backups;

	/**
	 * Whether to skip saving when the output is not smaller than the source.
	 *
	 * @var bool
	 */
	private bool $skip_smaller;

	/**
	 * Whether to auto-populate empty alt text from filenames.
	 *
	 * @var bool
	 */
	private bool $auto_alt_text;

	/**
	 * Whether to apply Core Web Vitals image attribute hints.
	 *
	 * @var bool
	 */
	private bool $cwv_enhancements;

	/**
	 * Parallel bulk worker count (1-6).
	 *
	 * @var int
	 */
	private int $concurrency;

	/**
	 * Constructor.
	 *
	 * @param bool        $enabled          Enable auto optimization on upload.
	 * @param ImageFormat $format           Target format.
	 * @param int         $quality          Quality 40-95.
	 * @param int         $max_width        Max width px.
	 * @param int         $max_height       Max height px.
	 * @param bool        $keep_backups     Keep original backups.
	 * @param bool        $skip_smaller     Skip if not smaller.
	 * @param bool        $auto_alt_text    Auto-generate alt text.
	 * @param bool        $cwv_enhancements Apply CWV hints.
	 * @param int         $concurrency      Bulk worker count.
	 */
	public function __construct(
		bool $enabled,
		ImageFormat $format,
		int $quality,
		int $max_width,
		int $max_height,
		bool $keep_backups,
		bool $skip_smaller,
		bool $auto_alt_text,
		bool $cwv_enhancements,
		int $concurrency
	) {
		$this->enabled          = $enabled;
		$this->format           = $format;
		$this->quality          = max( self::MIN_QUALITY, min( self::MAX_QUALITY, $quality ) );
		$this->max_width        = max( 0, min( self::MAX_DIMENSION, $max_width ) );
		$this->max_height       = max( 0, min( self::MAX_DIMENSION, $max_height ) );
		$this->keep_backups     = $keep_backups;
		$this->skip_smaller     = $skip_smaller;
		$this->auto_alt_text    = $auto_alt_text;
		$this->cwv_enhancements = $cwv_enhancements;
		$this->concurrency      = max( self::MIN_CONCURRENCY, min( self::MAX_CONCURRENCY, $concurrency ) );
	}

	/**
	 * Build default settings.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return new self(
			true,
			ImageFormat::from_string( ImageFormat::WEBP ),
			82,
			2560,
			2560,
			true,
			true,
			true,
			true,
			3
		);
	}

	/**
	 * Hydrate from an untrusted array with strict bounds and defaults.
	 *
	 * @param array<string, mixed> $input Raw settings array.
	 * @return self
	 */
	public static function from_array( array $input ): self {
		$defaults = self::defaults();

		return new self(
			isset( $input['enabled'] ) ? (bool) $input['enabled'] : $defaults->is_enabled(),
			is_string( $input['format'] ?? null ) ? ImageFormat::from_string( $input['format'] ) : $defaults->format(),
			isset( $input['quality'] ) ? (int) $input['quality'] : $defaults->quality(),
			isset( $input['max_width'] ) ? (int) $input['max_width'] : $defaults->max_width(),
			isset( $input['max_height'] ) ? (int) $input['max_height'] : $defaults->max_height(),
			isset( $input['keep_backups'] ) ? (bool) $input['keep_backups'] : $defaults->keep_backups(),
			isset( $input['skip_smaller'] ) ? (bool) $input['skip_smaller'] : $defaults->skip_smaller(),
			isset( $input['auto_alt_text'] ) ? (bool) $input['auto_alt_text'] : $defaults->auto_alt_text(),
			isset( $input['cwv_enhancements'] ) ? (bool) $input['cwv_enhancements'] : $defaults->cwv_enhancements(),
			isset( $input['concurrency'] ) ? (int) $input['concurrency'] : $defaults->concurrency()
		);
	}

	/**
	 * Serialize to a plain array for storage or JSON responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'enabled'          => $this->enabled,
			'format'           => $this->format->value(),
			'quality'          => $this->quality,
			'max_width'        => $this->max_width,
			'max_height'       => $this->max_height,
			'keep_backups'     => $this->keep_backups,
			'skip_smaller'     => $this->skip_smaller,
			'auto_alt_text'    => $this->auto_alt_text,
			'cwv_enhancements' => $this->cwv_enhancements,
			'concurrency'      => $this->concurrency,
		);
	}

	/**
	 * Whether auto-optimization on upload is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Target image format.
	 *
	 * @return ImageFormat
	 */
	public function format(): ImageFormat {
		return $this->format;
	}

	/**
	 * Compression quality (40-95).
	 *
	 * @return int
	 */
	public function quality(): int {
		return $this->quality;
	}

	/**
	 * Max width in pixels.
	 *
	 * @return int
	 */
	public function max_width(): int {
		return $this->max_width;
	}

	/**
	 * Max height in pixels.
	 *
	 * @return int
	 */
	public function max_height(): int {
		return $this->max_height;
	}

	/**
	 * Whether backups are kept.
	 *
	 * @return bool
	 */
	public function keep_backups(): bool {
		return $this->keep_backups;
	}

	/**
	 * Whether non-smaller outputs are skipped.
	 *
	 * @return bool
	 */
	public function skip_smaller(): bool {
		return $this->skip_smaller;
	}

	/**
	 * Whether auto alt text is enabled.
	 *
	 * @return bool
	 */
	public function auto_alt_text(): bool {
		return $this->auto_alt_text;
	}

	/**
	 * Whether Core Web Vitals hints are enabled.
	 *
	 * @return bool
	 */
	public function cwv_enhancements(): bool {
		return $this->cwv_enhancements;
	}

	/**
	 * Bulk worker parallelism.
	 *
	 * @return int
	 */
	public function concurrency(): int {
		return $this->concurrency;
	}
}
