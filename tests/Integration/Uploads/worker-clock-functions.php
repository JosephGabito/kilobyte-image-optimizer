<?php
/**
 * Stands in for PHP's clock inside the background upload worker's namespace.
 *
 * PHP looks up an unqualified function call in the caller's namespace first, so these
 * functions answer for AutomaticUploadOptimization and nothing else. They must be loaded
 * before the worker first runs, which PHPUnit guarantees by loading test files first.
 * They pass through to PHP's clock until a test moves WorkerClock.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Hooks;

use Kilobyte\ImageOptimizer\Tests\Integration\Uploads\WorkerClock;

/**
 * The current Unix time as the worker sees it.
 *
 * @return int
 */
function time(): int {
	return \time() + WorkerClock::$wall_offset;
}

/**
 * The current time in microseconds as the worker sees it.
 *
 * @param bool $as_float Return a float.
 * @return string|float
 */
function microtime( bool $as_float = false ) {
	return $as_float ? \microtime( true ) + WorkerClock::$budget_offset : \microtime( false );
}
