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

	/**
	 * Persist optimization settings.
	 *
	 * @param OptimizationSettings $settings Settings to persist.
	 * @return void
	 */
	public function save( OptimizationSettings $settings ): void;

	/**
	 * Ensure default settings exist on activation.
	 *
	 * @return void
	 */
	public function ensure_defaults(): void;
}
