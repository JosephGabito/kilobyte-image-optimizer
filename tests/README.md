# Kilobyte tests

These are integration tests. Each one runs inside real WordPress, against a real MySQL or MariaDB database, with real image files on disk. WordPress rolls back the database after each test, and `TestCase::tear_down()` deletes every file the test added to the uploads folder.

## Running the tests

The WordPress test library deletes and recreates every table in its database on each run. Give it a database that holds nothing else.

```bash
composer install
wp core download --version=7.1.5 --path=.cache/wordpress --skip-content   # once
WP_TESTS_DB_HOST=127.0.0.1:3306 composer test            # all tests
WP_TESTS_DB_HOST=127.0.0.1:3306 composer test:coverage   # with a coverage summary (needs pcov or Xdebug)
```

Settings come from environment variables: `WP_TESTS_DB_HOST`, `WP_TESTS_DB_NAME` (default `kilobyte_tests`), `WP_TESTS_DB_USER` and `WP_TESTS_DB_PASSWORD` (both default `root`), and `WP_CORE_DIR` (default `.cache/wordpress`). Each database name gets its own uploads folder, so two runs with different database names can run at the same time.

## How the tests are written

- **Test through what WordPress or a person actually calls.** That means REST routes (`$this->rest()`), WordPress hooks and filters (`apply_filters( 'the_content', ... )`, `do_action( 'kilobyte_io_optimize_pending_uploads' )`, `wp_delete_attachment()`), the HTML the plugin prints, WP-CLI commands, and the plugin's public PHP entry points such as `kilobyte_io_activate()`. Don't call private methods or reach into internal classes to set up or check results when a public path exists.
- **Check results where a user would see them.** Good places are the REST response, the files on disk, the `<picture>` markup visitors get (`$this->delivered_copies()`), the Media Library column HTML and the stats endpoint. Read plugin post meta or options only to set up a state that has no public path, such as a record written by an older plugin version or a job whose delay has passed.
- **Don't mock the plugin's own classes.** Fake only what PHP or the host controls: `is_uploaded_file()` (see `Support/UploadedFiles.php`), another PHP request holding a lock (`hold_lock_in_other_request()`), a dropped database connection (`reconnect_database()`), the clock (`make_upload_job_due()`), and WordPress filters that make a core call fail, which is how a full disk or a broken cron is simulated.
- **One behaviour per test, named as a sentence.** For example `test_restoring_stops_offering_the_copy`. Expected values are literals or facts about the fixture, never recomputed the way the plugin computes them.
- **Keep tests fast.** Use the small fixtures in `Support/Images.php` (640x480 by default, drawn once per run). Never sleep.
- **A test must be able to fail.** After writing a test, break the behaviour it covers in the plugin code for a moment and check the test goes red.
