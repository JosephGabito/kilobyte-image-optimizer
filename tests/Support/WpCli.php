<?php
/**
 * A small stand-in for WP-CLI, which PHPUnit does not load.
 *
 * It records what a command prints, the way a terminal would show it. `WP_CLI::error()`
 * stops the command, as the real one does, by throwing `WpCliStopped`.
 *
 * Load it with require_once. It also defines the `WP_CLI` constant, which stays defined
 * for the rest of the PHP run.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

// phpcs:disable Universal.Namespaces, WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI's own class and constant live in the global namespace.

namespace Kilobyte\ImageOptimizer\Tests\Support {

	/**
	 * Thrown where real WP-CLI would end the command with an error.
	 */
	final class WpCliStopped extends \RuntimeException {
	}
}

namespace {

	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
	/**
	 * Records commands and printed lines instead of writing to a terminal.
	 */
	final class WP_CLI {

		/**
		 * Commands registered with add_command, by name.
		 *
		 * @var array<string, object>
		 */
		public static array $commands = array();

		/**
		 * Printed lines, each prefixed with its kind: "log: ", "warning: ", "success: " or "error: ".
		 *
		 * @var string[]
		 */
		public static array $lines = array();

		/**
		 * Forgets every printed line.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$lines = array();
		}

		/**
		 * Registers a command.
		 *
		 * @param string $name    Command name.
		 * @param object $command Command object.
		 * @return void
		 */
		public static function add_command( string $name, $command ): void {
			self::$commands[ $name ] = $command;
		}

		/**
		 * Prints a plain line.
		 *
		 * @param string $message Line.
		 * @return void
		 */
		public static function log( string $message ): void {
			self::$lines[] = 'log: ' . $message;
		}

		/**
		 * Prints a warning.
		 *
		 * @param string $message Line.
		 * @return void
		 */
		public static function warning( string $message ): void {
			self::$lines[] = 'warning: ' . $message;
		}

		/**
		 * Prints a success line.
		 *
		 * @param string $message Line.
		 * @return void
		 */
		public static function success( string $message ): void {
			self::$lines[] = 'success: ' . $message;
		}

		/**
		 * Prints an error and stops the command.
		 *
		 * @param string $message Line.
		 * @return void
		 * @throws \Kilobyte\ImageOptimizer\Tests\Support\WpCliStopped Always.
		 */
		public static function error( string $message ): void {
			self::$lines[] = 'error: ' . $message;
			throw new \Kilobyte\ImageOptimizer\Tests\Support\WpCliStopped( $message );
		}
	}
}
