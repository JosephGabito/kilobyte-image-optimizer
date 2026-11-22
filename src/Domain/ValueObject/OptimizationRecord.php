<?php
/**
 * Immutable optimization record value object.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\ValueObject;

/**
 * Tracks optimization outcome, delivery mode, and backup details for a single attachment.
 */
final class OptimizationRecord {

	public const STATUS_OPTIMIZED            = 'optimized';
	public const STATUS_ALREADY_OPTIMAL      = 'already_optimal';
	public const STATUS_ANIMATED             = 'animated';
	public const STATUS_ORIGINAL_FORMAT_KEPT = 'original_format_kept';
	public const ENGINE_BROWSER              = 'browser';
	public const ENGINE_SERVER               = 'server';
	public const MODE_IN_PLACE               = 'in_place';
	public const MODE_SIBLING                = 'sibling';

	/**
	 * Original byte size.
	 *
	 * @var int
	 */
	private int $original_size;

	/**
	 * Optimized byte size.
	 *
	 * @var int
	 */
	private int $optimized_size;

	/**
	 * Output file extension.
	 *
	 * @var string
	 */
	private string $format;

	/**
	 * Original filename before optimization.
	 *
	 * @var string
	 */
	private string $original_name;

	/**
	 * Relative backup path inside uploads directory (for in-place replacements).
	 *
	 * @var string
	 */
	private string $backup_path;

	/**
	 * Backup byte size on disk.
	 *
	 * @var int
	 */
	private int $backup_bytes;

	/**
	 * Upload-relative source paths mapped to the files generated for them.
	 *
	 * @var array<string, string>
	 */
	private array $variants;

	/** @var array<string, int> */
	private array $variant_widths;

	/** @var string */
	private string $source_revision;

	/** @var array<string, string> */
	private array $thumbnail_stamps;

	/**
	 * Relative sibling variant path inside uploads directory (for non-destructive format conversion).
	 *
	 * @var string
	 */
	private string $variant_path;

	/**
	 * Delivery mode: 'in_place' or 'sibling'.
	 *
	 * @var string
	 */
	private string $delivery_mode;

	/**
	 * UTC timestamp of optimization.
	 *
	 * @var string
	 */
	private string $optimized_at;

	/**
	 * Record status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Compression engine used (browser or server).
	 *
	 * @var string
	 */
	private string $engine;

	/**
	 * Constructor.
	 *
	 * @param int    $original_size  Original byte size.
	 * @param int    $optimized_size Optimized byte size.
	 * @param string $format         Output extension.
	 * @param string $original_name  Original filename.
	 * @param string $backup_path    Relative backup path.
	 * @param string $optimized_at   UTC timestamp.
	 * @param string $status         Optimization status.
	 * @param string $engine         Engine identifier.
	 * @param string $delivery_mode  Delivery mode ('in_place' or 'sibling').
	 * @param string $variant_path   Relative sibling file path.
	 * @param int    $backup_bytes   Backup size in bytes.
	 * @param array<string, int> $variant_widths Width of each generated file.
	 * @param array<string, string> $variants   Source paths mapped to generated files.
	 * @param string                $source_revision Original file revision.
	 * @param array<string, string> $thumbnail_stamps Revisions of original sub-size files.
	 */
	public function __construct(
		int $original_size,
		int $optimized_size,
		string $format,
		string $original_name,
		string $backup_path,
		string $optimized_at,
		string $status = self::STATUS_OPTIMIZED,
		string $engine = self::ENGINE_BROWSER,
		string $delivery_mode = self::MODE_IN_PLACE,
		string $variant_path = '',
		int $backup_bytes = 0,
		array $variants = array(),
		array $variant_widths = array(),
		string $source_revision = '',
		array $thumbnail_stamps = array()
	) {
		$this->original_size    = max( 0, $original_size );
		$this->optimized_size   = max( 0, $optimized_size );
		$this->format           = $format;
		$this->original_name    = $original_name;
		$this->backup_path      = $backup_path;
		$this->optimized_at     = $optimized_at;
		$this->status           = $status;
		$this->engine           = $engine;
		$this->delivery_mode    = self::MODE_SIBLING === $delivery_mode ? self::MODE_SIBLING : self::MODE_IN_PLACE;
		$this->variant_path     = $variant_path;
		$this->backup_bytes     = '' !== $backup_path ? max( 0, $backup_bytes ) : 0;
		$this->variants         = $variants;
		$this->variant_widths   = $variant_widths;
		$this->source_revision  = $source_revision;
		$this->thumbnail_stamps = $thumbnail_stamps;
	}

