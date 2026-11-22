<?php
/**
 * WordPress options adapter for settings persistence with agency filter support.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;

/**
 * Persists OptimizationSettings in wp_options.
 */
final class OptionSettingsRepository implements SettingsRepositoryInterface {

	public const OPTION_NAME = 'kilobyte_io_settings';

	/**
	 * Load current optimization settings (filterable via kilobyte_io_settings).
	 *
	 * @return OptimizationSettings
	 */
	public function load(): OptimizationSettings {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = OptimizationSettings::from_array( $saved )->to_array();

		/**
		 * Filter active Kilobyte optimization settings (e.g. agency blueprint lock).
		 *
		 * @param array<string, mixed> $merged Active settings array.
		 */
		$filtered = apply_filters( 'kilobyte_io_settings', $merged );

		return OptimizationSettings::from_array( is_array( $filtered ) ? $filtered : $merged );
	}

	/**
	 * Persist optimization settings.
	 *
	 * @param OptimizationSettings $settings Settings to persist.
	 * @return void
	 */
	public function save( OptimizationSettings $settings ): void {
		update_option( self::OPTION_NAME, $settings->to_array() );
	}

	/**
	 * Initialize default settings on activation if missing.
	 *
	 * @return void
	 */
	public function ensure_defaults(): void {
		if ( false === get_option( self::OPTION_NAME ) ) {
			add_option( self::OPTION_NAME, OptimizationSettings::defaults()->to_array() );
		}
	}
}
