<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Installer {
	const DB_VERSION_OPTION = 'eventbridge_db_version';

	private $log;
	private $status;

	public function __construct( EventBridge_Log $log, EventBridge_Upgrade_Status $status ) {
		$this->log    = $log;
		$this->status = $status;
	}

	public function activate() {
		$result = $this->install();

		if ( ! $result['success'] ) {
			wp_die(
				esc_html__( 'EventBridge kon de vereiste database-infrastructuur niet installeren.', 'eventbridge' ),
				esc_html__( 'EventBridge-activering mislukt', 'eventbridge' ),
				array( 'back_link' => true )
			);
		}
	}

	public function install() {
		$current_version = $this->get_stored_version();

		if ( ! $this->log->ensure_table() ) {
			return $this->failure( 'log_table_unavailable' );
		}

		add_option(
			'eventbridge_meta_settings',
			array(
				'pixel_id'   => '',
				'capi_token' => '',
				'debug'      => false,
			),
			'',
			false
		);
		add_option( 'eventbridge_events', array(), '', false );

		if ( ! $this->log->ensure_cleanup_schedule() ) {
			return $this->failure( 'cleanup_cron_unavailable' );
		}

		if ( ! $this->store_current_version() ) {
			return $this->failure( 'db_version_write_failed' );
		}

		$this->status->mark_succeeded( $current_version, EVENTBRIDGE_DB_VERSION );

		return array( 'success' => true, 'error_code' => '' );
	}

	private function get_stored_version() {
		$version = get_option( self::DB_VERSION_OPTION, 0 );

		return is_numeric( $version ) ? absint( $version ) : 0;
	}

	private function store_current_version() {
		$current = $this->get_stored_version();
		if ( $current > EVENTBRIDGE_DB_VERSION ) {
			return true;
		}

		if ( false === get_option( self::DB_VERSION_OPTION, false ) ) {
			add_option( self::DB_VERSION_OPTION, EVENTBRIDGE_DB_VERSION, '', false );
		} else {
			update_option( self::DB_VERSION_OPTION, EVENTBRIDGE_DB_VERSION, false );
		}

		return EVENTBRIDGE_DB_VERSION === absint( get_option( self::DB_VERSION_OPTION, 0 ) );
	}

	private function failure( $error_code ) {
		return array(
			'success'    => false,
			'error_code' => sanitize_key( $error_code ),
		);
	}
}
