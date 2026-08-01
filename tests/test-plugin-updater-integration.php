<?php

/**
 * Exercises EventBridge's updater callbacks through the real WordPress upgrader.
 *
 * All plugin destinations used here live inside the disposable WordPress core
 * installed for the PHPUnit job. The repository checkout is only loaded by the
 * test bootstrap and is never used as an upgrader destination.
 *
 * @group updater-integration
 */
class EventBridge_Plugin_Updater_Integration_Test extends WP_UnitTestCase {
	const PLUGIN  = 'eventbridge/eventbridge.php';
	const VERSION = '1.3.1';

	private $work_dir;
	private $eventbridge_dir;
	private $http_packages = array();
	private $requested_urls = array();
	private $old_active_plugins = array();
	private $last_upgrade_messages = array();
	private $removed_process_complete_hooks = array();

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The updater integration tests require ZipArchive.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		$this->disable_process_complete_refreshes();

		$this->work_dir       = trailingslashit( get_temp_dir() ) . 'eventbridge-updater-' . wp_generate_uuid4();
		$this->eventbridge_dir = trailingslashit( WP_PLUGIN_DIR ) . 'eventbridge';
		$this->requested_urls = array();
		$this->old_active_plugins = (array) get_option( 'active_plugins', array() );

		wp_mkdir_p( $this->work_dir );
		$this->remove_tree( $this->eventbridge_dir );
		$this->remove_tree( trailingslashit( WP_PLUGIN_DIR ) . 'eventbridge-dummy' );
		$this->create_installed_plugin( 'eventbridge', 'eventbridge.php', EVENTBRIDGE_VERSION, 'baseline' );

