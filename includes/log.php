<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Log {
	const CLEANUP_HOOK = 'eventbridge_cleanup_logs';

	const RETENTION_DAYS = 180;

	public function init() {
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
	}

	public function activate() {
		$this->create_table();

		if ( ! $this->table_exists() ) {
			return false;
		}

		$this->schedule_cleanup();

		return $this->log( 'info', 'system', 'EventBridge activities log initialized.' );
	}

	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(20) NOT NULL,
			source varchar(50) NOT NULL,
			event_key varchar(100) NULL,
			event_name varchar(100) NULL,
			event_id varchar(100) NULL,
			message varchar(500) NOT NULL,
			page_url text NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public function log( $level, $source, $message, $details = array() ) {
		global $wpdb;

		if ( ! is_scalar( $level ) || ! is_scalar( $source ) || ! is_scalar( $message ) ) {
			return false;
		}

		$level   = sanitize_text_field( wp_unslash( (string) $level ) );
		$source  = $this->sanitize_text( $source, 50 );
		$message = $this->sanitize_text( $message, 500 );

		if ( ! in_array( $level, array( 'info', 'warning', 'error' ), true ) || '' === $source || '' === $message ) {
			return false;
		}

		$details = is_array( $details ) ? $details : array();
		$context = null;

		if ( isset( $details['context'] ) && is_array( $details['context'] ) && ! empty( $details['context'] ) ) {
			$context = wp_json_encode( $details['context'] );

			if ( false === $context ) {
				return false;
			}
		}

		$data = array(
			'created_at' => current_time( 'mysql', true ),
			'level'      => $level,
			'source'     => $source,
			'event_key'  => $this->get_optional_text( $details, 'event_key', 100 ),
			'event_name' => $this->get_optional_text( $details, 'event_name', 100 ),
			'event_id'   => $this->get_optional_text( $details, 'event_id', 100 ),
			'message'    => $message,
			'page_url'   => $this->get_optional_url( $details ),
			'context'    => $context,
		);

		$result = $wpdb->insert(
			$this->get_table_name(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}
	}

	public function get_recent_logs( $limit = 100 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );
		$sql   = $wpdb->prepare(
			'SELECT id, created_at, level, source, event_key, event_name, event_id, message, page_url, context FROM ' . $this->get_table_name() . ' ORDER BY created_at DESC, id DESC LIMIT %d',
			$limit
		);

		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$logs                     = $wpdb->get_results( $sql, ARRAY_A );
		$wpdb->suppress_errors( $previous_suppress_errors );

		return is_array( $logs ) ? $logs : array();
	}

	public function get_logs_since( $created_at ) {
		global $wpdb;

		if ( ! is_scalar( $created_at ) || '' === trim( (string) $created_at ) ) {
			return array();
		}

		$sql = $wpdb->prepare(
			'SELECT id, created_at, level, source, event_key, event_name, event_id, message, page_url, context FROM ' . $this->get_table_name() . ' WHERE created_at >= %s ORDER BY created_at ASC, id ASC',
			trim( (string) $created_at )
		);

		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$logs                     = $wpdb->get_results( $sql, ARRAY_A );
		$wpdb->suppress_errors( $previous_suppress_errors );

		return is_array( $logs ) ? $logs : array();
	}

	public function cleanup() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$sql    = $wpdb->prepare( 'DELETE FROM ' . $this->get_table_name() . ' WHERE created_at < %s', $cutoff );

		return false !== $wpdb->query( $sql );
	}

	public function unschedule_cleanup() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'eventbridge_logs';
	}

	private function table_exists() {
		global $wpdb;

		$table_name = $this->get_table_name();

		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
	}

	private function sanitize_text( $value, $maximum_length ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $maximum_length ) : substr( $value, 0, $maximum_length );
	}

	private function get_optional_text( $details, $key, $maximum_length ) {
		if ( ! isset( $details[ $key ] ) || ! is_scalar( $details[ $key ] ) ) {
			return null;
		}

		$value = $this->sanitize_text( $details[ $key ], $maximum_length );

		return '' === $value ? null : $value;
	}

	private function get_optional_url( $details ) {
		if ( ! isset( $details['page_url'] ) || ! is_scalar( $details['page_url'] ) ) {
			return null;
		}

		$value = esc_url_raw( wp_unslash( (string) $details['page_url'] ) );

		return '' === $value ? null : $value;
	}
}
