<?php

/**
 * Release package verifier. Compatible with PHP 7.4.
 */
class EventBridge_Release_Verifier {
	const MAX_PACKAGE_BYTES = 20971520;

	private $root;
	private $allowlist;

	public function __construct( $root ) {
		$this->root      = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->allowlist = $this->read_allowlist( $this->root . '/tools/release/allowlist.txt' );
	}

	public function selected_files( $tracked_files ) {
		$selected = array();
		foreach ( $tracked_files as $file ) {
			$file = str_replace( '\\', '/', trim( $file ) );
			if ( '' !== $file && $this->is_allowed_path( $file ) ) {
				$selected[] = $file;
			}
		}
		sort( $selected, SORT_STRING );

		if ( ! in_array( 'eventbridge.php', $selected, true ) ) {
			throw new RuntimeException( 'Allowlist does not select eventbridge.php.' );
		}

		return $selected;
	}

	public function verify_directory( $directory, $expected_version ) {
		$directory = rtrim( $directory, '/\\' );
		if ( 'eventbridge' !== basename( str_replace( '\\', '/', $directory ) ) ) {
			throw new RuntimeException( 'Package root directory must be named eventbridge.' );
		}
		if ( ! is_file( $directory . DIRECTORY_SEPARATOR . 'eventbridge.php' ) ) {
			throw new RuntimeException( 'eventbridge/eventbridge.php is missing.' );
		}
		if ( is_dir( $directory . DIRECTORY_SEPARATOR . 'eventbridge' ) ) {
			throw new RuntimeException( 'Package contains a nested eventbridge/eventbridge directory.' );
		}

		$files = $this->directory_files( $directory );
		foreach ( $files as $relative => $absolute ) {
			if ( ! $this->is_allowed_path( $relative ) ) {
				throw new RuntimeException( 'File is not allowlisted: ' . $relative );
			}
			$this->verify_path_policy( $relative );
			$this->scan_file( $absolute, $relative );
		}
		$this->verify_versions( $directory . DIRECTORY_SEPARATOR . 'eventbridge.php', $expected_version );
	}

