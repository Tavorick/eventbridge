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
		unset( $upgrader );

		if ( false !== $reply || ! $this->is_scoped_update( $hook_extra ) ) {
			return $reply;
		}

		$update = $this->get_current_update();
		if ( false === $update ) {
			return $reply;
		}
		$version = $this->get_update_version( $update );
		$expected_package = false === $version
			? ''
			: self::UPDATE_URI . '/releases/download/v' . $version . '/eventbridge-' . $version . '.zip';
		if ( ! isset( $update->package ) || $package !== $update->package || $package !== $expected_package ) {
			return new WP_Error( 'eventbridge_invalid_package_url', __( 'The EventBridge update package URL is invalid.', 'eventbridge' ) );
		}
		if ( ! isset( $update->eventbridge_sha256, $update->eventbridge_package_size )
			|| ! is_string( $update->eventbridge_sha256 )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $update->eventbridge_sha256 )
		) {
			return new WP_Error( 'eventbridge_missing_digest', __( 'EventBridge update metadata does not contain a valid GitHub digest.', 'eventbridge' ) );
		}

		$expected_size = absint( $update->eventbridge_package_size );
		if ( $expected_size < 1 || $expected_size > self::MAX_PACKAGE_BYTES ) {
			return new WP_Error( 'eventbridge_invalid_package_size', __( 'The EventBridge update package has an invalid size.', 'eventbridge' ) );
		}

		$download = $this->download_verified_package( $package, $expected_size, $update->eventbridge_sha256 );
		return $download;
	}

	public function verify_package_source( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $remote_source, $upgrader );

		if ( ! $this->is_scoped_update( $hook_extra ) ) {
			return $source;
		}

		$update = $this->get_current_update();
		$version = $this->get_update_version( $update );
		if ( false === $version ) {
			return new WP_Error( 'eventbridge_missing_update_metadata', __( 'The EventBridge update metadata is missing.', 'eventbridge' ) );
		}

		$normalized_source = untrailingslashit( wp_normalize_path( $source ) );
		if ( 'eventbridge' !== basename( $normalized_source ) ) {
			return new WP_Error( 'eventbridge_invalid_package_root', __( 'The EventBridge update package must contain exactly one eventbridge directory.', 'eventbridge' ) );
		}

		global $wp_filesystem;
		$plugin_file = trailingslashit( $source ) . 'eventbridge.php';
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->is_file( $plugin_file ) ) {
			return new WP_Error( 'eventbridge_missing_plugin_file', __( 'The EventBridge update package is missing eventbridge.php.', 'eventbridge' ) );
		}
		$contents = $wp_filesystem->get_contents( $plugin_file );
		if ( ! is_string( $contents ) ) {
			return new WP_Error( 'eventbridge_unreadable_plugin_file', __( 'The EventBridge update package could not be inspected.', 'eventbridge' ) );
		}

		if ( $version !== $this->read_header_value( $contents, 'Version' )
			|| self::UPDATE_URI !== $this->read_header_value( $contents, 'Update URI' )
		) {
			return new WP_Error( 'eventbridge_package_metadata_mismatch', __( 'The EventBridge update package metadata does not match the selected release.', 'eventbridge' ) );
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

		$size = absint( $asset['size'] );
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

			clearstatcache( true, $temp_file );
			$actual_size = filesize( $temp_file );
			$actual_hash = hash_file( 'sha256', $temp_file );
			if ( false === $actual_size
				|| $actual_size < 1
				|| $actual_size > self::MAX_PACKAGE_BYTES
				|| $actual_size !== $expected_size
				|| ! is_string( $actual_hash )
				|| ! hash_equals( $expected_sha256, $actual_hash )
			) {
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
			|| isset( $parts['user'], $parts['pass'] )
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

	private function is_scoped_update( $hook_extra ) {
		return is_array( $hook_extra )
			&& isset( $hook_extra['plugin'], $hook_extra['action'] )
			&& self::PLUGIN_BASENAME === $hook_extra['plugin']
			&& 'update' === $hook_extra['action'];
	}

	private function get_current_update() {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response[ self::PLUGIN_BASENAME ] ) ) {
			return false;
		}

		$update = $transient->response[ self::PLUGIN_BASENAME ];
		return is_object( $update ) ? $update : (object) $update;
	}

	private function get_update_version( $update ) {
		if ( ! is_object( $update ) ) {
			return false;
		}
		$version = isset( $update->new_version ) ? $update->new_version : ( isset( $update->version ) ? $update->version : false );
		return is_string( $version ) && preg_match( '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$/D', $version ) ? $version : false;
	}

	private function read_header_value( $contents, $header ) {
		$pattern = '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+)$/mi';
		return preg_match( $pattern, $contents, $matches ) ? trim( $matches[1] ) : '';
	}
}
