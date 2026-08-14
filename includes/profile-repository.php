<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Profile_Repository {
	public function ensure_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$profiles = $this->profiles_table();
		$links = $this->links_table();
		dbDelta( "CREATE TABLE {$profiles} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			browser_token_hash binary(32) NOT NULL,
			first_touch longtext NULL,
			last_touch longtext NULL,
			identification_status varchar(20) NOT NULL,
			created_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			last_activity_at datetime NOT NULL,
			identified_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY browser_token_hash (browser_token_hash),
			KEY retention (identification_status, last_activity_at)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id bigint(20) unsigned NOT NULL,
			provider varchar(64) NOT NULL,
			entity_type varchar(64) NOT NULL,
			external_id varchar(191) NOT NULL,
			external_id_hash binary(32) NOT NULL,
			created_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY external_entity (provider, entity_type, external_id_hash),
			KEY profile_activity (profile_id, last_seen_at)
		) {$charset};" );
		return $this->verify_tables();
	}

	public function verify_tables() {
		global $wpdb;
		foreach ( array(
			$this->profiles_table() => array( 'id', 'browser_token_hash', 'first_touch', 'last_touch', 'identification_status', 'created_at', 'last_seen_at', 'last_activity_at', 'identified_at' ),
			$this->links_table() => array( 'id', 'profile_id', 'provider', 'entity_type', 'external_id', 'external_id_hash', 'created_at', 'last_seen_at' ),
		) as $table => $required ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			if ( ! is_array( $columns ) || array_diff( $required, $columns ) ) {
				return false;
			}
		}
		$profile_indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $this->profiles_table(), ARRAY_A );
		$link_indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $this->links_table(), ARRAY_A );
		return $this->has_index( $profile_indexes, 'browser_token_hash' )
			&& $this->has_index( $profile_indexes, 'retention' )
			&& $this->has_index( $link_indexes, 'external_entity' )
			&& $this->has_index( $link_indexes, 'profile_activity' );
	}

	public function find_by_token_hash( $hash ) {
		global $wpdb;
		if ( ! is_string( $hash ) || 32 !== strlen( $hash ) ) return false;
		$profile = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->profiles_table() . ' WHERE browser_token_hash = %s LIMIT 1', $hash ), ARRAY_A );
		return is_array( $profile ) ? $profile : false;
	}

	public function get_or_create( $token_hash ) {
		global $wpdb;
		$profile = $this->find_by_token_hash( $token_hash );
		if ( is_array( $profile ) ) return $profile;
		$now = current_time( 'mysql', true );
		$result = $wpdb->insert( $this->profiles_table(), array(
			'browser_token_hash' => $token_hash, 'identification_status' => 'anonymous',
			'created_at' => $now, 'last_seen_at' => $now, 'last_activity_at' => $now,
		), array( '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $result ) return $this->find_by_token_hash( $token_hash );
		return $this->get_by_id( $wpdb->insert_id );
	}

	public function get_by_id( $profile_id ) {
		global $wpdb;
		return $profile_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->profiles_table() . ' WHERE id = %d', $profile_id ), ARRAY_A ) : false;
	}

	public function save_touch( $profile, $touch ) {
		global $wpdb;
		if ( ! is_array( $profile ) || empty( $profile['id'] ) || ! is_array( $touch ) ) return false;
		$last = isset( $profile['last_touch'] ) ? json_decode( $profile['last_touch'], true ) : null;
		if ( $this->same_marketing_touch( $last, $touch ) ) return true;
		$json = wp_json_encode( $touch );
		if ( ! is_string( $json ) ) return false;
		$now = current_time( 'mysql', true );
		$data = array( 'last_touch' => $json, 'last_seen_at' => $now, 'last_activity_at' => $now );
		if ( empty( $profile['first_touch'] ) ) $data['first_touch'] = $json;
		return false !== $wpdb->update( $this->profiles_table(), $data, array( 'id' => absint( $profile['id'] ) ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) );
	}

	public function link( $profile_id, $provider, $entity_type, $external_id ) {
		global $wpdb;
		$provider = sanitize_key( $provider ); $entity_type = sanitize_key( $entity_type ); $external_id = trim( (string) $external_id );
		if ( ! $profile_id || '' === $provider || '' === $entity_type || '' === $external_id || strlen( $external_id ) > 191 ) return false;
		$hash = hash( 'sha256', $external_id, true ); $now = current_time( 'mysql', true );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->links_table() . ' WHERE provider = %s AND entity_type = %s AND external_id_hash = %s LIMIT 1', $provider, $entity_type, $hash ), ARRAY_A );
		if ( is_array( $existing ) ) {
			if ( absint( $existing['profile_id'] ) !== absint( $profile_id ) ) return false;
			$wpdb->update( $this->links_table(), array( 'last_seen_at' => $now ), array( 'id' => $existing['id'] ), array( '%s' ), array( '%d' ) );
		} else {
			if ( false === $wpdb->insert( $this->links_table(), array( 'profile_id' => $profile_id, 'provider' => $provider, 'entity_type' => $entity_type, 'external_id' => $external_id, 'external_id_hash' => $hash, 'created_at' => $now, 'last_seen_at' => $now ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) ) ) return false;
		}
		return false !== $wpdb->update( $this->profiles_table(), array( 'last_seen_at' => $now, 'last_activity_at' => $now ), array( 'id' => $profile_id ), array( '%s', '%s' ), array( '%d' ) );
	}

	public function find_link( $provider, $entity_type, $external_id ) {
		global $wpdb;
		$provider = sanitize_key( $provider ); $entity_type = sanitize_key( $entity_type ); $external_id = trim( (string) $external_id );
		if ( '' === $provider || '' === $entity_type || '' === $external_id || strlen( $external_id ) > 191 ) return false;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->links_table() . ' WHERE provider = %s AND entity_type = %s AND external_id_hash = %s LIMIT 1', $provider, $entity_type, hash( 'sha256', $external_id, true ) ), ARRAY_A );
	}

	public function delete_profile( $profile_id ) {
		global $wpdb; $profile_id = absint( $profile_id ); if ( ! $profile_id ) return false;
		$wpdb->delete( $this->links_table(), array( 'profile_id' => $profile_id ), array( '%d' ) );
		return false !== $wpdb->delete( $this->profiles_table(), array( 'id' => $profile_id ), array( '%d' ) );
	}

	public function profiles_table() { global $wpdb; return $wpdb->prefix . 'eventbridge_profiles'; }
	public function links_table() { global $wpdb; return $wpdb->prefix . 'eventbridge_profile_links'; }

	private function same_marketing_touch( $left, $right ) {
		if ( ! is_array( $left ) || ! is_array( $right ) ) return false;
		unset( $left['captured_at'], $right['captured_at'] );
		return $left === $right;
	}

	private function has_index( $indexes, $name ) {
		foreach ( (array) $indexes as $index ) {
			if ( is_array( $index ) && isset( $index['Key_name'] ) && $name === $index['Key_name'] ) return true;
		}
		return false;
	}
}
