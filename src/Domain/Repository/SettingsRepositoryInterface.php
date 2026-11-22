<?php
/**
 * Port for persisting and loading plugin settings.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Repository;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;

/**
 * Settings repository contract.
 */
interface SettingsRepositoryInterface {

	/**
	 * Load current optimization settings.
	 *
	 * @return OptimizationSettings
	 */
	public function load(): OptimizationSettings;

	/** Load saved settings before site filters are applied. */
	public function load_saved(): OptimizationSettings;

	/**
	 * Persist optimization settings.
	 *
	 * @param OptimizationSettings $settings Settings to persist.
	 * @return bool Whether the requested settings are saved.
	 */
	public function save( OptimizationSettings $settings ): bool;

	/**
	 * Ensure default settings exist on activation.
	 *
	 * @return void
	 */
	public function ensure_defaults(): void;
}
