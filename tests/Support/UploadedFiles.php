<?php
/**
 * Stands in for PHP's upload handling, which never runs under the command line.
 *
 * WordPress's REST server receives `$_FILES` from PHP. The optimize endpoint checks
 * `is_uploaded_file()`, which only accepts files PHP itself moved from a real HTTP
 * upload. Tests register the files they "upload" here, and the namespaced function
 * below answers for the controller's namespace.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Support {

	/**
	 * Files a test has sent as an HTTP upload.
	 */
	final class UploadedFiles {

		/**
		 * Paths received as uploads.
		 *
		 * @var array<string, true>
		 */
		private static array $paths = array();

		/**
		 * Marks a file as received from an HTTP upload.
		 *
		 * @param string $path File path.
		 * @return void
		 */
		public static function receive( string $path ): void {
			self::$paths[ $path ] = true;
		}

		/**
		 * Whether PHP would treat the path as an uploaded file.
		 *
		 * @param string $path File path.
		 * @return bool
		 */
		public static function contains( string $path ): bool {
			return isset( self::$paths[ $path ] );
		}

		/**
		 * Forgets every upload.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$paths = array();
		}
	}
}

namespace Kilobyte\ImageOptimizer\Presentation\Rest {

	use Kilobyte\ImageOptimizer\Tests\Support\UploadedFiles;

	/**
	 * Answers PHP's upload check for files a test sent.
	 *
	 * @param string $filename File path.
	 * @return bool
	 */
	function is_uploaded_file( string $filename ): bool {
		return UploadedFiles::contains( $filename );
	}
}
