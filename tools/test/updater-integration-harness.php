<?php

/**
 * Standalone WordPress 5.8 / WP-CLI updater integration harness.
 *
 * This file deliberately creates a short-lived MU plugin in a disposable local
 * site. The mock serves only the expected GitHub API/package URLs and blocks all
 * other HTTP so the smoke test can never fall through to a real asset request.
 */

if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	return;
}

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness only runs from PHP CLI.\n" );
	exit( 1 );
}

$command = isset( $argv[1] ) ? strtolower( trim( (string) $argv[1] ) ) : '';
if ( ! in_array( $command, array( 'prepare', 'core', 'verify', 'cleanup' ), true ) ) {
	fwrite( STDERR, "Usage: php tools/test/updater-integration-harness.php prepare|core|verify|cleanup\n" );
	exit( 1 );
}

require dirname( __DIR__, 5 ) . '/wp-load.php';

function eventbridge_updater_harness_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function eventbridge_updater_harness_assert_safe_site() {
	if ( '1' !== getenv( 'EVENTBRIDGE_UPDATER_HARNESS' ) ) {
		eventbridge_updater_harness_fail( 'Refusing to run without EVENTBRIDGE_UPDATER_HARNESS=1.' );
	}
	if ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() ) {
		eventbridge_updater_harness_fail( 'Refusing to run: wp_get_environment_type() must be local.' );
	}

	$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( ! in_array( $home_host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		eventbridge_updater_harness_fail( 'Refusing to run: WordPress must use a loopback host.' );
	}

	$root = wp_normalize_path( (string) realpath( ABSPATH ) );
	$temp = trailingslashit( wp_normalize_path( (string) realpath( sys_get_temp_dir() ) ) );
	if ( '' === $root || '' === $temp || 0 !== strpos( trailingslashit( $root ), $temp ) ) {
		eventbridge_updater_harness_fail( 'Refusing to run outside a disposable temporary WordPress site.' );
	}
}

function eventbridge_updater_harness_paths() {
	$key = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
	return array(
		'state'       => WP_CONTENT_DIR . '/eventbridge-updater-harness-state.json',
		'mu_plugin'   => WPMU_PLUGIN_DIR . '/eventbridge-updater-harness.php',
		'fixture_dir' => trailingslashit( get_temp_dir() ) . 'eventbridge-updater-harness-' . $key,
	);
}

function eventbridge_updater_harness_manifest( $root ) {
	$root = wp_normalize_path( untrailingslashit( $root ) );
	if ( ! is_dir( $root ) ) {
		return array();
	}

	$manifest = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		$path     = wp_normalize_path( $item->getPathname() );
		$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
		$manifest[ $relative ] = $item->isDir() ? 'dir' : hash_file( 'sha256', $item->getPathname() );
	}
	ksort( $manifest, SORT_STRING );
	return $manifest;
}

function eventbridge_updater_harness_php_manifest( $manifest ) {
	return array_filter(
		$manifest,
		function ( $digest, $path ) {
			return 'dir' !== $digest && '.php' === strtolower( substr( $path, -4 ) );
		},
		ARRAY_FILTER_USE_BOTH
	);
}

function eventbridge_updater_harness_plugin_file( $version ) {
	return "<?php\n/**\n * Plugin Name: EventBridge updater harness package\n * Version: " . $version . "\n * Requires at least: 5.8\n * Requires PHP: 7.4\n * Update URI: https://github.com/Tavorick/eventbridge\n */\n";
}

function eventbridge_updater_harness_create_zip( $path, $version, $marker ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		eventbridge_updater_harness_fail( 'Unable to create an updater ZIP fixture.' );
	}
	$ok = $zip->addFromString( 'eventbridge/eventbridge.php', eventbridge_updater_harness_plugin_file( $version ) )
		&& $zip->addFromString( 'eventbridge/assets/js/toctou.js', 'window.eventbridgeUpdaterHarness = "' . $marker . '";' )
		&& $zip->close();
	if ( ! $ok ) {
		eventbridge_updater_harness_fail( 'Unable to finish an updater ZIP fixture.' );
	}
}

