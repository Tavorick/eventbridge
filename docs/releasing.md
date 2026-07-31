# EventBridge releases

EventBridge uses GitHub Releases and the native WordPress updater. GitHub's generated source archives are never valid update packages; only the workflow-built `eventbridge-<version>.zip` asset may be used.

## Release preparation

1. Update both the `Version` header and `EVENTBRIDGE_VERSION` in `eventbridge.php`. Keep `EVENTBRIDGE_DB_VERSION` independent and change it only for a schema migration.
2. Use `X.Y.Z-rc.N` for a prerelease and `X.Y.Z` for a stable release. Publish stable versions in strictly increasing version order, because production reads GitHub's latest stable release.
3. Run the release workflow with `workflow_dispatch` and inspect its ZIP and checksum artifacts.
4. Create the matching protected tag, for example `v1.3.1-rc.1` or `v1.3.1`. A tag push publishes the release only after all validation jobs pass.
5. Never reuse or move a version tag. If publication fails after a draft release was created, inspect and deliberately remove that draft before rerunning; the workflow never overwrites it.

Enable GitHub immutable releases in repository settings. Protect `master` and `v*`, require the workflow checks, require review for workflow changes, enable 2FA/passkeys, secret scanning and push protection, and leave the default Actions token read-only except for the isolated publish job.

The workflow installs development-only PHP support, including the pinned Composer PHPUnit runner, from `composer.lock`; `vendor/` is never packaged. Browser checks use the runner's Chromium installation directly, so there is no npm dependency tree or mutable browser download in the repository. The release checksum asset remains available for publication and manual audit; runtime installation verifies GitHub's ZIP-asset digest instead. To reproduce a package locally from a committed tree:

```text
php tools/release/self-test.php
php tools/release/build.php --output=dist --ref=HEAD --tag=v1.3.0
php tools/release/verify.php dist/eventbridge-1.3.0.zip 1.3.0
```

The builder is intentionally commit-based. Commit the intended release tree before comparing package hashes; uncommitted working-copy files are never copied into the ZIP.

## Staging prereleases

Production ignores prereleases. A staging site can opt in from `wp-config.php` only when its WordPress environment type is also staging:

```php
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
define( 'EVENTBRIDGE_ALLOW_PRERELEASES', true );
```

An existing installation that predates the updater-enabled 1.3.0 build needs that official ZIP installed once by hand. Subsequent updates use the standard WordPress Plugins and Updates screens.

## End-to-end acceptance

Before updating, record the active plugin basename, `eventbridge_meta_settings`, `eventbridge_events`, the EventBridge log table and representative production/test WooCommerce ledgers. Update from updater-enabled 1.3.0 to a unique RC through WordPress, then confirm the plugin remains active at `eventbridge/eventbridge.php`, no nested directory exists, and all recorded database state remains intact. Confirm the same RC is invisible on a production-configured site before publishing and testing the new stable tag.

Also exercise a corrupt or interrupted package through a controlled HTTP mock and confirm the installed 1.3.0 files remain active and unchanged. After RC acceptance, bump both plugin versions to `1.3.1`, create a new `v1.3.1` tag, and repeat the production update. Keep the RC release for audit and never reuse either version.
