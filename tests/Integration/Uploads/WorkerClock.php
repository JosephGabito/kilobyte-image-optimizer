<?php
/**
 * How far a test has moved the background upload worker's clock.
 *
 * The worker gives each run a 4-second budget measured with `microtime()` and decides
 * which jobs are due with `time()`. Tests cannot wait that long, so they move the
 * worker's clock instead. See worker-clock-functions.php for the functions that read it.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

/**
 * Offsets added to PHP's clock as the worker sees it. Both are zero unless a test moves them.
 */
final class WorkerClock {

	/**
	 * Seconds added to `time()` as the worker sees it.
	 *
	 * @var int
	 */
	public static int $wall_offset = 0;

	/**
	 * Seconds added to `microtime( true )` as the worker sees it.
	 *
	 * @var float
	 */
	public static float $budget_offset = 0.0;

	/**
	 * Puts the worker back on the real clock.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$wall_offset   = 0;
		self::$budget_offset = 0.0;
	}
}