function eventbridge_updater_harness_equalize_zip_sizes( $a, $b ) {
	clearstatcache( true, $a );
	clearstatcache( true, $b );
	$a_size = filesize( $a );
	$b_size = filesize( $b );
	if ( $a_size === $b_size ) {
		return;
	}

	$smaller = $a_size < $b_size ? $a : $b;
	$padding = abs( $a_size - $b_size );
	$zip     = new ZipArchive();
	if ( true !== $zip->open( $smaller )
		|| ! $zip->setArchiveComment( str_repeat( 'p', $padding ) )
		|| ! $zip->close()
	) {
		eventbridge_updater_harness_fail( 'Unable to equalize the updater ZIP fixture sizes.' );
	}

	clearstatcache( true, $a );
	clearstatcache( true, $b );
	if ( filesize( $a ) !== filesize( $b ) ) {
		eventbridge_updater_harness_fail( 'The updater ZIP fixtures are not the same size.' );
	}
}

function eventbridge_updater_harness_seed_update( $state ) {
	$item = (object) array(
		'id'                       => 'https://github.com/Tavorick/eventbridge',
		'slug'                     => 'eventbridge',
		'plugin'                   => 'eventbridge/eventbridge.php',
		'version'                  => $state['version'],
		'new_version'              => $state['version'],
		'url'                      => 'https://github.com/Tavorick/eventbridge/releases/tag/v' . $state['version'],
		'package'                  => $state['package'],
		'requires'                 => '5.8',
		'requires_php'             => '7.4',
		'tested'                   => '7.0',
		'eventbridge_sha256'       => $state['sha256'],
		'eventbridge_package_size' => $state['size'],
	);
	set_site_transient(
		'update_plugins',
		(object) array(
			'last_checked' => time(),
			'checked'      => array( 'eventbridge/eventbridge.php' => EVENTBRIDGE_VERSION ),
			'response'     => array( 'eventbridge/eventbridge.php' => $item ),
			'no_update'    => array(),
			'translations' => array(),
		)
	);
}

function eventbridge_updater_harness_read_state( $path ) {
	$contents = is_file( $path ) ? file_get_contents( $path ) : false;
	$state    = is_string( $contents ) ? json_decode( $contents, true ) : null;
	if ( ! is_array( $state ) ) {
		eventbridge_updater_harness_fail( 'The updater harness state is missing or invalid.' );
	}
	return $state;
}

function eventbridge_updater_harness_verify_manifest( $state ) {
	$plugin_dir = WP_PLUGIN_DIR . '/eventbridge';
	$current    = eventbridge_updater_harness_manifest( $plugin_dir );
	if ( ! isset( $state['manifest'], $state['php_manifest'] )
		|| $state['manifest'] !== $current
		|| $state['php_manifest'] !== eventbridge_updater_harness_php_manifest( $current )
	) {
		eventbridge_updater_harness_fail( 'The EventBridge plugin tree changed during the rejected update.' );
	}
	if ( is_file( $plugin_dir . '/assets/js/toctou.js' ) ) {
		eventbridge_updater_harness_fail( 'The rejected package placed a new executable asset.' );
	}
}

