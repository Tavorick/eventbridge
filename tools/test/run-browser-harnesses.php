<?php

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This runner only supports PHP CLI.\n" );
	exit( 1 );
}

$chrome = getenv( 'CHROME_BIN' );
if ( ! $chrome ) {
	foreach ( array( 'google-chrome', 'chromium', 'chromium-browser' ) as $candidate ) {
		$command = DIRECTORY_SEPARATOR === '\\' ? array( 'where', $candidate ) : array( 'which', $candidate );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( is_resource( $process ) ) {
			$path = trim( stream_get_contents( $pipes[1] ) );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			if ( 0 === proc_close( $process ) && '' !== $path ) {
				$chrome = strtok( $path, "\r\n" );
				break;
			}
		}
	}
}
if ( ! $chrome ) {
	fwrite( STDERR, "Set CHROME_BIN to a Chromium-compatible browser.\n" );
	exit( 1 );
}

$tests = array(
	'tests/js-syntax-harness.html'                       => 'data-eventbridge-scripts-loaded="1"',
	'tests/js-functional-harness.html'                   => 'data-eventbridge-result="passed"',
	'tests/js-admin-family-harness.html'                 => 'data-status="passed"',
	'tests/js-admin-woocommerce-trigger-harness.html'    => 'data-status="passed"',
	'tests/js-woocommerce-interactions-harness.html'     => 'data-eventbridge-result="passed"',
);
$repository = dirname( __DIR__, 2 );

foreach ( $tests as $relative => $expected ) {
	$url     = 'file:///' . str_replace( array( '%2F', '%3A' ), array( '/', ':' ), rawurlencode( str_replace( '\\', '/', $repository . '/' . $relative ) ) );
	$command = array(
		$chrome,
		'--headless=new',
		'--no-sandbox',
		'--disable-gpu',
		'--disable-dev-shm-usage',
		'--allow-file-access-from-files',
		'--enable-logging=stderr',
		'--log-level=0',
		'--virtual-time-budget=2500',
		'--dump-dom',
		$url,
	);
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $repository, null, array( 'bypass_shell' => true ) );
	if ( ! is_resource( $process ) ) {
		fwrite( STDERR, "Unable to start browser for {$relative}.\n" );
		exit( 1 );
	}
	$html   = stream_get_contents( $pipes[1] );
	$errors = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	$console_failed = (bool) preg_match( '/(?:CONSOLE[^\r\n]*(?:ERROR|SEVERE)|Uncaught (?:Error|TypeError|ReferenceError|SyntaxError))/i', $errors );
	if ( 0 !== $status || $console_failed || false === strpos( $html, $expected ) ) {
		fwrite( STDERR, "Browser harness failed: {$relative}\n{$errors}\n" );
		exit( 1 );
	}
	echo "Browser harness passed: {$relative}\n";
}