		add_filter( 'filesystem_method', array( $this, 'force_direct_filesystem' ), 999 );
		$this->assertTrue( WP_Filesystem(), 'The disposable integration filesystem must initialize.' );
		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), PHP_INT_MAX, 3 );
	}

	public function tear_down() {
		remove_filter( 'filesystem_method', array( $this, 'force_direct_filesystem' ), 999 );
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), PHP_INT_MAX );
		remove_filter( 'automatic_updater_disabled', '__return_false', 999 );
		remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false', 999 );
		remove_filter( 'auto_update_plugin', '__return_true', 999 );
		remove_filter( 'file_mod_allowed', '__return_true', 999 );
		$this->restore_process_complete_refreshes();

		update_option( 'active_plugins', $this->old_active_plugins );
		delete_site_transient( 'update_plugins' );
		$this->remove_tree( $this->eventbridge_dir );
		$this->remove_tree( trailingslashit( WP_PLUGIN_DIR ) . 'eventbridge-dummy' );
		$this->remove_tree( trailingslashit( WP_CONTENT_DIR ) . 'upgrade-temp-backup/plugins/eventbridge' );
		$this->remove_tree( trailingslashit( WP_CONTENT_DIR ) . 'upgrade-temp-backup/plugins/eventbridge-dummy' );
		$this->remove_tree( $this->work_dir );
		$this->http_packages = array();

		parent::tear_down();
	}

	public function force_direct_filesystem() {
		return 'direct';
	}

	public function mock_http_request( $preempt, $args, $url ) {
		unset( $preempt );
		$this->requested_urls[] = $url;

		if ( ! isset( $this->http_packages[ $url ] ) ) {
			return new WP_Error( 'eventbridge_test_unexpected_http', 'The updater test blocked an unexpected HTTP request.' );
		}

		$bytes = $this->http_packages[ $url ];
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			if ( strlen( $bytes ) !== file_put_contents( $args['filename'], $bytes ) ) {
				return new WP_Error( 'eventbridge_test_stream_failed' );
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

	public function test_bulk_upgrade_accepts_the_minimal_per_plugin_hook_shape() {
		$zip     = $this->create_eventbridge_zip( 'valid-bulk.zip', self::VERSION, array( 'assets/js/integration.js' => 'window.eventbridgeUpdater = "bulk";' ) );
		$update  = $this->store_eventbridge_update( $zip );
		$results = $this->run_bulk_upgrade( array( self::PLUGIN ) );

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( self::PLUGIN, $results );
		$this->assertFalse( is_wp_error( $results[ self::PLUGIN ] ) );
		$this->assertSame( self::VERSION, $this->installed_version( self::PLUGIN ) );
		$this->assertFileExists( $this->eventbridge_dir . '/assets/js/integration.js' );
		$this->assertSame( $update->package, $this->canonical_package_url( self::VERSION ) );
	}

	public function test_bulk_upgrade_rejects_a_package_with_multiple_roots() {
		$zip = $this->create_zip(
			'multiple-roots.zip',
			array(
				'eventbridge/eventbridge.php' => $this->plugin_file_contents( 'EventBridge', self::VERSION, EventBridge_Plugin_Updater::UPDATE_URI, 'multiple-roots' ),
				'second-root/marker.txt'      => 'This second package root must never be installed.',
				// Let core's priority-10 package check pass so EventBridge priority 20 verifies the roots.
				'decoy.php'                   => $this->plugin_file_contents( 'EventBridge root-policy decoy', self::VERSION, 'https://example.test/decoy', 'multiple-roots-decoy' ),
			)
		);
		$update = $this->store_eventbridge_update( $zip );
		$before = $this->manifest_hash( $this->eventbridge_dir );

		$results = $this->run_bulk_upgrade( array( self::PLUGIN ) );

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( self::PLUGIN, $results );
		$this->assertWPError( $results[ self::PLUGIN ] );
		$this->assertSame( 'eventbridge_update_package_invalid', $results[ self::PLUGIN ]->get_error_code() );
		$this->assertContains( $update->package, $this->requested_urls );
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertSame( EVENTBRIDGE_VERSION, $this->installed_version( self::PLUGIN ) );
		$this->assertDirectoryDoesNotExist( trailingslashit( WP_PLUGIN_DIR ) . 'second-root' );
	}

	public function test_direct_upgrade_rejects_same_size_changed_bytes_before_extraction() {
		$pair   = $this->create_same_size_zip_pair();
		$before = $this->manifest_hash( $this->eventbridge_dir );
		$this->store_eventbridge_update( $pair['a'], $pair['b'] );

		$result = $this->run_direct_upgrade( self::PLUGIN );

		$this->assertNotTrue( $result );
		$this->assertStringContainsString( 'The EventBridge update could not be verified.', implode( "\n", $this->last_upgrade_messages ) );
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertSame( EVENTBRIDGE_VERSION, $this->installed_version( self::PLUGIN ) );
		$this->assertFileDoesNotExist( $this->eventbridge_dir . '/assets/js/toctou.js' );
	}

	public function test_direct_upgrade_fails_when_the_transient_disappears_before_download() {
		$zip = $this->create_eventbridge_zip(
			'missing-transient.zip',
			self::VERSION,
			array( 'includes/missing-transient.php' => "<?php\n// This file must never reach the destination.\n" )
		);
		$this->store_eventbridge_update( $zip );
		$before = $this->manifest_hash( $this->eventbridge_dir );
		$delete_transient = function ( $reply, $package, $upgrader, $hook_extra ) {
			unset( $package, $upgrader );
			if ( is_array( $hook_extra )
				&& isset( $hook_extra['plugin'] )
				&& self::PLUGIN === $hook_extra['plugin']
			) {
				delete_site_transient( 'update_plugins' );
			}
			return $reply;
		};

		add_filter( 'upgrader_pre_download', $delete_transient, 9, 4 );
		try {
			$result = $this->run_direct_upgrade( self::PLUGIN );
		} finally {
			remove_filter( 'upgrader_pre_download', $delete_transient, 9 );
		}

		$this->assertNotTrue( $result );
		$this->assertStringContainsString( 'The EventBridge update could not be verified.', implode( "\n", $this->last_upgrade_messages ) );
		$this->assertSame( array(), $this->requested_urls, 'Missing transient metadata must stop before package HTTP.' );
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertSame( EVENTBRIDGE_VERSION, $this->installed_version( self::PLUGIN ) );
		$this->assertFileDoesNotExist( $this->eventbridge_dir . '/includes/missing-transient.php' );
	}

	public function test_bulk_upgrade_rejects_same_size_changed_bytes_before_extraction() {
		$pair   = $this->create_same_size_zip_pair();
		$before = $this->manifest_hash( $this->eventbridge_dir );
		$this->store_eventbridge_update( $pair['a'], $pair['b'] );

		$results = $this->run_bulk_upgrade( array( self::PLUGIN ) );

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( self::PLUGIN, $results );
		$this->assertWPError( $results[ self::PLUGIN ] );
		$this->assertSame( 'eventbridge_update_verification_failed', $results[ self::PLUGIN ]->get_error_code() );
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertFileDoesNotExist( $this->eventbridge_dir . '/assets/js/toctou.js' );
	}

	public function test_automatic_updater_rejects_same_size_changed_bytes_before_extraction() {
		$pair   = $this->create_same_size_zip_pair();
		$before = $this->manifest_hash( $this->eventbridge_dir );
		$item   = $this->store_eventbridge_update( $pair['a'], $pair['b'] );
		$item->autoupdate = true;
		$this->replace_update_record( self::PLUGIN, $item );
		$this->enable_automatic_updates();

		$automatic = new class() extends WP_Automatic_Updater {
			public function eventbridge_update_results() {
				return $this->update_results;
			}
		};
		$buffer_level    = ob_get_level();
		$buffer_handlers = ob_list_handlers();
		try {
			$result = $automatic->update( 'plugin', $item );
		} finally {
			// Core's early Automatic_Upgrader_Skin error path can leave its first buffer open.
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}
		$this->assertSame( $buffer_level, ob_get_level(), 'The integration test must restore its outer output buffers.' );
		$this->assertSame( $buffer_handlers, ob_list_handlers(), 'The integration test must preserve outer output buffers.' );

		$this->assertNotTrue( $result );
		$update_results = $automatic->eventbridge_update_results();
		$this->assertArrayHasKey( 'plugin', $update_results );
		$this->assertStringContainsString(
			'The EventBridge update could not be verified.',
			implode( "\n", $update_results['plugin'][0]->messages )
		);
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertFileDoesNotExist( $this->eventbridge_dir . '/assets/js/toctou.js' );
	}

	public function test_source_policy_failure_keeps_files_but_direct_flow_can_deactivate_plugin() {
		$zip = $this->create_eventbridge_zip(
			'invalid-source.zip',
			self::VERSION,
			array( 'unexpected.php' => "<?php\n// This file must never reach the destination.\n" )
		);
		$this->store_eventbridge_update( $zip );
		update_option( 'active_plugins', array( self::PLUGIN ) );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
		$before = $this->manifest_hash( $this->eventbridge_dir );

		$result = $this->run_direct_upgrade( self::PLUGIN );

		$this->assertNotTrue( $result );
		$this->assertStringContainsString( 'The EventBridge update package could not be verified.', implode( "\n", $this->last_upgrade_messages ) );
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertFileDoesNotExist( $this->eventbridge_dir . '/unexpected.php' );
		$this->assertFalse( is_plugin_active( self::PLUGIN ), 'Core deactivates an active plugin in the direct non-cron flow before source selection.' );
	}

	public function test_multi_bulk_continues_with_another_plugin_after_eventbridge_fails() {
		$eventbridge_zip = $this->create_eventbridge_zip(
			'invalid-bulk-source.zip',
			self::VERSION,
			array( 'tools/forbidden.php' => "<?php\n" )
		);
		$this->store_eventbridge_update( $eventbridge_zip );

		$dummy_plugin = 'eventbridge-dummy/eventbridge-dummy.php';
		$this->create_installed_plugin( 'eventbridge-dummy', 'eventbridge-dummy.php', '1.0.0', 'old-dummy' );
		$dummy_zip = $this->create_zip(
			'dummy.zip',
			array(
				'eventbridge-dummy/eventbridge-dummy.php' => $this->plugin_file_contents( 'EventBridge Dummy', '1.1.0', 'https://example.test/eventbridge-dummy', 'new-dummy' ),
			)
		);
		$dummy_url = 'https://github.com/example/eventbridge-dummy/releases/download/v1.1.0/eventbridge-dummy-1.1.0.zip';
		$this->http_packages[ $dummy_url ] = file_get_contents( $dummy_zip );
		$this->append_update_record(
			$dummy_plugin,
			(object) array(
				'id'           => 'https://example.test/eventbridge-dummy',
				'slug'         => 'eventbridge-dummy',
				'plugin'       => $dummy_plugin,
				'version'      => '1.1.0',
				'new_version'  => '1.1.0',
				'package'      => $dummy_url,
				'requires'     => '5.8',
				'requires_php' => '7.4',
			)
		);
		$before = $this->manifest_hash( $this->eventbridge_dir );

		$results = $this->run_bulk_upgrade( array( self::PLUGIN, $dummy_plugin ) );

		$this->assertWPError( $results[ self::PLUGIN ] );
		$this->assertFalse( is_wp_error( $results[ $dummy_plugin ] ) );
		$this->assertSame( $before, $this->manifest_hash( $this->eventbridge_dir ) );
		$this->assertSame( '1.1.0', $this->installed_version( $dummy_plugin ) );
	}

	public function test_automatic_updater_installs_a_verified_package() {
		$zip  = $this->create_eventbridge_zip( 'valid-automatic.zip', self::VERSION, array( 'includes/automatic.php' => "<?php\n// Integration marker.\n" ) );
		$item = $this->store_eventbridge_update( $zip );
		$item->autoupdate = true;
		$this->replace_update_record( self::PLUGIN, $item );
		$this->enable_automatic_updates();

		$updater = new WP_Automatic_Updater();
		$result  = $updater->update( 'plugin', $item );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertTrue( (bool) $result );
		$this->assertSame( self::VERSION, $this->installed_version( self::PLUGIN ) );
		$this->assertFileExists( $this->eventbridge_dir . '/includes/automatic.php' );
	}

	public function test_manual_local_overwrite_is_not_bound_to_update_transient() {
		$update_zip = $this->create_eventbridge_zip( 'unused-update.zip', self::VERSION );
		$this->store_eventbridge_update( $update_zip );
		$manual_zip = $this->create_eventbridge_zip( 'manual.zip', '1.3.2', array( 'assets/css/manual.css' => '.manual { display: block; }' ) );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$level    = ob_get_level();
		ob_start();
		try {
			$result = $upgrader->install(
				$manual_zip,
				array(
					'clear_update_cache' => false,
					'overwrite_package'  => true,
				)
			);
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		$this->assertTrue( $result );
		$this->assertSame( '1.3.2', $this->installed_version( self::PLUGIN ) );
		$this->assertFileExists( $this->eventbridge_dir . '/assets/css/manual.css' );
	}

	private function run_direct_upgrade( $plugin ) {
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$level    = ob_get_level();
		ob_start();
		try {
			$result = $upgrader->upgrade( $plugin, array( 'clear_update_cache' => false ) );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
		$this->last_upgrade_messages = $skin->get_upgrade_messages();
		return $result;
	}

	private function run_bulk_upgrade( $plugins ) {
		$skin = new class(
			array(
				'url'     => '',
				'nonce'   => '',
				'plugins' => $plugins,
			)
		) extends Bulk_Plugin_Upgrader_Skin {
			public function flush_output() {
				// Keep the integration test's output buffer intact.
			}
		};
		$upgrader = new Plugin_Upgrader( $skin );
		$level    = ob_get_level();
		ob_start();
		try {
			$result = $upgrader->bulk_upgrade( $plugins, array( 'clear_update_cache' => false ) );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
		return $result;
	}

	private function enable_automatic_updates() {
		add_filter( 'automatic_updater_disabled', '__return_false', 999 );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_false', 999 );
		add_filter( 'auto_update_plugin', '__return_true', 999 );
		add_filter( 'file_mod_allowed', '__return_true', 999 );
	}

	private function disable_process_complete_refreshes() {
		$hooks = array(
			array( 'wp_version_check', 10, 0 ),
			array( 'wp_update_plugins', 10, 0 ),
			array( 'wp_update_themes', 10, 0 ),
			array( array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20, 1 ),
		);
		foreach ( $hooks as $hook ) {
			if ( false !== has_action( 'upgrader_process_complete', $hook[0] ) ) {
				remove_action( 'upgrader_process_complete', $hook[0], $hook[1] );
				$this->removed_process_complete_hooks[] = $hook;
			}
		}
	}

	private function restore_process_complete_refreshes() {
		foreach ( $this->removed_process_complete_hooks as $hook ) {
			add_action( 'upgrader_process_complete', $hook[0], $hook[1], $hook[2] );
		}
		$this->removed_process_complete_hooks = array();
	}

	private function store_eventbridge_update( $metadata_zip, $served_zip = '' ) {
		$package = $this->canonical_package_url( self::VERSION );
		$bytes   = file_get_contents( $metadata_zip );
		$item    = (object) array(
			'id'                           => EventBridge_Plugin_Updater::UPDATE_URI,
			'slug'                         => 'eventbridge',
			'plugin'                       => self::PLUGIN,
			'version'                      => self::VERSION,
			'new_version'                  => self::VERSION,
			'url'                          => EventBridge_Plugin_Updater::UPDATE_URI . '/releases/tag/v' . self::VERSION,
			'package'                      => $package,
			'requires'                     => '5.8',
			'requires_php'                 => '7.4',
			'tested'                       => '7.0',
			'eventbridge_sha256'           => hash( 'sha256', $bytes ),
			'eventbridge_package_size'     => strlen( $bytes ),
		);
		$this->http_packages[ $package ] = file_get_contents( '' !== $served_zip ? $served_zip : $metadata_zip );

		$transient = (object) array(
			'last_checked' => time(),
			'checked'      => array( self::PLUGIN => EVENTBRIDGE_VERSION ),
			'response'     => array( self::PLUGIN => $item ),
			'no_update'    => array(),
			'translations' => array(),
		);
		set_site_transient( 'update_plugins', $transient );

		return $item;
	}

	private function append_update_record( $plugin, $item ) {
		$transient = get_site_transient( 'update_plugins' );
		$transient->response[ $plugin ] = $item;
		$transient->checked[ $plugin ]  = '1.0.0';
		set_site_transient( 'update_plugins', $transient );
	}

	private function replace_update_record( $plugin, $item ) {
		$transient = get_site_transient( 'update_plugins' );
		$transient->response[ $plugin ] = $item;
		set_site_transient( 'update_plugins', $transient );
	}

	private function create_same_size_zip_pair() {
		$a = $this->create_eventbridge_zip( 'toctou-a.zip', self::VERSION, array( 'assets/js/toctou.js' => 'window.eventbridgeToctou = "A";' ) );
		$b = $this->create_eventbridge_zip( 'toctou-b.zip', self::VERSION, array( 'assets/js/toctou.js' => 'window.eventbridgeToctou = "B";' ) );

		$a_size = filesize( $a );
		$b_size = filesize( $b );
		if ( $a_size !== $b_size ) {
			$smaller = $a_size < $b_size ? $a : $b;
			$padding = abs( $a_size - $b_size );
			$zip     = new ZipArchive();
			$this->assertTrue( $zip->open( $smaller ) );
			$this->assertTrue( $zip->setArchiveComment( str_repeat( 'p', $padding ) ) );
			$this->assertTrue( $zip->close() );
		}

		clearstatcache( true, $a );
		clearstatcache( true, $b );
		$this->assertSame( filesize( $a ), filesize( $b ), 'TOCTOU fixtures must have identical byte lengths.' );
		$this->assertNotSame( hash_file( 'sha256', $a ), hash_file( 'sha256', $b ) );

		return array( 'a' => $a, 'b' => $b );
	}

	private function create_eventbridge_zip( $name, $version, $extra_files = array() ) {
		$entries = array(
			'eventbridge/eventbridge.php' => $this->plugin_file_contents( 'EventBridge', $version, EventBridge_Plugin_Updater::UPDATE_URI, 'package-' . $version ),
		);
		foreach ( $extra_files as $path => $contents ) {
			$entries[ 'eventbridge/' . ltrim( $path, '/\\' ) ] = $contents;
		}
		return $this->create_zip( $name, $entries );
	}

	private function create_zip( $name, $entries ) {
		$path = trailingslashit( $this->work_dir ) . $name;
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		foreach ( $entries as $entry => $contents ) {
			$this->assertTrue( $zip->addFromString( $entry, $contents ) );
		}
		$this->assertTrue( $zip->close() );
		return $path;
	}

	private function create_installed_plugin( $directory, $filename, $version, $marker ) {
		$path = trailingslashit( WP_PLUGIN_DIR ) . $directory;
		wp_mkdir_p( $path . '/includes' );
		file_put_contents(
			$path . '/' . $filename,
			$this->plugin_file_contents(
				'eventbridge' === $directory ? 'EventBridge integration baseline' : 'EventBridge Dummy',
				$version,
				'eventbridge' === $directory ? EventBridge_Plugin_Updater::UPDATE_URI : 'https://example.test/eventbridge-dummy',
				$marker
			)
		);
		file_put_contents( $path . '/includes/baseline.php', "<?php\n// " . $marker . "\n" );
	}

	private function plugin_file_contents( $name, $version, $update_uri, $marker ) {
		return "<?php\n/**\n * Plugin Name: " . $name . "\n * Version: " . $version . "\n * Update URI: " . $update_uri . "\n */\n// " . $marker . "\n";
	}

	private function canonical_package_url( $version ) {
		return EventBridge_Plugin_Updater::UPDATE_URI . '/releases/download/v' . $version . '/eventbridge-' . $version . '.zip';
	}

	private function installed_version( $plugin ) {
		$data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . $plugin, false, false );
		return isset( $data['Version'] ) ? $data['Version'] : '';
	}

	private function manifest_hash( $root ) {
		$manifest = array();
		$root     = wp_normalize_path( untrailingslashit( $root ) );
		if ( ! is_dir( $root ) ) {
			return '';
		}

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
		return hash( 'sha256', serialize( $manifest ) );
	}

	private function remove_tree( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}
		$filesystem = new WP_Filesystem_Direct( null );
		$filesystem->delete( $path, true );
	}
}
