<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Profile_Cleanup {
	const CLEANUP_HOOK = 'eventbridge_cleanup_profiles';
	const BATCH_SIZE = 100;
	private $profiles;
	private $conversions;
	private $contexts;

	public function __construct( EventBridge_Profile_Repository $profiles, EventBridge_Conversion_Repository $conversions = null, EventBridge_Profile_Context_Repository $contexts = null ) { $this->profiles = $profiles; $this->conversions = $conversions; $this->contexts = $contexts; }
	public function init() {
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		add_action( 'admin_post_eventbridge_profile_cleanup', array( $this, 'handle_manual_cleanup' ) );
	}
	public function ensure_schedule() {
		if ( 'daily' === wp_get_schedule( self::CLEANUP_HOOK ) ) return true;
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		return wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
	}
	public function unschedule() { wp_clear_scheduled_hook( self::CLEANUP_HOOK ); }
	public function cleanup( $days = null ) {
		global $wpdb;
		$links = $this->profiles->links_table(); $profiles = $this->profiles->profiles_table();
		$orphan_ids = $wpdb->get_col( "SELECT l.id FROM {$links} l LEFT JOIN {$profiles} p ON p.id = l.profile_id WHERE p.id IS NULL ORDER BY l.id ASC LIMIT " . self::BATCH_SIZE );
		foreach ( (array) $orphan_ids as $orphan_id ) $wpdb->delete( $links, array( 'id' => absint( $orphan_id ) ), array( '%d' ) );
		if ( $this->contexts ) $this->contexts->delete_orphans( self::BATCH_SIZE );
		$days = null === $days ? absint( apply_filters( 'eventbridge_profile_retention_days', 0 ) ) : absint( $days );
		if ( 0 === $days ) return 0;
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $days * DAY_IN_SECONDS );
		$conversions = $this->conversions ? $this->conversions->table() : '';
		$opportunity_clause = $conversions ? " AND NOT EXISTS (SELECT 1 FROM {$conversions} c WHERE c.profile_id = p.id AND c.status = 'open')" : '';
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.id FROM {$profiles} p LEFT JOIN {$links} l ON l.profile_id = p.id WHERE p.identification_status = %s AND p.last_activity_at < %s AND l.id IS NULL{$opportunity_clause} ORDER BY p.id ASC LIMIT %d", 'anonymous', $cutoff, self::BATCH_SIZE ) );
		$count = 0; foreach ( (array) $ids as $id ) { if ( $this->contexts ) $this->contexts->delete_for_profile( $id ); if ( $this->profiles->delete_profile( $id ) ) $count++; }
		return $count;
	}
	public function preview( $days ) {
		global $wpdb; $days = absint( $days ); if ( ! $days ) return 0;
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $days * DAY_IN_SECONDS );
		$conversions = $this->conversions ? $this->conversions->table() : '';
		$opportunity_clause = $conversions ? " AND NOT EXISTS (SELECT 1 FROM {$conversions} c WHERE c.profile_id = p.id AND c.status = 'open')" : '';
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->profiles->profiles_table() . ' p WHERE p.identification_status = %s AND p.last_activity_at < %s AND NOT EXISTS (SELECT 1 FROM ' . $this->profiles->links_table() . ' l WHERE l.profile_id = p.id)' . $opportunity_clause, 'anonymous', $cutoff ) ) );
	}
	public function handle_manual_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Onvoldoende rechten.', 'eventbridge' ) );
		check_admin_referer( 'eventbridge_profile_cleanup' );
		$this->cleanup();
		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}
}
