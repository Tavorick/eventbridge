<?php

require __DIR__ . '/verify.php';

function eventbridge_release_test_remove_directory( $directory, $allowed_parent ) {
	$directory      = rtrim( str_replace( '\\', '/', $directory ), '/' );
	$allowed_parent = rtrim( str_replace( '\\', '/', $allowed_parent ), '/' ) . '/';
	if ( $directory . '/' === $allowed_parent
		|| 0 !== strpos( $directory . '/', $allowed_parent )
		|| 0 !== strpos( basename( $directory ), 'eventbridge-release-selftest-' )
		|| ! is_dir( $directory )
	) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $directory );
}

function eventbridge_release_test_package( $base, $plugin_contents, $extra_path = '', $extra_contents = '' ) {
	$root = $base . '/eventbridge';
	if ( ! mkdir( $root, 0777, true ) ) {
		throw new RuntimeException( 'Unable to create test package.' );
	}
	file_put_contents( $root . '/eventbridge.php', $plugin_contents );
	if ( '' !== $extra_path ) {
		$target = $root . '/' . $extra_path;
		if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) ) {
			throw new RuntimeException( 'Unable to create test package path.' );
		}
		file_put_contents( $target, $extra_contents );
	}

	return $root;
}

function eventbridge_release_expect_failure( $callback, $label ) {
	try {
		$callback();
	} catch ( RuntimeException $exception ) {
		echo 'Expected verifier failure: ' . $label . "\n";
		return;
	}
	throw new RuntimeException( 'Verifier accepted invalid package: ' . $label );
}

$repository = dirname( __DIR__, 2 );
$parent     = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' );
$temporary  = $parent . '/eventbridge-release-selftest-' . bin2hex( random_bytes( 8 ) );

try {
	if ( ! mkdir( $temporary, 0777, true ) ) {
		throw new RuntimeException( 'Unable to create release self-test directory.' );
	}
	$plugin_contents = file_get_contents( $repository . '/eventbridge.php' );
	if ( ! is_string( $plugin_contents ) ) {
		throw new RuntimeException( 'Unable to read eventbridge.php.' );
	}
	if ( ! preg_match( '/^[ \t\/*#@]*Version:[ \t]*(.+)$/mi', $plugin_contents, $version_match ) ) {
		throw new RuntimeException( 'Unable to read the current plugin version.' );
	}
	$current_version = trim( $version_match[1] );
	$verifier = new EventBridge_Release_Verifier( $repository );

	$valid = eventbridge_release_test_package( $temporary . '/valid', $plugin_contents );
	$verifier->verify_directory( $valid, $current_version );

	$forbidden = eventbridge_release_test_package( $temporary . '/forbidden', $plugin_contents, 'tests/leak.php', '<?php' );
	eventbridge_release_expect_failure(
		function () use ( $verifier, $forbidden, $current_version ) {
			$verifier->verify_directory( $forbidden, $current_version );
		},
		'forbidden tests directory'
	);

	$secret = eventbridge_release_test_package(
		$temporary . '/secret',
		$plugin_contents,
		'includes/leak.php',
		"<?php\n\$token = 'ghp_" . str_repeat( 'a', 40 ) . "';\n"
	);
	eventbridge_release_expect_failure(
		function () use ( $verifier, $secret, $current_version ) {
			$verifier->verify_directory( $secret, $current_version );
		},
		'hard-coded token'
	);

	$local_path = eventbridge_release_test_package(
		$temporary . '/local-path',
		$plugin_contents,
		'includes/leak.php',
		"<?php\n\$path = 'C:\\wamp64\\private';\n"
	);
	eventbridge_release_expect_failure(
		function () use ( $verifier, $local_path, $current_version ) {
			$verifier->verify_directory( $local_path, $current_version );
		},
		'local absolute path'
	);

	eventbridge_release_expect_failure(
		function () use ( $verifier, $valid ) {
			$verifier->verify_directory( $valid, '0.0.0-self-test-mismatch' );
		},
		'version mismatch'
	);

	if ( ! class_exists( 'ZipArchive' ) ) {
		throw new RuntimeException( 'The PHP zip extension is required.' );
	}
	$bad_zip = $temporary . '/wrong-root.zip';
	$zip     = new ZipArchive();
	if ( true !== $zip->open( $bad_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Unable to create invalid test ZIP.' );
	}
	$zip->addFromString( 'wrong/eventbridge.php', $plugin_contents );
	$zip->close();
	eventbridge_release_expect_failure(
		function () use ( $verifier, $bad_zip, $current_version ) {
			$verifier->verify_zip( $bad_zip, $current_version );
		},
		'wrong ZIP root'
	);

	echo "Release verifier self-test passed.\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'Release verifier self-test failed: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
} finally {
	eventbridge_release_test_remove_directory( $temporary, $parent );
}
