<?php

defined( 'ABSPATH' ) || exit;

/**
 * Connects EventBridge to the native WordPress plugin update flow.
 */
class EventBridge_Plugin_Updater {
	const PLUGIN_BASENAME    = 'eventbridge/eventbridge.php';
	const UPDATE_URI         = 'https://github.com/Tavorick/eventbridge';
	const REPOSITORY_PATH    = 'Tavorick/eventbridge';
	const API_VERSION        = '2026-03-10';
	const REQUIRES_WORDPRESS = '5.8';
	const REQUIRES_PHP       = '7.4';
	const TESTED_WORDPRESS   = '7.0';

	const MAX_API_BYTES     = 524288;
	const MAX_PACKAGE_BYTES = 20971520;
	const MAX_REDIRECTS     = 3;

	/**
	 * Verified pre-download metadata, keyed by the exact upgrader instance.
	 *
	 * The context deliberately lives for only the current PHP request. It binds
	 * source inspection to bytes that this instance verified before extraction.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $verification_contexts = array();

	public function register_hooks() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'verify_package_source' ), 20, 4 );
	}

	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		if ( ! $this->is_eventbridge_plugin( $plugin_file, $plugin_data ) ) {
			return $update;
		}

		$release = $this->get_release( $this->allow_prereleases() );
		if ( is_wp_error( $release ) ) {
			return false;
		}

		$current_version = isset( $plugin_data['Version'] ) && is_string( $plugin_data['Version'] )
			? $plugin_data['Version']
			: EVENTBRIDGE_VERSION;
		if ( version_compare( $release['version'], $current_version, '<=' ) ) {
			return false;
		}

		return array(
			'version'                     => $release['version'],
			'url'                         => self::UPDATE_URI . '/releases/tag/' . $release['tag'],
			'package'                     => $release['package_url'],
			'requires'                    => self::REQUIRES_WORDPRESS,
			'requires_php'                => self::REQUIRES_PHP,
			'tested'                      => self::TESTED_WORDPRESS,
			'eventbridge_sha256'          => $release['sha256'],
			'eventbridge_package_size'    => $release['package_size'],
		);
	}

	public function verify_package_download( $reply, $package, $upgrader, $hook_extra ) {
		$this->clear_verification_context( $upgrader );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$scope = $this->classify_update( $package, $upgrader, $hook_extra );
		if ( 'manual_install' === $scope || 'other_plugin' === $scope || 'unknown' === $scope ) {
			return $reply;
		}
		if ( 'self_update' !== $scope ) {
			return $this->verification_error();
		}

		$context = $this->normalize_current_update( $package );
		if ( false === $context ) {
			return $this->verification_error();
		}

		if ( false === $reply ) {
			$verified_package = $this->download_verified_package( $package, $context['size'], $context['digest'] );
			if ( is_wp_error( $verified_package ) ) {
				return $this->verification_error();
			}
		} elseif ( is_string( $reply ) ) {
			$verified_package = $reply;
			if ( ! $this->verify_local_package( $verified_package, $context['size'], $context['digest'] ) ) {
				return $this->verification_error();
			}
		} else {
			return $this->verification_error();
		}

		$this->store_verification_context( $upgrader, $context );
		return $verified_package;
	}

	public function verify_package_source( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) ) {
			$this->clear_verification_context( $upgrader );
			return $source;
		}

		$scope = $this->classify_update( null, $upgrader, $hook_extra );
		if ( 'unknown' === $scope && $this->has_verification_context( $upgrader ) ) {
			$this->clear_verification_context( $upgrader );
			return $this->source_verification_error();
		}
		if ( 'manual_install' === $scope || 'other_plugin' === $scope || 'unknown' === $scope ) {
			$this->clear_verification_context( $upgrader );
			return $source;
		}
		if ( 'self_update' !== $scope ) {
			$this->clear_verification_context( $upgrader );
			return $this->source_verification_error();
		}

		$context = $this->consume_verification_context( $upgrader );
		if ( false === $context ) {
			return $this->source_verification_error();
		}

		$current = $this->normalize_current_update( $context['package'] );
		if ( false === $current || ! $this->contexts_match( $context, $current ) ) {
			return $this->source_verification_error();
		}

		if ( ! $this->verify_runtime_source( $source, $remote_source, $context ) ) {
			return $this->source_verification_error();
		}

		return $source;
	}

	private function is_eventbridge_plugin( $plugin_file, $plugin_data ) {
		return self::PLUGIN_BASENAME === $plugin_file
			&& is_array( $plugin_data )
			&& isset( $plugin_data['UpdateURI'] )
			&& self::UPDATE_URI === untrailingslashit( (string) $plugin_data['UpdateURI'] );
	}

	private function allow_prereleases() {
		return defined( 'EVENTBRIDGE_ALLOW_PRERELEASES' )
			&& true === EVENTBRIDGE_ALLOW_PRERELEASES
			&& function_exists( 'wp_get_environment_type' )
			&& 'staging' === wp_get_environment_type();
	}

	private function get_release( $allow_prereleases ) {
		$endpoint = $allow_prereleases
			? 'https://api.github.com/repos/' . self::REPOSITORY_PATH . '/releases?per_page=20'
			: 'https://api.github.com/repos/' . self::REPOSITORY_PATH . '/releases/latest';
		$payload  = $this->request_release_payload( $endpoint );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$releases = $allow_prereleases ? $payload : array( $payload );
		if ( ! is_array( $releases ) ) {
			return new WP_Error( 'eventbridge_invalid_release_list' );
		}

		$selected = false;
		foreach ( array_slice( $releases, 0, 20 ) as $candidate ) {
			$release = $this->normalize_release( $candidate, $allow_prereleases );
			if ( is_wp_error( $release ) ) {
				continue;
			}
			if ( false === $selected || version_compare( $release['version'], $selected['version'], '>' ) ) {
				$selected = $release;
			}
		}

		return false === $selected ? new WP_Error( 'eventbridge_no_valid_release' ) : $selected;
	}

	private function request_release_payload( $endpoint ) {
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_API_BYTES,
				'headers'             => array(
					'Accept'               => 'application/vnd.github+json',
					'User-Agent'           => 'EventBridge/' . EVENTBRIDGE_VERSION,
					'X-GitHub-Api-Version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'eventbridge_github_http_error' );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_API_BYTES ) {
			return new WP_Error( 'eventbridge_invalid_api_body' );
		}
		$payload = json_decode( $body, true );
		return JSON_ERROR_NONE === json_last_error() && is_array( $payload )
			? $payload
			: new WP_Error( 'eventbridge_invalid_api_json' );
	}

	private function normalize_release( $release, $allow_prereleases ) {
		if ( ! is_array( $release )
			|| ! isset( $release['tag_name'], $release['draft'], $release['prerelease'], $release['published_at'], $release['assets'] )
			|| ! is_string( $release['tag_name'] )
			|| ! is_bool( $release['draft'] )
			|| $release['draft']
			|| ! is_bool( $release['prerelease'] )
			|| ! is_string( $release['published_at'] )
			|| '' === $release['published_at']
			|| ! is_array( $release['assets'] )
		) {
			return new WP_Error( 'eventbridge_invalid_release' );
		}

		$tag       = $release['tag_name'];
		$is_stable = (bool) preg_match( '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $tag );
		$is_rc     = (bool) preg_match( '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-rc\.([1-9][0-9]*)$/D', $tag );
		if ( ( ! $is_stable && ! $is_rc )
			|| ( $is_stable && $release['prerelease'] )
			|| ( $is_rc && ( ! $release['prerelease'] || ! $allow_prereleases ) )
		) {
			return new WP_Error( 'eventbridge_invalid_release_tag' );
		}

		$version    = substr( $tag, 1 );
		$zip_name   = 'eventbridge-' . $version . '.zip';
		$zip_assets = array();
		foreach ( $release['assets'] as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'] ) && $zip_name === $asset['name'] ) {
				$zip_assets[] = $asset;
			}
		}
		if ( 1 !== count( $zip_assets ) ) {
			return new WP_Error( 'eventbridge_missing_release_asset' );
		}

		$asset = $this->normalize_asset( $zip_assets[0], $tag, $zip_name );
		if ( is_wp_error( $asset ) ) {
			return $asset;
		}

		return array(
			'tag'          => $tag,
			'version'      => $version,
			'package_url'  => $asset['browser_download_url'],
			'package_size' => $asset['size'],
			'sha256'       => $asset['sha256'],
		);
	}

	private function normalize_asset( $asset, $tag, $name ) {
		if ( ! is_array( $asset )
			|| ! isset( $asset['name'], $asset['state'], $asset['size'], $asset['browser_download_url'], $asset['digest'] )
			|| $name !== $asset['name']
			|| 'uploaded' !== $asset['state']
			|| ! is_int( $asset['size'] )
			|| ! is_string( $asset['browser_download_url'] )
			|| ! is_string( $asset['digest'] )
		) {
			return new WP_Error( 'eventbridge_invalid_asset' );
		}

		$size = $asset['size'];
		if ( $size < 1 || $size > self::MAX_PACKAGE_BYTES ) {
			return new WP_Error( 'eventbridge_invalid_asset_size' );
		}

		$expected_url = self::UPDATE_URI . '/releases/download/' . $tag . '/' . $name;
		if ( $expected_url !== $asset['browser_download_url'] || ! preg_match( '/^sha256:([a-f0-9]{64})$/D', $asset['digest'], $matches ) ) {
			return new WP_Error( 'eventbridge_invalid_asset_metadata' );
		}

		return array(
			'browser_download_url' => $expected_url,
			'size'                 => $size,
			'sha256'               => $matches[1],
		);
	}

	private function download_verified_package( $initial_url, $expected_size, $expected_sha256 ) {
		$current_url = $initial_url;
		for ( $redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++ ) {
			if ( ! $this->is_allowed_download_url( $current_url, 0 === $redirects, $initial_url ) ) {
				return new WP_Error( 'eventbridge_invalid_download_url', __( 'The EventBridge download URL is not allowed.', 'eventbridge' ) );
			}

			$temp_file = wp_tempnam( basename( (string) wp_parse_url( $current_url, PHP_URL_PATH ) ) );
			if ( ! $temp_file ) {
				return new WP_Error( 'eventbridge_temp_file_failed' );
			}

			$response = wp_safe_remote_get(
				$current_url,
				array(
					'timeout'             => 120,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $temp_file,
					'limit_response_size' => self::MAX_PACKAGE_BYTES + 1,
					'headers'             => array( 'User-Agent' => 'EventBridge/' . EVENTBRIDGE_VERSION ),
				)
			);
			if ( is_wp_error( $response ) ) {
				@unlink( $temp_file );
				return $response;
			}

			$status = wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				@unlink( $temp_file );
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! is_string( $location ) || '' === $location || self::MAX_REDIRECTS === $redirects ) {
					return new WP_Error( 'eventbridge_invalid_download_redirect' );
				}
				$current_url = $location;
				continue;
			}
			if ( 200 !== $status ) {
				@unlink( $temp_file );
				return new WP_Error( 'eventbridge_download_http_error' );
			}

			if ( ! $this->verify_local_package( $temp_file, $expected_size, $expected_sha256 ) ) {
				@unlink( $temp_file );
				return new WP_Error( 'eventbridge_package_verification_failed', __( 'The EventBridge update package failed its integrity check.', 'eventbridge' ) );
			}

			return $temp_file;
		}

		return new WP_Error( 'eventbridge_too_many_redirects' );
	}

	private function is_allowed_download_url( $url, $initial, $initial_url ) {
		if ( $initial && $url !== $initial_url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== strtolower( $parts['scheme'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
		) {
			return false;
		}

		$host = strtolower( $parts['host'] );
		if ( $initial ) {
			return 'github.com' === $host;
		}

		return in_array( $host, array( 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' ), true );
	}

	/**
	 * Classifies one per-package upgrader callback without treating filenames or
	 * extracted directory names as plugin identity.
	 */
	private function classify_update( $package, $upgrader, $hook_extra ) {
		if ( is_array( $hook_extra ) && array_key_exists( 'plugin', $hook_extra ) ) {
			if ( self::PLUGIN_BASENAME === $hook_extra['plugin'] ) {
				if ( ( array_key_exists( 'action', $hook_extra ) && 'update' !== $hook_extra['action'] )
					|| ( array_key_exists( 'type', $hook_extra ) && 'plugin' !== $hook_extra['type'] )
					|| ! ( $upgrader instanceof Plugin_Upgrader )
				) {
					return 'ambiguous';
				}

				return 'self_update';
			}

			if ( is_string( $hook_extra['plugin'] ) && '' !== $hook_extra['plugin'] ) {
				return 'other_plugin';
			}
		}

		if ( is_array( $hook_extra )
			&& isset( $hook_extra['action'], $hook_extra['type'] )
			&& 'install' === $hook_extra['action']
			&& 'plugin' === $hook_extra['type']
		) {
			return 'manual_install';
		}

		return $this->has_canonical_package_evidence( $package ) ? 'ambiguous' : 'unknown';
	}

