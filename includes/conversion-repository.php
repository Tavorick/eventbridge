<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Conversion_Repository {
	const STATUS_OPEN      = 'open';
	const STATUS_CONVERTED = 'converted';

	const DELIVERY_PENDING    = 'pending';
	const DELIVERY_PROCESSING = 'processing';
	const DELIVERY_SUCCEEDED  = 'succeeded';
	const DELIVERY_RETRYABLE  = 'retryable';
	const DELIVERY_BLOCKED    = 'blocked';

	public function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table      = $this->table();
		$deliveries = $this->deliveries_table();
		$charset    = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id bigint(20) unsigned NOT NULL,
			profile_link_id bigint(20) unsigned NULL,
			provider varchar(64) NOT NULL,
			entity_type varchar(64) NOT NULL,
			external_id varchar(191) NOT NULL,
			external_id_hash binary(32) NOT NULL,
			status varchar(20) NOT NULL,
			conversion_event_ids longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			converted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY external_entity (provider, entity_type, external_id_hash),
			KEY profile_status (profile_id, status),
			KEY profile_link_id (profile_link_id),
			KEY status_created (status, created_at)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$deliveries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversion_id bigint(20) unsigned NOT NULL,
			event_key varchar(64) NOT NULL,
			destination_id varchar(64) NOT NULL,
			event_id char(36) NOT NULL,
			event_time bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			lease_token char(64) NULL,
			lease_expires_at datetime NULL,
			occurrence longtext NULL,
			last_error_code varchar(64) NULL,
			last_http_code smallint(5) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			succeeded_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conversion_event_destination (conversion_id, event_key, destination_id),
			KEY conversion_status (conversion_id, status),
			KEY claimable (status, lease_expires_at)
		) {$charset};" );
		if ( ! $this->ensure_innodb( $table ) || ! $this->ensure_innodb( $deliveries ) ) return false;
		return $this->verify_table();
	}

	public function verify_table() {
		global $wpdb;
		$schemas = array(
			$this->table() => array(
				'columns' => array( 'id', 'profile_id', 'profile_link_id', 'provider', 'entity_type', 'external_id', 'external_id_hash', 'status', 'conversion_event_ids', 'created_at', 'updated_at', 'converted_at' ),
				'indexes' => array( 'external_entity', 'profile_status', 'profile_link_id', 'status_created' ),
			),
			$this->deliveries_table() => array(
				'columns' => array( 'id', 'conversion_id', 'event_key', 'destination_id', 'event_id', 'event_time', 'status', 'attempt_count', 'lease_token', 'lease_expires_at', 'occurrence', 'last_error_code', 'last_http_code', 'created_at', 'updated_at', 'succeeded_at' ),
				'indexes' => array( 'conversion_event_destination', 'conversion_status', 'claimable' ),
			),
		);
		foreach ( $schemas as $table => $schema ) {
			$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table, 0 );
			if ( ! is_array( $columns ) || array_diff( $schema['columns'], $columns ) ) return false;
			$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A );
			$names   = wp_list_pluck( (array) $indexes, 'Key_name' );
			if ( array_diff( $schema['indexes'], $names ) ) return false;
		}
		return true;
	}

	public function ensure_open( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids = array() ) {
		global $wpdb;
		$provider = sanitize_key( $provider ); $entity_type = sanitize_key( $entity_type ); $external_id = trim( (string) $external_id );
		if ( ! is_array( $profile_link ) || empty( $profile_link['id'] ) || empty( $profile_link['profile_id'] ) || '' === $provider || '' === $entity_type || '' === $external_id || strlen( $external_id ) > 191 ) return false;
		$conversion_event_ids = $this->normalize_conversion_event_ids( $conversion_event_ids );
		$snapshot             = wp_json_encode( $conversion_event_ids );
		if ( ! is_string( $snapshot ) ) return false;
		$hash = hash( 'sha256', $external_id, true ); $now = current_time( 'mysql', true );
		$result = $wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . $this->table() . ' (profile_id, profile_link_id, provider, entity_type, external_id, external_id_hash, status, conversion_event_ids, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
			absint( $profile_link['profile_id'] ), absint( $profile_link['id'] ), $provider, $entity_type, $external_id, $hash, self::STATUS_OPEN, $snapshot, $now, $now
		) );
		return false !== $result;
	}

	public function get_open( $page = 1, $per_page = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', self::STATUS_OPEN, max( 1, absint( $per_page ) ), max( 0, absint( $page ) - 1 ) * max( 1, absint( $per_page ) ) ), ARRAY_A );
	}

	public function get_for_admin( $per_page = 100 ) {
		global $wpdb;
		$records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' ORDER BY CASE WHEN status = %s THEN 0 ELSE 1 END, COALESCE(converted_at, created_at) DESC, id DESC LIMIT %d', self::STATUS_OPEN, max( 1, absint( $per_page ) ) ), ARRAY_A );
		foreach ( (array) $records as &$record ) {
			$record['deliveries'] = $this->get_deliveries( $record['id'] );
		}
		return $records;
	}

	public function get_by_id( $conversion_id ) {
		global $wpdb;
		return $conversion_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1', absint( $conversion_id ) ), ARRAY_A ) : false;
	}

	public function get_deliveries( $conversion_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->deliveries_table() . ' WHERE conversion_id = %d ORDER BY id ASC', absint( $conversion_id ) ), ARRAY_A );
	}

	public function has_open_for_profile( $profile_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $this->table() . ' WHERE profile_id = %d AND status = %s LIMIT 1', absint( $profile_id ), self::STATUS_OPEN ) );
	}

	/** Reconciles prepared immutable deliveries while serializing initialisation per conversion. */
	public function reconcile_deliveries( $conversion_id, array $prepared ) {
		global $wpdb;
		$conversion_id = absint( $conversion_id );
		if ( ! $conversion_id ) return false;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$conversion = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d FOR UPDATE', $conversion_id ), ARRAY_A );
			if ( ! is_array( $conversion ) || self::STATUS_OPEN !== $conversion['status'] ) {
				$wpdb->query( 'ROLLBACK' );
				return is_array( $conversion ) && self::STATUS_CONVERTED === $conversion['status'];
			}
			$now = current_time( 'mysql', true );
			foreach ( $prepared as $item ) {
				if ( ! is_array( $item ) || empty( $item['event_key'] ) || ! isset( $item['event_id'], $item['event_time'], $item['status'] ) ) continue;
				$event_key      = (string) $item['event_key'];
				$destination_id = isset( $item['destination_id'] ) ? sanitize_key( $item['destination_id'] ) : '';
				if ( '' !== $destination_id ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->deliveries_table() . ' WHERE conversion_id = %d AND event_key = %s AND destination_id = %s AND status <> %s', $conversion_id, $event_key, '', self::DELIVERY_SUCCEEDED ) );
				} else {
					$has_delivery = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $this->deliveries_table() . ' WHERE conversion_id = %d AND event_key = %s AND destination_id <> %s LIMIT 1', $conversion_id, $event_key, '' ) );
					if ( $has_delivery ) continue;
				}
				$occurrence = isset( $item['occurrence'] ) && is_array( $item['occurrence'] ) ? wp_json_encode( $item['occurrence'] ) : null;
				if ( is_array( $item['occurrence'] ?? null ) && ! is_string( $occurrence ) ) throw new RuntimeException( 'occurrence_encoding_failed' );
				$status = in_array( $item['status'], array( self::DELIVERY_PENDING, self::DELIVERY_BLOCKED ), true ) ? $item['status'] : self::DELIVERY_BLOCKED;
				$error  = isset( $item['error_code'] ) ? sanitize_key( $item['error_code'] ) : '';
				$sql = $wpdb->prepare(
					'INSERT INTO ' . $this->deliveries_table() . ' (conversion_id, event_key, destination_id, event_id, event_time, status, occurrence, last_error_code, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE status = IF(status = %s AND last_error_code = %s AND VALUES(status) = %s, %s, status), occurrence = IF(occurrence IS NULL, VALUES(occurrence), occurrence), last_error_code = IF(status = %s AND last_error_code = %s, %s, last_error_code), updated_at = VALUES(updated_at)',
					$conversion_id, $event_key, $destination_id, $item['event_id'], absint( $item['event_time'] ), $status, $occurrence, $error, $now, $now,
					self::DELIVERY_BLOCKED, 'destination_unavailable', self::DELIVERY_PENDING, self::DELIVERY_PENDING,
					self::DELIVERY_PENDING, 'destination_unavailable', ''
				);
				if ( false === $wpdb->query( $sql ) ) throw new RuntimeException( 'delivery_write_failed' );
			}
			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( Throwable $throwable ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	public function claim_delivery( $delivery_id, $lease_seconds = 120 ) {
		global $wpdb;
		$delivery_id = absint( $delivery_id );
		if ( ! $delivery_id ) return false;
		try { $token = bin2hex( random_bytes( 32 ) ); } catch ( Exception $exception ) { return false; }
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) + max( 30, absint( $lease_seconds ) ) );
		$result  = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . $this->deliveries_table() . ' SET status = %s, lease_token = %s, lease_expires_at = %s, attempt_count = attempt_count + 1, updated_at = %s WHERE id = %d AND (status IN (%s, %s) OR (status = %s AND lease_expires_at < %s))',
			self::DELIVERY_PROCESSING, $token, $expires, $now, $delivery_id, self::DELIVERY_PENDING, self::DELIVERY_RETRYABLE, self::DELIVERY_PROCESSING, $now
		) );
		if ( 1 !== $result ) return false;
		$delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->deliveries_table() . ' WHERE id = %d AND lease_token = %s LIMIT 1', $delivery_id, $token ), ARRAY_A );
		return is_array( $delivery ) ? $delivery : false;
	}

	public function complete_delivery( $delivery_id, $lease_token, $status, $error_code = '', $http_code = 0 ) {
		global $wpdb;
		if ( ! in_array( $status, array( self::DELIVERY_SUCCEEDED, self::DELIVERY_RETRYABLE, self::DELIVERY_BLOCKED ), true ) ) return false;
		$now       = current_time( 'mysql', true );
		$succeeded_sql = self::DELIVERY_SUCCEEDED === $status ? $wpdb->prepare( ', succeeded_at = %s', $now ) : '';
		$result = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . $this->deliveries_table() . ' SET status = %s, lease_token = NULL, lease_expires_at = NULL, last_error_code = %s, last_http_code = %d, updated_at = %s' . $succeeded_sql . ' WHERE id = %d AND status = %s AND lease_token = %s',
			$status, sanitize_key( $error_code ), absint( $http_code ), $now, absint( $delivery_id ), self::DELIVERY_PROCESSING, (string) $lease_token
		) );
		return 1 === $result;
	}

	public function block_delivery( $delivery_id, $error_code ) {
		global $wpdb;
		return false !== $wpdb->update( $this->deliveries_table(), array( 'status' => self::DELIVERY_BLOCKED, 'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => sanitize_key( $error_code ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $delivery_id ) ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
	}

	public function finalize_if_complete( $conversion_id, array $snapshot ) {
		global $wpdb;
		$snapshot = $this->normalize_conversion_event_ids( $snapshot );
		if ( empty( $snapshot ) ) return false;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$conversion = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d FOR UPDATE', absint( $conversion_id ) ), ARRAY_A );
			if ( ! is_array( $conversion ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
			if ( self::STATUS_CONVERTED === $conversion['status'] ) { $wpdb->query( 'COMMIT' ); return true; }
			$deliveries = $wpdb->get_results( $wpdb->prepare( 'SELECT event_key, destination_id, status FROM ' . $this->deliveries_table() . ' WHERE conversion_id = %d FOR UPDATE', absint( $conversion_id ) ), ARRAY_A );
			$by_event = array();
			foreach ( (array) $deliveries as $delivery ) $by_event[ $delivery['event_key'] ][] = $delivery;
			foreach ( $snapshot as $event_key ) {
				if ( empty( $by_event[ $event_key ] ) ) { $wpdb->query( 'COMMIT' ); return false; }
				foreach ( $by_event[ $event_key ] as $delivery ) {
					if ( '' === $delivery['destination_id'] || self::DELIVERY_SUCCEEDED !== $delivery['status'] ) { $wpdb->query( 'COMMIT' ); return false; }
				}
			}
			$now = current_time( 'mysql', true );
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET status = %s, converted_at = %s, updated_at = %s WHERE id = %d AND status = %s', self::STATUS_CONVERTED, $now, $now, absint( $conversion_id ), self::STATUS_OPEN ) );
			$wpdb->query( 'COMMIT' );
			return 1 === $result;
		} catch ( Throwable $throwable ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	public function decode_snapshot( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) return false;
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) return false;
		$normalized = $this->normalize_conversion_event_ids( $decoded );
		return count( $normalized ) === count( $decoded ) ? $normalized : false;
	}

	private function normalize_conversion_event_ids( $ids ) {
		$ids = is_array( $ids ) ? $ids : array(); $normalized = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) continue;
			$id = trim( sanitize_text_field( (string) $id ) );
			if ( preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id ) && ! in_array( $id, $normalized, true ) ) $normalized[] = $id;
		}
		return $normalized;
	}

	private function ensure_innodb( $table ) {
		global $wpdb;
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		if ( is_array( $status ) && isset( $status['Engine'] ) && 0 === strcasecmp( $status['Engine'], 'InnoDB' ) ) return true;
		return false !== $wpdb->query( 'ALTER TABLE ' . $table . ' ENGINE=InnoDB' );
	}

	public function table() { global $wpdb; return $wpdb->prefix . 'eventbridge_conversions'; }
	public function deliveries_table() { global $wpdb; return $wpdb->prefix . 'eventbridge_conversion_deliveries'; }
}
