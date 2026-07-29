<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Upgrade_Status {
	const OPTION_NAME = 'eventbridge_upgrade_status';

	public function get() {
		$status = get_option( self::OPTION_NAME, array() );

		return is_array( $status ) ? wp_parse_args( $status, $this->get_defaults() ) : $this->get_defaults();
	}

	public function mark_running( $current_version, $target_version, $migration ) {
		$previous = $this->get();

		return $this->save(
			array(
				'state'          => 'running',
				'current_version' => absint( $current_version ),
				'target_version'  => absint( $target_version ),
				'migration'       => absint( $migration ),
				'error_code'      => '',
				'attempts'        => absint( $previous['attempts'] ),
				'attempted_at'    => time(),
				'next_retry_at'   => 0,
				'succeeded_at'    => absint( $previous['succeeded_at'] ),
				'notice_pending'  => false,
			)
		);
	}

	public function mark_succeeded( $previous_version, $target_version ) {
		return $this->save(
			array(
				'state'           => 'succeeded',
				'current_version' => absint( $target_version ),
				'target_version'  => absint( $target_version ),
				'migration'       => absint( $target_version ),
				'error_code'      => '',
				'attempts'        => 0,
				'attempted_at'    => time(),
				'next_retry_at'   => 0,
				'succeeded_at'    => time(),
				'notice_pending'  => absint( $previous_version ) < absint( $target_version ),
			)
		);
	}

	public function mark_failed( $current_version, $target_version, $migration, $error_code ) {
		$previous = $this->get();
		$attempts = absint( $previous['attempts'] ) + 1;
		$delays   = array( 300, 1800, 7200, 43200 );
		$delay    = $delays[ min( $attempts - 1, count( $delays ) - 1 ) ];

		return $this->save(
			array(
				'state'           => 'failed',
				'current_version' => absint( $current_version ),
				'target_version'  => absint( $target_version ),
				'migration'       => absint( $migration ),
				'error_code'      => sanitize_key( $error_code ),
				'attempts'        => $attempts,
				'attempted_at'    => time(),
				'next_retry_at'   => time() + $delay,
				'succeeded_at'    => absint( $previous['succeeded_at'] ),
				'notice_pending'  => false,
			)
		);
	}

	public function mark_notice_seen() {
		$status = $this->get();
		if ( ! $status['notice_pending'] ) {
			return true;
		}

		$status['notice_pending'] = false;

		return $this->save( $status );
	}

	public function init_admin() {
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
	}

	public function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->get();

		if ( 'failed' === $status['state'] ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $this->get_failure_message( $status ) )
			);
		} elseif ( $status['notice_pending'] ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: EventBridge database version. */
						__( 'EventBridge is succesvol bijgewerkt naar databaseversie %d.', 'eventbridge' ),
						absint( $status['target_version'] )
					)
				)
			);
			$this->mark_notice_seen();
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'EventBridge cleanup staat gepland, maar WP-Cron is uitgeschakeld. Zorg dat wp-cron.php extern wordt aangeroepen.', 'eventbridge' ) . '</p></div>';
		}
	}

	public function render_inline_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = $this->get();
		$message = '';
		$class   = 'notice-info';

		if ( 'running' === $status['state'] ) {
			$message = sprintf(
				/* translators: 1: migration number, 2: target database version. */
				__( 'EventBridge-migratie %1$d naar databaseversie %2$d is in uitvoering of tijdelijk door een andere request vergrendeld.', 'eventbridge' ),
				absint( $status['migration'] ),
				absint( $status['target_version'] )
			);
		} elseif ( 'failed' === $status['state'] ) {
			$class   = 'notice-error';
			$message = $this->get_failure_message( $status );
		}

		if ( '' !== $message ) {
			printf(
				'<div class="notice %1$s inline eventbridge-upgrade-status"><p>%2$s</p></div>',
				esc_attr( $class ),
				esc_html( $message )
			);
		}
	}

	private function get_failure_message( $status ) {
		$retry = absint( $status['next_retry_at'] );

		return sprintf(
			/* translators: 1: migration number, 2: current version, 3: target version, 4: error code, 5: retry time. */
			__( 'EventBridge-migratie %1$d van databaseversie %2$d naar %3$d is mislukt (%4$s). Volgende automatische poging: %5$s.', 'eventbridge' ),
			absint( $status['migration'] ),
			absint( $status['current_version'] ),
			absint( $status['target_version'] ),
			sanitize_key( $status['error_code'] ),
			$retry ? wp_date( 'Y-m-d H:i:s', $retry ) : __( 'nog niet gepland', 'eventbridge' )
		);
	}

	private function save( $status ) {
		$status = wp_parse_args( $status, $this->get_defaults() );

		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $status, '', false );
		} else {
			update_option( self::OPTION_NAME, $status, false );
		}

		return $this->get() === $status;
	}

	private function get_defaults() {
		return array(
			'state'           => 'idle',
			'current_version' => 0,
			'target_version'  => defined( 'EVENTBRIDGE_DB_VERSION' ) ? EVENTBRIDGE_DB_VERSION : 0,
			'migration'       => 0,
			'error_code'      => '',
			'attempts'        => 0,
			'attempted_at'    => 0,
			'next_retry_at'   => 0,
			'succeeded_at'    => 0,
			'notice_pending'  => false,
		);
	}
}