	/**
	 * Returns the strict, immutable metadata that both upgrader filters bind to.
	 */
	private function normalize_current_update( $callback_package ) {
		$update = $this->get_current_update_record();
		if ( false === $update
			|| ! array_key_exists( 'plugin', $update )
			|| ! array_key_exists( 'id', $update )
			|| ! array_key_exists( 'version', $update )
			|| ! array_key_exists( 'new_version', $update )
			|| ! array_key_exists( 'package', $update )
			|| ! array_key_exists( 'eventbridge_sha256', $update )
			|| ! array_key_exists( 'eventbridge_package_size', $update )
			|| self::PLUGIN_BASENAME !== $update['plugin']
			|| self::UPDATE_URI !== $update['id']
			|| ! is_string( $update['version'] )
			|| ! is_string( $update['new_version'] )
			|| $update['version'] !== $update['new_version']
			|| ! $this->is_valid_update_version( $update['version'] )
			|| ! defined( 'EVENTBRIDGE_VERSION' )
			|| ! is_string( EVENTBRIDGE_VERSION )
			|| ! version_compare( $update['version'], EVENTBRIDGE_VERSION, '>' )
			|| ( false !== strpos( $update['version'], '-rc.' ) && ! $this->allow_prereleases() )
			|| ! is_string( $update['package'] )
			|| ! is_string( $callback_package )
			|| ! is_string( $update['eventbridge_sha256'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $update['eventbridge_sha256'] )
			|| ! is_int( $update['eventbridge_package_size'] )
			|| $update['eventbridge_package_size'] < 1
			|| $update['eventbridge_package_size'] > self::MAX_PACKAGE_BYTES
		) {
			return false;
		}

		$expected_package = $this->get_expected_package_url( $update['version'] );
		if ( $expected_package !== $update['package'] || $expected_package !== $callback_package ) {
			return false;
		}

		return array(
			'plugin'  => self::PLUGIN_BASENAME,
			'version' => $update['version'],
			'package' => $expected_package,
			'digest'  => $update['eventbridge_sha256'],
			'size'    => $update['eventbridge_package_size'],
		);
	}

	/**
	 * Detects an unidentified callback that nevertheless carries the canonical
	 * EventBridge release URL from the EventBridge response slot. Strict identity,
	 * version, digest and size fields are intentionally not required here: missing
	 * or conflicting security metadata must fail closed, not make it unscoped.
	 */
	private function has_canonical_package_evidence( $package ) {
		$update = $this->get_current_update_record();
		if ( ! is_string( $package )
			|| false === $update
			|| ! isset( $update['package'] )
			|| ! is_string( $update['package'] )
			|| $package !== $update['package']
		) {
			return false;
		}

		$version_pattern = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?';
		return (bool) preg_match(
			'/^' . preg_quote( self::UPDATE_URI, '/' ) . '\/releases\/download\/v(' . $version_pattern . ')\/eventbridge-\1\.zip$/D',
			$package
		);
	}

	private function get_current_update_record() {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient )
			|| ! isset( $transient->response )
			|| ! is_array( $transient->response )
			|| ! array_key_exists( self::PLUGIN_BASENAME, $transient->response )
		) {
			return false;
		}

