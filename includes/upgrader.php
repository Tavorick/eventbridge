<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Upgrader {
	const LOCK_OPTION         = 'eventbridge_upgrade_lock';
	const LOCK_TTL            = 300;
	const CRON_CHECK_OPTION   = 'eventbridge_cron_health_checked_at';
	const CRON_CHECK_INTERVAL = 43200;

	private $log;
	private $installer;
	private $status;

	public function __construct( EventBridge_Log $log, EventBridge_Installer $installer, EventBridge_Upgrade_Status $status ) {
		$this->log       = $log;
		$this->installer = $installer;
		$this->status    = $status;
	}

	public function run() {
		$current_version = $this->get_stored_version();
		$status          = $this->status->get();

		if ( 'failed' === $status['state'] && absint( $status['next_retry_at'] ) > time() ) {
			return;
		}

		if ( $current_version < EVENTBRIDGE_DB_VERSION ) {
			$this->run_pending_migrations( $current_version );
			return;
		}

		$this->maybe_heal_cleanup_cron( $current_version );
	}

	private function run_pending_migrations( $current_version ) {
		$lock = $this->acquire_lock();
		if ( false === $lock ) {
			return;
		}

		$active_migration = EVENTBRIDGE_DB_VERSION;

		try {
			$current_version = $this->get_stored_version();
			if ( $current_version >= EVENTBRIDGE_DB_VERSION ) {
				return;
			}

			if ( 0 === $current_version && ! $this->has_legacy_footprint() ) {
				$this->status->mark_running( 0, EVENTBRIDGE_DB_VERSION, EVENTBRIDGE_DB_VERSION );
				$result = $this->installer->install();
				if ( ! $result['success'] ) {
					$this->record_failure( 0, EVENTBRIDGE_DB_VERSION, EVENTBRIDGE_DB_VERSION, $result['error_code'] );
				}
				return;
			}

			$migrations = array(
				1 => array( $this, 'migrate_to_1' ),
			);

			foreach ( $migrations as $version => $callback ) {
				if ( $version <= $current_version || $version > EVENTBRIDGE_DB_VERSION ) {
					continue;
				}

				$active_migration = $version;
				$this->status->mark_running( $current_version, EVENTBRIDGE_DB_VERSION, $version );
				$result = call_user_func( $callback );

				if ( ! is_array( $result ) || empty( $result['success'] ) ) {
					$error_code = is_array( $result ) && isset( $result['error_code'] ) ? $result['error_code'] : 'migration_failed';
					$this->record_failure( $current_version, EVENTBRIDGE_DB_VERSION, $version, $error_code );
					return;
				}
			}

			if ( ! $this->store_db_version( EVENTBRIDGE_DB_VERSION ) ) {
				$this->record_failure( $current_version, EVENTBRIDGE_DB_VERSION, EVENTBRIDGE_DB_VERSION, 'db_version_write_failed' );
				return;
			}

			$this->status->mark_succeeded( $current_version, EVENTBRIDGE_DB_VERSION );
			$this->store_cron_check_time();
			$this->safe_log( 'info', 'upgrade_succeeded', EVENTBRIDGE_DB_VERSION, EVENTBRIDGE_DB_VERSION );
		} catch ( Throwable $throwable ) {
			$this->record_failure( $current_version, EVENTBRIDGE_DB_VERSION, $active_migration, 'unexpected_migration_error' );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function migrate_to_1() {
		if ( ! $this->log->ensure_table() ) {
			return $this->migration_failure( 'log_table_unavailable' );
		}

		if ( ! $this->log->ensure_cleanup_schedule() ) {
			return $this->migration_failure( 'cleanup_cron_unavailable' );
		}

		if ( ! $this->log->verify_table_schema() ) {
			return $this->migration_failure( 'log_table_verification_failed' );
		}

		return array(
			'success'    => true,
			'migration'  => 1,
			'error_code' => '',
		);
	}

	private function maybe_heal_cleanup_cron( $current_version ) {
		$last_check = absint( get_option( self::CRON_CHECK_OPTION, 0 ) );
		$now        = time();

		if ( $last_check >= $now - self::CRON_CHECK_INTERVAL && $last_check <= $now + self::CRON_CHECK_INTERVAL ) {
			return;
		}

		$lock = $this->acquire_lock();
		if ( false === $lock ) {
			return;
		}

		try {
			$last_check = absint( get_option( self::CRON_CHECK_OPTION, 0 ) );
			if ( $last_check >= time() - self::CRON_CHECK_INTERVAL && $last_check <= time() + self::CRON_CHECK_INTERVAL ) {
				return;
			}

			if ( ! $this->log->ensure_cleanup_schedule() ) {
				$this->record_failure( $current_version, EVENTBRIDGE_DB_VERSION, 0, 'cleanup_cron_unavailable' );
				return;
			}

			$this->store_cron_check_time();
		} catch ( Throwable $throwable ) {
			$this->record_failure( $current_version, EVENTBRIDGE_DB_VERSION, 0, 'unexpected_cron_health_error' );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function has_legacy_footprint() {
		return $this->log->table_exists()
			|| $this->option_exists( 'eventbridge_meta_settings' )
			|| $this->option_exists( 'eventbridge_events' )
			|| (bool) wp_next_scheduled( EventBridge_Log::CLEANUP_HOOK );
	}

	private function option_exists( $option_name ) {
		global $wpdb;

		$option_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		return null !== $option_id;
	}

	private function get_stored_version() {
		$version = get_option( EventBridge_Installer::DB_VERSION_OPTION, 0 );

		return is_numeric( $version ) ? absint( $version ) : 0;
	}

	private function store_db_version( $version ) {
		$stored = $this->get_stored_version();
		if ( $stored > $version ) {
			return true;
		}

		if ( ! $this->option_exists( EventBridge_Installer::DB_VERSION_OPTION ) ) {
			add_option( EventBridge_Installer::DB_VERSION_OPTION, absint( $version ), '', false );
		} else {
			update_option( EventBridge_Installer::DB_VERSION_OPTION, absint( $version ), false );
		}

		return absint( $version ) === $this->get_stored_version();
	}

	private function store_cron_check_time() {
		if ( ! $this->option_exists( self::CRON_CHECK_OPTION ) ) {
			add_option( self::CRON_CHECK_OPTION, time(), '', false );
		} else {
			update_option( self::CRON_CHECK_OPTION, time(), false );
		}
	}

	private function acquire_lock() {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			return false;
		}

		$now   = time();
		$token = hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) . '|' . wp_rand() );
		$value = ( $now + self::LOCK_TTL ) . '|' . $token;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s, autoload = 'no'
				WHERE option_name = %s
				AND (
					option_value NOT REGEXP '^[0-9]+[|][a-f0-9]{64}$'
					OR CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d
				)",
				$value,
				self::LOCK_OPTION,
				$now
			)
		);

		if ( false === $result ) {
			return false;
		}

		if ( 0 === $result ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::LOCK_OPTION,
					$value
				)
			);
			if ( false === $result ) {
				return false;
			}
		}

		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$stored_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::LOCK_OPTION
			)
		);

		return $value === $stored_value ? $value : false;
	}

	private function release_lock( $lock_value ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_OPTION,
				$lock_value
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	private function record_failure( $current_version, $target_version, $migration, $error_code ) {
		$error_code = sanitize_key( $error_code );
		$this->status->mark_failed( $current_version, $target_version, $migration, $error_code );
		$this->safe_log( 'error', $error_code, $migration, $target_version );
	}

	private function safe_log( $level, $error_code, $migration, $target_version ) {
		$details = array(
			'context' => array(
				'error_code'     => sanitize_key( $error_code ),
				'migration'      => absint( $migration ),
				'target_version' => absint( $target_version ),
			),
		);

		if ( $this->log->table_exists() ) {
			$this->log->log( $level, 'upgrade', 'EventBridge upgrade status changed.', $details );
			return;
		}

		error_log(
			sprintf(
				'EventBridge upgrade: code=%s migration=%d target=%d',
				sanitize_key( $error_code ),
				absint( $migration ),
				absint( $target_version )
			)
		);
	}

	private function migration_failure( $error_code ) {
		return array(
			'success'    => false,
			'migration'  => 1,
			'error_code' => sanitize_key( $error_code ),
		);
	}
}
