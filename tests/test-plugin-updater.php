<?php

class EventBridge_Plugin_Updater_Test extends WP_UnitTestCase {
	private $updater;
	private $http_handler;
	private $plugin_upgrader;
	private $temporary_paths;

	public function set_up() {
		parent::set_up();
		$this->updater      = new EventBridge_Plugin_Updater();
		$this->http_handler = null;
		$this->temporary_paths = array();
		$this->plugin_upgrader = $this->make_plugin_upgrader();
		delete_site_transient( 'update_plugins' );
		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10 );
		delete_site_transient( 'update_plugins' );
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			$this->remove_tree( $path );
		}
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

	/**
	 * @dataProvider self_update_hook_provider
	 */
	public function test_all_supported_core_self_update_hook_shapes_are_verified( $hook_extra ) {
		$package_file = $this->make_package_file( 'verified package' );
		$update       = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );

		$this->assertSame(
			$package_file,
			$this->updater->verify_package_download( $package_file, $update->package, $this->plugin_upgrader, $hook_extra )
		);
	}

	public function self_update_hook_provider() {
		return array(
			'WordPress 5.8 AJAX, bulk and WP-CLI' => array( array( 'plugin' => 'eventbridge/eventbridge.php' ) ),
			'WordPress 7 AJAX, bulk and WP-CLI' => array(
				array(
					'plugin'      => 'eventbridge/eventbridge.php',
					'temp_backup' => array( 'slug' => 'eventbridge', 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins' ),
				),
			),
			'direct and automatic update' => array( $this->hook_extra() ),
			'WordPress 7 direct and automatic update' => array(
				array(
					'plugin'      => 'eventbridge/eventbridge.php',
					'type'        => 'plugin',
					'action'      => 'update',
					'temp_backup' => array( 'slug' => 'eventbridge', 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins' ),
				),
			),
		);
	}

	public function test_manual_install_is_passed_through_even_with_an_eventbridge_transient() {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );
		$manual_package = $this->make_package_file( 'manual package' );

		$this->assertFalse(
			$this->updater->verify_package_download(
				false,
				$manual_package,
				$this->plugin_upgrader,
				array( 'type' => 'plugin', 'action' => 'install' )
			)
		);
	}

	public function test_another_plugin_is_passed_through_even_with_eventbridge_package_data() {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );

		$this->assertFalse(
			$this->updater->verify_package_download(
				false,
				$update->package,
				$this->plugin_upgrader,
				array( 'plugin' => 'dummy/dummy.php', 'type' => 'plugin', 'action' => 'update' )
			)
		);
	}

	/**
	 * @dataProvider conflicting_hook_provider
	 */
	public function test_conflicting_eventbridge_hook_metadata_fails_closed( $hook_extra ) {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );

		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $hook_extra )
		);
	}

	public function conflicting_hook_provider() {
		return array(
			'install action with EventBridge identity' => array(
				array( 'plugin' => 'eventbridge/eventbridge.php', 'type' => 'plugin', 'action' => 'install' ),
			),
			'wrong type' => array(
				array( 'plugin' => 'eventbridge/eventbridge.php', 'type' => 'theme', 'action' => 'update' ),
			),
			'unknown action' => array(
				array( 'plugin' => 'eventbridge/eventbridge.php', 'action' => 'downgrade' ),
			),
		);
	}

	public function test_eventbridge_identity_with_a_non_plugin_upgrader_fails_closed() {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );

		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, new stdClass(), array( 'plugin' => 'eventbridge/eventbridge.php' ) )
		);
	}

	public function test_unknown_generic_flow_passes_through_without_eventbridge_evidence() {
		$this->assertFalse(
			$this->updater->verify_package_download( false, 'https://example.test/package.zip', $this->plugin_upgrader, array() )
		);
	}

	public function test_unknown_flow_with_the_canonical_transient_package_fails_closed() {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );

		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, array() )
		);

		unset( $update->eventbridge_sha256 );
		$this->store_update( $update );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, array() )
		);

		foreach ( array( 'plugin', 'id', 'new_version' ) as $missing_field ) {
			$incomplete = $this->make_update( '1.3.1', 'verified package' );
			unset( $incomplete->{$missing_field} );
			$this->store_update( $incomplete );
			$this->assert_verification_error(
				$this->updater->verify_package_download( false, $incomplete->package, $this->plugin_upgrader, array() )
			);
		}
	}

	public function test_missing_transient_or_response_record_fails_closed_for_eventbridge() {
		$package = $this->canonical_package( '1.3.1' );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $package, $this->plugin_upgrader, $this->hook_extra() )
		);

		$transient           = new stdClass();
		$transient->response = array();
		set_site_transient( 'update_plugins', $transient );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	/**
	 * @dataProvider invalid_update_metadata_provider
	 */
	public function test_invalid_or_missing_update_metadata_fails_closed( $property, $value, $remove ) {
		$contents = 'verified package';
		$update   = $this->make_update( '1.3.1', $contents );
		if ( $remove ) {
			unset( $update->{$property} );
		} else {
			$update->{$property} = $value;
		}
		$this->store_update( $update );

		$this->assert_verification_error(
			$this->updater->verify_package_download( $this->make_package_file( $contents ), $this->canonical_package( '1.3.1' ), $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function invalid_update_metadata_provider() {
		return array(
			'missing plugin' => array( 'plugin', null, true ),
			'wrong plugin' => array( 'plugin', 'other/other.php', false ),
			'missing id' => array( 'id', null, true ),
			'wrong id' => array( 'id', 'https://example.test/eventbridge', false ),
			'missing version' => array( 'version', null, true ),
			'missing new_version' => array( 'new_version', null, true ),
			'invalid version' => array( 'version', '1.3', false ),
			'contradicting versions' => array( 'new_version', '1.3.2', false ),
			'wrong package' => array( 'package', 'https://github.com/Tavorick/eventbridge/archive/refs/tags/v1.3.1.zip', false ),
			'missing digest' => array( 'eventbridge_sha256', null, true ),
			'uppercase digest' => array( 'eventbridge_sha256', str_repeat( 'A', 64 ), false ),
			'short digest' => array( 'eventbridge_sha256', str_repeat( 'a', 63 ), false ),
			'missing size' => array( 'eventbridge_package_size', null, true ),
			'string size' => array( 'eventbridge_package_size', '16', false ),
			'float size' => array( 'eventbridge_package_size', 16.0, false ),
			'zero size' => array( 'eventbridge_package_size', 0, false ),
			'negative size' => array( 'eventbridge_package_size', -16, false ),
			'oversized package' => array( 'eventbridge_package_size', 20971521, false ),
		);
	}

	public function test_array_update_record_with_strict_metadata_is_accepted() {
		$contents = 'verified package';
		$update   = $this->make_update( '1.3.1', $contents );
		$this->store_update( (array) $update );
		$package_file = $this->make_package_file( $contents );

		$this->assertSame(
			$package_file,
			$this->updater->verify_package_download( $package_file, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_update_transient_itself_must_be_an_object_with_a_response_array() {
		$update = $this->make_update( '1.3.1', 'verified package' );
		set_site_transient( 'update_plugins', array( 'response' => array( 'eventbridge/eventbridge.php' => $update ) ) );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);

		$transient           = new stdClass();
		$transient->response = 'not-an-array';
		set_site_transient( 'update_plugins', $transient );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_downgrade_and_unapproved_prerelease_metadata_fail_closed() {
		$downgrade          = $this->make_update( '1.2.9', 'verified package' );
		$downgrade->package = $this->canonical_package( '1.2.9' );
		$this->store_update( $downgrade );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $downgrade->package, $this->plugin_upgrader, $this->hook_extra() )
		);

		$prerelease = $this->make_update( '1.3.1-rc.1', 'verified package' );
		$this->store_update( $prerelease );
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $prerelease->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_staging_prerelease_metadata_is_accepted_when_explicitly_enabled() {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' !== WP_ENVIRONMENT_TYPE ) {
			$this->markTestSkipped( 'The test configuration fixes a non-staging environment type.' );
		}
		if ( defined( 'EVENTBRIDGE_ALLOW_PRERELEASES' ) && true !== EVENTBRIDGE_ALLOW_PRERELEASES ) {
			$this->markTestSkipped( 'Prereleases are explicitly disabled by the test configuration.' );
		}

		$previous_environment = getenv( 'WP_ENVIRONMENT_TYPE' );
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );
		if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
			define( 'WP_RUN_CORE_TESTS', true );
		}
		if ( ! defined( 'EVENTBRIDGE_ALLOW_PRERELEASES' ) ) {
			define( 'EVENTBRIDGE_ALLOW_PRERELEASES', true );
		}
		$this->assertSame( 'staging', wp_get_environment_type() );

		try {
			$contents = 'verified prerelease package';
			$update   = $this->make_update( '1.3.1-rc.1', $contents );
			$file     = $this->make_package_file( $contents );
			$this->store_update( $update );
			$this->assertSame(
				$file,
				$this->updater->verify_package_download( $file, $update->package, $this->plugin_upgrader, $this->hook_extra() )
			);
		} finally {
			$this->restore_environment_variable( 'WP_ENVIRONMENT_TYPE', $previous_environment );
		}
	}

	/**
	 * @dataProvider invalid_callback_package_provider
	 */
	public function test_callback_package_must_equal_the_canonical_transient_url( $package ) {
		$update = $this->make_update( '1.3.1', 'verified package' );
		$this->store_update( $update );

		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function invalid_callback_package_provider() {
		return array(
			'HTTP' => array( 'http://github.com/Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'userinfo' => array( 'https://user@github.com/Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'explicit default port' => array( 'https://github.com:443/Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'alternate port' => array( 'https://github.com:444/Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'wrong host' => array( 'https://example.test/Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'trailing dot host' => array( 'https://github.com./Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'suffix host' => array( 'https://github.com.example.test/Tavorick/eventbridge/releases/download/v1.3.1/eventbridge-1.3.1.zip' ),
			'archive fallback' => array( 'https://github.com/Tavorick/eventbridge/archive/refs/tags/v1.3.1.zip' ),
		);
	}

	public function test_verified_download_is_returned_and_same_size_corruption_is_rejected() {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function ( $preempt, $args ) use ( $package ) {
			unset( $preempt );
			return $this->stream_response( $args, $package );
		};

		$result = $this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() );
		$this->assertIsString( $result );
		$this->assertSame( hash( 'sha256', $package ), hash_file( 'sha256', $result ) );
		@unlink( $result );

		$this->http_handler = function ( $preempt, $args ) use ( $package ) {
			unset( $preempt );
			return $this->stream_response( $args, strrev( $package ) );
		};
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	/**
	 * @dataProvider allowed_redirect_provider
	 */
	public function test_allowed_release_asset_redirect_hosts_are_accepted( $redirect_url ) {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function ( $preempt, $args, $url ) use ( $package, $update, $redirect_url ) {
			unset( $preempt );
			if ( $update->package === $url ) {
				return $this->http_response( 302, '', array( 'location' => $redirect_url ) );
			}
			return $this->stream_response( $args, $package );
		};

		$result = $this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() );
		$this->assertIsString( $result );
		@unlink( $result );
	}

	public function allowed_redirect_provider() {
		return array(
			'objects host with signed query' => array( 'https://objects.githubusercontent.com/release.zip?sp=r&sig=test%2Bvalue' ),
			'release assets host with signed query' => array( 'https://release-assets.githubusercontent.com/release.zip?se=2030-01-01&sig=test' ),
		);
	}

	/**
	 * @dataProvider invalid_redirect_provider
	 */
	public function test_invalid_redirects_fail_closed( $redirect_url ) {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function () use ( $redirect_url ) {
			return $this->http_response( 302, '', array( 'location' => $redirect_url ) );
		};

		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function invalid_redirect_provider() {
		return array(
			'relative' => array( '/relative/release.zip' ),
			'wrong host' => array( 'https://example.test/release.zip' ),
			'trailing dot host' => array( 'https://objects.githubusercontent.com./release.zip' ),
			'suffix host' => array( 'https://objects.githubusercontent.com.example.test/release.zip' ),
			'userinfo' => array( 'https://user@objects.githubusercontent.com/release.zip' ),
			'alternate port' => array( 'https://objects.githubusercontent.com:444/release.zip' ),
			'plain HTTP' => array( 'http://objects.githubusercontent.com/release.zip' ),
		);
	}

	public function test_too_many_download_redirects_fail_closed() {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function () {
			return $this->http_response( 302, '', array( 'location' => 'https://objects.githubusercontent.com/release.zip' ) );
		};

		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_transport_size_and_digest_failures_are_generic() {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$this->http_handler = function () {
			return new WP_Error( 'http_request_failed', 'secret transport details', array( 'body' => 'secret' ) );
		};
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);

		$this->http_handler = function ( $preempt, $args ) {
			unset( $preempt );
			return $this->stream_response( $args, 'wrong size' );
		};
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);

		$this->http_handler = function () {
			return $this->http_response( 500, 'server details' );
		};
		$this->assert_verification_error(
			$this->updater->verify_package_download( false, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_upstream_download_error_is_preserved_and_invalid_reply_types_fail_closed() {
		$package  = 'verified package';
		$update   = $this->make_update( '1.3.1', $package );
		$upstream = new WP_Error( 'upstream_failure', 'Upstream failed.' );
		$tree     = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->store_update( $update );
		$this->prime_download_context();

		$this->assertSame(
			$upstream,
			$this->updater->verify_package_download( $upstream, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_verification_error(
			$this->updater->verify_package_download( true, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_verification_error(
			$this->updater->verify_package_download( array(), $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_verification_error(
			$this->updater->verify_package_download( null, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_upstream_local_package_path_is_verified() {
		$package = 'verified package';
		$update  = $this->make_update( '1.3.1', $package );
		$this->store_update( $update );
		$valid = $this->make_package_file( $package );
		$this->assertSame(
			$valid,
			$this->updater->verify_package_download( $valid, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);

		$invalid = $this->make_package_file( strrev( $package ) );
		$this->assert_verification_error(
			$this->updater->verify_package_download( $invalid, $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_verification_error(
			$this->updater->verify_package_download( $invalid . '.missing', $update->package, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_source_requires_a_verified_context_and_the_same_upgrader() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->minimal_hook_extra() )
		);

		$this->prime_download_context( $this->plugin_upgrader, $this->minimal_hook_extra() );
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->make_plugin_upgrader(), $this->minimal_hook_extra() )
		);
	}

	public function test_source_with_a_verified_context_but_missing_hook_identity_fails_closed() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->prime_download_context();

		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, array() )
		);
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_context_cleanup_is_isolated_between_upgrader_instances() {
		$first  = $this->plugin_upgrader;
		$second = $this->make_plugin_upgrader();
		$tree   = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->prime_download_context( $first );
		$this->prime_download_context( $second );

		$upstream = new WP_Error( 'upstream_failure', 'Upstream failed.' );
		$this->assertSame(
			$upstream,
			$this->updater->verify_package_download( $upstream, $this->canonical_package( '1.3.1' ), $first, $this->hook_extra() )
		);
		$this->assertSame(
			$tree['source'],
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $second, $this->hook_extra() )
		);
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $first, $this->hook_extra() )
		);
	}

	public function test_source_context_is_bound_to_unchanged_transient_metadata() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->prime_download_context();
		$update = $this->make_update( '1.3.1', 'verified package' );
		$update->eventbridge_sha256 = str_repeat( 'b', 64 );
		$this->store_update( $update );
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);

		$this->prime_download_context();
		delete_site_transient( 'update_plugins' );
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_source_context_is_consumed_on_success() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->prime_download_context();
		$source_with_slash = trailingslashit( $tree['source'] );
		$remote_with_slash = trailingslashit( $tree['remote'] );

		$this->assertSame(
			$source_with_slash,
			$this->updater->verify_package_source( $source_with_slash, $remote_with_slash, $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_a_later_bulk_item_clears_stale_context_for_the_same_upgrader() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->prime_download_context();

		$this->assertFalse(
			$this->updater->verify_package_download(
				false,
				'https://example.test/dummy.zip',
				$this->plugin_upgrader,
				array( 'plugin' => 'dummy/dummy.php' )
			)
		);
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_source_context_cannot_be_reused_with_conflicting_hook_metadata() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		$this->prime_download_context();

		$this->assert_source_error(
			$this->updater->verify_package_source(
				$tree['source'],
				$tree['remote'],
				$this->plugin_upgrader,
				array( 'plugin' => 'eventbridge/eventbridge.php', 'type' => 'theme', 'action' => 'update' )
			)
		);
	}

	public function test_upstream_source_error_is_preserved_and_clears_context() {
		$tree     = $this->make_source_tree();
		$upstream = new WP_Error( 'upstream_source_failure', 'Upstream source check failed.' );
		$this->set_direct_filesystem();
		$this->prime_download_context();

		$this->assertSame(
			$upstream,
			$this->updater->verify_package_source( $upstream, $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_manual_install_and_other_plugin_sources_pass_through() {
		$source = '/temporary/eventbridge';
		$this->store_update( $this->make_update( '1.3.1', 'verified package' ) );
		$this->assertSame(
			$source,
			$this->updater->verify_package_source( $source, '/temporary', $this->plugin_upgrader, array( 'type' => 'plugin', 'action' => 'install' ) )
		);
		$this->assertSame(
			$source,
			$this->updater->verify_package_source( $source, '/temporary', $this->plugin_upgrader, array( 'plugin' => 'dummy/dummy.php' ) )
		);
	}

	/**
	 * @dataProvider allowed_runtime_path_provider
	 */
	public function test_each_runtime_allowlist_path_is_accepted( $relative_path ) {
		$tree = $this->make_source_tree();
		if ( 'eventbridge.php' !== $relative_path ) {
			$this->write_fixture_file( $tree['source'] . '/' . $relative_path, 'runtime asset' );
		}
		$this->set_direct_filesystem();
		$this->prime_download_context();

		$this->assertSame(
			$tree['source'],
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function allowed_runtime_path_provider() {
		return array(
			'root plugin' => array( 'eventbridge.php' ),
			'includes PHP' => array( 'includes/class-example.php' ),
			'nested includes PHP' => array( 'includes/nested/class-example.php' ),
			'CSS' => array( 'assets/css/admin.css' ),
			'nested CSS' => array( 'assets/css/admin/editor.css' ),
			'JavaScript' => array( 'assets/js/admin.js' ),
			'nested JavaScript' => array( 'assets/js/admin/editor.js' ),
			'MO translation' => array( 'languages/eventbridge-nl_NL.mo' ),
			'JSON translation' => array( 'languages/eventbridge-nl_NL.json' ),
			'PHP translation' => array( 'languages/eventbridge-nl_NL.l10n.php' ),
		);
	}

	public function test_runtime_examples_match_the_release_allowlist_contract() {
		$lines = array_values(
			array_filter(
				file( dirname( __DIR__ ) . '/tools/release/allowlist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ),
				function ( $line ) {
					return 0 !== strpos( ltrim( $line ), '#' );
				}
			)
		);
		$this->assertSame(
			array(
				'eventbridge.php',
				'includes/**/*.php',
				'assets/css/**/*.css',
				'assets/js/**/*.js',
				'languages/**/*.mo',
				'languages/**/*.json',
				'languages/**/*.l10n.php',
			),
			$lines
		);
	}

	/**
	 * @dataProvider forbidden_runtime_file_provider
	 */
	public function test_unexpected_or_executable_files_are_rejected( $relative_path ) {
		$tree = $this->make_source_tree();
		$this->write_fixture_file( $tree['source'] . '/' . $relative_path, '<?php echo "unexpected";' );
		$this->assert_tree_is_rejected( $tree );
	}

	public function forbidden_runtime_file_provider() {
		return array(
			'extra root PHP' => array( 'backdoor.php' ),
			'PHP in CSS' => array( 'assets/css/backdoor.php' ),
			'PHP in JavaScript' => array( 'assets/js/backdoor.php' ),
			'PHTML' => array( 'includes/backdoor.phtml' ),
			'PHAR' => array( 'includes/backdoor.phar' ),
			'uppercase PHP extension' => array( 'includes/backdoor.PHP' ),
			'htaccess' => array( '.htaccess' ),
			'user ini' => array( '.user.ini' ),
			'web.config' => array( 'web.config' ),
			'unexpected text' => array( 'readme.txt' ),
		);
	}

	/**
	 * @dataProvider forbidden_runtime_directory_provider
	 */
	public function test_development_directories_are_rejected_even_when_empty( $relative_path ) {
		$tree = $this->make_source_tree();
		wp_mkdir_p( $tree['source'] . '/' . $relative_path );
		$this->assert_tree_is_rejected( $tree );
	}

	public function forbidden_runtime_directory_provider() {
		return array(
			'.git' => array( '.git' ),
			'.github' => array( '.github' ),
			'tests' => array( 'tests' ),
			'tools' => array( 'tools' ),
			'vendor' => array( 'vendor' ),
			'dist' => array( 'dist' ),
			'nested vendor' => array( 'includes/vendor' ),
			'case variant' => array( 'includes/Vendor' ),
			'unexpected empty directory' => array( 'docs' ),
		);
	}

	public function test_valid_root_plugin_plus_a_nested_eventbridge_directory_is_rejected() {
		$tree = $this->make_source_tree();
		$this->write_fixture_file( $tree['source'] . '/eventbridge/eventbridge.php', $this->plugin_header( '1.3.1' ) );

		$this->assertFileExists( $tree['source'] . '/eventbridge.php' );
		$this->assert_tree_is_rejected( $tree );

		$tree = $this->make_source_tree();
		$this->write_fixture_file( $tree['source'] . '/includes/EventBridge/class-nested.php', '<?php' );
		$this->assert_tree_is_rejected( $tree );
	}

	public function test_source_root_structure_and_containment_are_enforced() {
		$tree = $this->make_source_tree();
		wp_mkdir_p( $tree['remote'] . '/another-root' );
		$this->assert_tree_is_rejected( $tree );

		$outside = $this->make_source_tree();
		$remote  = $this->make_temporary_directory( 'eventbridge-other-remote' );
		$this->set_direct_filesystem();
		$this->prime_download_context();
		$this->assert_source_error(
			$this->updater->verify_package_source( $outside['source'], $remote, $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_missing_plugin_file_or_mismatching_headers_are_rejected() {
		$tree = $this->make_source_tree();
		@unlink( $tree['plugin'] );
		$this->assert_tree_is_rejected( $tree );

		$tree = $this->make_source_tree( '1.3.0' );
		$this->assert_tree_is_rejected( $tree );

		$tree = $this->make_source_tree( '1.3.1', 'https://example.test/eventbridge' );
		$this->assert_tree_is_rejected( $tree );
	}

	public function test_plugin_headers_after_the_wordpress_header_window_are_rejected() {
		$tree = $this->make_source_tree();
		file_put_contents(
			$tree['plugin'],
			"<?php\n/* filler */\n" . str_repeat( 'x', 8192 ) . "\n" . $this->plugin_header( '1.3.1' )
		);

		$this->assert_tree_is_rejected( $tree );
	}

	public function test_runtime_scan_failure_consumes_the_verified_context() {
		$tree = $this->make_source_tree();
		$this->write_fixture_file( $tree['source'] . '/unexpected.php', '<?php' );
		$this->set_direct_filesystem();
		$this->prime_download_context();

		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
		@unlink( $tree['source'] . '/unexpected.php' );
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function test_unreadable_directory_listing_is_rejected() {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		global $wp_filesystem;
		$wp_filesystem = new class( false ) extends WP_Filesystem_Direct {
			public function dirlist( $path, $include_hidden = true, $recursive = false ) {
				unset( $path, $include_hidden, $recursive );
				return false;
			}
		};
		$this->prime_download_context();

		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	/**
	 * @dataProvider invalid_filesystem_type_provider
	 */
	public function test_unknown_filesystem_entry_type_is_rejected( $type ) {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		global $wp_filesystem;
		$wp_filesystem = new class( $type ) extends WP_Filesystem_Base {
			private $type;

			public function __construct( $type ) {
				$this->type = $type;
			}

			public function dirlist( $path, $include_hidden = true, $recursive = false ) {
				unset( $include_hidden, $recursive );
				if ( 'eventbridge' === basename( untrailingslashit( wp_normalize_path( $path ) ) ) ) {
					return array( 'mystery' => array( 'name' => 'mystery', 'type' => $this->type ) );
				}
				return array( 'eventbridge' => array( 'name' => 'eventbridge', 'type' => 'd' ) );
			}
		};
		$this->prime_download_context();

		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function invalid_filesystem_type_provider() {
		return array(
			'link' => array( 'l' ),
			'unknown' => array( '?' ),
		);
	}

	/**
	 * @dataProvider invalid_filesystem_segment_provider
	 */
	public function test_invalid_filesystem_entry_segments_are_rejected( $invalid_name ) {
		$tree = $this->make_source_tree();
		$this->set_direct_filesystem();
		global $wp_filesystem;
		$wp_filesystem = new class( $invalid_name ) extends WP_Filesystem_Base {
			private $invalid_name;

			public function __construct( $invalid_name ) {
				$this->invalid_name = $invalid_name;
			}

			public function dirlist( $path, $include_hidden = true, $recursive = false ) {
				unset( $include_hidden, $recursive );
				if ( 'eventbridge' === basename( untrailingslashit( wp_normalize_path( $path ) ) ) ) {
					return array( $this->invalid_name => array( 'name' => $this->invalid_name, 'type' => 'f' ) );
				}
				return array( 'eventbridge' => array( 'name' => 'eventbridge', 'type' => 'd' ) );
			}
		};
		$this->prime_download_context();

		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	public function invalid_filesystem_segment_provider() {
		return array(
			'empty' => array( '' ),
			'current directory' => array( '.' ),
			'parent directory' => array( '..' ),
			'forward slash' => array( 'nested/file.php' ),
			'backslash' => array( 'nested\\file.php' ),
			'NUL' => array( "bad\0file.php" ),
		);
	}

	public function test_direct_filesystem_symlink_is_rejected() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable.' );
		}
		$tree   = $this->make_source_tree();
		$target = $this->make_package_file( '<?php echo "target";' );
		wp_mkdir_p( $tree['source'] . '/includes' );
		$link = $tree['source'] . '/includes/link.php';
		if ( ! @symlink( $target, $link ) ) {
			$this->markTestSkipped( 'The test filesystem does not permit symlinks.' );
		}

		$this->assert_tree_is_rejected( $tree );
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
			'plugin'                   => 'eventbridge/eventbridge.php',
			'id'                       => 'https://github.com/Tavorick/eventbridge',
			'version'                  => $version,
			'new_version'              => $version,
			'package'                  => $this->canonical_package( $version ),
			'eventbridge_sha256'       => hash( 'sha256', $package ),
			'eventbridge_package_size' => strlen( $package ),
		);
	}

	private function canonical_package( $version ) {
		return 'https://github.com/Tavorick/eventbridge/releases/download/v' . $version . '/eventbridge-' . $version . '.zip';
	}

	private function make_plugin_upgrader() {
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		return new Plugin_Upgrader();
	}

	private function make_package_file( $contents ) {
		$file = wp_tempnam( 'eventbridge-package.zip' );
		file_put_contents( $file, $contents );
		$this->temporary_paths[] = $file;
		return $file;
	}

	private function make_temporary_directory( $name ) {
		$directory = wp_tempnam( $name );
		@unlink( $directory );
		wp_mkdir_p( $directory );
		$this->temporary_paths[] = $directory;
		return untrailingslashit( wp_normalize_path( $directory ) );
	}

	private function make_source_tree( $version = '1.3.1', $update_uri = 'https://github.com/Tavorick/eventbridge' ) {
		$remote = $this->make_temporary_directory( 'eventbridge-extracted' );
		$source = $remote . '/eventbridge';
		wp_mkdir_p( $source );
		$plugin_file = $source . '/eventbridge.php';
		file_put_contents( $plugin_file, $this->plugin_header( $version, $update_uri ) );

		return array(
			'remote' => $remote,
			'source' => $source,
			'plugin' => $plugin_file,
		);
	}

	private function plugin_header( $version, $update_uri = 'https://github.com/Tavorick/eventbridge' ) {
		return "<?php\n/*\n * Plugin Name: EventBridge\n * Version: " . $version . "\n * Update URI: " . $update_uri . "\n */\n";
	}

	private function write_fixture_file( $path, $contents ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents );
	}

	private function prime_download_context( $upgrader = null, $hook_extra = null ) {
		$upgrader   = null === $upgrader ? $this->plugin_upgrader : $upgrader;
		$hook_extra = null === $hook_extra ? $this->hook_extra() : $hook_extra;
		$contents   = 'verified package';
		$update     = $this->make_update( '1.3.1', $contents );
		$file       = $this->make_package_file( $contents );
		$this->store_update( $update );

		$this->assertSame(
			$file,
			$this->updater->verify_package_download( $file, $update->package, $upgrader, $hook_extra )
		);
	}

	private function assert_tree_is_rejected( $tree ) {
		$this->set_direct_filesystem();
		$this->prime_download_context();
		$this->assert_source_error(
			$this->updater->verify_package_source( $tree['source'], $tree['remote'], $this->plugin_upgrader, $this->hook_extra() )
		);
	}

	private function assert_verification_error( $error ) {
		$this->assertWPError( $error );
		$this->assertSame( 'eventbridge_update_verification_failed', $error->get_error_code() );
		$this->assertSame( 'The EventBridge update could not be verified.', $error->get_error_message() );
		$this->assertNull( $error->get_error_data() );
	}

	private function assert_source_error( $error ) {
		$this->assertWPError( $error );
		$this->assertSame( 'eventbridge_update_package_invalid', $error->get_error_code() );
		$this->assertSame( 'The EventBridge update package could not be verified.', $error->get_error_message() );
		$this->assertNull( $error->get_error_data() );
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

	private function minimal_hook_extra() {
		return array( 'plugin' => 'eventbridge/eventbridge.php' );
	}

	private function set_direct_filesystem() {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		global $wp_filesystem;
		$wp_filesystem = new WP_Filesystem_Direct( false );
	}

	private function restore_environment_variable( $name, $value ) {
		if ( false === $value ) {
			putenv( $name );
			return;
		}
		putenv( $name . '=' . $value );
	}

	private function remove_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->remove_tree( $path . DIRECTORY_SEPARATOR . $entry );
			}
		}
		@rmdir( $path );
	}
}
