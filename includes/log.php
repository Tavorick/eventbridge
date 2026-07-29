<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Log {
	const CLEANUP_HOOK = 'eventbridge_cleanup_logs';

	const RETENTION_DAYS = 30;

	const CONTEXT_MAX_BYTES = 4096;

	const PAGE_URL_MAX_BYTES = 2048;

	const DEFAULT_PAGE_SIZE = 50;

	const MAX_PAGE_SIZE = 100;

	const DASHBOARD_EVENT_LIMIT = 100;

	public function init() {
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
	}

	public function activate() {
		if ( ! $this->ensure_table() ) {
			return false;
		}

		if ( ! $this->ensure_cleanup_schedule() ) {
			return false;
		}

		return $this->log( 'info', 'system', 'EventBridge activities log initialized.' );
	}

	public function ensure_table() {
		$this->create_table();

		return $this->verify_table_schema();
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

			if ( strlen( $context ) > self::CONTEXT_MAX_BYTES ) {
				$context = '{"context_omitted":"too_large"}';
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
		return $this->ensure_cleanup_schedule();
	}

	public function ensure_cleanup_schedule() {
		$schedule = wp_get_schedule( self::CLEANUP_HOOK );

		if ( 'daily' === $schedule ) {
			return true;
		}

		if ( false !== $schedule || wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		}

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) && ! wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK ) ) {
			return false;
		}

		return 'daily' === wp_get_schedule( self::CLEANUP_HOOK ) && (bool) wp_next_scheduled( self::CLEANUP_HOOK );
	}

	public function get_recent_logs( $limit = 100 ) {
		return $this->get_logs_page( 1, $limit );
	}

	public function get_logs_page( $page = 1, $per_page = self::DEFAULT_PAGE_SIZE ) {
		global $wpdb;

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( self::MAX_PAGE_SIZE, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql      = $wpdb->prepare(
			'SELECT id, created_at, level, source, event_key, event_name, event_id, message, page_url, context FROM ' . $this->get_table_name() . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
			$per_page,
			$offset
		);

		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$logs                     = $wpdb->get_results( $sql, ARRAY_A );
		$wpdb->suppress_errors( $previous_suppress_errors );

		return is_array( $logs ) ? $logs : array();
	}

	public function count_logs() {
		global $wpdb;

		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$count                    = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->get_table_name() );
		$wpdb->suppress_errors( $previous_suppress_errors );

		return null === $count ? 0 : absint( $count );
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

	public function get_dashboard_statistics( $day_ranges ) {
		global $wpdb;

		$days   = $this->normalize_dashboard_days( $day_ranges );
		$totals = $this->get_empty_dashboard_totals();
		$events = array();
		$daily  = array();

		foreach ( $days as $day ) {
			$daily[ $day['key'] ] = array(
				'interactions' => 0,
				'browser'      => 0,
				'capi_started' => 0,
			);
		}

		if ( empty( $days ) ) {
			return array(
				'totals' => $totals,
				'events' => $events,
				'daily'  => $daily,
			);
		}

		$range_start = $days[0]['start'];
		$range_end   = $days[ count( $days ) - 1 ]['end'];
		$table_name  = $this->get_table_name();

		$total_sql = $wpdb->prepare(
			"SELECT
				COUNT(DISTINCT NULLIF(BINARY TRIM(event_id), _binary '')) AS interactions,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS browser,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS endpoint_accepted,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS endpoint_rejected,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS capi_started,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS capi_not_started
			FROM {$table_name}
			WHERE created_at >= %s AND created_at < %s",
			array(
				'browser',
				'Browser event invoked.',
				'custom_event_endpoint',
				'Custom event endpoint request accepted.',
				'custom_event_endpoint',
				'Custom event endpoint request rejected.',
				'meta_capi',
				'Custom CAPI request started.',
				'meta_capi',
				'Custom CAPI request not started.',
				$range_start,
				$range_end,
			)
		);

		$event_group_expression = "CASE
			WHEN NULLIF(event_key, '') IS NOT NULL THEN CONCAT('key:', event_key)
			WHEN NULLIF(event_name, '') IS NOT NULL THEN CONCAT('name:', event_name)
			ELSE NULL
		END";
		$event_sql              = $wpdb->prepare(
			"SELECT
				{$event_group_expression} AS group_key,
				SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(event_name, '') ORDER BY created_at DESC, id DESC SEPARATOR '\\n'), '\\n', 1) AS event_name,
				COUNT(DISTINCT NULLIF(BINARY TRIM(event_id), _binary '')) AS interactions,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS browser,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS endpoint_accepted,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS endpoint_rejected,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS capi_started,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS capi_not_started
			FROM {$table_name}
			WHERE created_at >= %s
				AND created_at < %s
				AND (NULLIF(event_key, '') IS NOT NULL OR NULLIF(event_name, '') IS NOT NULL)
			GROUP BY group_key
			ORDER BY event_name ASC, group_key ASC
			LIMIT " . self::DASHBOARD_EVENT_LIMIT,
			array(
				'browser',
				'Browser event invoked.',
				'custom_event_endpoint',
				'Custom event endpoint request accepted.',
				'custom_event_endpoint',
				'Custom event endpoint request rejected.',
				'meta_capi',
				'Custom CAPI request started.',
				'meta_capi',
				'Custom CAPI request not started.',
				$range_start,
				$range_end,
			)
		);

		$day_cases       = array();
		$daily_arguments = array();

		foreach ( $days as $day ) {
			$day_cases[]      = 'WHEN created_at >= %s AND created_at < %s THEN %s';
			$daily_arguments[] = $day['start'];
			$daily_arguments[] = $day['end'];
			$daily_arguments[] = $day['key'];
		}

		$daily_arguments[] = 'browser';
		$daily_arguments[] = 'Browser event invoked.';
		$daily_arguments[] = 'meta_capi';
		$daily_arguments[] = 'Custom CAPI request started.';
		$daily_arguments[] = $range_start;
		$daily_arguments[] = $range_end;
		$daily_sql         = $wpdb->prepare(
			'SELECT
				CASE ' . implode( ' ', $day_cases ) . ' ELSE NULL END AS day_key,
				COUNT(DISTINCT NULLIF(BINARY TRIM(event_id), _binary \'\')) AS interactions,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS browser,
				SUM(CASE WHEN source = %s AND message = %s THEN 1 ELSE 0 END) AS capi_started
			FROM ' . $table_name . '
			WHERE created_at >= %s AND created_at < %s
			GROUP BY day_key
			HAVING day_key IS NOT NULL
			ORDER BY day_key ASC
			LIMIT 7',
			$daily_arguments
		);

		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$total_row                = $wpdb->get_row( $total_sql, ARRAY_A );
		$event_rows               = $wpdb->get_results( $event_sql, ARRAY_A );
		$daily_rows               = $wpdb->get_results( $daily_sql, ARRAY_A );
		$wpdb->suppress_errors( $previous_suppress_errors );

		if ( is_array( $total_row ) ) {
			foreach ( array_keys( $totals ) as $metric ) {
				$totals[ $metric ] = isset( $total_row[ $metric ] ) ? absint( $total_row[ $metric ] ) : 0;
			}
		}

		if ( is_array( $event_rows ) ) {
			foreach ( $event_rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['group_key'] ) || ! is_scalar( $row['group_key'] ) || '' === (string) $row['group_key'] ) {
					continue;
				}

				$group_key            = (string) $row['group_key'];
				$events[ $group_key ] = array(
					'event_name'        => isset( $row['event_name'] ) && is_scalar( $row['event_name'] ) ? (string) $row['event_name'] : '',
					'interactions'      => isset( $row['interactions'] ) ? absint( $row['interactions'] ) : 0,
					'browser'           => isset( $row['browser'] ) ? absint( $row['browser'] ) : 0,
					'endpoint_accepted' => isset( $row['endpoint_accepted'] ) ? absint( $row['endpoint_accepted'] ) : 0,
					'endpoint_rejected' => isset( $row['endpoint_rejected'] ) ? absint( $row['endpoint_rejected'] ) : 0,
					'capi_started'      => isset( $row['capi_started'] ) ? absint( $row['capi_started'] ) : 0,
					'capi_not_started'  => isset( $row['capi_not_started'] ) ? absint( $row['capi_not_started'] ) : 0,
				);
			}
		}

		if ( is_array( $daily_rows ) ) {
			foreach ( $daily_rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['day_key'] ) || ! is_scalar( $row['day_key'] ) ) {
					continue;
				}

				$day_key = (string) $row['day_key'];
				if ( ! isset( $daily[ $day_key ] ) ) {
					continue;
				}

				$daily[ $day_key ] = array(
					'interactions' => isset( $row['interactions'] ) ? absint( $row['interactions'] ) : 0,
					'browser'      => isset( $row['browser'] ) ? absint( $row['browser'] ) : 0,
					'capi_started' => isset( $row['capi_started'] ) ? absint( $row['capi_started'] ) : 0,
				);
			}
		}

		return array(
			'totals' => $totals,
			'events' => $events,
			'daily'  => $daily,
		);
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

	public function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'eventbridge_logs';
	}

	public function table_exists() {
		global $wpdb;

		$table_name = $this->get_table_name();

		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
	}

	public function verify_table_schema() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		$table_name              = $this->get_table_name();
		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$columns                 = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}", 0 );
		$indexes                 = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		$wpdb->suppress_errors( $previous_suppress_errors );

		$required_columns = array( 'id', 'created_at', 'level', 'source', 'event_key', 'event_name', 'event_id', 'message', 'page_url', 'context' );
		if ( ! is_array( $columns ) || array_diff( $required_columns, $columns ) ) {
			return false;
		}

		$has_primary    = false;
		$has_created_at = false;

		if ( is_array( $indexes ) ) {
			foreach ( $indexes as $index ) {
				if ( ! is_array( $index ) ) {
					continue;
				}
				if ( isset( $index['Key_name'], $index['Column_name'] ) && 'PRIMARY' === $index['Key_name'] && 'id' === $index['Column_name'] ) {
					$has_primary = true;
				}
				if ( isset( $index['Key_name'], $index['Column_name'] ) && 'created_at' === $index['Key_name'] && 'created_at' === $index['Column_name'] ) {
					$has_created_at = true;
				}
			}
		}

		return $has_primary && $has_created_at;
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
		$value = $this->truncate_bytes( $value, self::PAGE_URL_MAX_BYTES );

		return '' === $value ? null : $value;
	}

	private function truncate_bytes( $value, $maximum_bytes ) {
		if ( strlen( $value ) <= $maximum_bytes ) {
			return $value;
		}

		return function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $maximum_bytes, 'UTF-8' ) : substr( $value, 0, $maximum_bytes );
	}

	private function get_empty_dashboard_totals() {
		return array(
			'interactions'      => 0,
			'browser'           => 0,
			'endpoint_accepted' => 0,
			'endpoint_rejected' => 0,
			'capi_started'      => 0,
			'capi_not_started'  => 0,
		);
	}

	private function normalize_dashboard_days( $day_ranges ) {
		if ( ! is_array( $day_ranges ) || 7 !== count( $day_ranges ) ) {
			return array();
		}

		$days = array();

		foreach ( $day_ranges as $key => $range ) {
			if (
				! is_scalar( $key )
				|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $key )
				|| ! is_array( $range )
				|| ! isset( $range['start'], $range['end'] )
				|| ! $this->is_mysql_datetime( $range['start'] )
				|| ! $this->is_mysql_datetime( $range['end'] )
				|| (string) $range['end'] <= (string) $range['start']
			) {
				continue;
			}

			$days[] = array(
				'key'   => (string) $key,
				'start' => (string) $range['start'],
				'end'   => (string) $range['end'],
			);
		}

		usort(
			$days,
			function ( $left, $right ) {
				return strcmp( $left['start'], $right['start'] );
			}
		);

		if ( 7 !== count( $days ) ) {
			return array();
		}

		$previous_end = null;

		foreach ( $days as $day ) {
			if ( null !== $previous_end && $day['start'] !== $previous_end ) {
				return array();
			}

			$duration = strtotime( $day['end'] . ' UTC' ) - strtotime( $day['start'] . ' UTC' );
			if ( 22 * HOUR_IN_SECONDS > $duration || 26 * HOUR_IN_SECONDS < $duration ) {
				return array();
			}

			$previous_end = $day['end'];
		}

		if (
			! empty( $days )
			&& strtotime( $days[ count( $days ) - 1 ]['end'] . ' UTC' ) - strtotime( $days[0]['start'] . ' UTC' ) > 8 * DAY_IN_SECONDS
		) {
			return array();
		}

		return $days;
	}

	private function is_mysql_datetime( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value  = (string) $value;
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();

		return false !== $date
			&& ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) )
			&& $date->format( 'Y-m-d H:i:s' ) === $value;
	}
}
