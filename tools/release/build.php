<?php

require __DIR__ . '/verify.php';

function eventbridge_release_run( $command, $working_directory ) {
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process = proc_open( $command, $descriptors, $pipes, $working_directory, null, array( 'bypass_shell' => true ) );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start command: ' . implode( ' ', $command ) );
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	if ( 0 !== $status ) {
		throw new RuntimeException( trim( $stderr ) ?: 'Command failed.' );
	}

	return $stdout;
}

function eventbridge_release_remove_directory( $directory, $allowed_parent ) {
	if ( ! is_dir( $directory ) ) {
		return;
	}
	$normalized_directory = rtrim( str_replace( '\\', '/', $directory ), '/' );
	$normalized_parent    = rtrim( str_replace( '\\', '/', $allowed_parent ), '/' );
	if ( dirname( $normalized_directory ) !== $normalized_parent || 'package-stage' !== basename( $normalized_directory ) ) {
		throw new RuntimeException( 'Refusing to remove an unsafe staging directory.' );
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

try {
	$options    = getopt( '', array( 'output::', 'ref::', 'tag::' ) );
	$repository = dirname( __DIR__, 2 );
	$output     = isset( $options['output'] ) ? $options['output'] : $repository . '/dist';
	$ref        = isset( $options['ref'] ) ? $options['ref'] : 'HEAD';
	$tag        = isset( $options['tag'] ) ? $options['tag'] : '';
	$is_absolute = (bool) preg_match( '#^[A-Za-z]:[\\\\/]#', $output ) || '/' === substr( $output, 0, 1 );
	$output      = $is_absolute ? $output : $repository . '/' . $output;
	$output     = rtrim( $output, '/\\' );

	$safe_git = array( 'git', '-c', 'safe.directory=' . str_replace( '\\', '/', $repository ) );
	$tree     = eventbridge_release_run( array_merge( $safe_git, array( 'ls-tree', '-r', '-z', $ref ) ), $repository );
	$tracked  = array();
	$modes    = array();
	foreach ( explode( "\0", rtrim( $tree, "\0" ) ) as $entry ) {
		if ( ! preg_match( '/^([0-9]{6}) ([^ ]+) [0-9a-f]+\t(.+)$/sD', $entry, $matches ) ) {
			throw new RuntimeException( 'Unable to parse the Git tree.' );
		}
		$tracked[]             = $matches[3];
		$modes[ $matches[3] ] = $matches[1] . ' ' . $matches[2];
	}
	$verifier = new EventBridge_Release_Verifier( $repository );
	$files    = $verifier->selected_files( $tracked );
	foreach ( $files as $file ) {
		if ( ! isset( $modes[ $file ] ) || '100644 blob' !== $modes[ $file ] ) {
			throw new RuntimeException( 'Runtime files must be regular non-executable Git files: ' . $file );
		}
	}

	$plugin = eventbridge_release_run( array_merge( $safe_git, array( 'show', $ref . ':eventbridge.php' ) ), $repository );
	if ( ! preg_match( '/^[ \t\/*#@]*Version:[ \t]*(.+)$/mi', $plugin, $matches ) ) {
		throw new RuntimeException( 'Unable to determine plugin version.' );
	}
	$version = trim( $matches[1] );
	if ( '' === $tag ) {
		$tag = 'v' . $version;
	}
	if ( ! preg_match( '/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $tag ) || substr( $tag, 1 ) !== $version ) {
		throw new RuntimeException( 'Tag must be v<plugin-version> using stable or -rc.N syntax.' );
	}

	if ( ! is_dir( $output ) && ! mkdir( $output, 0777, true ) ) {
		throw new RuntimeException( 'Unable to create output directory.' );
	}
	$asset_name = 'eventbridge-' . $version . '.zip';
	$zip_file   = $output . '/' . $asset_name;
	$stage      = $output . '/package-stage';
	if ( is_file( $zip_file ) ) {
		unlink( $zip_file );
	}
	eventbridge_release_remove_directory( $stage, $output );
	if ( ! mkdir( $stage, 0777, true ) ) {
		throw new RuntimeException( 'Unable to create staging directory.' );
	}

	$archive_command = array_merge(
		$safe_git,
		array( 'archive', '--format=zip', '--prefix=eventbridge/', '--output=' . $zip_file, $ref, '--' ),
		$files
	);
	eventbridge_release_run( $archive_command, $repository );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) || ! $zip->extractTo( $stage ) ) {
		throw new RuntimeException( 'Unable to extract generated ZIP for verification.' );
	}
	$zip->close();
	$verifier->verify_directory( $stage . '/eventbridge', $version );
	$verifier->verify_zip( $zip_file, $version );

	$checksum      = hash_file( 'sha256', $zip_file );
	$checksum_file = $zip_file . '.sha256';
	if ( ! is_string( $checksum ) || false === file_put_contents( $checksum_file, $checksum . '  ' . $asset_name . "\n" ) ) {
		throw new RuntimeException( 'Unable to write checksum.' );
	}
	eventbridge_release_remove_directory( $stage, $output );

	echo $zip_file . "\n" . $checksum_file . "\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'Release build failed: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}
