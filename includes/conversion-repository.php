<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Conversion_Repository {
	const STATUS_OPEN = 'open';

	public function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->table();
		$charset = $wpdb->get_charset_collate();
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
			PRIMARY KEY  (id),
			UNIQUE KEY external_entity (provider, entity_type, external_id_hash),
			KEY profile_status (profile_id, status),
			KEY profile_link_id (profile_link_id),
			KEY status_created (status, created_at)
		) {$charset};" );
		return $this->verify_table();
	}

	public function verify_table() {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $this->table(), 0 );
		$required = array( 'id', 'profile_id', 'profile_link_id', 'provider', 'entity_type', 'external_id', 'external_id_hash', 'status', 'conversion_event_ids', 'created_at', 'updated_at' );
		if ( ! is_array( $columns ) || array_diff( $required, $columns ) ) return false;
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $this->table(), ARRAY_A );
		$names = wp_list_pluck( (array) $indexes, 'Key_name' );
		return ! array_diff( array( 'external_entity', 'profile_status', 'profile_link_id', 'status_created' ), $names );
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

	public function has_open_for_profile( $profile_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $this->table() . ' WHERE profile_id = %d AND status = %s LIMIT 1', absint( $profile_id ), self::STATUS_OPEN ) );
	}
	private function normalize_conversion_event_ids( $ids ) {
		$ids        = is_array( $ids ) ? $ids : array();
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) continue;
			$id = trim( sanitize_text_field( (string) $id ) );
			if ( preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id ) && ! in_array( $id, $normalized, true ) ) $normalized[] = $id;
		}
		return $normalized;
	}
	public function table() { global $wpdb; return $wpdb->prefix . 'eventbridge_conversions'; }
}
