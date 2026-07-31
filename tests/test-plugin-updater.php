<?php

class EventBridge_Plugin_Updater_Test extends WP_UnitTestCase {
	private $updater;
	private $http_handler;

	public function set_up() {
		parent::set_up();
		$this->updater      = new EventBridge_Plugin_Updater();
		$this->http_handler = null;
		delete_site_transient( 'update_plugins' );
		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10 );
		delete_site_transient( 'update_plugins' );
		parent::tear_down();
	}

	public function mock_http_request( $preempt, $args, $url ) {
		return is_callable( $this->http_handler )
			? call_user_func( $this->http_handler, $preempt, $args, $url )
			: $preempt;
	}

	public function test_higher_stable_release_is_offered_with_github_digest() {
		$digest  = str_repeat( 'a', 64 );
		$release = $this->make_release( '1.3.1', false, $digest );
		$requests = 0;
		$this->http_handler = function () use ( $release, &$requests ) {
			$requests++;
			return $this->http_response( 200, wp_json_encode( $release ) );
		};

		$update = $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array( 'en_US' ) );

		$this->assertTrue( is_array( $update ) );
		$this->assertSame( '1.3.1', $update['version'] );
		$this->assertSame( $digest, $update['eventbridge_sha256'] );
		$this->assertSame( '7.4', $update['requires_php'] );
		$this->assertArrayNotHasKey( 'slug', $update );
		$this->assertArrayNotHasKey( 'new_version', $update );
		$this->assertSame( 1, $requests );
	}

	public function test_update_checks_do_not_add_an_etag_cache() {
		$release = $this->make_release( '1.3.1', false, str_repeat( 'b', 64 ) );
		$requests = 0;
		$this->http_handler = function ( $preempt, $args ) use ( $release, &$requests ) {
			unset( $preempt );
			$requests++;
			$this->assertArrayNotHasKey( 'If-None-Match', $args['headers'] );
			return $this->http_response( 200, wp_json_encode( $release ) );
		};

		$this->assertTrue( is_array( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) ) );
		$this->assertTrue( is_array( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) ) );
		$this->assertSame( 2, $requests );
	}

	public function test_equal_or_lower_release_is_not_offered() {
		$release = $this->make_release( '1.3.0', false, str_repeat( 'c', 64 ) );
		$this->http_handler = function () use ( $release ) {
			return $this->http_response( 200, wp_json_encode( $release ) );
		};

		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );
	}

	public function test_prerelease_is_rejected_by_the_stable_channel() {
		$release = $this->make_release( '1.3.1-rc.1', true, str_repeat( 'd', 64 ) );
		$this->http_handler = function () use ( $release ) {
			return $this->http_response( 200, wp_json_encode( $release ) );
		};

		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );
	}

	public function test_prerelease_channel_selects_the_highest_allowed_version() {
		$releases = array(
			$this->make_release( '1.3.1', false, str_repeat( 'e', 64 ) ),
			$this->make_release( '1.3.2-rc.2', true, str_repeat( 'e', 64 ) ),
			$this->make_release( '1.3.2-beta.1', true, str_repeat( 'e', 64 ) ),
		);
		$this->http_handler = function () use ( $releases ) {
			return $this->http_response( 200, wp_json_encode( $releases ) );
		};

		$method = new ReflectionMethod( EventBridge_Plugin_Updater::class, 'get_release' );
		$method->setAccessible( true );
		$release = $method->invoke( $this->updater, true );

		$this->assertTrue( is_array( $release ) );
		$this->assertSame( '1.3.2-rc.2', $release['version'] );
	}

	public function test_wrong_repository_or_invalid_json_is_rejected() {
		$release = $this->make_release( '1.3.1', false, str_repeat( 'f', 64 ) );
		$release['assets'][0]['browser_download_url'] = 'https://github.com/SomeoneElse/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip';
		$this->http_handler = function () use ( $release ) {
			return $this->http_response( 200, wp_json_encode( $release ) );
		};
		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );

		$this->http_handler = function () {
			return $this->http_response( 200, '{invalid-json' );
		};
		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );
	}

	public function test_missing_duplicate_or_wrongly_named_zip_asset_is_rejected() {
		$release = $this->make_release( '1.3.1', false, str_repeat( '1', 64 ) );
		$release['assets'] = array();
		$this->assert_release_is_rejected( $release );

		$release             = $this->make_release( '1.3.1', false, str_repeat( '2', 64 ) );
		$release['assets'][] = $release['assets'][0];
		$this->assert_release_is_rejected( $release );

		$release                     = $this->make_release( '1.3.1', false, str_repeat( '3', 64 ) );
		$release['assets'][0]['name'] = 'eventbridge.zip';
		$this->assert_release_is_rejected( $release );
	}

	public function test_missing_or_invalid_github_digest_is_rejected() {
		$release = $this->make_release( '1.3.1', false, str_repeat( '4', 64 ) );
		unset( $release['assets'][0]['digest'] );
		$this->assert_release_is_rejected( $release );

		$release = $this->make_release( '1.3.1', false, str_repeat( 'A', 64 ) );
		$this->assert_release_is_rejected( $release );
	}

	/**
	 * @dataProvider http_error_provider
	 */
	public function test_github_http_errors_fail_closed( $status ) {
		$this->http_handler = function () use ( $status ) {
			return $this->http_response( $status, '{}' );
		};

		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );
	}

	public function http_error_provider() {
		return array( array( 404 ), array( 403 ), array( 429 ), array( 500 ) );
	}

	public function test_github_timeout_fails_closed() {
		$this->http_handler = function () {
			return new WP_Error( 'http_request_failed', 'Timed out' );
		};

		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );
	}

	public function test_verified_download_is_returned_and_corruption_is_rejected() {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function ( $preempt, $args ) use ( $package ) {
			unset( $preempt );
			return $this->stream_response( $args, $package );
		};

		$result = $this->updater->verify_package_download( false, $update->package, null, $this->hook_extra() );
		$this->assertIsString( $result );
		$this->assertSame( hash( 'sha256', $package ), hash_file( 'sha256', $result ) );
		@unlink( $result );

		$this->http_handler = function ( $preempt, $args ) use ( $package ) {
			unset( $preempt );
			return $this->stream_response( $args, strrev( $package ) );
		};
		$this->assertWPError( $this->updater->verify_package_download( false, $update->package, null, $this->hook_extra() ) );
	}

	public function test_download_requires_allowed_redirect_hosts() {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function () {
			return $this->http_response( 302, '', array( 'location' => 'https://example.test/untrusted.zip' ) );
		};
		$this->assertWPError( $this->updater->verify_package_download( false, $update->package, null, $this->hook_extra() ) );

		$this->http_handler = function ( $preempt, $args, $url ) use ( $package ) {
			unset( $preempt );
			if ( false !== strpos( $url, 'github.com/' ) ) {
				return $this->http_response( 302, '', array( 'location' => 'https://objects.githubusercontent.com/release.zip' ) );
			}
			return $this->stream_response( $args, $package );
		};
		$result = $this->updater->verify_package_download( false, $update->package, null, $this->hook_extra() );
		$this->assertIsString( $result );
		@unlink( $result );
	}

	public function test_download_rejects_a_package_url_outside_the_selected_release_asset() {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$update->package = 'https://github.com/Tavorick/eventbridge/archive/refs/tags/v1.3.1.zip';
		$this->store_update( $update );

		$this->assertWPError( $this->updater->verify_package_download( false, $update->package, null, $this->hook_extra() ) );
	}

	public function test_package_source_must_have_expected_root_and_headers() {
		$this->store_update( (object) array( 'new_version' => '1.3.1' ) );
		$root = wp_tempnam( 'eventbridge-updater' );
		@unlink( $root );
		wp_mkdir_p( $root . '/eventbridge' );
		$plugin_file = $root . '/eventbridge/eventbridge.php';
		file_put_contents( $plugin_file, "<?php\n/*\n * Version: 1.3.1\n * Update URI: https://github.com/Tavorick/eventbridge\n */\n" );
		$this->set_direct_filesystem();

		$this->assertSame( $root . '/eventbridge', $this->updater->verify_package_source( $root . '/eventbridge', '', null, $this->hook_extra() ) );
		$this->assertWPError( $this->updater->verify_package_source( $root . '/wrong-root', '', null, $this->hook_extra() ) );

		@unlink( $plugin_file );
		wp_mkdir_p( $root . '/eventbridge/eventbridge' );
		file_put_contents( $root . '/eventbridge/eventbridge/eventbridge.php', "<?php\n/*\n * Version: 1.3.1\n * Update URI: https://github.com/Tavorick/eventbridge\n */\n" );
		$this->assertWPError( $this->updater->verify_package_source( $root . '/eventbridge', '', null, $this->hook_extra() ) );
		@unlink( $root . '/eventbridge/eventbridge/eventbridge.php' );
		@rmdir( $root . '/eventbridge/eventbridge' );

		file_put_contents( $plugin_file, "<?php\n/*\n * Version: 1.3.0\n * Update URI: https://github.com/Tavorick/eventbridge\n */\n" );
		$this->assertWPError( $this->updater->verify_package_source( $root . '/eventbridge', '', null, $this->hook_extra() ) );

		file_put_contents( $plugin_file, "<?php\n/*\n * Version: 1.3.1\n * Update URI: https://example.test/eventbridge\n */\n" );
		$this->assertWPError( $this->updater->verify_package_source( $root . '/eventbridge', '', null, $this->hook_extra() ) );
		@unlink( $plugin_file );
		@rmdir( $root . '/eventbridge' );
		@rmdir( $root );
	}

	private function assert_release_is_rejected( $release ) {
		$this->http_handler = function () use ( $release ) {
			return $this->http_response( 200, wp_json_encode( $release ) );
		};
		$this->assertFalse( $this->updater->filter_update( false, $this->plugin_data(), 'eventbridge/eventbridge.php', array() ) );
	}

	private function plugin_data() {
		return array(
			'Name'      => 'EventBridge',
			'Version'   => '1.3.0',
			'UpdateURI' => 'https://github.com/Tavorick/eventbridge',
		);
	}

	private function make_release( $version, $prerelease, $digest ) {
		$tag      = 'v' . $version;
		$zip_name = 'eventbridge-' . $version . '.zip';

		return array(
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => $prerelease,
			'published_at' => '2026-07-31T10:00:00Z',
			'assets'       => array(
				array(
					'name'                 => $zip_name,
					'state'                => 'uploaded',
					'size'                 => 128,
					'digest'               => 'sha256:' . $digest,
					'browser_download_url' => 'https://github.com/Tavorick/eventbridge/releases/download/' . $tag . '/' . $zip_name,
				),
			),
		);
	}

	private function make_update( $version, $package ) {
		return (object) array(
			'new_version'              => $version,
			'package'                  => 'https://github.com/Tavorick/eventbridge/releases/download/v' . $version . '/eventbridge-' . $version . '.zip',
			'eventbridge_sha256'       => hash( 'sha256', $package ),
			'eventbridge_package_size' => strlen( $package ),
		);
	}

	private function stream_response( $args, $contents ) {
		file_put_contents( $args['filename'], $contents );
		return $this->http_response( 200, '' );
	}

	private function http_response( $status, $body, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
		);
	}

	private function store_update( $update ) {
		$transient           = new stdClass();
		$transient->response = array( 'eventbridge/eventbridge.php' => $update );
		set_site_transient( 'update_plugins', $transient );
	}

	private function hook_extra() {
		return array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'eventbridge/eventbridge.php' );
	}

	private function set_direct_filesystem() {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		global $wp_filesystem;
		$wp_filesystem = new WP_Filesystem_Direct( false );
	}
}
