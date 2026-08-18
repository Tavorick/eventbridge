<?php

defined( 'ABSPATH' ) || exit;

/** Platform-neutral storage for small, allowlisted browser/profile context values. */
class EventBridge_Profile_Context_Repository {
	public function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id bigint(20) unsigned NOT NULL,
			context_namespace varchar(64) NOT NULL,
			context_key varchar(64) NOT NULL,
			context_value varchar(512) NOT NULL,
			captured_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY profile_context (profile_id, context_namespace, context_key),
			KEY profile_id (profile_id)
		) {$charset};" );
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		if ( ! is_array( $status ) || empty( $status['Engine'] ) || 0 !== strcasecmp( $status['Engine'], 'InnoDB' ) ) {
			if ( false === $wpdb->query( 'ALTER TABLE ' . $table . ' ENGINE=InnoDB' ) ) return false;
		}
		return $this->verify_table();
	}

	public function verify_table() {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $this->table(), 0 );
		if ( ! is_array( $columns ) || array_diff( array( 'id', 'profile_id', 'context_namespace', 'context_key', 'context_value', 'captured_at', 'updated_at' ), $columns ) ) return false;
		$indexes = wp_list_pluck( (array) $wpdb->get_results( 'SHOW INDEX FROM ' . $this->table(), ARRAY_A ), 'Key_name' );
		return ! array_diff( array( 'profile_context', 'profile_id' ), $indexes );
	}

	public function save( $profile_id, $namespace, array $values ) {
		global $wpdb;
		$profile_id = absint( $profile_id ); $namespace = sanitize_key( $namespace );
		if ( ! $profile_id || '' === $namespace ) return false;
		$now = current_time( 'mysql', true ); $saved = true;
		foreach ( $values as $key => $value ) {
			$key = is_string( $key ) ? trim( $key ) : '';
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( ! preg_match( '/^_?[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $key ) || '' === $value || strlen( $value ) > 512 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) continue;
			$result = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $this->table() . ' (profile_id, context_namespace, context_key, context_value, captured_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE context_value = VALUES(context_value), captured_at = VALUES(captured_at), updated_at = VALUES(updated_at)', $profile_id, $namespace, $key, $value, $now, $now ) );
			$saved = false !== $result && $saved;
		}
		return $saved;
	}

	public function get_for_profile( $profile_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT context_namespace, context_key, context_value, captured_at FROM ' . $this->table() . ' WHERE profile_id = %d ORDER BY id ASC', absint( $profile_id ) ), ARRAY_A );
		$context = array();
		foreach ( (array) $rows as $row ) {
			$context[ $row['context_namespace'] ][ $row['context_key'] ] = array( 'value' => $row['context_value'], 'captured_at' => $row['captured_at'] );
		}
		return $context;
	}

	/** Batch read limited to the browser identifiers approved for admin display. */
	public function get_admin_contexts( array $profile_ids ) {
		global $wpdb;
		$profile_ids = array_values( array_unique( array_filter( array_map( 'absint', $profile_ids ) ) ) );
		if ( empty( $profile_ids ) ) return array();
		$placeholders = implode( ', ', array_fill( 0, count( $profile_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT profile_id, context_key, context_value FROM ' . $this->table() . " WHERE profile_id IN ({$placeholders}) AND context_namespace = %s AND context_key IN (%s, %s) ORDER BY id ASC",
				array_merge( $profile_ids, array( 'browser_cookie', '_fbp', '_fbc' ) )
			),
			ARRAY_A
		);
		$contexts = array();
		foreach ( (array) $rows as $row ) {
			$profile_id = absint( $row['profile_id'] );
			if ( ! isset( $contexts[ $profile_id ] ) ) $contexts[ $profile_id ] = array();
			$contexts[ $profile_id ][ $row['context_key'] ] = $row['context_value'];
		}
		return $contexts;
	}

	public function delete_for_profile( $profile_id ) {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'profile_id' => absint( $profile_id ) ), array( '%d' ) );
	}

	public function delete_orphans( $limit = 100 ) {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT c.id FROM ' . $this->table() . ' c LEFT JOIN ' . $wpdb->prefix . 'eventbridge_profiles p ON p.id = c.profile_id WHERE p.id IS NULL ORDER BY c.id ASC LIMIT ' . max( 1, absint( $limit ) ) );
		foreach ( (array) $ids as $id ) $wpdb->delete( $this->table(), array( 'id' => absint( $id ) ), array( '%d' ) );
		return count( (array) $ids );
	}

	public function count_orphans() {
		global $wpdb;
		return absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() . ' c LEFT JOIN ' . $wpdb->prefix . 'eventbridge_profiles p ON p.id = c.profile_id WHERE p.id IS NULL' ) );
	}

	public function table() { global $wpdb; return $wpdb->prefix . 'eventbridge_profile_contexts'; }
}