function eventbridge_updater_harness_write_mu_plugin( $path ) {
	$contents = <<<'PHP'
<?php
/**
 * Plugin Name: EventBridge updater integration harness HTTP mock
 */

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		unset( $preempt );
		$state_path = WP_CONTENT_DIR . '/eventbridge-updater-harness-state.json';
		$contents   = is_file( $state_path ) ? file_get_contents( $state_path ) : false;
		$state      = is_string( $contents ) ? json_decode( $contents, true ) : null;
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'eventbridge_updater_harness_missing_state' );
		}

		$api_url = 'https://api.github.com/repos/Tavorick/eventbridge/releases/latest';
		if ( $api_url === $url ) {
			$payload = array(
				'tag_name'     => 'v' . $state['version'],
				'draft'        => false,
				'prerelease'   => false,
				'published_at' => '2026-08-01T00:00:00Z',
				'assets'       => array(
					array(
						'name'                 => 'eventbridge-' . $state['version'] . '.zip',
						'state'                => 'uploaded',
						'size'                 => $state['size'],
						'browser_download_url' => $state['package'],
						'digest'               => 'sha256:' . $state['sha256'],
					),
				),
			);
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		}

		if ( $state['package'] === $url ) {
			$bytes = is_file( $state['served_zip'] ) ? file_get_contents( $state['served_zip'] ) : false;
			if ( ! is_string( $bytes ) ) {
				return new WP_Error( 'eventbridge_updater_harness_missing_package' );
			}
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				if ( strlen( $bytes ) !== file_put_contents( $args['filename'], $bytes ) ) {
					return new WP_Error( 'eventbridge_updater_harness_stream_failed' );
				}
				$body = '';
			} else {
				$body = $bytes;
			}
			return array(
				'headers'  => array( 'content-length' => (string) strlen( $bytes ) ),
				'body'     => $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => ! empty( $args['filename'] ) ? $args['filename'] : null,
			);
		}

		return new WP_Error( 'eventbridge_updater_harness_unexpected_http', 'Unexpected HTTP is blocked by the updater harness.' );
	},
	PHP_INT_MAX,
	3
);
PHP;

	wp_mkdir_p( dirname( $path ) );
	if ( strlen( $contents ) !== file_put_contents( $path, $contents ) ) {
		eventbridge_updater_harness_fail( 'Unable to install the temporary updater HTTP mock.' );
	}
}

function eventbridge_updater_harness_cleanup( $paths ) {
	if ( is_file( $paths['mu_plugin'] ) ) {
		$contents = file_get_contents( $paths['mu_plugin'] );
		if ( is_string( $contents ) && false !== strpos( $contents, 'EventBridge updater integration harness HTTP mock' ) ) {
			unlink( $paths['mu_plugin'] );
		}
	}

	$state = is_file( $paths['state'] ) ? json_decode( (string) file_get_contents( $paths['state'] ), true ) : array();
	if ( is_array( $state ) ) {
		foreach ( array( 'metadata_zip', 'served_zip' ) as $key ) {
			if ( isset( $state[ $key ] ) && is_string( $state[ $key ] ) && is_file( $state[ $key ] ) ) {
				unlink( $state[ $key ] );
			}
		}
	}
	if ( is_file( $paths['state'] ) ) {
		unlink( $paths['state'] );
	}
	if ( is_dir( $paths['fixture_dir'] ) ) {
		@rmdir( $paths['fixture_dir'] );
	}
	delete_site_transient( 'update_plugins' );
}

eventbridge_updater_harness_assert_safe_site();
$paths = eventbridge_updater_harness_paths();

if ( 'cleanup' === $command ) {
	eventbridge_updater_harness_cleanup( $paths );
	echo wp_json_encode( array( 'cleaned' => true ) ) . PHP_EOL;
	exit;
}

if ( 'prepare' === $command ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		eventbridge_updater_harness_fail( 'ZipArchive is required by the updater harness.' );
	}
	if ( is_file( $paths['state'] ) || is_file( $paths['mu_plugin'] ) ) {
		eventbridge_updater_harness_fail( 'Refusing to overwrite an existing updater harness state.' );
	}
	if ( ! wp_mkdir_p( $paths['fixture_dir'] ) ) {
		eventbridge_updater_harness_fail( 'Unable to create the updater fixture directory.' );
	}

	$version      = '1.3.1';
	$metadata_zip = trailingslashit( $paths['fixture_dir'] ) . 'eventbridge-a.zip';
	$served_zip   = trailingslashit( $paths['fixture_dir'] ) . 'eventbridge-b.zip';
	eventbridge_updater_harness_create_zip( $metadata_zip, $version, 'A' );
	eventbridge_updater_harness_create_zip( $served_zip, $version, 'B' );
	eventbridge_updater_harness_equalize_zip_sizes( $metadata_zip, $served_zip );
	if ( hash_file( 'sha256', $metadata_zip ) === hash_file( 'sha256', $served_zip ) ) {
		eventbridge_updater_harness_fail( 'The updater fixtures must have different digests.' );
	}

	$manifest = eventbridge_updater_harness_manifest( WP_PLUGIN_DIR . '/eventbridge' );
	if ( empty( $manifest ) ) {
		eventbridge_updater_harness_fail( 'The installed EventBridge plugin tree is missing.' );
	}
	$state = array(
		'version'      => $version,
		'package'      => 'https://github.com/Tavorick/eventbridge/releases/download/v' . $version . '/eventbridge-' . $version . '.zip',
		'sha256'       => hash_file( 'sha256', $metadata_zip ),
		'size'         => filesize( $metadata_zip ),
		'metadata_zip' => $metadata_zip,
		'served_zip'   => $served_zip,
		'manifest'     => $manifest,
		'php_manifest' => eventbridge_updater_harness_php_manifest( $manifest ),
	);
	$json = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) || strlen( $json ) !== file_put_contents( $paths['state'], $json ) ) {
		eventbridge_updater_harness_fail( 'Unable to write updater harness state.' );
	}
	eventbridge_updater_harness_write_mu_plugin( $paths['mu_plugin'] );
	eventbridge_updater_harness_seed_update( $state );
	echo wp_json_encode( array( 'prepared' => true, 'size' => $state['size'] ) ) . PHP_EOL;
	exit;
}