	/**
	 * Hydrate from persisted post meta array.
	 *
	 * @param array<string, mixed> $data Stored meta array.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$orig = isset( $data['original_size'] ) ? (int) $data['original_size'] : 0;
		$opt  = isset( $data['optimized_size'] ) ? (int) $data['optimized_size'] : $orig;

		return new self(
			$orig,
			$opt,
			isset( $data['format'] ) ? (string) $data['format'] : '',
			isset( $data['original_name'] ) ? (string) $data['original_name'] : '',
			isset( $data['backup_path'] ) ? (string) $data['backup_path'] : '',
			isset( $data['optimized_at'] ) ? (string) $data['optimized_at'] : '',
			isset( $data['status'] ) ? (string) $data['status'] : self::STATUS_OPTIMIZED,
			isset( $data['engine'] ) ? (string) $data['engine'] : self::ENGINE_BROWSER,
			isset( $data['delivery_mode'] ) ? (string) $data['delivery_mode'] : self::MODE_IN_PLACE,
			isset( $data['variant_path'] ) ? (string) $data['variant_path'] : '',
			isset( $data['backup_bytes'] ) ? (int) $data['backup_bytes'] : 0,
			isset( $data['variants'] ) && is_array( $data['variants'] ) ? array_filter( $data['variants'], 'is_string' ) : array(),
			isset( $data['variant_widths'] ) && is_array( $data['variant_widths'] ) ? array_filter( $data['variant_widths'], 'is_int' ) : array(),
			is_string( $data['source_revision'] ?? null ) ? $data['source_revision'] : '',
			isset( $data['thumbnail_stamps'] ) && is_array( $data['thumbnail_stamps'] ) ? array_filter( $data['thumbnail_stamps'], 'is_string' ) : array()
		);
	}

	/**
	 * Convert to array for post meta storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'original_size'    => $this->original_size,
			'optimized_size'   => $this->optimized_size,
			'saved_bytes'      => $this->saved_bytes(),
			'format'           => $this->format,
			'original_name'    => $this->original_name,
			'backup_path'      => $this->backup_path,
			'backup_bytes'     => $this->backup_bytes,
			'variant_path'     => $this->variant_path,
			'variants'         => $this->variants,
			'variant_widths'   => $this->variant_widths,
			'source_revision'  => $this->source_revision,
			'thumbnail_stamps' => $this->thumbnail_stamps,
			'delivery_mode'    => $this->delivery_mode,
			'optimized_at'     => $this->optimized_at,
			'status'           => $this->status,
			'engine'           => $this->engine,
		);
	}

	/**
	 * Original byte size.
	 *
	 * @return int
	 */
	public function original_size(): int {
		return $this->original_size;
	}

	/**
	 * @return string UTC time the copy was created.
	 */
	public function optimized_at(): string {
		return $this->optimized_at;
	}

	/**
	 * @return string Stored optimization status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * @return string
	 */
	public function source_revision(): string {
		return $this->source_revision;
	}

	/**
	 * @return array<string, string> Original sub-size revisions by upload-relative path.
	 */
	public function thumbnail_stamps(): array {
		return $this->thumbnail_stamps;
	}

	/**
	 * Optimized byte size.
	 *
	 * @return int
	 */
	public function optimized_size(): int {
		return $this->optimized_size;
	}

	/**
	 * Bytes saved.
	 *
	 * @return int
	 */
	public function saved_bytes(): int {
		return max( 0, $this->original_size - $this->optimized_size );
	}

	/**
	 * Percentage reduction rounded to 1 decimal place.
	 *
	 * @return float
	 */
	public function saved_percent(): float {
		if ( $this->original_size <= 0 ) {
			return 0.0;
		}
		return round( ( $this->saved_bytes() / $this->original_size ) * 100, 1 );
	}

	/**
	 * Output extension.
	 *
	 * @return string
	 */
	public function format(): string {
		return $this->format;
	}

	/**
	 * Original filename.
	 *
	 * @return string
	 */
	public function original_name(): string {
		return $this->original_name;
	}

	/**
	 * Relative backup path.
	 *
	 * @return string
	 */
	public function backup_path(): string {
		return $this->backup_path;
	}

	/**
	 * Backup size in bytes.
	 *
	 * @return int
	 */
	public function backup_bytes(): int {
		return $this->backup_bytes;
	}

	/**
	 * Relative sibling variant path.
	 *
	 * @return string
	 */
	public function variant_path(): string {
		return $this->variant_path;
	}

	/**
	 * Paths of generated files by upload-relative source path.
	 *
	 * @return array<string, string>
	 */
	public function variants(): array {
		return $this->variants;
	}

	/**
	 * @return array<string, int>
	 */
	public function variant_widths(): array {
		return $this->variant_widths;
	}

	/**
	 * Delivery mode.
	 *
	 * @return string
	 */
	public function delivery_mode(): string {
		return $this->delivery_mode;
	}

	/**
	 * Whether this attachment uses a non-destructive sibling variant.
	 *
	 * @return bool
	 */
	public function is_sibling(): bool {
		return self::MODE_SIBLING === $this->delivery_mode && '' !== $this->variant_path;
	}

	/**
	 * Whether an external backup exists.
	 *
	 * @return bool
	 */
	public function has_backup(): bool {
		return '' !== $this->backup_path;
	}

	/**
	 * Whether this attachment can be restored (either via sibling removal or backup restore).
	 *
	 * @return bool
	 */
	public function is_restorable(): bool {
		return $this->is_sibling() || $this->has_backup();
	}

	/**
	 * Whether the image was marked already optimal.
	 *
	 * @return bool
	 */
	public function is_already_optimal(): bool {
		return self::STATUS_ALREADY_OPTIMAL === $this->status;
	}

	/**
	 * Whether the check kept the source and produced no new file.
	 *
	 * @return bool
	 */
	public function is_unchanged(): bool {
		return in_array( $this->status, array( self::STATUS_ALREADY_OPTIMAL, self::STATUS_ANIMATED, self::STATUS_ORIGINAL_FORMAT_KEPT ), true );
	}

	/**
	 * Compression engine name.
	 *
	 * @return string
	 */
	public function engine(): string {
		return $this->engine;
	}
}
