# Fluent Booking integration test

The optional integration suite loads the real Fluent Booking plugin and creates synthetic data only in the WordPress PHPUnit test database. It does not bootstrap the normal local site database. All HTTP requests are blocked; the expected Meta request receives a local fake response, and WordPress mail is preempted.

Prerequisites:

- an isolated WordPress PHPUnit test installation selected by `WP_TESTS_DIR`;
- Composer development dependencies;
- a local Fluent Booking plugin directory selected by `FLUENT_BOOKING_PLUGIN_DIR`.

PowerShell example:

```powershell
$env:WP_TESTS_DIR = 'C:\path\to\wordpress-tests-lib'
$env:FLUENT_BOOKING_PLUGIN_DIR = 'C:\wamp64\www\hypnotherapielars\wp-content\plugins\fluent-booking'
C:\wamp64\bin\php\php7.4.33\php.exe vendor\bin\phpunit --configuration phpunit-fluent-integration.xml.dist
```

Do not point `WP_TESTS_DIR` at a normal WordPress installation. Its `wp-tests-config.php` must name a disposable test database because the WordPress test framework drops and recreates tables. As a fail-safe, this suite refuses to start unless the configured database name contains `test`.