	public function verify_zip( $zip_file, $expected_version ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'The PHP zip extension is required.' );
		}
		if ( ! is_file( $zip_file ) || filesize( $zip_file ) > self::MAX_PACKAGE_BYTES ) {
			throw new RuntimeException( 'ZIP is missing or exceeds the package size limit.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file ) ) {
			throw new RuntimeException( 'Unable to open ZIP.' );
		}

		$roots      = array();
		$entries    = array();
		$entry_keys = array();
		$has_plugin = false;
		$total_size = 0;
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
				$zip->close();
				throw new RuntimeException( 'ZIP contains an unreadable entry.' );
			}

			$name = $stat['name'];
			if ( '' === $name || false !== strpos( $name, '\\' ) || '/' === substr( $name, 0, 1 ) || false !== strpos( $name, '../' ) ) {
				$zip->close();
				throw new RuntimeException( 'ZIP contains an unsafe entry: ' . $name );
			}
			if ( isset( $entries[ $name ] ) ) {
				$zip->close();
				throw new RuntimeException( 'ZIP contains a duplicate entry: ' . $name );
			}
			$entry_key = strtolower( $name );
			if ( isset( $entry_keys[ $entry_key ] ) ) {
				$zip->close();
				throw new RuntimeException( 'ZIP contains case-colliding entries: ' . $name );
			}
			$entries[ $name ] = true;
			$entry_keys[ $entry_key ] = true;
			$parts            = explode( '/', $name );
			$roots[ $parts[0] ] = true;
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
				$operating_system = 0;
				$attributes       = 0;
				if ( $zip->getExternalAttributesIndex( $index, $operating_system, $attributes )
					&& 0120000 === ( ( $attributes >> 16 ) & 0170000 )
				) {
					$zip->close();
					throw new RuntimeException( 'ZIP contains a symbolic link: ' . $name );
				}
			}

			if ( 'eventbridge/eventbridge.php' === $name ) {
				$has_plugin = true;
			}
			if ( 'eventbridge/eventbridge/' === substr( $name, 0, 24 ) ) {
				$zip->close();
				throw new RuntimeException( 'ZIP contains a nested eventbridge directory.' );
			}

			if ( '/' === substr( $name, -1 ) ) {
				$relative_directory = trim( substr( $name, strlen( 'eventbridge/' ) ), '/' );
				if ( 'eventbridge/' !== $name
					&& ( 0 !== strpos( $name, 'eventbridge/' ) || ! $this->is_allowed_directory( $relative_directory ) )
				) {
					$zip->close();
					throw new RuntimeException( 'ZIP directory is not allowlisted: ' . $name );
				}
				if ( '' !== $relative_directory ) {
					$this->verify_path_policy( $relative_directory );
				}
			} else {
				$entry_size = isset( $stat['size'] ) ? (int) $stat['size'] : -1;
				$total_size += max( 0, $entry_size );
				if ( $entry_size < 0 || $entry_size > self::MAX_PACKAGE_BYTES || $total_size > self::MAX_PACKAGE_BYTES ) {
					$zip->close();
					throw new RuntimeException( 'ZIP uncompressed contents exceed the package size limit.' );
				}
				$relative = substr( $name, strlen( 'eventbridge/' ) );
				if ( 0 !== strpos( $name, 'eventbridge/' ) || ! $this->is_allowed_path( $relative ) ) {
					$zip->close();
					throw new RuntimeException( 'ZIP entry is not allowlisted: ' . $name );
				}
				$this->verify_path_policy( $relative );
				$contents = $zip->getFromIndex( $index );
				if ( ! is_string( $contents ) || strlen( $contents ) !== $entry_size ) {
					$zip->close();
					throw new RuntimeException( 'Unable to read ZIP entry: ' . $name );
				}
				$this->scan_contents( $contents, $relative );
			}
		}

		if ( array( 'eventbridge' ) !== array_keys( $roots ) || ! $has_plugin ) {
			$zip->close();
			throw new RuntimeException( 'ZIP must contain exactly one eventbridge root with eventbridge.php.' );
		}

		$plugin_contents = $zip->getFromName( 'eventbridge/eventbridge.php' );
		$zip->close();
		if ( ! is_string( $plugin_contents ) ) {
			throw new RuntimeException( 'Unable to read eventbridge.php from ZIP.' );
		}
		$this->verify_version_contents( $plugin_contents, $expected_version );
	}

	private function read_allowlist( $file ) {
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			throw new RuntimeException( 'Unable to read release allowlist.' );
		}

		return array_values(
			array_filter(
				array_map( 'trim', $lines ),
				function ( $line ) {
					return '' !== $line && '#' !== substr( $line, 0, 1 );
				}
			)
		);
	}

	private function is_allowed_path( $path ) {
		foreach ( $this->allowlist as $pattern ) {
			$quoted = preg_quote( $pattern, '#' );
			$quoted = str_replace( '\*\*/', '(?:.*/)?', $quoted );
			$quoted = str_replace( '\*\*', '.*', $quoted );
			$quoted = str_replace( '\*', '[^/]*', $quoted );
			if ( preg_match( '#^' . $quoted . '$#D', $path ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_allowed_directory( $directory ) {
		foreach ( $this->allowlist as $pattern ) {
			$asterisk = strpos( $pattern, '*' );
			$prefix   = rtrim( false === $asterisk ? dirname( $pattern ) : substr( $pattern, 0, $asterisk ), '/' );
			if ( '.' === $prefix ) {
				$prefix = '';
			}
			if ( $directory === $prefix
				|| ( '' !== $prefix && 0 === strpos( $directory . '/', $prefix . '/' ) )
				|| ( '' !== $directory && 0 === strpos( $prefix . '/', $directory . '/' ) )
			) {
				return true;
			}
		}

		return false;
	}

	private function directory_files( $directory ) {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isLink() || ! $file->isFile() ) {
				throw new RuntimeException( 'Package may contain regular files only: ' . $file->getPathname() );
			}
			$absolute            = $file->getPathname();
			$relative            = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $directory ) ) ), '/' );
			$files[ $relative ] = $absolute;
		}
		ksort( $files, SORT_STRING );

		return $files;
	}

	private function verify_path_policy( $relative ) {
		$lower     = strtolower( $relative );
		$segments  = explode( '/', $lower );
		$forbidden = array( '.git', '.github', 'tests', 'tools', 'coverage', 'screenshots', 'node_modules' );
		foreach ( $segments as $segment ) {
			if ( in_array( $segment, $forbidden, true ) ) {
				throw new RuntimeException( 'Forbidden directory in package: ' . $relative );
			}
		}

		if ( preg_match( '/(^|\/)(\.env(?:\..*)?|composer\.(?:json|lock)|package(?:-lock)?\.json|phpunit\.xml(?:\.dist)?|.*\.(?:log|sql|bak|tmp|swp|map|pem|key|p12|pfx|phar|zip))$/D', $lower ) ) {
			throw new RuntimeException( 'Forbidden file type in package: ' . $relative );
		}
	}

	private function scan_file( $absolute, $relative ) {
		$contents = file_get_contents( $absolute );
		if ( ! is_string( $contents ) ) {
			throw new RuntimeException( 'Unable to scan file: ' . $relative );
		}
		$this->scan_contents( $contents, $relative );
	}

	private function scan_contents( $contents, $relative ) {
		$patterns = array(
			'/C:\\\\wamp64\\\\/i'                             => 'local WAMP path',
			'#/(?:home/runner/work|Users/[^/]+|var/www)/#i'   => 'local absolute path',
			'/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/' => 'private key',
			'/\bgh[pousr]_[A-Za-z0-9]{36,}\b/'                  => 'GitHub token',
			'/\bgithub_pat_[A-Za-z0-9_]{40,}\b/'                 => 'GitHub token',
			'/\bAKIA[0-9A-Z]{16}\b/'                              => 'AWS access key',
			'/(?:api[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token|password)\s*[:=]\s*([\'\"])[^\'\"\r\n]{16,}\1/i' => 'hard-coded secret',
		);
		foreach ( $patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $contents ) ) {
				throw new RuntimeException( ucfirst( $label ) . ' found in ' . $relative );
			}
		}
	}

	private function verify_versions( $plugin_file, $expected_version ) {
		$contents = file_get_contents( $plugin_file );
		if ( ! is_string( $contents ) ) {
			throw new RuntimeException( 'Unable to read eventbridge.php.' );
		}
		$this->verify_version_contents( $contents, $expected_version );
	}

	private function verify_version_contents( $contents, $expected_version ) {
		$header_version = $this->header_value( $contents, 'Version' );
		$update_uri     = $this->header_value( $contents, 'Update URI' );
		$requires_wp    = $this->header_value( $contents, 'Requires at least' );
		$requires_php   = $this->header_value( $contents, 'Requires PHP' );
		$constant       = preg_match( "/define\(\s*'EVENTBRIDGE_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $matches ) ? $matches[1] : '';

		if ( $expected_version !== $header_version || $expected_version !== $constant ) {
			throw new RuntimeException( 'Plugin header, EVENTBRIDGE_VERSION and release version must match.' );
		}
		if ( 'https://github.com/Tavorick/eventbridge' !== $update_uri || '5.8' !== $requires_wp || '7.4' !== $requires_php ) {
			throw new RuntimeException( 'Plugin update/minimum-version headers are invalid.' );
		}
	}

	private function header_value( $contents, $header ) {
		$pattern = '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+)$/mi';
		return preg_match( $pattern, $contents, $matches ) ? trim( $matches[1] ) : '';
	}
}

if ( isset( $argv ) && realpath( $argv[0] ) === __FILE__ ) {
	try {
		if ( 3 !== count( $argv ) ) {
			throw new RuntimeException( 'Usage: php tools/release/verify.php <directory-or-zip> <version>' );
		}
		$repository = dirname( __DIR__, 2 );
		$verifier   = new EventBridge_Release_Verifier( $repository );
		if ( '.zip' === strtolower( substr( $argv[1], -4 ) ) ) {
			$verifier->verify_zip( $argv[1], $argv[2] );
		} else {
			$verifier->verify_directory( $argv[1], $argv[2] );
		}
		echo "Release package verification passed.\n";
	} catch ( Throwable $throwable ) {
		fwrite( STDERR, 'Release package verification failed: ' . $throwable->getMessage() . "\n" );
		exit( 1 );
	}
}