$state = eventbridge_updater_harness_read_state( $paths['state'] );
if ( ! is_file( $paths['mu_plugin'] ) ) {
	eventbridge_updater_harness_fail( 'The temporary updater HTTP mock is missing.' );
}

if ( 'verify' === $command ) {
	eventbridge_updater_harness_verify_manifest( $state );
	echo wp_json_encode( array( 'verified' => true ) ) . PHP_EOL;
	exit;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
add_filter( 'filesystem_method', function () { return 'direct'; }, 999 );

eventbridge_updater_harness_seed_update( $state );
$direct_skin = new Automatic_Upgrader_Skin();
$direct      = new Plugin_Upgrader( $direct_skin );
$buffer_level = ob_get_level();
ob_start();
$direct_result = $direct->upgrade( 'eventbridge/eventbridge.php', array( 'clear_update_cache' => false ) );
while ( ob_get_level() > $buffer_level ) {
	ob_end_clean();
}
$direct_messages = implode( "\n", $direct_skin->get_upgrade_messages() );
if ( true === $direct_result || false === strpos( $direct_messages, 'The EventBridge update could not be verified.' ) ) {
	eventbridge_updater_harness_fail( 'The WordPress 5.8 direct updater did not reject the TOCTOU fixture.' );
}
eventbridge_updater_harness_verify_manifest( $state );

eventbridge_updater_harness_seed_update( $state );
$bulk_skin = new class(
	array(
		'url'     => '',
		'nonce'   => '',
		'plugins' => array( 'eventbridge/eventbridge.php' ),
	)
) extends Bulk_Plugin_Upgrader_Skin {
	public $eventbridge_errors = array();

	public function flush_output() {
		// Keep the standalone harness output buffer intact.
	}

	public function error( $errors ) {
		$this->eventbridge_errors[] = $errors;
		parent::error( $errors );
	}
};
$bulk      = new Plugin_Upgrader( $bulk_skin );
$buffer_level = ob_get_level();
ob_start();
$bulk_results = $bulk->bulk_upgrade( array( 'eventbridge/eventbridge.php' ), array( 'clear_update_cache' => false ) );
while ( ob_get_level() > $buffer_level ) {
	ob_end_clean();
}
$bulk_has_result = is_array( $bulk_results ) && array_key_exists( 'eventbridge/eventbridge.php', $bulk_results );
$bulk_result = $bulk_has_result
	? $bulk_results['eventbridge/eventbridge.php']
	: false;
$bulk_verified_error = false;
foreach ( $bulk_skin->eventbridge_errors as $bulk_error ) {
	if ( is_wp_error( $bulk_error ) && 'eventbridge_update_verification_failed' === $bulk_error->get_error_code() ) {
		$bulk_verified_error = true;
	}
}
if ( ! $bulk_has_result || true === $bulk_result || ! $bulk_verified_error ) {
	eventbridge_updater_harness_fail( 'The WordPress 5.8 bulk updater did not reject the TOCTOU fixture.' );
}
eventbridge_updater_harness_verify_manifest( $state );

echo wp_json_encode( array( 'direct' => 'rejected', 'bulk' => 'rejected', 'verified' => true ) ) . PHP_EOL;
