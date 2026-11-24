<?php
/**
 * Fails when line coverage in a Clover report is below a minimum.
 *
 * Usage: php tests/bin/check-coverage.php <clover.xml> <minimum percent>
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

[ , $report, $minimum ] = array_pad( $argv, 3, null );

if ( ! is_string( $report ) || ! is_readable( $report ) || ! is_numeric( $minimum ) ) {
	fwrite( STDERR, "Usage: php tests/bin/check-coverage.php <clover.xml> <minimum percent>\n" );
	exit( 2 );
}

$metrics = simplexml_load_file( $report )->project->metrics;
$total   = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $total > 0 ? 100 * $covered / $total : 0.0;

printf( "Line coverage: %.2f%% (%d of %d lines). Minimum: %s%%.\n", $percent, $covered, $total, $minimum );
exit( $percent + 1e-9 >= (float) $minimum ? 0 : 1 );
