<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Profile_Cleanup {
	const CLEANUP_HOOK = 'eventbridge_cleanup_profiles';
	const BATCH_SIZE = 100;
	const LOCK_OPTION = 'eventbridge_profile_cleanup_lock';
	const LOCK_TTL = 600;
	const PREVIEW_TTL = 600;
	private $profiles;
	private $conversions;
	private $contexts;

	public function __construct( EventBridge_Profile_Repository $profiles, EventBridge_Conversion_Repository $conversions = null, EventBridge_Profile_Context_Repository $contexts = null ) { $this->profiles = $profiles; $this->conversions = $conversions; $this->contexts = $contexts; }
	public function init() {
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		add_action( 'admin_post_eventbridge_profile_cleanup_preview', array( $this, 'handle_manual_preview' ) );
		add_action( 'admin_post_eventbridge_profile_cleanup', array( $this, 'handle_manual_cleanup' ) );
	}
	public function ensure_schedule() {
		if ( 'daily' === wp_get_schedule( self::CLEANUP_HOOK ) ) return true;
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		return wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
	}
	public function unschedule() { wp_clear_scheduled_hook( self::CLEANUP_HOOK ); }
	public function cleanup( $days = null ) { $result = $this->run_cleanup( $days ); return $result['profiles']; }
	public function run_cleanup( $days = null ) {
		$result = array( 'links' => 0, 'contexts' => 0, 'profiles' => 0, 'locked' => false ); $lock = $this->acquire_lock();
		if ( false === $lock ) { $result['locked'] = true; return $result; }
		try {
			$result['links'] = $this->delete_orphan_links();
			$result['contexts'] = $this->contexts ? $this->contexts->delete_orphans( self::BATCH_SIZE ) : 0;
			foreach ( $this->get_eligible_profile_ids( $days, self::BATCH_SIZE ) as $profile_id ) { if ( $this->contexts ) $this->contexts->delete_for_profile( $profile_id ); if ( $this->profiles->delete_profile( $profile_id ) ) $result['profiles']++; }
		} finally { $this->release_lock( $lock ); }
		return $result;
	}
	public function preview( $days ) { $preview = $this->get_preview( $days ); return $preview['profiles']; }
	public function get_preview( $days = null ) { return array( 'links' => $this->count_orphan_links(), 'contexts' => $this->contexts ? $this->contexts->count_orphans() : 0, 'profiles' => $this->count_eligible_profiles( $days ) ); }
	public static function get_preview_transient_key( $user_id ) { return 'eventbridge_profile_cleanup_preview_' . absint( $user_id ); }
	public static function get_manual_preview( $user_id ) {
		$preview = get_transient( self::get_preview_transient_key( $user_id ) ); if ( ! is_array( $preview ) ) return false;
		foreach ( array( 'links', 'contexts', 'profiles' ) as $key ) if ( ! isset( $preview[ $key ] ) || ! is_numeric( $preview[ $key ] ) ) return false;
		return array_map( 'absint', $preview );
	}
	public function handle_manual_preview() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Onvoldoende rechten.', 'eventbridge' ) ); check_admin_referer( 'eventbridge_profile_cleanup_preview' );
		set_transient( self::get_preview_transient_key( get_current_user_id() ), $this->get_preview(), self::PREVIEW_TTL );
		wp_safe_redirect( $this->get_settings_url( array( 'eventbridge_profile_cleanup_preview' => '1' ) ) ); exit;
	}
	public function handle_manual_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Onvoldoende rechten.', 'eventbridge' ) ); check_admin_referer( 'eventbridge_profile_cleanup' ); $user_id = get_current_user_id();
		if ( false === self::get_manual_preview( $user_id ) ) { wp_safe_redirect( $this->get_settings_url( array( 'eventbridge_profile_cleanup_status' => 'preview_required' ) ) ); exit; }
		$result = $this->run_cleanup(); if ( ! $result['locked'] ) delete_transient( self::get_preview_transient_key( $user_id ) );
		wp_safe_redirect( $this->get_settings_url( array( 'eventbridge_profile_cleanup_status' => $result['locked'] ? 'locked' : 'completed' ) ) ); exit;
	}
	private function get_settings_url( array $args = array() ) { return add_query_arg( array_merge( array( 'page' => 'eventbridge-settings' ), $args ), admin_url( 'admin.php' ) ); }
	private function get_eligible_profile_ids( $days, $limit ) {
		global $wpdb; $days = $this->get_retention_days( $days ); if ( 0 === $days ) return array(); $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $days * DAY_IN_SECONDS ); $conversions = $this->conversions ? $this->conversions->table() : ''; $conversion_clause = $conversions ? " AND NOT EXISTS (SELECT 1 FROM {$conversions} c WHERE c.profile_id = p.id)" : '';
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.id FROM {$this->profiles->profiles_table()} p LEFT JOIN {$this->profiles->links_table()} l ON l.profile_id = p.id WHERE p.identification_status = %s AND p.last_activity_at < %s AND l.id IS NULL{$conversion_clause} ORDER BY p.id ASC LIMIT %d", 'anonymous', $cutoff, max( 1, absint( $limit ) ) ) );
	}
	private function count_eligible_profiles( $days ) {
		global $wpdb; $days = $this->get_retention_days( $days ); if ( 0 === $days ) return 0; $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $days * DAY_IN_SECONDS ); $conversions = $this->conversions ? $this->conversions->table() : ''; $conversion_clause = $conversions ? " AND NOT EXISTS (SELECT 1 FROM {$conversions} c WHERE c.profile_id = p.id)" : '';
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->profiles->profiles_table()} p WHERE p.identification_status = %s AND p.last_activity_at < %s AND NOT EXISTS (SELECT 1 FROM {$this->profiles->links_table()} l WHERE l.profile_id = p.id){$conversion_clause}", 'anonymous', $cutoff ) ) );
	}
	private function get_retention_days( $days ) { return null === $days ? absint( apply_filters( 'eventbridge_profile_retention_days', 0 ) ) : absint( $days ); }
	private function delete_orphan_links() {
		global $wpdb; $ids = $wpdb->get_col( "SELECT l.id FROM {$this->profiles->links_table()} l LEFT JOIN {$this->profiles->profiles_table()} p ON p.id = l.profile_id WHERE p.id IS NULL ORDER BY l.id ASC LIMIT " . self::BATCH_SIZE ); $count = 0;
		foreach ( (array) $ids as $id ) if ( false !== $wpdb->delete( $this->profiles->links_table(), array( 'id' => absint( $id ) ), array( '%d' ) ) ) $count++; return $count;
	}
	private function count_orphan_links() { global $wpdb; return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$this->profiles->links_table()} l LEFT JOIN {$this->profiles->profiles_table()} p ON p.id = l.profile_id WHERE p.id IS NULL" ) ); }
	private function acquire_lock() {
		global $wpdb; try { $token = bin2hex( random_bytes( 16 ) ); } catch ( Exception $exception ) { return false; } $payload = array( 'token' => $token, 'expires_at' => time() + self::LOCK_TTL );
		if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) return $token;
		$current_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LOCK_OPTION ) ); $current = maybe_unserialize( $current_raw );
		if ( is_array( $current ) && ! empty( $current['expires_at'] ) && absint( $current['expires_at'] ) >= time() ) return false;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $payload ), self::LOCK_OPTION, $current_raw ) ); if ( 1 !== $updated ) return false; wp_cache_delete( self::LOCK_OPTION, 'options' ); return $token;
	}
	private function release_lock( $token ) {
		global $wpdb; $current_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LOCK_OPTION ) ); $current = maybe_unserialize( $current_raw );
		if ( ! is_array( $current ) || ! isset( $current['token'] ) || ! hash_equals( (string) $current['token'], (string) $token ) ) return;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $current_raw ) ); wp_cache_delete( self::LOCK_OPTION, 'options' );
	}
}