		$update = $transient->response[ self::PLUGIN_BASENAME ];
		if ( is_object( $update ) ) {
			return get_object_vars( $update );
		}

		return is_array( $update ) ? $update : false;
	}

	private function is_valid_update_version( $version ) {
		return is_string( $version )
			&& (bool) preg_match( '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version );
	}

	private function get_expected_package_url( $version ) {
		return self::UPDATE_URI . '/releases/download/v' . $version . '/eventbridge-' . $version . '.zip';
	}

	private function store_verification_context( $upgrader, $context ) {
		if ( is_object( $upgrader ) ) {
			$this->verification_contexts[ spl_object_hash( $upgrader ) ] = $context;
		}
	}

	private function consume_verification_context( $upgrader ) {
		if ( ! is_object( $upgrader ) ) {
			return false;
		}

		$key = spl_object_hash( $upgrader );
		if ( ! isset( $this->verification_contexts[ $key ] ) ) {
			return false;
		}

		$context = $this->verification_contexts[ $key ];
		unset( $this->verification_contexts[ $key ] );
		return $context;
	}

	private function clear_verification_context( $upgrader ) {
		if ( is_object( $upgrader ) ) {
			unset( $this->verification_contexts[ spl_object_hash( $upgrader ) ] );
		}
	}

	private function has_verification_context( $upgrader ) {
		return is_object( $upgrader )
			&& isset( $this->verification_contexts[ spl_object_hash( $upgrader ) ] );
	}

	private function contexts_match( $first, $second ) {
		return is_array( $first )
			&& is_array( $second )
			&& isset( $first['plugin'], $first['version'], $first['package'], $first['digest'], $first['size'] )
			&& isset( $second['plugin'], $second['version'], $second['package'], $second['digest'], $second['size'] )
			&& $first['plugin'] === $second['plugin']
			&& $first['version'] === $second['version']
			&& $first['package'] === $second['package']
			&& $first['digest'] === $second['digest']
			&& $first['size'] === $second['size'];
	}

	private function verification_error() {
		return new WP_Error(
			'eventbridge_update_verification_failed',
			__( 'The EventBridge update could not be verified.', 'eventbridge' )
		);
	}

	private function source_verification_error() {
		return new WP_Error(
			'eventbridge_update_package_invalid',
			__( 'The EventBridge update package could not be verified.', 'eventbridge' )
		);
	}

	private function verify_local_package( $file, $expected_size, $expected_sha256 ) {
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}

		clearstatcache( true, $file );
		$actual_size = @filesize( $file );
		$actual_hash = @hash_file( 'sha256', $file );
		return is_int( $actual_size )
			&& $actual_size > 0
			&& $actual_size <= self::MAX_PACKAGE_BYTES
			&& $actual_size === $expected_size
			&& is_string( $actual_hash )
			&& hash_equals( $expected_sha256, $actual_hash );
	}

	/**
	 * Applies the compact runtime package policy to the extracted source tree.
	 */
	private function verify_runtime_source( $source, $remote_source, $context ) {
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) ) {
			return false;
		}

		$normalized_remote = $this->normalize_runtime_path( $remote_source );
		$normalized_source = $this->normalize_runtime_path( $source );
		if ( false === $normalized_remote
			|| false === $normalized_source
			|| $normalized_source !== $normalized_remote . '/eventbridge'
			|| ! $this->path_is_within( $normalized_source, $normalized_remote )
		) {
			return false;
		}

		$remote_listing = $wp_filesystem->dirlist( $remote_source, true, false );
		if ( ! is_array( $remote_listing ) || 1 !== count( $remote_listing ) ) {
			return false;
		}

		$root_name = key( $remote_listing );
		$root_info = current( $remote_listing );
		if ( ! $this->is_valid_listing_entry( $root_name, $root_info )
			|| 'eventbridge' !== $root_name
			|| 'd' !== $root_info['type']
		) {
			return false;
		}

		$root_path = trailingslashit( $remote_source ) . $root_name;
		if ( $this->is_direct_filesystem_link( $wp_filesystem, $root_path ) ) {
			return false;
		}

		$directories = array(
			array(
				'path'     => $source,
				'relative' => '',
			),
		);
		$plugin_files = 0;

		while ( $directories ) {
			$directory = array_pop( $directories );
			$listing   = $wp_filesystem->dirlist( $directory['path'], true, false );
			if ( ! is_array( $listing ) ) {
				return false;
			}

			foreach ( $listing as $name => $info ) {
				if ( ! $this->is_valid_listing_entry( $name, $info ) ) {
					return false;
				}

				$relative   = '' === $directory['relative'] ? $name : $directory['relative'] . '/' . $name;
				$child_path = trailingslashit( $directory['path'] ) . $name;
				$normalized_child = $this->normalize_runtime_path( $child_path );
				if ( false === $normalized_child || ! $this->path_is_within( $normalized_child, $normalized_source ) ) {
					return false;
				}
				if ( $this->is_direct_filesystem_link( $wp_filesystem, $child_path ) ) {
					return false;
				}

				if ( 'd' === $info['type'] ) {
					$lower_name = strtolower( $name );
					if ( 'eventbridge' === $lower_name
						|| in_array( $lower_name, array( '.git', '.github', 'tests', 'tools', 'vendor', 'dist' ), true )
						|| ! $this->is_allowed_runtime_directory( $relative )
					) {
						return false;
					}

					$directories[] = array(
						'path'     => $child_path,
						'relative' => $relative,
					);
					continue;
				}

				if ( 'f' !== $info['type'] || ! $this->is_allowed_runtime_file( $relative ) ) {
					return false;
				}
				if ( 'eventbridge.php' === $relative ) {
					$plugin_files++;
				}
			}
		}

		if ( 1 !== $plugin_files ) {
			return false;
		}

		$plugin_file = trailingslashit( $source ) . 'eventbridge.php';
		if ( ! $wp_filesystem->is_file( $plugin_file ) ) {
			return false;
		}
		$contents = $wp_filesystem->get_contents( $plugin_file );
		return is_string( $contents )
			&& $context['version'] === $this->read_header_value( $contents, 'Version' )
			&& self::UPDATE_URI === $this->read_header_value( $contents, 'Update URI' );
	}

	private function is_valid_listing_entry( $name, $info ) {
		return is_string( $name )
			&& '' !== $name
			&& '.' !== $name
			&& '..' !== $name
			&& false === strpos( $name, "\0" )
			&& false === strpos( $name, '/' )
			&& false === strpos( $name, '\\' )
			&& is_array( $info )
			&& isset( $info['name'], $info['type'] )
			&& is_string( $info['name'] )
			&& $name === $info['name']
			&& is_string( $info['type'] )
			&& in_array( $info['type'], array( 'f', 'd' ), true );
	}

	private function is_direct_filesystem_link( $filesystem, $path ) {
		return $filesystem instanceof WP_Filesystem_Direct && is_link( $path );
	}

	private function normalize_runtime_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$normalized = untrailingslashit( wp_normalize_path( $path ) );
		if ( '' === $normalized ) {
			return false;
		}
		foreach ( explode( '/', $normalized ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return $normalized;
	}

	private function path_is_within( $path, $parent ) {
		return $path !== $parent && 0 === strpos( $path, $parent . '/' );
	}

	private function is_allowed_runtime_directory( $path ) {
		return 'includes' === $path
			|| 0 === strpos( $path, 'includes/' )
			|| 'assets' === $path
			|| 'assets/css' === $path
			|| 0 === strpos( $path, 'assets/css/' )
			|| 'assets/js' === $path
			|| 0 === strpos( $path, 'assets/js/' )
			|| 'languages' === $path
			|| 0 === strpos( $path, 'languages/' );
	}

	private function is_allowed_runtime_file( $path ) {
		return 'eventbridge.php' === $path
			|| ( 0 === strpos( $path, 'includes/' ) && '.php' === substr( $path, -4 ) )
			|| ( 0 === strpos( $path, 'assets/css/' ) && '.css' === substr( $path, -4 ) )
			|| ( 0 === strpos( $path, 'assets/js/' ) && '.js' === substr( $path, -3 ) )
			|| ( 0 === strpos( $path, 'languages/' )
				&& ( '.mo' === substr( $path, -3 )
					|| '.json' === substr( $path, -5 )
					|| '.l10n.php' === substr( $path, -9 ) )
			);
	}

	private function read_header_value( $contents, $header ) {
		$contents = substr( $contents, 0, 8192 );
		$pattern = '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+)$/mi';
		return preg_match( $pattern, $contents, $matches ) ? trim( $matches[1] ) : '';
	}
}
