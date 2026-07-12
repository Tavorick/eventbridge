<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Admin {
	const SETTINGS_PAGE_SLUG = 'eventbridge-settings';

	private $settings;
	private $events;
	private $log;
	private $event_form_values;
	private $editing_event_key = '';
	private $is_editing_event  = false;

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events, EventBridge_Log $log ) {
		$this->settings          = $settings;
		$this->events            = $events;
		$this->log               = $log;
		$this->event_form_values = $events->get_form_defaults();
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_event_parameter_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_event_form' ) );
		add_action( 'admin_init', array( $this, 'handle_update_event_form' ) );
		add_action( 'admin_init', array( $this, 'handle_delete_event_form' ) );
	}

	public function enqueue_event_parameter_assets( $hook_suffix ) {
		if ( 'eventbridge_page_' . self::SETTINGS_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'eventbridge-event-parameters',
			plugins_url( 'assets/js/eventbridge-event-parameters.js', dirname( __FILE__ ) ),
			array(),
			'0.1.0',
			true
		);
	}

	public function enqueue_dashboard_assets( $hook_suffix ) {
		if ( 'toplevel_page_eventbridge' !== $hook_suffix ) {
			return;
		}

		$script_handle = 'eventbridge-dashboard';
		$plugin_url    = plugin_dir_url( dirname( __DIR__ ) . '/eventbridge.php' );

		wp_enqueue_style( $script_handle, $plugin_url . 'assets/css/eventbridge-dashboard.css', array(), '0.1.0' );
		wp_enqueue_script( $script_handle, $plugin_url . 'assets/js/eventbridge-dashboard.js', array(), '0.1.0', true );
	}

	public function handle_event_form() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' !== $request_method ) {
			return;
		}

		$form = isset( $_POST['eventbridge_form'] ) && is_scalar( $_POST['eventbridge_form'] ) ? sanitize_key( wp_unslash( (string) $_POST['eventbridge_form'] ) ) : '';

		if ( 'add_event' !== $form ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om events toe te voegen.', 'eventbridge' ) );
		}

		check_admin_referer( 'eventbridge_add_event', 'eventbridge_event_nonce' );

		$input                   = isset( $_POST['eventbridge_event'] ) && is_array( $_POST['eventbridge_event'] ) ? $_POST['eventbridge_event'] : array();
		$validation              = $this->events->validate_event( $input );
		$this->event_form_values = $validation['event'];

		if ( ! empty( $validation['errors'] ) ) {
			foreach ( $validation['errors'] as $index => $message ) {
				add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_event_error_' . $index, $message );
			}
			return;
		}

		if ( ! $this->events->add_event( $validation['event'] ) ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_event_save_failed', __( 'Het event kon niet worden opgeslagen.', 'eventbridge' ) );
			return;
		}

		$redirect_url = add_query_arg(
			array(
				'page'                    => self::SETTINGS_PAGE_SLUG,
				'eventbridge_event_added' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function handle_update_event_form() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' !== $request_method ) {
			return;
		}

		$form = isset( $_POST['eventbridge_form'] ) && is_scalar( $_POST['eventbridge_form'] ) ? sanitize_key( wp_unslash( (string) $_POST['eventbridge_form'] ) ) : '';

		if ( 'update_event' !== $form ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om events te bewerken.', 'eventbridge' ) );
		}

		$input      = isset( $_POST['eventbridge_event'] ) && is_array( $_POST['eventbridge_event'] ) ? $_POST['eventbridge_event'] : array();
		$validation = $this->events->validate_event( $input );
		$event_key  = isset( $_POST['eventbridge_event_key'] ) && is_string( $_POST['eventbridge_event_key'] ) ? wp_unslash( $_POST['eventbridge_event_key'] ) : '';

		$this->event_form_values = $validation['event'];

		if ( ! $this->events->is_valid_event_key( $event_key ) ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_update_invalid_key', __( 'Het event kon niet worden bijgewerkt omdat de eventsleutel ongeldig is.', 'eventbridge' ) );
			return;
		}

		$event = $this->events->get_event( $event_key );

		if ( false === $event ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_update_not_found', __( 'Het event kon niet worden bijgewerkt omdat het niet bestaat.', 'eventbridge' ) );
			return;
		}

		$this->is_editing_event  = true;
		$this->editing_event_key = $event_key;

		$nonce = isset( $_POST['eventbridge_update_nonce'] ) && is_string( $_POST['eventbridge_update_nonce'] ) ? wp_unslash( $_POST['eventbridge_update_nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, 'eventbridge_update_event_' . $event_key ) ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_update_invalid_nonce', __( 'Het event kon niet worden bijgewerkt omdat de beveiligingscontrole is mislukt.', 'eventbridge' ) );
			return;
		}

		if ( ! empty( $validation['errors'] ) ) {
			foreach ( $validation['errors'] as $index => $message ) {
				add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_update_error_' . $index, $message );
			}
			return;
		}

		$status = $this->events->update_event( $event_key, $validation['event'] );

		if ( 'updated' !== $status ) {
			$message = 'not_found' === $status
				? __( 'Het event kon niet worden bijgewerkt omdat het niet bestaat.', 'eventbridge' )
				: __( 'Het event kon niet worden bijgewerkt omdat de opslag is mislukt.', 'eventbridge' );
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_update_' . $status, $message );
			return;
		}

		$redirect_url = add_query_arg(
			array(
				'page'                      => self::SETTINGS_PAGE_SLUG,
				'eventbridge_event_updated' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function handle_delete_event_form() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' !== $request_method ) {
			return;
		}

		$form = isset( $_POST['eventbridge_form'] ) && is_scalar( $_POST['eventbridge_form'] ) ? sanitize_key( wp_unslash( (string) $_POST['eventbridge_form'] ) ) : '';

		if ( 'delete_event' !== $form ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om events te verwijderen.', 'eventbridge' ) );
		}

		if ( ! isset( $_POST['eventbridge_event_key'] ) ) {
			$this->redirect_after_delete( 'missing_key' );
		}

		if ( ! is_string( $_POST['eventbridge_event_key'] ) ) {
			$this->redirect_after_delete( 'invalid_key' );
		}

		$event_key = wp_unslash( $_POST['eventbridge_event_key'] );

		if ( ! preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_key ) ) {
			$this->redirect_after_delete( 'invalid_key' );
		}

		$nonce = isset( $_POST['eventbridge_delete_nonce'] ) && is_string( $_POST['eventbridge_delete_nonce'] ) ? wp_unslash( $_POST['eventbridge_delete_nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, 'eventbridge_delete_event_' . $event_key ) ) {
			$this->redirect_after_delete( 'invalid_nonce' );
		}

		$this->redirect_after_delete( $this->events->delete_event( $event_key ) );
	}

	private function redirect_after_delete( $status ) {
		$redirect_url = add_query_arg(
			array(
				'page'                      => self::SETTINGS_PAGE_SLUG,
				'eventbridge_delete_status' => $status,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'EventBridge Dashboard', 'eventbridge' ),
			__( 'EventBridge', 'eventbridge' ),
			'manage_options',
			'eventbridge',
			array( $this, 'render_dashboard_page' ),
			'dashicons-share'
		);

		add_submenu_page( 'eventbridge', __( 'Dashboard', 'eventbridge' ), __( 'Dashboard', 'eventbridge' ), 'manage_options', 'eventbridge', array( $this, 'render_dashboard_page' ) );
		add_submenu_page( 'eventbridge', __( 'Instellingen', 'eventbridge' ), __( 'Instellingen', 'eventbridge' ), 'manage_options', self::SETTINGS_PAGE_SLUG, array( $this, 'render_settings_page' ) );
	}

	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'eventbridge' ) );
		}

		$timezone   = wp_timezone();
		$today      = new DateTimeImmutable( 'today', $timezone );
		$period     = $this->get_dashboard_period( $today );
		$cutoff     = $today->modify( '-6 days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$statistics = $this->calculate_dashboard_statistics( $this->log->get_logs_since( $cutoff ), $period, $timezone );
		$chart_data = $this->get_dashboard_chart_data( $statistics );
		$encoded    = wp_json_encode( $chart_data );

		if ( false !== $encoded ) {
			wp_add_inline_script( 'eventbridge-dashboard', 'window.EventBridgeDashboard = ' . $encoded . ';', 'before' );
		}
		?>
		<div class="wrap eventbridge-dashboard">
			<h1><?php echo esc_html__( 'EventBridge Dashboard', 'eventbridge' ); ?></h1>
			<?php $this->render_overview_cards( $statistics['totals'] ); ?>
			<?php $this->render_dashboard_charts( $chart_data ); ?>
			<?php $this->render_event_overview( $statistics['events'] ); ?>
			<div class="eventbridge-dashboard__panel eventbridge-dashboard__table-panel"><?php $this->render_activity_log(); ?></div>
		</div>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'eventbridge' ) );
		}

		$this->load_editing_event();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'EventBridge Instellingen', 'eventbridge' ); ?></h1>
			<?php settings_errors( EventBridge_Settings::OPTION_NAME ); ?>
			<?php $this->render_event_notices(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( EventBridge_Settings::OPTION_GROUP );
				do_settings_sections( EventBridge_Settings::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<?php $this->render_event_list(); ?>
			<?php $this->render_event_form(); ?>
		</div>
		<?php
	}

	public function render_pixel_id_field() {
		$settings = $this->settings->get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[pixel_id]" value="<?php echo esc_attr( $settings['pixel_id'] ); ?>">
		<?php
	}

	public function render_capi_token_field() {
		$settings = $this->settings->get_settings();
		?>
		<input type="password" class="regular-text" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[capi_token]" value="<?php echo esc_attr( $settings['capi_token'] ); ?>" autocomplete="off">
		<?php
	}

	public function render_debug_field() {
		$settings = $this->settings->get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[debug]" value="1" <?php checked( $settings['debug'] ); ?>>
			<?php echo esc_html__( 'Debugmodus inschakelen', 'eventbridge' ); ?>
		</label>
		<p class="description"><?php echo esc_html__( 'Debugmodus zal later extra feedback tonen.', 'eventbridge' ); ?></p>
		<?php
	}

	private function render_event_notices() {
		$event_added = isset( $_GET['eventbridge_event_added'] ) && is_scalar( $_GET['eventbridge_event_added'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eventbridge_event_added'] ) ) : '';
		$event_updated = isset( $_GET['eventbridge_event_updated'] ) && is_scalar( $_GET['eventbridge_event_updated'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eventbridge_event_updated'] ) ) : '';
		$delete_status = isset( $_GET['eventbridge_delete_status'] ) && is_scalar( $_GET['eventbridge_delete_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['eventbridge_delete_status'] ) ) : '';

		if ( '1' === $event_added ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_event_added', __( 'Het event is toegevoegd.', 'eventbridge' ), 'success' );
		}

		if ( '1' === $event_updated ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_event_updated', __( 'Het event is bijgewerkt.', 'eventbridge' ), 'success' );
		}

		$delete_notices = array(
			'deleted'       => array( 'eventbridge_event_deleted', __( 'Het event is verwijderd.', 'eventbridge' ), 'success' ),
			'missing_key'   => array( 'eventbridge_delete_missing_key', __( 'Het event kon niet worden verwijderd omdat de eventsleutel ontbreekt.', 'eventbridge' ), 'error' ),
			'invalid_key'   => array( 'eventbridge_delete_invalid_key', __( 'Het event kon niet worden verwijderd omdat de eventsleutel ongeldig is.', 'eventbridge' ), 'error' ),
			'not_found'     => array( 'eventbridge_delete_not_found', __( 'Het event kon niet worden verwijderd omdat het niet bestaat.', 'eventbridge' ), 'error' ),
			'invalid_nonce' => array( 'eventbridge_delete_invalid_nonce', __( 'Het event kon niet worden verwijderd omdat de beveiligingscontrole is mislukt.', 'eventbridge' ), 'error' ),
			'save_failed'   => array( 'eventbridge_delete_save_failed', __( 'Het event kon niet worden verwijderd omdat de opslag is mislukt.', 'eventbridge' ), 'error' ),
		);

		if ( isset( $delete_notices[ $delete_status ] ) ) {
			$notice = $delete_notices[ $delete_status ];
			add_settings_error( EventBridge_Events::OPTION_NAME, $notice[0], $notice[1], $notice[2] );
		}

		settings_errors( EventBridge_Events::OPTION_NAME );
	}

	private function load_editing_event() {
		if ( $this->is_editing_event || ! array_key_exists( 'edit_event', $_GET ) ) {
			return;
		}

		if ( ! is_string( $_GET['edit_event'] ) ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_edit_invalid_key', __( 'Het event kan niet worden bewerkt omdat de eventsleutel ongeldig is.', 'eventbridge' ) );
			return;
		}

		$event_key = wp_unslash( $_GET['edit_event'] );

		if ( ! $this->events->is_valid_event_key( $event_key ) ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_edit_invalid_key', __( 'Het event kan niet worden bewerkt omdat de eventsleutel ongeldig is.', 'eventbridge' ) );
			return;
		}

		$event = $this->events->get_event( $event_key );

		if ( false === $event ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_edit_not_found', __( 'Het event kan niet worden bewerkt omdat het niet bestaat.', 'eventbridge' ) );
			return;
		}

		$this->is_editing_event  = true;
		$this->editing_event_key = $event_key;
		$this->event_form_values = $this->events->normalize_event( $event );
	}

	private function render_event_list() {
		$events = $this->events->get_events();
		?>
		<h2><?php echo esc_html__( 'Ingestelde events', 'eventbridge' ); ?></h2>
		<?php if ( empty( $events ) ) : ?>
			<p><?php echo esc_html__( 'Er zijn nog geen events ingesteld.', 'eventbridge' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Interne naam', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Beschrijving', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Meta-eventnaam', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Browser', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'CAPI', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Actief', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Trigger', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Triggerconfiguratie', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Acties', 'eventbridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event_key => $event ) : ?>
						<?php if ( ! is_array( $event ) ) { continue; } ?>
						<?php
						$event = $this->events->normalize_event( $event );
						$edit_url = add_query_arg(
							array(
								'page'       => self::SETTINGS_PAGE_SLUG,
								'edit_event' => $event_key,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><?php echo esc_html( isset( $event['label'] ) && is_scalar( $event['label'] ) ? (string) $event['label'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $event['description'] ) && is_scalar( $event['description'] ) ? (string) $event['description'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $event['event_name'] ) && is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '' ); ?></td>
							<td><?php echo ! empty( $event['browser'] ) ? esc_html__( 'Ja', 'eventbridge' ) : esc_html__( 'Nee', 'eventbridge' ); ?></td>
							<td><?php echo ! empty( $event['capi'] ) ? esc_html__( 'Ja', 'eventbridge' ) : esc_html__( 'Nee', 'eventbridge' ); ?></td>
							<td><?php echo ! empty( $event['enabled'] ) ? esc_html__( 'Ja', 'eventbridge' ) : esc_html__( 'Nee', 'eventbridge' ); ?></td>
							<td><?php echo 'pageview' === $event['trigger_type'] ? esc_html__( 'Pagina bezocht', 'eventbridge' ) : esc_html__( 'Klik', 'eventbridge' ); ?></td>
							<td>
								<?php
								if ( 'pageview' === $event['trigger_type'] ) {
									$match_labels = array(
										'path_exact'    => __( 'Pad is exact', 'eventbridge' ),
										'path_contains' => __( 'Pad bevat', 'eventbridge' ),
										'url_exact'     => __( 'Volledige URL is exact', 'eventbridge' ),
									);
									$match_label = isset( $match_labels[ $event['url_match_type'] ] ) ? $match_labels[ $event['url_match_type'] ] : '';
									echo '' !== $match_label && '' !== $event['url_match_value'] ? esc_html( $match_label . ': ' . $event['url_match_value'] ) : '&mdash;';
								} else {
									echo '' !== $event['selector'] ? esc_html( $event['selector'] ) : '&mdash;';
								}
								?>
							</td>
							<td>
								<a href="<?php echo esc_url( $edit_url ) . '#event-form'; ?>"><?php echo esc_html__( 'Bewerken', 'eventbridge' ); ?></a>
								<form action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ) ); ?>" method="post">
									<input type="hidden" name="eventbridge_form" value="delete_event">
									<input type="hidden" name="eventbridge_event_key" value="<?php echo esc_attr( $event_key ); ?>">
									<?php wp_nonce_field( 'eventbridge_delete_event_' . $event_key, 'eventbridge_delete_nonce' ); ?>
									<?php submit_button( __( 'Verwijderen', 'eventbridge' ), 'small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_event_form() {
		$values      = $this->event_form_values;
		$form_action = $this->is_editing_event ? 'update_event' : 'add_event';
		?>
		<h2><?php echo $this->is_editing_event ? esc_html__( 'Event bewerken', 'eventbridge' ) : esc_html__( 'Nieuw event toevoegen', 'eventbridge' ); ?></h2>
		<form id="event-form" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ) ); ?>" method="post">
			<input type="hidden" name="eventbridge_form" value="<?php echo esc_attr( $form_action ); ?>">
			<?php if ( $this->is_editing_event ) : ?>
				<input type="hidden" name="eventbridge_event_key" value="<?php echo esc_attr( $this->editing_event_key ); ?>">
				<?php wp_nonce_field( 'eventbridge_update_event_' . $this->editing_event_key, 'eventbridge_update_nonce' ); ?>
			<?php else : ?>
				<?php wp_nonce_field( 'eventbridge_add_event', 'eventbridge_event_nonce' ); ?>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="eventbridge_event_label"><?php echo esc_html__( 'Interne naam', 'eventbridge' ); ?></label></th>
					<td><input type="text" class="regular-text" id="eventbridge_event_label" name="eventbridge_event[label]" value="<?php echo esc_attr( $values['label'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::LABEL_MAX_LENGTH ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="eventbridge_event_description"><?php echo esc_html__( 'Beschrijving', 'eventbridge' ); ?></label></th>
					<td><textarea class="large-text" id="eventbridge_event_description" name="eventbridge_event[description]" maxlength="<?php echo esc_attr( EventBridge_Events::DESCRIPTION_MAX_LENGTH ); ?>" rows="4"><?php echo esc_textarea( $values['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="eventbridge_event_name"><?php echo esc_html__( 'Meta-eventnaam', 'eventbridge' ); ?></label></th>
					<td><input type="text" class="regular-text" id="eventbridge_event_name" name="eventbridge_event[event_name]" value="<?php echo esc_attr( $values['event_name'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::EVENT_NAME_MAX_LENGTH ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="eventbridge_event_trigger_type"><?php echo esc_html__( 'Triggertype', 'eventbridge' ); ?></label></th>
					<td><select id="eventbridge_event_trigger_type" name="eventbridge_event[trigger_type]" required><option value="click" <?php selected( $values['trigger_type'], 'click' ); ?>><?php echo esc_html__( 'Klik op CSS-selector', 'eventbridge' ); ?></option><option value="pageview" <?php selected( $values['trigger_type'], 'pageview' ); ?>><?php echo esc_html__( 'Pagina bezocht', 'eventbridge' ); ?></option></select></td>
				</tr>
				<tr id="eventbridge-selector-row">
					<th scope="row"><label for="eventbridge_event_selector"><?php echo esc_html__( 'CSS-selector', 'eventbridge' ); ?></label></th>
					<td><input type="text" class="regular-text" id="eventbridge_event_selector" name="eventbridge_event[selector]" value="<?php echo esc_attr( $values['selector'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::SELECTOR_MAX_LENGTH ); ?>" required></td>
				</tr>
				<tr id="eventbridge-url-match-type-row">
					<th scope="row"><label for="eventbridge_event_url_match_type"><?php echo esc_html__( 'URL-vergelijking', 'eventbridge' ); ?></label></th>
					<td><select id="eventbridge_event_url_match_type" name="eventbridge_event[url_match_type]"><option value="path_exact" <?php selected( $values['url_match_type'], 'path_exact' ); ?>><?php echo esc_html__( 'Pad is exact', 'eventbridge' ); ?></option><option value="path_contains" <?php selected( $values['url_match_type'], 'path_contains' ); ?>><?php echo esc_html__( 'Pad bevat', 'eventbridge' ); ?></option><option value="url_exact" <?php selected( $values['url_match_type'], 'url_exact' ); ?>><?php echo esc_html__( 'Volledige URL is exact', 'eventbridge' ); ?></option></select></td>
				</tr>
				<tr id="eventbridge-url-match-value-row">
					<th scope="row"><label for="eventbridge_event_url_match_value"><?php echo esc_html__( 'URL-waarde', 'eventbridge' ); ?></label></th>
					<td><input type="text" class="large-text" id="eventbridge_event_url_match_value" name="eventbridge_event[url_match_value]" value="<?php echo esc_attr( $values['url_match_value'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::URL_MATCH_VALUE_MAX_LENGTH ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Parameters', 'eventbridge' ); ?></th>
					<td>
						<div id="eventbridge-event-parameters">
							<?php foreach ( $values['parameters'] as $index => $parameter ) : ?>
								<?php $this->render_parameter_row( $parameter, $index ); ?>
							<?php endforeach; ?>
						</div>
						<p><button type="button" class="button" id="eventbridge-add-parameter"><?php echo esc_html__( 'Parameter toevoegen', 'eventbridge' ); ?></button></p>
						<template id="eventbridge-parameter-template"><?php $this->render_parameter_row( array( 'name' => '', 'value' => '' ), '__INDEX__' ); ?></template>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Browser verzenden', 'eventbridge' ); ?></th>
					<td><label><input type="checkbox" name="eventbridge_event[browser]" value="1" <?php checked( $values['browser'] ); ?>> <?php echo esc_html__( 'Browser verzenden', 'eventbridge' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Conversion API verzenden', 'eventbridge' ); ?></th>
					<td><label><input type="checkbox" name="eventbridge_event[capi]" value="1" <?php checked( $values['capi'] ); ?>> <?php echo esc_html__( 'Conversion API verzenden', 'eventbridge' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Actief', 'eventbridge' ); ?></th>
					<td><label><input type="checkbox" name="eventbridge_event[enabled]" value="1" <?php checked( $values['enabled'] ); ?>> <?php echo esc_html__( 'Actief', 'eventbridge' ); ?></label></td>
				</tr>
			</table>
			<?php if ( $this->is_editing_event ) : ?>
				<?php submit_button( __( 'Wijzigingen opslaan', 'eventbridge' ), 'primary', 'submit', false ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Annuleren', 'eventbridge' ); ?></a>
			<?php else : ?>
				<?php submit_button( __( 'Event toevoegen', 'eventbridge' ) ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	private function render_parameter_row( $parameter, $index ) {
		$name  = isset( $parameter['name'] ) && is_scalar( $parameter['name'] ) ? (string) $parameter['name'] : '';
		$value = isset( $parameter['value'] ) && is_scalar( $parameter['value'] ) ? (string) $parameter['value'] : '';
		?>
		<div class="eventbridge-parameter-row">
			<label>
				<?php echo esc_html__( 'Parameternaam', 'eventbridge' ); ?>
				<input type="text" class="regular-text" name="eventbridge_event[parameters][<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::PARAMETER_NAME_MAX_LENGTH ); ?>">
			</label>
			<label>
				<?php echo esc_html__( 'Waarde', 'eventbridge' ); ?>
				<input type="text" class="regular-text" name="eventbridge_event[parameters][<?php echo esc_attr( $index ); ?>][value]" value="<?php echo esc_attr( $value ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::PARAMETER_VALUE_MAX_LENGTH ); ?>">
			</label>
			<button type="button" class="button-link-delete eventbridge-remove-parameter"><?php echo esc_html__( 'Verwijderen', 'eventbridge' ); ?></button>
		</div>
		<?php
	}

	private function get_dashboard_period( DateTimeImmutable $today ) {
		$period = array();

		for ( $offset = 6; $offset >= 0; $offset-- ) {
			$date         = $today->modify( '-' . $offset . ' days' );
			$key          = $date->format( 'Y-m-d' );
			$period[ $key ] = array(
				'label'        => wp_date( 'j M', $date->getTimestamp(), $date->getTimezone() ),
				'interactions' => array(),
				'browser'      => 0,
				'capi_started' => 0,
			);
		}

		return $period;
	}

	private function calculate_dashboard_statistics( $logs, $period, DateTimeZone $timezone ) {
		$totals = array( 'interactions' => array(), 'browser' => 0, 'endpoint_accepted' => 0, 'endpoint_rejected' => 0, 'capi_started' => 0, 'capi_not_started' => 0 );
		$events = array();

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$event_id   = isset( $log['event_id'] ) && is_scalar( $log['event_id'] ) ? trim( (string) $log['event_id'] ) : '';
			$event_key  = isset( $log['event_key'] ) && is_scalar( $log['event_key'] ) ? (string) $log['event_key'] : '';
			$event_name = isset( $log['event_name'] ) && is_scalar( $log['event_name'] ) ? (string) $log['event_name'] : '';
			$source     = isset( $log['source'] ) && is_scalar( $log['source'] ) ? (string) $log['source'] : '';
			$message    = isset( $log['message'] ) && is_scalar( $log['message'] ) ? (string) $log['message'] : '';
			$metric     = $this->get_dashboard_metric( $source, $message );
			$day_key    = $this->get_dashboard_day_key( isset( $log['created_at'] ) ? $log['created_at'] : null, $timezone );

			if ( '' !== $event_id ) {
				$totals['interactions'][ $event_id ] = true;
			}
			if ( null !== $metric ) {
				$totals[ $metric ]++;
			}
			if ( isset( $period[ $day_key ] ) ) {
				if ( '' !== $event_id ) {
					$period[ $day_key ]['interactions'][ $event_id ] = true;
				}
				if ( 'browser' === $metric || 'capi_started' === $metric ) {
					$period[ $day_key ][ $metric ]++;
				}
			}

			$group_key = '' !== $event_key ? 'key:' . $event_key : ( '' !== $event_name ? 'name:' . $event_name : '' );
			if ( '' === $group_key ) {
				continue;
			}

			if ( ! isset( $events[ $group_key ] ) ) {
				$events[ $group_key ] = array( 'event_name' => $event_name, 'interactions' => array(), 'browser' => 0, 'endpoint_accepted' => 0, 'endpoint_rejected' => 0, 'capi_started' => 0, 'capi_not_started' => 0 );
			}
			if ( '' !== $event_name ) {
				$events[ $group_key ]['event_name'] = $event_name;
			}
			if ( '' !== $event_id ) {
				$events[ $group_key ]['interactions'][ $event_id ] = true;
			}
			if ( null !== $metric ) {
				$events[ $group_key ][ $metric ]++;
			}
		}

		$totals['interactions'] = count( $totals['interactions'] );
		foreach ( $period as &$day ) {
			$day['interactions'] = count( $day['interactions'] );
		}
		unset( $day );
		foreach ( $events as &$event ) {
			$event['interactions'] = count( $event['interactions'] );
		}
		unset( $event );
		uasort( $events, function ( $left, $right ) { return strcasecmp( $left['event_name'], $right['event_name'] ); } );

		return array( 'totals' => $totals, 'events' => $events, 'daily' => $period );
	}

	private function get_dashboard_day_key( $created_at, DateTimeZone $timezone ) {
		if ( ! is_scalar( $created_at ) || '' === (string) $created_at ) {
			return '';
		}

		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $created_at, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();

		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== (string) $created_at ) {
			return '';
		}

		return $date->setTimezone( $timezone )->format( 'Y-m-d' );
	}

	private function get_dashboard_chart_data( $statistics ) {
		$daily = array( 'labels' => array(), 'interactions' => array(), 'browser' => array(), 'capi_started' => array() );

		foreach ( $statistics['daily'] as $day ) {
			$daily['labels'][]       = $day['label'];
			$daily['interactions'][] = $day['interactions'];
			$daily['browser'][]      = $day['browser'];
			$daily['capi_started'][] = $day['capi_started'];
		}

		$chart_events = array_values( $statistics['events'] );
		usort( $chart_events, function ( $left, $right ) {
			$comparison = $right['interactions'] <=> $left['interactions'];

			return 0 !== $comparison ? $comparison : strcasecmp( $left['event_name'], $right['event_name'] );
		} );
		$chart_events = array_slice( $chart_events, 0, 10 );
		$events       = array( 'labels' => array(), 'interactions' => array(), 'browser' => array(), 'capi_started' => array() );
		$fallback     = 0;

		foreach ( $chart_events as $event ) {
			$label = trim( $event['event_name'] );
			if ( '' === $label ) {
				$fallback++;
				$label = sprintf( __( 'Naamloos event %d', 'eventbridge' ), $fallback );
			}
			$events['labels'][]       = $label;
			$events['interactions'][] = $event['interactions'];
			$events['browser'][]      = $event['browser'];
			$events['capi_started'][] = $event['capi_started'];
		}

		return array( 'daily' => $daily, 'events' => $events );
	}

	private function get_dashboard_metric( $source, $message ) {
		$metrics = array(
			'browser|Browser event invoked.'                                => 'browser',
			'custom_event_endpoint|Custom event endpoint request accepted.' => 'endpoint_accepted',
			'custom_event_endpoint|Custom event endpoint request rejected.' => 'endpoint_rejected',
			'meta_capi|Custom CAPI request started.'                        => 'capi_started',
			'meta_capi|Custom CAPI request not started.'                    => 'capi_not_started',
		);
		$key = $source . '|' . $message;

		return isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
	}

	private function render_overview_cards( $totals ) {
		$cards = array(
			'interactions'      => array( __( 'Unieke interacties', 'eventbridge' ), __( 'Unieke, gelogde event-ID\'s.', 'eventbridge' ) ),
			'browser'           => array( __( 'Browser events', 'eventbridge' ), __( 'Browseraanroepen die EventBridge logde.', 'eventbridge' ) ),
			'endpoint_accepted' => array( __( 'Endpoint accepted', 'eventbridge' ), __( 'Endpointverzoeken die EventBridge accepteerde.', 'eventbridge' ) ),
			'endpoint_rejected' => array( __( 'Endpoint rejected', 'eventbridge' ), __( 'Endpointverzoeken die EventBridge afwees.', 'eventbridge' ) ),
			'capi_started'      => array( __( 'CAPI started', 'eventbridge' ), __( 'CAPI-verzoeken die EventBridge startte.', 'eventbridge' ) ),
			'capi_not_started'  => array( __( 'CAPI not started', 'eventbridge' ), __( 'CAPI-verzoeken die EventBridge niet startte.', 'eventbridge' ) ),
		);
		?>
		<h2><?php echo esc_html__( 'Laatste 7 dagen', 'eventbridge' ); ?></h2>
		<div class="eventbridge-dashboard__cards">
			<?php foreach ( $cards as $key => $card ) : ?>
				<div class="eventbridge-dashboard__card"><span class="eventbridge-dashboard__card-label"><?php echo esc_html( $card[0] ); ?></span><strong><?php echo esc_html( (string) $totals[ $key ] ); ?></strong><p><?php echo esc_html( $card[1] ); ?></p></div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_dashboard_charts( $chart_data ) {
		$daily_has_data  = array_sum( $chart_data['daily']['interactions'] ) + array_sum( $chart_data['daily']['browser'] ) + array_sum( $chart_data['daily']['capi_started'] ) > 0;
		$events_has_data = array_sum( $chart_data['events']['interactions'] ) + array_sum( $chart_data['events']['browser'] ) + array_sum( $chart_data['events']['capi_started'] ) > 0;
		?>
		<div class="eventbridge-dashboard__charts">
			<section class="eventbridge-dashboard__panel"><h2><?php echo esc_html__( 'Activiteit over tijd', 'eventbridge' ); ?></h2><div class="eventbridge-dashboard__chart-wrap"<?php echo $daily_has_data ? '' : ' hidden'; ?>><canvas id="eventbridge-daily-chart" aria-label="<?php echo esc_attr__( 'Activiteit over de laatste zeven dagen', 'eventbridge' ); ?>"></canvas></div><p class="eventbridge-dashboard__empty"<?php echo $daily_has_data ? ' hidden' : ''; ?>><?php echo esc_html__( 'Er is nog onvoldoende activiteit om deze grafiek te tonen.', 'eventbridge' ); ?></p></section>
			<section class="eventbridge-dashboard__panel"><h2><?php echo esc_html__( 'Vergelijking per event', 'eventbridge' ); ?></h2><div class="eventbridge-dashboard__chart-wrap"<?php echo $events_has_data ? '' : ' hidden'; ?>><canvas id="eventbridge-events-chart" aria-label="<?php echo esc_attr__( 'Vergelijking van de actiefste events', 'eventbridge' ); ?>"></canvas></div><p class="eventbridge-dashboard__empty"<?php echo $events_has_data ? ' hidden' : ''; ?>><?php echo esc_html__( 'Er is nog onvoldoende eventactiviteit om deze grafiek te tonen.', 'eventbridge' ); ?></p></section>
		</div>
		<?php
	}

	private function render_event_overview( $events ) {
		?>
		<div class="eventbridge-dashboard__panel eventbridge-dashboard__table-panel"><h2><?php echo esc_html__( 'Eventoverzicht', 'eventbridge' ); ?></h2>
		<?php if ( empty( $events ) ) : ?>
			<p><?php echo esc_html__( 'Er zijn in de laatste 7 dagen geen eventactiviteiten gelogd.', 'eventbridge' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Eventnaam', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Interacties', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Browser', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Endpoint accepted', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Endpoint rejected', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'CAPI started', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'CAPI not started', 'eventbridge' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr><td><?php $this->render_log_text( $event['event_name'] ); ?></td><td><?php echo esc_html( (string) $event['interactions'] ); ?></td><td><?php echo esc_html( (string) $event['browser'] ); ?></td><td><?php echo esc_html( (string) $event['endpoint_accepted'] ); ?></td><td><?php echo esc_html( (string) $event['endpoint_rejected'] ); ?></td><td><?php echo esc_html( (string) $event['capi_started'] ); ?></td><td><?php echo esc_html( (string) $event['capi_not_started'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		</div>
		<?php
	}

	private function render_activity_log() {
		$logs = $this->log->get_recent_logs( 100 );
		?>
		<h2><?php echo esc_html__( 'Activiteitenlog', 'eventbridge' ); ?></h2>
		<?php if ( empty( $logs ) ) : ?>
			<p><?php echo esc_html__( 'Er zijn nog geen activiteiten gelogd.', 'eventbridge' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Tijd', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Level', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Bron', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Event', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Event-ID', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Bericht', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Pagina', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Details', 'eventbridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<?php if ( ! is_array( $log ) ) { continue; } ?>
						<tr>
							<td><?php $this->render_log_time( isset( $log['created_at'] ) ? $log['created_at'] : null ); ?></td>
							<td><?php $this->render_log_level( isset( $log['level'] ) ? $log['level'] : null ); ?></td>
							<td><?php $this->render_log_text( isset( $log['source'] ) ? $log['source'] : null ); ?></td>
							<td><?php $this->render_log_event( $log ); ?></td>
							<td><?php $this->render_log_text( isset( $log['event_id'] ) ? $log['event_id'] : null ); ?></td>
							<td><?php echo esc_html( isset( $log['message'] ) && is_scalar( $log['message'] ) ? (string) $log['message'] : '' ); ?></td>
							<td><?php $this->render_log_page_url( isset( $log['page_url'] ) ? $log['page_url'] : null ); ?></td>
							<td><?php $this->render_log_context( isset( $log['context'] ) ? $log['context'] : null ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_log_time( $created_at ) {
		if ( ! is_scalar( $created_at ) || '' === (string) $created_at ) {
			echo '&mdash;';
			return;
		}

		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $created_at, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();

		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== (string) $created_at ) {
			echo '&mdash;';
			return;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo esc_html( wp_date( $format, $date->getTimestamp(), wp_timezone() ) );
	}

	private function render_log_level( $level ) {
		$labels = array(
			'info'    => __( 'Info', 'eventbridge' ),
			'warning' => __( 'Waarschuwing', 'eventbridge' ),
			'error'   => __( 'Fout', 'eventbridge' ),
		);

		if ( is_scalar( $level ) && isset( $labels[ (string) $level ] ) ) {
			echo esc_html( $labels[ (string) $level ] );
			return;
		}

		$this->render_log_text( $level );
	}

	private function render_log_text( $value ) {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			echo '&mdash;';
			return;
		}

		echo esc_html( (string) $value );
	}

	private function render_log_event( $log ) {
		if ( isset( $log['event_name'] ) && is_scalar( $log['event_name'] ) && '' !== (string) $log['event_name'] ) {
			echo esc_html( (string) $log['event_name'] );
			return;
		}

		$this->render_log_text( isset( $log['event_key'] ) ? $log['event_key'] : null );
	}

	private function render_log_page_url( $page_url ) {
		$url = is_scalar( $page_url ) ? wp_http_validate_url( (string) $page_url ) : false;

		if ( false === $url ) {
			echo '&mdash;';
			return;
		}

		printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html__( 'Openen', 'eventbridge' ) );
	}

	private function render_log_context( $context ) {
		if ( ! is_scalar( $context ) || '' === (string) $context ) {
			echo '&mdash;';
			return;
		}

		$decoded = json_decode( (string) $context, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			echo '&mdash;';
			return;
		}

		$formatted = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $formatted ) {
			echo '&mdash;';
			return;
		}

		?>
		<details>
			<summary><?php echo esc_html__( 'Bekijken', 'eventbridge' ); ?></summary>
			<pre><?php echo esc_html( $formatted ); ?></pre>
		</details>
		<?php
	}
}
