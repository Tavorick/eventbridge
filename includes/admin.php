<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Admin {
	const SETTINGS_PAGE_SLUG = 'eventbridge-settings';

	private $settings;
	private $events;
	private $log;
	private $fluent_booking;
	private $upgrade_status;
	private $woocommerce;
	private $conditions;
	private $event_form_values;
	private $editing_event_key = '';
	private $is_editing_event  = false;
	private $trigger_error_numbers = array();

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events, EventBridge_Log $log, EventBridge_Fluent_Booking $fluent_booking, EventBridge_Upgrade_Status $upgrade_status, EventBridge_WooCommerce $woocommerce, EventBridge_Conditions $conditions = null ) {
		$this->settings          = $settings;
		$this->events            = $events;
		$this->log               = $log;
		$this->fluent_booking    = $fluent_booking;
		$this->upgrade_status    = $upgrade_status;
		$this->woocommerce      = $woocommerce;
		$this->conditions       = $conditions;
		$this->event_form_values = $events->get_form_defaults();
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_event_parameter_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_event_form' ) );
		add_action( 'admin_init', array( $this, 'handle_update_event_form' ) );
		add_action( 'admin_init', array( $this, 'handle_delete_event_form' ) );
		add_action( 'wp_ajax_eventbridge_condition_search', array( $this, 'handle_condition_search' ) );
	}

	public function enqueue_event_parameter_assets( $hook_suffix ) {
		if ( 'eventbridge_page_' . self::SETTINGS_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$admin_style_path         = dirname( __DIR__ ) . '/assets/css/eventbridge-admin.css';
		$parameter_script_path    = dirname( __DIR__ ) . '/assets/js/eventbridge-event-parameters.js';
		$condition_script_path    = dirname( __DIR__ ) . '/assets/js/eventbridge-conditions.js';
		$admin_style_version      = is_readable( $admin_style_path ) ? (string) filemtime( $admin_style_path ) : EVENTBRIDGE_VERSION;
		$parameter_script_version = is_readable( $parameter_script_path ) ? (string) filemtime( $parameter_script_path ) : EVENTBRIDGE_VERSION;
		$condition_script_version = is_readable( $condition_script_path ) ? (string) filemtime( $condition_script_path ) : EVENTBRIDGE_VERSION;
		$admin_style_dependencies = array();
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			$admin_style_dependencies[] = 'woocommerce_admin_styles';
		}

		wp_enqueue_style(
			'eventbridge-admin',
			plugins_url( 'assets/css/eventbridge-admin.css', dirname( __FILE__ ) ),
			$admin_style_dependencies,
			$admin_style_version
		);
		wp_enqueue_script(
			'eventbridge-event-parameters',
			plugins_url( 'assets/js/eventbridge-event-parameters.js', dirname( __FILE__ ) ),
			array(),
			$parameter_script_version,
			true
		);

		$condition_dependencies = array( 'jquery', 'eventbridge-event-parameters' );
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
			$condition_dependencies[] = 'selectWoo';
		}

		wp_enqueue_script(
			'eventbridge-conditions',
			plugins_url( 'assets/js/eventbridge-conditions.js', dirname( __FILE__ ) ),
			$condition_dependencies,
			$condition_script_version,
			true
		);
		wp_localize_script(
			'eventbridge-conditions',
			'eventbridgeConditions',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'eventbridge_condition_search' ),
				'provider'      => 'woocommerce',
				'catalog'       => $this->conditions ? $this->conditions->get_catalog( 'woocommerce' ) : array(),
				'maxReferences' => EventBridge_Conditions::MAX_REFERENCES,
				'texts'         => array(
					'chooseValue' => __( 'Zoek en kies een waarde', 'eventbridge' ),
					'yes'         => __( 'Ja', 'eventbridge' ),
					'remove'      => __( 'Verwijderen', 'eventbridge' ),
				),
			)
		);
	}

	public function handle_condition_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Onvoldoende rechten.', 'eventbridge' ) ), 403 );
		}

		check_ajax_referer( 'eventbridge_condition_search', 'nonce' );
		$provider = isset( $_GET['provider'] ) && is_scalar( $_GET['provider'] ) ? sanitize_key( wp_unslash( (string) $_GET['provider'] ) ) : '';
		$field    = isset( $_GET['field'] ) && is_scalar( $_GET['field'] ) ? sanitize_key( wp_unslash( (string) $_GET['field'] ) ) : '';
		$search   = isset( $_GET['q'] ) && is_scalar( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$page     = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? max( 1, absint( $_GET['page'] ) ) : 1;

		if ( ! $this->conditions || 'woocommerce' !== $provider || strlen( $search ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Ongeldige zoekopdracht.', 'eventbridge' ) ), 400 );
		}

		$catalog = $this->conditions->get_catalog( $provider );
		if ( ! isset( $catalog[ $field ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Ongeldig voorwaardenveld.', 'eventbridge' ) ), 400 );
		}

		$result = $this->conditions->search_values( $provider, $field, $search, $page, 20 );
		wp_send_json_success(
			array(
				'results' => isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array(),
				'more'    => ! empty( $result['more'] ),
			)
		);
	}

	public function enqueue_dashboard_assets( $hook_suffix ) {
		if ( 'toplevel_page_eventbridge' !== $hook_suffix ) {
			return;
		}

		$script_handle = 'eventbridge-dashboard';
		$plugin_url    = plugin_dir_url( dirname( __DIR__ ) . '/eventbridge.php' );

		wp_enqueue_style( 'eventbridge-admin', $plugin_url . 'assets/css/eventbridge-admin.css', array(), EVENTBRIDGE_VERSION );
		wp_enqueue_script( $script_handle, $plugin_url . 'assets/js/eventbridge-dashboard.js', array(), EVENTBRIDGE_VERSION, true );
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
		$validation              = $this->events->validate_event( $input, null, $this->fluent_booking->is_available() );
		$this->event_form_values = $validation['event'];

		if ( ! empty( $validation['errors'] ) ) {
			$this->set_trigger_error_numbers( $validation['errors'] );
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

		$input     = isset( $_POST['eventbridge_event'] ) && is_array( $_POST['eventbridge_event'] ) ? $_POST['eventbridge_event'] : array();
		$event_key = isset( $_POST['eventbridge_event_key'] ) && is_string( $_POST['eventbridge_event_key'] ) ? wp_unslash( $_POST['eventbridge_event_key'] ) : '';

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

		$validation              = $this->events->validate_event( $input, $event, $this->fluent_booking->is_available(), $event_key );
		$this->event_form_values = $validation['event'];

		if ( ! empty( $validation['errors'] ) ) {
			$this->set_trigger_error_numbers( $validation['errors'] );
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

	private function set_trigger_error_numbers( $errors ) {
		$this->trigger_error_numbers = array();
		foreach ( is_array( $errors ) ? $errors : array() as $error ) {
			if ( is_string( $error ) && preg_match( '/(?:^Trigger\s+|\strigger\s+)(\d+)(?::|\s|$)/iu', wp_strip_all_tags( $error ), $matches ) ) {
				$this->trigger_error_numbers[] = absint( $matches[1] );
			}
		}
		$this->trigger_error_numbers = array_values( array_unique( $this->trigger_error_numbers ) );
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

		$today               = new DateTimeImmutable( 'today', wp_timezone() );
		$period              = $this->get_dashboard_period( $today );
		$statistics          = $this->log->get_dashboard_statistics( $this->get_dashboard_day_ranges( $today ) );
		$statistics['daily'] = $this->merge_dashboard_period( $period, $statistics['daily'] );
		$chart_data          = $this->get_dashboard_chart_data( $statistics );
		$encoded             = wp_json_encode( $chart_data );

		if ( false !== $encoded ) {
			wp_add_inline_script( 'eventbridge-dashboard', 'window.EventBridgeDashboard = ' . $encoded . ';', 'before' );
		}
		?>
		<div class="wrap eventbridge-admin eventbridge-dashboard">
			<div class="eventbridge-admin__header">
				<h1><?php echo esc_html__( 'EventBridge Dashboard', 'eventbridge' ); ?></h1>
				<p><?php echo esc_html__( 'Overzicht van activiteit die EventBridge zelf op je website heeft geregistreerd.', 'eventbridge' ); ?></p>
			</div>
			<?php $this->upgrade_status->render_inline_status(); ?>
			<?php $this->render_ledger_budget_warning(); ?>
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
		<div class="wrap eventbridge-admin eventbridge-settings">
			<div class="eventbridge-admin__header">
				<h1><?php echo esc_html__( 'EventBridge Instellingen', 'eventbridge' ); ?></h1>
				<p><?php echo esc_html__( 'Koppel EventBridge met Meta en beheer de events die op je website worden gemeten.', 'eventbridge' ); ?></p>
			</div>
			<?php $this->upgrade_status->render_inline_status(); ?>
			<?php $this->render_ledger_budget_warning(); ?>
			<?php settings_errors( EventBridge_Settings::OPTION_NAME ); ?>
			<form action="options.php" method="post" class="eventbridge-settings__form">
				<?php settings_fields( EventBridge_Settings::OPTION_GROUP ); ?>
				<section class="eventbridge-admin__panel">
					<div class="eventbridge-admin__panel-heading">
						<h2><?php echo esc_html__( 'Meta-koppeling', 'eventbridge' ); ?></h2>
						<p><?php echo esc_html__( 'Deze gegevens zijn nodig om browser- en serverevents met je Meta-dataset te verbinden.', 'eventbridge' ); ?></p>
					</div>
					<table class="form-table" role="presentation"><?php do_settings_fields( EventBridge_Settings::PAGE_SLUG, 'eventbridge_meta_section' ); ?></table>
				</section>
				<section class="eventbridge-admin__panel">
					<div class="eventbridge-admin__panel-heading">
						<h2><?php echo esc_html__( 'Diagnose', 'eventbridge' ); ?></h2>
						<p><?php echo esc_html__( 'Gebruik deze instelling alleen wanneer je de tracking wilt controleren.', 'eventbridge' ); ?></p>
					</div>
					<table class="form-table" role="presentation"><?php do_settings_fields( EventBridge_Settings::PAGE_SLUG, 'eventbridge_diagnostics_section' ); ?></table>
				</section>
				<?php submit_button( __( 'Instellingen opslaan', 'eventbridge' ), 'primary eventbridge-admin__primary-action' ); ?>
			</form>

			<div class="eventbridge-admin__section-heading">
				<div>
					<h2><?php echo esc_html__( 'Events beheren', 'eventbridge' ); ?></h2>
					<p><?php echo esc_html__( 'Bekijk bestaande events of maak een nieuw event aan.', 'eventbridge' ); ?></p>
				</div>
				<a class="button button-primary" href="#event-form"><?php echo esc_html__( 'Nieuw event toevoegen', 'eventbridge' ); ?></a>
			</div>
			<?php $this->render_event_list(); ?>
			<?php $this->render_event_notices(); ?>
			<?php $this->render_event_form(); ?>
		</div>
		<?php
	}

	public function render_pixel_id_field() {
		$settings = $this->settings->get_settings();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[pixel_id]" value="<?php echo esc_attr( $settings['pixel_id'] ); ?>" inputmode="numeric" autocomplete="off">
		<p class="description"><?php echo esc_html__( 'Het numerieke ID van de Meta Pixel die op de website wordt geladen.', 'eventbridge' ); ?></p>
		<?php
	}

	public function render_capi_token_field() {
		$settings = $this->settings->get_settings();
		$has_token = isset( $settings['capi_token'] ) && is_scalar( $settings['capi_token'] ) && '' !== trim( (string) $settings['capi_token'] );
		?>
		<div class="eventbridge-secret-field">
			<input type="password" class="regular-text" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[capi_token]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Nieuwe token invoeren', 'eventbridge' ); ?>">
			<span class="eventbridge-status-badge <?php echo $has_token ? 'is-success' : 'is-neutral'; ?>"><?php echo $has_token ? esc_html__( 'Token ingesteld', 'eventbridge' ) : esc_html__( 'Geen token ingesteld', 'eventbridge' ); ?></span>
		</div>
		<p class="description"><?php echo esc_html__( 'Laat leeg om de huidige token te behouden. Een opgeslagen token wordt nooit opnieuw getoond.', 'eventbridge' ); ?></p>
		<?php if ( $has_token ) : ?>
			<label class="eventbridge-danger-option"><input type="checkbox" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[remove_capi_token]" value="1"> <?php echo esc_html__( 'Bestaande token verwijderen', 'eventbridge' ); ?></label>
		<?php endif; ?>
		<?php
	}

	public function render_debug_field() {
		$settings = $this->settings->get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( EventBridge_Settings::OPTION_NAME ); ?>[debug]" value="1" <?php checked( $settings['debug'] ); ?>>
			<?php echo esc_html__( 'Debugmodus inschakelen', 'eventbridge' ); ?>
		</label>
		<p class="description"><?php echo esc_html__( 'Schrijft extra technische informatie voor diagnose. Schakel dit na het testen weer uit voor productie.', 'eventbridge' ); ?></p>
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

	private function render_ledger_budget_warning() {
		$budget = $this->woocommerce->get_ledger_budget_status();
		$unsafe = array();
		foreach ( array( 'production' => __( 'productie', 'eventbridge' ), 'test' => __( 'testmodus', 'eventbridge' ) ) as $mode => $label ) {
			if ( ! empty( $budget[ $mode ]['over_budget'] ) ) {
				$unsafe[] = sprintf( '%1$s: %2$d/%3$d', $label, $budget[ $mode ]['count'], $budget[ $mode ]['limit'] );
			}
		}
		if ( empty( $unsafe ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf( __( 'WooCommerce-lifecycleverzending is geblokkeerd voor een onveilig ledgerbudget (%s). Verminder het aantal actieve backendroutes.', 'eventbridge' ), implode( ', ', $unsafe ) ) )
		);
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
		$this->event_form_values = $this->events->normalize_event( $event, $event_key );
	}

	private function render_event_list() {
		$events = $this->events->get_events();
		?>
		<section class="eventbridge-admin__panel eventbridge-admin__table-panel">
		<?php if ( empty( $events ) ) : ?>
			<div class="eventbridge-admin__empty-state">
				<h3><?php echo esc_html__( 'Nog geen events ingesteld', 'eventbridge' ); ?></h3>
				<p><?php echo esc_html__( 'Maak je eerste event aan om een klik of paginabezoek te meten.', 'eventbridge' ); ?></p>
				<a class="button button-primary" href="#event-form"><?php echo esc_html__( 'Eerste event toevoegen', 'eventbridge' ); ?></a>
			</div>
		<?php else : ?>
			<div class="eventbridge-admin__table-scroll">
			<table class="widefat striped eventbridge-event-list">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Interne naam', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Meta-eventnaam', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Trigger', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Browser', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'CAPI', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Databron', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Acties', 'eventbridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event_key => $event ) : ?>
						<?php if ( ! is_array( $event ) ) { continue; } ?>
						<?php
						$event = $this->events->normalize_event( $event, $event_key );
						$has_browser = false;
						$has_capi    = false;
						$sources     = array();
						foreach ( $event['triggers'] as $trigger ) {
							$route       = $this->events->get_effective_event( $event, $trigger );
							$has_browser = $has_browser || ! empty( $route['browser'] );
							$has_capi    = $has_capi || ! empty( $route['capi'] );
							if ( 'woocommerce' === $route['trigger_type'] ) {
								$sources[] = __( 'WooCommerce', 'eventbridge' );
							} elseif ( 'fluent_booking' === $route['data_source']['provider'] ) {
								$sources[] = __( 'Fluent Booking', 'eventbridge' );
							}
						}
						$sources = array_values( array_unique( $sources ) );
						$edit_url = add_query_arg(
							array(
								'page'       => self::SETTINGS_PAGE_SLUG,
								'edit_event' => $event_key,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( isset( $event['label'] ) && is_scalar( $event['label'] ) ? (string) $event['label'] : '' ); ?></strong></td>
							<td><?php echo esc_html( isset( $event['event_name'] ) && is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '' ); ?></td>
							<td>
								<?php $this->render_status_badge( ! empty( $event['enabled'] ) ? __( 'Actief', 'eventbridge' ) : __( 'Inactief', 'eventbridge' ), ! empty( $event['enabled'] ) ? 'success' : 'neutral' ); ?>
							</td>
							<td><?php echo esc_html( $this->get_event_trigger_summary( $event ) ); ?></td>
							<td><?php $this->render_status_badge( $has_browser ? __( 'Aan', 'eventbridge' ) : __( 'Uit', 'eventbridge' ), $has_browser ? 'success' : 'neutral' ); ?></td>
							<td><?php $this->render_status_badge( $has_capi ? __( 'Aan', 'eventbridge' ) : __( 'Uit', 'eventbridge' ), $has_capi ? 'success' : 'neutral' ); ?></td>
							<td><?php $this->render_status_badge( ! empty( $sources ) ? implode( ' + ', $sources ) : __( 'Geen', 'eventbridge' ), ! empty( $sources ) ? 'info' : 'neutral' ); ?></td>
							<td>
								<div class="eventbridge-event-actions">
								<a href="<?php echo esc_url( $edit_url ) . '#event-form'; ?>"><?php echo esc_html__( 'Bewerken', 'eventbridge' ); ?></a>
								<form action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ) ); ?>" method="post" class="eventbridge-delete-form" data-confirm="<?php echo esc_attr__( 'Weet je zeker dat je dit event wilt verwijderen? Dit kan niet ongedaan worden gemaakt.', 'eventbridge' ); ?>">
									<input type="hidden" name="eventbridge_form" value="delete_event">
									<input type="hidden" name="eventbridge_event_key" value="<?php echo esc_attr( $event_key ); ?>">
									<?php wp_nonce_field( 'eventbridge_delete_event_' . $event_key, 'eventbridge_delete_nonce' ); ?>
									<button type="submit" class="button-link-delete"><?php echo esc_html__( 'Verwijderen', 'eventbridge' ); ?></button>
								</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		</section>
		<?php
	}

	private function render_status_badge( $label, $type = 'neutral' ) {
		printf( '<span class="eventbridge-status-badge is-%1$s">%2$s</span>', esc_attr( $type ), esc_html( $label ) );
	}

	private function get_event_trigger_summary( $event ) {
		if ( isset( $event['triggers'] ) && is_array( $event['triggers'] ) ) {
			$frontend = 0;
			$woo      = 0;
			foreach ( $event['triggers'] as $trigger ) {
				if ( is_array( $trigger ) && isset( $trigger['provider'] ) && 'frontend' === $trigger['provider'] ) {
					$frontend++;
				} elseif ( is_array( $trigger ) && isset( $trigger['provider'] ) && 'woocommerce' === $trigger['provider'] ) {
					$woo++;
				}
			}
			$parts = array();
			if ( $frontend > 0 ) {
				$parts[] = sprintf( __( 'Frontend: %d', 'eventbridge' ), $frontend );
			}
			if ( $woo > 0 ) {
				$parts[] = sprintf( __( 'WooCommerce: %d', 'eventbridge' ), $woo );
			}

			return sprintf( _n( '%d trigger', '%d triggers', count( $event['triggers'] ), 'eventbridge' ), count( $event['triggers'] ) )
				. ( empty( $parts ) ? '' : ' · ' . implode( ' · ', $parts ) );
		}

		if ( 'woocommerce' === $event['trigger_type'] ) {
			$configuration = isset( $event['woocommerce'] ) && is_array( $event['woocommerce'] ) ? $event['woocommerce'] : array();
			$event_type    = isset( $configuration['event'] ) ? $configuration['event'] : '';

			if ( 'created' === $event_type ) {
				return __( 'WooCommerce · Bestelling aangemaakt', 'eventbridge' );
			}
			if ( 'paid' === $event_type ) {
				return __( 'WooCommerce · Betaling voltooid', 'eventbridge' );
			}
			if ( 'status' === $event_type ) {
				$statuses = $this->woocommerce->get_order_statuses();
				$status   = isset( $configuration['status'] ) ? $configuration['status'] : '';
				$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
				return sprintf( __( 'WooCommerce · Status: %s', 'eventbridge' ), $label );
			}

			return __( 'WooCommerce', 'eventbridge' );
		}

		if ( 'pageview' !== $event['trigger_type'] ) {
			return sprintf( __( 'Klik op %s', 'eventbridge' ), '' !== $event['selector'] ? $event['selector'] : __( 'onbekend element', 'eventbridge' ) );
		}

		$formats = array(
			'path_exact'    => __( 'Pagina met exact pad %s', 'eventbridge' ),
			'path_contains' => __( 'Pagina waarvan het pad %s bevat', 'eventbridge' ),
			'url_exact'     => __( 'Pagina met exacte URL %s', 'eventbridge' ),
		);

		return isset( $formats[ $event['url_match_type'] ] )
			? sprintf( $formats[ $event['url_match_type'] ], $event['url_match_value'] )
			: __( 'Pagina bezocht', 'eventbridge' );
	}

	private function render_event_form() {
		$values           = $this->event_form_values;
		$form_action      = $this->is_editing_event ? 'update_event' : 'add_event';
		$fluent_status    = $this->get_fluent_runtime_status();
		$fluent_available = 'available' === $fluent_status;
		$woocommerce_status = $this->woocommerce->get_runtime_status();
		$woocommerce_available = 'available' === $woocommerce_status;
		$channels          = isset( $values['channels'] ) && is_array( $values['channels'] ) ? $values['channels'] : array();
		$values['browser'] = ! empty( $channels['browser'] );
		$values['capi']    = ! empty( $channels['capi'] );
		$action_url       = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ) . '#event-form';
		?>
		<section class="eventbridge-admin__panel eventbridge-event-form-panel">
			<div class="eventbridge-admin__panel-heading">
				<h2><?php echo $this->is_editing_event ? esc_html__( 'Event bewerken', 'eventbridge' ) : esc_html__( 'Nieuw event toevoegen', 'eventbridge' ); ?></h2>
				<p><?php echo esc_html__( 'Doorloop de stappen van boven naar beneden. Geavanceerde keuzes verschijnen alleen wanneer ze nodig zijn.', 'eventbridge' ); ?></p>
			</div>
			<form id="event-form" class="eventbridge-event-form" action="<?php echo esc_url( $action_url ); ?>" method="post" data-fluent-available="<?php echo $fluent_available ? '1' : '0'; ?>" data-woocommerce-available="<?php echo $woocommerce_available ? '1' : '0'; ?>" data-new-event="<?php echo $this->is_editing_event ? '0' : '1'; ?>">
				<input type="hidden" name="eventbridge_form" value="<?php echo esc_attr( $form_action ); ?>">
				<?php if ( $this->is_editing_event ) : ?>
					<input type="hidden" name="eventbridge_event_key" value="<?php echo esc_attr( $this->editing_event_key ); ?>">
					<?php wp_nonce_field( 'eventbridge_update_event_' . $this->editing_event_key, 'eventbridge_update_nonce' ); ?>
				<?php else : ?>
					<?php wp_nonce_field( 'eventbridge_add_event', 'eventbridge_event_nonce' ); ?>
				<?php endif; ?>

				<?php $this->render_event_basic_section( $values ); ?>
				<?php $this->render_event_triggers_section( $values, $fluent_status, $woocommerce_status ); ?>
				<?php $this->render_event_diagnostics_section( $values ); ?>

				<div class="eventbridge-event-form__actions">
					<?php submit_button( $this->is_editing_event ? __( 'Wijzigingen opslaan', 'eventbridge' ) : __( 'Event toevoegen', 'eventbridge' ), 'primary eventbridge-admin__primary-action', 'submit', false, array( 'id' => 'eventbridge-event-submit' ) ); ?>
					<?php if ( $this->is_editing_event ) : ?>
						<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Annuleren', 'eventbridge' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</section>
		<?php
	}

	private function render_event_card_heading( $number, $title, $description ) {
		?>
		<div class="eventbridge-form-card__heading">
			<span class="eventbridge-form-card__number" aria-hidden="true"><?php echo esc_html( (string) $number ); ?></span>
			<div><h3><?php echo esc_html( $title ); ?></h3><p><?php echo esc_html( $description ); ?></p></div>
		</div>
		<?php
	}

	private function render_event_basic_section( $values ) {
		?>
		<section class="eventbridge-form-card">
			<?php $this->render_event_card_heading( 1, __( 'Basisgegevens', 'eventbridge' ), __( 'Geef het event een herkenbare naam en bepaal of het actief is.', 'eventbridge' ) ); ?>
			<div class="eventbridge-form-grid">
				<div class="eventbridge-field"><label for="eventbridge_event_label"><?php echo esc_html__( 'Interne naam', 'eventbridge' ); ?></label><input type="text" class="regular-text" id="eventbridge_event_label" name="eventbridge_event[label]" value="<?php echo esc_attr( $values['label'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::LABEL_MAX_LENGTH ); ?>" required aria-describedby="eventbridge-label-help"><p class="description" id="eventbridge-label-help"><?php echo esc_html__( 'Alleen zichtbaar in EventBridge, bijvoorbeeld “Boeking afgerond”.', 'eventbridge' ); ?></p></div>
				<div class="eventbridge-field"><label for="eventbridge_event_name"><?php echo esc_html__( 'Meta-eventnaam', 'eventbridge' ); ?></label><input type="text" class="regular-text" id="eventbridge_event_name" name="eventbridge_event[event_name]" value="<?php echo esc_attr( $values['event_name'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::EVENT_NAME_MAX_LENGTH ); ?>" pattern="[A-Za-z0-9_]+" required aria-describedby="eventbridge-event-name-help"><p class="description" id="eventbridge-event-name-help"><?php echo esc_html__( 'Een herkenbaar custom event zoals BookingComplete heeft de voorkeur; Meta-standaardevents zoals Lead of Purchase blijven mogelijk.', 'eventbridge' ); ?></p></div>
				<div class="eventbridge-field eventbridge-field--wide"><label for="eventbridge_event_description"><?php echo esc_html__( 'Interne notitie (optioneel)', 'eventbridge' ); ?></label><textarea class="large-text" id="eventbridge_event_description" name="eventbridge_event[description]" maxlength="<?php echo esc_attr( EventBridge_Events::DESCRIPTION_MAX_LENGTH ); ?>" rows="3"><?php echo esc_textarea( $values['description'] ); ?></textarea></div>
				<div class="eventbridge-field eventbridge-field--wide"><label class="eventbridge-toggle"><input type="checkbox" name="eventbridge_event[enabled]" value="1" <?php checked( $values['enabled'] ); ?>> <span><?php echo esc_html__( 'Event is actief', 'eventbridge' ); ?></span></label><p class="description"><?php echo esc_html__( 'Schakel uit om het event tijdelijk niet te laten afvuren.', 'eventbridge' ); ?></p></div>
			</div>
		</section>
		<?php
	}

	private function render_event_triggers_section( $values, $fluent_status, $woocommerce_status ) {
		$triggers = isset( $values['triggers'] ) && is_array( $values['triggers'] ) ? array_values( $values['triggers'] ) : array();
		if ( empty( $triggers ) ) {
			$triggers[] = ( new EventBridge_Triggers() )->get_trigger_defaults();
		}
		$trigger_service = new EventBridge_Triggers();
		$family          = $trigger_service->get_event_family( $triggers );
		$first_family    = $trigger_service->get_trigger_family( $triggers[0] );
		$has_conflict    = ( '' === $family && count( $triggers ) > 1 ) || ! empty( $values[ EventBridge_Triggers::FAMILY_CONFLICT_KEY ] );
		?>
		<section class="eventbridge-form-card eventbridge-triggers-section">
			<?php $this->render_event_card_heading( 2, __( 'Triggers', 'eventbridge' ), __( 'Iedere trigger is een zelfstandige route. Tussen de routes geldt OF; voorwaarden binnen één route gelden samen.', 'eventbridge' ) ); ?>
			<p id="eventbridge-family-conflict" class="eventbridge-inline-notice is-error" role="alert"<?php echo $has_conflict ? '' : ' hidden'; ?>><?php echo esc_html__( 'Dit event bevat incompatibele triggerfamilies. Verwijder of wijzig triggers totdat alle triggers frontendtriggers of alle triggers backendtriggers zijn.', 'eventbridge' ); ?></p>
			<div id="eventbridge-trigger-list" data-next-index="<?php echo esc_attr( (string) count( $triggers ) ); ?>">
				<?php foreach ( $triggers as $index => $trigger ) : ?>
					<?php if ( $index > 0 ) : ?><div class="eventbridge-trigger-or" aria-label="<?php echo esc_attr__( 'OF', 'eventbridge' ); ?>"><span><?php echo esc_html__( 'OF', 'eventbridge' ); ?></span></div><?php endif; ?>
					<?php $this->render_trigger_card( $trigger, $index, $fluent_status, $woocommerce_status, $has_conflict || 0 === $index || in_array( $index + 1, $this->trigger_error_numbers, true ) ); ?>
				<?php endforeach; ?>
			</div>
			<p><button type="button" class="button button-secondary" id="eventbridge-add-trigger"<?php disabled( count( $triggers ) >= EventBridge_Triggers::MAX_TRIGGERS ); ?>><?php echo esc_html__( 'Trigger toevoegen', 'eventbridge' ); ?></button></p>
			<template id="eventbridge-trigger-template"><?php $this->render_trigger_card( ( new EventBridge_Triggers() )->get_trigger_defaults(), '__TRIGGER__', $fluent_status, $woocommerce_status, true ); ?></template>
			<?php $this->render_event_delivery_section( $values, $first_family ); ?>
		</section>
		<?php
	}

	private function render_trigger_card( $trigger, $index, $fluent_status, $woocommerce_status, $is_open = false ) {
		$defaults              = ( new EventBridge_Triggers() )->get_trigger_defaults();
		$trigger               = wp_parse_args( is_array( $trigger ) ? $trigger : array(), $defaults );
		$config                = wp_parse_args( is_array( $trigger['provider_config'] ) ? $trigger['provider_config'] : array(), $defaults['provider_config'] );
		$data_source           = wp_parse_args( is_array( $trigger['data_source'] ) ? $trigger['data_source'] : array(), array( 'provider' => '', 'lookup_source' => '', 'lookup_value' => '', 'expected_event_id' => '' ) );
		$advanced_matching     = is_array( $trigger['advanced_matching'] ) ? $trigger['advanced_matching'] : array();
		$parameters            = is_array( $trigger['parameters'] ) ? $trigger['parameters'] : array();
		$conditions            = is_array( $trigger['conditions'] ) ? $trigger['conditions'] : array();
		$is_woocommerce        = 'woocommerce' === $trigger['provider'] && 'order_lifecycle' === $trigger['trigger_type'];
		$is_woocommerce_interaction = 'woocommerce' === $trigger['provider'] && in_array( $trigger['trigger_type'], array( 'product_viewed', 'added_to_cart', 'checkout_started' ), true );
		$is_woocommerce_any    = $is_woocommerce || $is_woocommerce_interaction;
		$is_pageview           = 'frontend' === $trigger['provider'] && 'pageview' === $trigger['trigger_type'];
		$kind                  = $is_woocommerce ? 'backend:woocommerce' : ( $is_woocommerce_interaction ? 'frontend:woocommerce' : ( $is_pageview ? 'frontend:pageview' : 'frontend:click' ) );
		$interaction_type      = $is_woocommerce_interaction ? $trigger['trigger_type'] : 'product_viewed';
		$base                  = 'eventbridge_event[triggers][' . $index . ']';
		$parameters_base       = $base . '[parameters]';
		$conditions_base       = $base . '[conditions]';
		$fluent_available      = 'available' === $fluent_status;
		$woocommerce_available = 'available' === $woocommerce_status;
		$fluent_selected       = 'fluent_booking' === $data_source['provider'];
		$fluent_locked         = $fluent_selected && ! $fluent_available;
		$woocommerce_locked    = $is_woocommerce_any && ! $woocommerce_available;
		$order_statuses        = $this->woocommerce->get_order_statuses();
		$catalog               = $this->conditions ? $this->conditions->get_catalog( 'woocommerce' ) : array();
		$billing_map           = $this->woocommerce->get_billing_field_map();
		$order_fields          = $this->woocommerce->get_order_parameter_fields();
		$advanced_fields       = array(
			'email'      => __( 'E-mail', 'eventbridge' ),
			'phone'      => __( 'Telefoon', 'eventbridge' ),
			'first_name' => __( 'Voornaam', 'eventbridge' ),
			'last_name'  => __( 'Achternaam', 'eventbridge' ),
		);
		$interaction_labels = array(
			'product_viewed'  => __( 'Product bekeken', 'eventbridge' ),
			'added_to_cart'   => __( 'Toegevoegd aan winkelmand', 'eventbridge' ),
			'checkout_started' => __( 'Checkout gestart', 'eventbridge' ),
		);
		$woocommerce_event_labels = array(
			'created' => __( 'Bestelling aangemaakt', 'eventbridge' ),
			'paid'    => __( 'Betaling voltooid', 'eventbridge' ),
			'status'  => __( 'Bestelling krijgt gekozen status', 'eventbridge' ),
		);
		$panel_id = 'eventbridge-trigger-panel-' . $index;
		$help_id  = 'eventbridge-trigger-family-help-' . $index;
		if ( $is_woocommerce ) {
			$event_label     = isset( $woocommerce_event_labels[ $config['event'] ] ) ? $woocommerce_event_labels[ $config['event'] ] : __( 'WooCommerce-gebeurtenis kiezen', 'eventbridge' );
			$trigger_summary = sprintf( __( 'WooCommerce — %s', 'eventbridge' ), $event_label );
		} elseif ( $is_woocommerce_interaction ) {
			$trigger_summary = sprintf( __( 'WooCommerce — %s', 'eventbridge' ), $interaction_labels[ $trigger['trigger_type'] ] );
		} elseif ( $is_pageview ) {
			$trigger_summary = '' !== $config['url_match_value'] ? $config['url_match_value'] : __( 'Paginabezoek', 'eventbridge' );
		} else {
			$trigger_summary = '' !== $config['selector'] ? $config['selector'] : __( 'CSS-selector', 'eventbridge' );
		}
		if ( '' !== $config['status'] && ! isset( $order_statuses[ $config['status'] ] ) ) {
			$order_statuses[ $config['status'] ] = sprintf( __( '%s (momenteel niet beschikbaar)', 'eventbridge' ), $config['status'] );
		}
		?>
		<article class="eventbridge-trigger-card<?php echo $is_open ? ' is-expanded' : ''; ?><?php echo $is_woocommerce ? ' is-woocommerce-lifecycle' : ( $is_woocommerce_interaction ? ' is-woocommerce-interaction' : '' ); ?>" data-trigger-index="<?php echo esc_attr( (string) $index ); ?>" data-condition-context="<?php echo esc_attr( $is_woocommerce ? 'order' : $trigger['trigger_type'] ); ?>" data-woocommerce-locked="<?php echo $woocommerce_locked ? '1' : '0'; ?>" data-fluent-locked="<?php echo $fluent_locked ? '1' : '0'; ?>">
			<header class="eventbridge-trigger-card__header">
				<button type="button" class="eventbridge-trigger-toggle" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><span><strong class="eventbridge-trigger-title"><?php echo esc_html( sprintf( __( 'Trigger %s', 'eventbridge' ), is_numeric( $index ) ? absint( $index ) + 1 : '' ) ); ?></strong><span class="eventbridge-trigger-summary"><?php echo esc_html( $trigger_summary ); ?></span></span><span class="eventbridge-trigger-toggle__icon" aria-hidden="true"></span></button>
				<button type="button" class="button-link-delete eventbridge-remove-trigger"><?php echo esc_html__( 'Trigger verwijderen', 'eventbridge' ); ?></button>
			</header>
			<div class="eventbridge-trigger-card__body" id="<?php echo esc_attr( $panel_id ); ?>"<?php echo $is_open ? '' : ' hidden'; ?>>
			<input type="hidden" name="<?php echo esc_attr( $base . '[trigger_id]' ); ?>" value="<?php echo esc_attr( $trigger['trigger_id'] ); ?>">
			<input type="hidden" class="eventbridge-trigger-provider" name="<?php echo esc_attr( $base . '[provider]' ); ?>" value="<?php echo esc_attr( $trigger['provider'] ); ?>">
			<input type="hidden" class="eventbridge-trigger-type" name="<?php echo esc_attr( $base . '[trigger_type]' ); ?>" value="<?php echo esc_attr( $trigger['trigger_type'] ); ?>">

			<div class="eventbridge-form-grid">
				<div class="eventbridge-field">
					<label><?php echo esc_html__( 'Triggertype', 'eventbridge' ); ?>
						<select class="eventbridge-trigger-kind" aria-describedby="<?php echo esc_attr( $help_id ); ?>" title="<?php echo esc_attr__( 'Frontendtriggers en backendtriggers kunnen niet binnen hetzelfde event worden gecombineerd.', 'eventbridge' ); ?>"<?php disabled( $woocommerce_locked ); ?>>
							<optgroup label="<?php echo esc_attr__( 'Frontendtriggers', 'eventbridge' ); ?>"><option value="frontend:click" data-family="frontend_interaction" <?php selected( $kind, 'frontend:click' ); ?>><?php echo esc_html__( 'CSS-selector', 'eventbridge' ); ?></option><option value="frontend:pageview" data-family="frontend_interaction" <?php selected( $kind, 'frontend:pageview' ); ?>><?php echo esc_html__( 'Paginabezoek', 'eventbridge' ); ?></option><option value="frontend:woocommerce" data-family="frontend_interaction" data-woocommerce="1" <?php selected( $kind, 'frontend:woocommerce' ); ?><?php disabled( ! $woocommerce_available && 'frontend:woocommerce' !== $kind ); ?>><?php echo esc_html__( 'WooCommerce', 'eventbridge' ); ?></option></optgroup>
							<optgroup label="<?php echo esc_attr__( 'Backendtriggers', 'eventbridge' ); ?>"><option value="backend:woocommerce" data-family="server_lifecycle" data-woocommerce="1" <?php selected( $kind, 'backend:woocommerce' ); ?><?php disabled( ! $woocommerce_available && ! $is_woocommerce ); ?>><?php echo esc_html__( 'WooCommerce', 'eventbridge' ); ?></option></optgroup>
						</select>
						<p class="description eventbridge-trigger-family-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html__( 'WooCommerce onder Frontendtriggers registreert een browserinteractie. WooCommerce onder Backendtriggers registreert een bestelgebeurtenis. Frontend- en backendtriggers kunnen niet binnen hetzelfde event worden gecombineerd; niet-compatibele opties blijven zichtbaar maar zijn niet beschikbaar.', 'eventbridge' ); ?></p>
					</label>
				</div>
				<div class="eventbridge-field eventbridge-click-config"<?php echo 'frontend:click' === $kind ? '' : ' hidden'; ?>><label><?php echo esc_html__( 'CSS-selector', 'eventbridge' ); ?><input type="text" class="regular-text eventbridge-selector" name="<?php echo esc_attr( $base . '[provider_config][selector]' ); ?>" value="<?php echo esc_attr( $config['selector'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::SELECTOR_MAX_LENGTH ); ?>" placeholder=".boek-knop"<?php echo 'frontend:click' === $kind ? ' required' : ''; ?>></label></div>
			</div>

			<div class="eventbridge-pageview-config"<?php echo $is_pageview ? '' : ' hidden'; ?>>
				<div class="eventbridge-form-grid">
					<div class="eventbridge-field"><label><?php echo esc_html__( 'URL-vergelijking', 'eventbridge' ); ?><select class="eventbridge-url-match-type" name="<?php echo esc_attr( $base . '[provider_config][url_match_type]' ); ?>"<?php echo $is_pageview ? ' required' : ''; ?>><option value="path_exact" <?php selected( $config['url_match_type'], 'path_exact' ); ?>><?php echo esc_html__( 'Pad is exact', 'eventbridge' ); ?></option><option value="path_contains" <?php selected( $config['url_match_type'], 'path_contains' ); ?>><?php echo esc_html__( 'Pad bevat', 'eventbridge' ); ?></option><option value="url_exact" <?php selected( $config['url_match_type'], 'url_exact' ); ?>><?php echo esc_html__( 'Volledige URL is exact', 'eventbridge' ); ?></option></select></label></div>
					<div class="eventbridge-field"><label><?php echo esc_html__( 'Pad of URL', 'eventbridge' ); ?><input type="text" class="large-text eventbridge-url-match-value" name="<?php echo esc_attr( $base . '[provider_config][url_match_value]' ); ?>" value="<?php echo esc_attr( $config['url_match_value'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::URL_MATCH_VALUE_MAX_LENGTH ); ?>" placeholder="/bedankt"<?php echo $is_pageview ? ' required' : ''; ?>></label></div>
				</div>
			</div>

			<div class="eventbridge-woocommerce-interaction-config"<?php echo $is_woocommerce_interaction ? '' : ' hidden'; ?>>
				<div class="eventbridge-form-grid">
					<div class="eventbridge-field"><label><?php echo esc_html__( 'WooCommerce-gebeurtenis', 'eventbridge' ); ?><select class="eventbridge-woocommerce-interaction-event"<?php disabled( $woocommerce_locked ); ?>><option value="product_viewed" <?php selected( $interaction_type, 'product_viewed' ); ?>><?php echo esc_html( $interaction_labels['product_viewed'] ); ?></option><option value="added_to_cart" <?php selected( $interaction_type, 'added_to_cart' ); ?>><?php echo esc_html( $interaction_labels['added_to_cart'] ); ?></option><option value="checkout_started" <?php selected( $interaction_type, 'checkout_started' ); ?>><?php echo esc_html( $interaction_labels['checkout_started'] ); ?></option></select></label></div>
				</div>
				<p class="description"><?php echo esc_html__( 'Deze WooCommerce-optie is een frontendinteractie en gebruikt de eventbrede browser- en CAPI-kanalen.', 'eventbridge' ); ?></p>
			</div>

			<div class="eventbridge-woocommerce-config"<?php echo $is_woocommerce ? '' : ' hidden'; ?>>
				<p class="description"><?php echo esc_html__( 'Deze WooCommerce-optie is een backendgebeurtenis en wordt uitsluitend via CAPI verstuurd.', 'eventbridge' ); ?></p>
				<?php if ( $woocommerce_locked ) : ?><p class="eventbridge-inline-notice is-warning"><?php echo esc_html__( 'De bestaande WooCommerce-configuratie blijft behouden zolang WooCommerce niet beschikbaar is.', 'eventbridge' ); ?></p><?php endif; ?>
				<div class="eventbridge-form-grid">
					<div class="eventbridge-field"><label><?php echo esc_html__( 'WooCommerce-gebeurtenis', 'eventbridge' ); ?><select class="eventbridge-woocommerce-event"<?php echo $woocommerce_locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[provider_config][event]' ) . '"'; ?>><option value="created" <?php selected( $config['event'], 'created' ); ?>><?php echo esc_html__( 'Bestelling aangemaakt', 'eventbridge' ); ?></option><option value="paid" <?php selected( $config['event'], 'paid' ); ?>><?php echo esc_html__( 'Betaling voltooid', 'eventbridge' ); ?></option><option value="status" <?php selected( $config['event'], 'status' ); ?>><?php echo esc_html__( 'Bestelling krijgt gekozen status', 'eventbridge' ); ?></option></select></label></div>
					<div class="eventbridge-field eventbridge-woocommerce-status"<?php echo $is_woocommerce && 'status' === $config['event'] ? '' : ' hidden'; ?>><label><?php echo esc_html__( 'Doelstatus', 'eventbridge' ); ?><select<?php echo $woocommerce_locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[provider_config][status]' ) . '"'; ?>><option value=""><?php echo esc_html__( 'Kies een status', 'eventbridge' ); ?></option><?php foreach ( $order_statuses as $status_slug => $status_label ) : ?><option value="<?php echo esc_attr( $status_slug ); ?>" <?php selected( $config['status'], $status_slug ); ?>><?php echo esc_html( $status_label ); ?></option><?php endforeach; ?></select></label></div>
				</div>
				<label class="eventbridge-toggle"><input type="checkbox"<?php echo $woocommerce_locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[provider_config][purchase_preset]' ) . '"'; ?> value="1" <?php checked( $config['purchase_preset'] ); ?>> <span><?php echo esc_html__( 'WooCommerce Purchase-preset gebruiken', 'eventbridge' ); ?></span></label>
				<?php if ( $woocommerce_locked ) : ?><input type="hidden" name="<?php echo esc_attr( $base . '[provider_config][event]' ); ?>" value="<?php echo esc_attr( $config['event'] ); ?>"><input type="hidden" name="<?php echo esc_attr( $base . '[provider_config][status]' ); ?>" value="<?php echo esc_attr( $config['status'] ); ?>"><?php if ( $config['purchase_preset'] ) : ?><input type="hidden" name="<?php echo esc_attr( $base . '[provider_config][purchase_preset]' ); ?>" value="1"><?php endif; ?><?php endif; ?>
			</div>

			<div class="eventbridge-frontend-sources"<?php echo $is_woocommerce ? ' hidden' : ''; ?>>
				<h5><?php echo esc_html__( 'Databron', 'eventbridge' ); ?></h5>
				<div class="eventbridge-form-grid">
					<div class="eventbridge-field"><label><?php echo esc_html__( 'Externe databron', 'eventbridge' ); ?><select class="eventbridge-data-source-provider"<?php echo $fluent_locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[data_source][provider]' ) . '"'; ?>><option value="" <?php selected( $data_source['provider'], '' ); ?>><?php echo esc_html__( 'Geen', 'eventbridge' ); ?></option><option value="fluent_booking" <?php selected( $data_source['provider'], 'fluent_booking' ); ?><?php disabled( ! $fluent_available && ! $fluent_selected ); ?>><?php echo esc_html__( 'Fluent Booking', 'eventbridge' ); ?></option></select><?php if ( $fluent_locked ) : ?><input type="hidden" name="<?php echo esc_attr( $base . '[data_source][provider]' ); ?>" value="fluent_booking"><?php endif; ?></label></div>
				</div>
				<div class="eventbridge-fluent-config"<?php echo $fluent_selected ? '' : ' hidden'; ?>>
					<input type="hidden" name="<?php echo esc_attr( $base . '[data_source][lookup_source]' ); ?>" value="query_parameter">
					<div class="eventbridge-form-grid">
						<div class="eventbridge-field"><label><?php echo esc_html__( 'Queryparameter met booking hash', 'eventbridge' ); ?><input type="text"<?php echo $fluent_locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[data_source][lookup_value]' ) . '"'; ?> value="<?php echo esc_attr( $data_source['lookup_value'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::QUERY_PARAMETER_NAME_MAX_LENGTH ); ?>" pattern="[A-Za-z0-9_]+"></label></div>
						<div class="eventbridge-field"><label><?php echo esc_html__( 'Verwacht Fluent Event ID (optioneel)', 'eventbridge' ); ?><input type="text"<?php echo $fluent_locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[data_source][expected_event_id]' ) . '"'; ?> value="<?php echo esc_attr( $data_source['expected_event_id'] ); ?>" pattern="[1-9][0-9]*" maxlength="20"></label></div>
					</div>
					<?php if ( $fluent_locked ) : ?><input type="hidden" name="<?php echo esc_attr( $base . '[data_source][lookup_value]' ); ?>" value="<?php echo esc_attr( $data_source['lookup_value'] ); ?>"><input type="hidden" name="<?php echo esc_attr( $base . '[data_source][expected_event_id]' ); ?>" value="<?php echo esc_attr( $data_source['expected_event_id'] ); ?>"><?php endif; ?>
				</div>
			</div>

			<details class="eventbridge-details eventbridge-route-parameters" open>
				<summary><?php echo esc_html__( 'Gewone parameters en beschikbare bronnen', 'eventbridge' ); ?></summary>
				<div class="eventbridge-details__content">
					<p class="description eventbridge-source-summary"><span class="eventbridge-source-summary-frontend"><?php echo esc_html__( 'Beschikbaar: vaste waarde, queryparameter en optioneel Fluent Booking.', 'eventbridge' ); ?></span><span class="eventbridge-source-summary-woocommerce"><?php echo esc_html__( 'Beschikbaar: vaste waarde en WooCommerce-ordergegevens.', 'eventbridge' ); ?></span><span class="eventbridge-source-summary-interaction"><?php echo esc_html__( 'Beschikbaar: vaste waarde, queryparameter, optioneel Fluent Booking en WooCommerce-interactiegegevens.', 'eventbridge' ); ?></span></p>
					<div class="eventbridge-parameter-rows" data-next-index="<?php echo esc_attr( (string) count( $parameters ) ); ?>"><?php foreach ( $parameters as $parameter_index => $parameter ) : ?><?php $this->render_parameter_row( $parameter, $parameter_index, $fluent_available, $woocommerce_available, $is_woocommerce, $parameters_base, $is_woocommerce_interaction ? $trigger['trigger_type'] : '' ); ?><?php endforeach; ?></div>
					<p><button type="button" class="button eventbridge-add-parameter"><?php echo esc_html__( 'Eventgegeven toevoegen', 'eventbridge' ); ?></button></p>
					<template class="eventbridge-parameter-template"><?php $this->render_parameter_row( array( 'name' => '', 'source' => 'static', 'value' => '' ), '__PARAMETER__', $fluent_available, $woocommerce_available, false, $parameters_base, $is_woocommerce_interaction ? $trigger['trigger_type'] : '' ); ?></template>
				</div>
			</details>

			<details class="eventbridge-details eventbridge-route-advanced">
				<summary><?php echo esc_html__( 'Meta Advanced Matching', 'eventbridge' ); ?></summary>
				<div class="eventbridge-details__content">
					<p><?php echo esc_html__( 'Waarden worden uitsluitend server-side genormaliseerd en gehasht.', 'eventbridge' ); ?></p>
					<p class="eventbridge-route-advanced-capi-warning eventbridge-inline-notice is-warning" hidden><?php echo esc_html__( 'Advanced Matching vereist dat CAPI bij de eventbrede verzendkanalen is ingeschakeld.', 'eventbridge' ); ?></p>
					<?php foreach ( $advanced_fields as $field_key => $field_label ) :
						$mapping = isset( $advanced_matching[ $field_key ] ) && is_array( $advanced_matching[ $field_key ] ) ? wp_parse_args( $advanced_matching[ $field_key ], array( 'source' => '', 'value' => '' ) ) : array( 'source' => '', 'value' => '' );
						$source = $mapping['source'];
						$value = $mapping['value'];
						$locked = ( ! $fluent_available && 'fluent_booking' === $source ) || ( ! $woocommerce_available && 'woocommerce_billing' === $source );
						$billing_value = isset( $billing_map[ $field_key ] ) ? $billing_map[ $field_key ] : '';
					?>
						<div class="eventbridge-parameter-row eventbridge-advanced-matching-row"<?php echo $locked ? ' data-source-locked="1"' : ''; ?>>
							<span class="eventbridge-parameter-label"><?php echo esc_html( $field_label ); ?></span>
							<select class="eventbridge-advanced-matching-source" data-woocommerce-value="<?php echo esc_attr( $billing_value ); ?>"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $base . '[advanced_matching][' . $field_key . '][source]' ) . '"'; ?>><option value="" <?php selected( $source, '' ); ?>><?php echo esc_html__( 'Niet gebruiken', 'eventbridge' ); ?></option><option value="static" <?php selected( $source, 'static' ); ?>><?php echo esc_html__( 'Vaste waarde', 'eventbridge' ); ?></option><option value="query_parameter" <?php selected( $source, 'query_parameter' ); ?>><?php echo esc_html__( 'Queryparameter', 'eventbridge' ); ?></option><option value="fluent_booking" <?php selected( $source, 'fluent_booking' ); ?><?php disabled( ! $fluent_available && ! $locked ); ?>><?php echo esc_html__( 'Fluent Booking', 'eventbridge' ); ?></option><option value="woocommerce_billing" <?php selected( $source, 'woocommerce_billing' ); ?><?php disabled( ! $woocommerce_available && ! $locked ); ?>><?php echo esc_html__( 'WooCommerce-facturatie', 'eventbridge' ); ?></option></select>
							<input type="text" class="eventbridge-advanced-matching-value"<?php echo $locked || in_array( $source, array( '', 'fluent_booking', 'woocommerce_billing' ), true ) ? ' disabled' : ' name="' . esc_attr( $base . '[advanced_matching][' . $field_key . '][value]' ) . '"'; ?> value="<?php echo esc_attr( 'woocommerce_billing' === $source ? $billing_value : $value ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::PARAMETER_VALUE_MAX_LENGTH ); ?>">
							<input type="hidden" class="eventbridge-advanced-matching-fixed-value" name="<?php echo esc_attr( $base . '[advanced_matching][' . $field_key . '][value]' ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php disabled( ! $locked && 'woocommerce_billing' !== $source ); ?>>
							<?php if ( $locked ) : ?><input type="hidden" name="<?php echo esc_attr( $base . '[advanced_matching][' . $field_key . '][source]' ); ?>" value="<?php echo esc_attr( $source ); ?>"><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</details>

			<div class="eventbridge-route-conditions"<?php echo $is_woocommerce_any ? '' : ' hidden'; ?>>
				<div class="eventbridge-conditions__heading"><div><h5><?php echo esc_html__( 'Voorwaarden', 'eventbridge' ); ?></h5><p class="description"><?php echo esc_html__( 'Alle voorwaarden binnen deze trigger moeten kloppen (AND).', 'eventbridge' ); ?></p></div><button type="button" class="button eventbridge-add-condition"<?php disabled( $woocommerce_locked ); ?>><?php echo esc_html__( 'Voorwaarde toevoegen', 'eventbridge' ); ?></button></div>
				<div class="eventbridge-condition-rows" data-next-index="<?php echo esc_attr( (string) count( $conditions ) ); ?>"><?php foreach ( $conditions as $condition_index => $condition ) : ?><?php $this->render_condition_row( $condition, $condition_index, $catalog, $woocommerce_locked, $conditions_base, $is_woocommerce ? 'order' : ( $is_woocommerce_interaction ? $trigger['trigger_type'] : '' ) ); ?><?php endforeach; ?></div>
				<template class="eventbridge-condition-template"><?php $this->render_condition_row( array( 'provider' => 'woocommerce', 'field' => '', 'operator' => '', 'value' => '' ), '__CONDITION__', $catalog, false, $conditions_base, $is_woocommerce ? 'order' : ( $is_woocommerce_interaction ? $trigger['trigger_type'] : '' ) ); ?></template>
			</div>
			</div>
		</article>
		<?php
	}

	private function render_event_trigger_section( $values, $woocommerce_status ) {
		$is_pageview          = 'pageview' === $values['trigger_type'];
		$is_woocommerce       = 'woocommerce' === $values['trigger_type'];
		$woocommerce_available = 'available' === $woocommerce_status;
		$woocommerce_locked   = $is_woocommerce && ! $woocommerce_available;
		$configuration        = isset( $values['woocommerce'] ) && is_array( $values['woocommerce'] ) ? $values['woocommerce'] : $this->woocommerce->get_configuration_defaults();
		$order_statuses       = $this->woocommerce->get_order_statuses();
		if ( '' !== $configuration['status'] && ! isset( $order_statuses[ $configuration['status'] ] ) ) {
			$order_statuses[ $configuration['status'] ] = sprintf( __( '%s (momenteel niet beschikbaar)', 'eventbridge' ), $configuration['status'] );
		}
		$status_messages = array(
			'available'          => array( 'success', __( 'Beschikbaar', 'eventbridge' ), __( 'WooCommerce is actief en beschikbaar.', 'eventbridge' ) ),
			'installed_inactive' => array( 'warning', __( 'Geïnstalleerd maar inactief', 'eventbridge' ), __( 'Activeer WooCommerce om nieuwe WooCommerce-configuratie te maken of bestaande configuratie te wijzigen.', 'eventbridge' ) ),
			'unsupported'        => array( 'warning', __( 'Versie niet ondersteund', 'eventbridge' ), sprintf( __( 'Deze WooCommerce-versie wordt niet ondersteund; minimaal %s is vereist.', 'eventbridge' ), EventBridge_WooCommerce::MINIMUM_VERSION ) ),
			'not_ready'          => array( 'warning', __( 'Tijdelijk niet volledig geladen', 'eventbridge' ), __( 'WooCommerce is actief maar nog niet volledig geladen.', 'eventbridge' ) ),
			'unavailable'        => array( 'warning', __( 'Niet geïnstalleerd', 'eventbridge' ), __( 'WooCommerce is niet geïnstalleerd of niet beschikbaar.', 'eventbridge' ) ),
		);
		$runtime_message = isset( $status_messages[ $woocommerce_status ] ) ? $status_messages[ $woocommerce_status ] : $status_messages['unavailable'];
		?>
		<section class="eventbridge-form-card">
			<?php $this->render_event_card_heading( 2, __( 'Wanneer moet het event afvuren?', 'eventbridge' ), __( 'Kies de actie op de website die dit event activeert.', 'eventbridge' ) ); ?>
			<div class="eventbridge-field"><label for="eventbridge_event_trigger_type"><?php echo esc_html__( 'Trigger', 'eventbridge' ); ?></label><select id="eventbridge_event_trigger_type"<?php echo $woocommerce_locked ? ' disabled aria-disabled="true"' : ' name="eventbridge_event[trigger_type]"'; ?> required aria-describedby="eventbridge-trigger-description" aria-controls="eventbridge-selector-row eventbridge-pageview-fields eventbridge-woocommerce-fields"><option value="click" <?php selected( $values['trigger_type'], 'click' ); ?>><?php echo esc_html__( 'Klik op een element', 'eventbridge' ); ?></option><option value="pageview" <?php selected( $values['trigger_type'], 'pageview' ); ?>><?php echo esc_html__( 'Pagina bezocht', 'eventbridge' ); ?></option><option value="woocommerce" <?php selected( $values['trigger_type'], 'woocommerce' ); ?><?php disabled( ! $woocommerce_available && ! $is_woocommerce ); ?>><?php echo esc_html__( 'WooCommerce', 'eventbridge' ); ?></option></select><?php if ( $woocommerce_locked ) : ?><input type="hidden" name="eventbridge_event[trigger_type]" value="woocommerce"><?php endif; ?><p class="description" id="eventbridge-trigger-description"><?php echo $is_woocommerce ? esc_html__( 'Dit event wordt server-side door een WooCommerce-ordergebeurtenis gestart.', 'eventbridge' ) : ( $is_pageview ? esc_html__( 'Het event vuurt af zodra iemand een passende pagina bezoekt.', 'eventbridge' ) : esc_html__( 'Het event vuurt af zodra iemand op het gekozen element klikt.', 'eventbridge' ) ); ?></p></div>
			<div class="eventbridge-field" id="eventbridge-selector-row"<?php echo $is_pageview || $is_woocommerce ? ' hidden' : ''; ?>><label for="eventbridge_event_selector"><?php echo esc_html__( 'CSS-selector van het element', 'eventbridge' ); ?></label><input type="text" class="regular-text" id="eventbridge_event_selector" name="eventbridge_event[selector]" value="<?php echo esc_attr( $values['selector'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::SELECTOR_MAX_LENGTH ); ?>" placeholder=".boek-knop"<?php echo $is_pageview || $is_woocommerce ? '' : ' required'; ?>><p class="description"><?php echo esc_html__( 'Bijvoorbeeld .boek-knop of #contact-verzenden.', 'eventbridge' ); ?></p></div>
			<div id="eventbridge-pageview-fields"<?php echo $is_pageview ? '' : ' hidden'; ?>>
				<div class="eventbridge-form-grid">
					<div class="eventbridge-field" id="eventbridge-url-match-type-row"><label for="eventbridge_event_url_match_type"><?php echo esc_html__( 'Hoe vergelijken?', 'eventbridge' ); ?></label><select id="eventbridge_event_url_match_type" name="eventbridge_event[url_match_type]"<?php echo $is_pageview ? ' required' : ''; ?>><option value="path_exact" <?php selected( $values['url_match_type'], 'path_exact' ); ?>><?php echo esc_html__( 'Pad is exact', 'eventbridge' ); ?></option><option value="path_contains" <?php selected( $values['url_match_type'], 'path_contains' ); ?>><?php echo esc_html__( 'Pad bevat', 'eventbridge' ); ?></option><option value="url_exact" <?php selected( $values['url_match_type'], 'url_exact' ); ?>><?php echo esc_html__( 'Volledige URL is exact', 'eventbridge' ); ?></option></select></div>
					<div class="eventbridge-field" id="eventbridge-url-match-value-row"><label for="eventbridge_event_url_match_value"><?php echo esc_html__( 'Pad of URL', 'eventbridge' ); ?></label><input type="text" class="large-text" id="eventbridge_event_url_match_value" name="eventbridge_event[url_match_value]" value="<?php echo esc_attr( $values['url_match_value'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::URL_MATCH_VALUE_MAX_LENGTH ); ?>" placeholder="/bedankt"<?php echo $is_pageview ? ' required' : ''; ?>></div>
				</div>
			</div>
			<div id="eventbridge-woocommerce-fields"<?php echo $is_woocommerce ? '' : ' hidden'; ?> class="eventbridge-locked-group"<?php echo $woocommerce_locked ? ' data-woocommerce-locked="1"' : ''; ?>>
				<p role="status"><?php $this->render_status_badge( sprintf( __( 'WooCommerce · %s', 'eventbridge' ), $runtime_message[1] ), $runtime_message[0] ); ?></p>
				<p class="description"><?php echo esc_html( $runtime_message[2] ); ?></p>
				<p class="description"><?php echo esc_html__( 'WooCommerce-lifecycleevents worden uitsluitend server-side via Meta Conversion API verstuurd.', 'eventbridge' ); ?></p>
				<div class="eventbridge-form-grid">
					<div class="eventbridge-field"><label for="eventbridge_woocommerce_event"><?php echo esc_html__( 'WooCommerce-gebeurtenis', 'eventbridge' ); ?></label><select id="eventbridge_woocommerce_event" name="eventbridge_event[woocommerce][event]"<?php echo $is_woocommerce && ! $woocommerce_locked ? ' required' : ''; ?><?php disabled( ! $is_woocommerce || $woocommerce_locked ); ?><?php echo $woocommerce_locked ? ' aria-disabled="true"' : ''; ?> aria-controls="eventbridge-woocommerce-status-field"><option value="created" <?php selected( $configuration['event'], 'created' ); ?>><?php echo esc_html__( 'Bestelling aangemaakt', 'eventbridge' ); ?></option><option value="paid" <?php selected( $configuration['event'], 'paid' ); ?>><?php echo esc_html__( 'Betaling voltooid', 'eventbridge' ); ?></option><option value="status" <?php selected( $configuration['event'], 'status' ); ?>><?php echo esc_html__( 'Bestelling krijgt gekozen status', 'eventbridge' ); ?></option></select></div>
					<div class="eventbridge-field" id="eventbridge-woocommerce-status-field"<?php echo $is_woocommerce && 'status' === $configuration['event'] ? '' : ' hidden'; ?>><label for="eventbridge_woocommerce_status"><?php echo esc_html__( 'Doelstatus', 'eventbridge' ); ?></label><select id="eventbridge_woocommerce_status" name="eventbridge_event[woocommerce][status]"<?php echo $is_woocommerce && 'status' === $configuration['event'] && ! $woocommerce_locked ? ' required' : ''; ?><?php disabled( ! $is_woocommerce || 'status' !== $configuration['event'] || $woocommerce_locked ); ?><?php echo $woocommerce_locked ? ' aria-disabled="true"' : ''; ?>><option value=""><?php echo esc_html__( 'Kies een status', 'eventbridge' ); ?></option><?php foreach ( $order_statuses as $status_slug => $status_label ) : ?><option value="<?php echo esc_attr( $status_slug ); ?>" <?php selected( $configuration['status'], $status_slug ); ?>><?php echo esc_html( $status_label ); ?></option><?php endforeach; ?></select></div>
				</div>
				<?php if ( $woocommerce_locked ) : ?>
					<input type="hidden" name="eventbridge_event[woocommerce][event]" value="<?php echo esc_attr( $configuration['event'] ); ?>">
					<input type="hidden" name="eventbridge_event[woocommerce][status]" value="<?php echo esc_attr( $configuration['status'] ); ?>">
					<?php if ( $configuration['purchase_preset'] ) : ?><input type="hidden" name="eventbridge_event[woocommerce][purchase_preset]" value="1"><?php endif; ?>
					<p class="eventbridge-inline-notice is-warning"><?php echo esc_html__( 'Deze bestaande WooCommerce-configuratie blijft behouden. Activeer een ondersteunde WooCommerce-versie om haar te wijzigen.', 'eventbridge' ); ?></p>
				<?php endif; ?>
			</div>
			<?php $this->render_event_conditions_section( $values, $is_woocommerce, $woocommerce_available ); ?>
		</section>
		<?php
	}

	private function render_event_conditions_section( $values, $is_woocommerce, $woocommerce_available ) {
		$conditions = isset( $values['conditions'] ) && is_array( $values['conditions'] ) ? $values['conditions'] : array();
		$catalog    = $this->conditions ? $this->conditions->get_catalog( 'woocommerce' ) : array();
		$locked     = ! $woocommerce_available && ! empty( $conditions );
		?>
		<div id="eventbridge-conditions-section" class="eventbridge-conditions"<?php echo ! $is_woocommerce && empty( $conditions ) ? ' hidden' : ''; ?> data-has-conditions="<?php echo empty( $conditions ) ? '0' : '1'; ?>"<?php echo $locked ? ' data-woocommerce-locked="1"' : ''; ?>>
			<div class="eventbridge-conditions__heading">
				<div>
					<h4><?php echo esc_html__( 'Voorwaarden', 'eventbridge' ); ?></h4>
					<p class="description"><?php echo esc_html__( 'Alle voorwaarden moeten kloppen. Ze filteren alleen de trigger en voegen geen Meta-parameters toe.', 'eventbridge' ); ?></p>
				</div>
				<button type="button" class="button" id="eventbridge-add-condition"<?php disabled( ! $is_woocommerce || ! $woocommerce_available ); ?>><?php echo esc_html__( 'Voorwaarde toevoegen', 'eventbridge' ); ?></button>
			</div>
			<p id="eventbridge-condition-trigger-warning" class="eventbridge-inline-notice is-warning"<?php echo $is_woocommerce || empty( $conditions ) ? ' hidden' : ''; ?>><?php echo esc_html__( 'Voorwaarden zijn in 1.2.0 alleen beschikbaar voor een WooCommerce-trigger. Verwijder de rijen of kies WooCommerce.', 'eventbridge' ); ?></p>
			<?php if ( $locked ) : ?><p class="eventbridge-inline-notice is-warning"><?php echo esc_html__( 'Deze voorwaarden blijven behouden. Activeer een ondersteunde WooCommerce-versie om ze te wijzigen.', 'eventbridge' ); ?></p><?php endif; ?>
			<div class="eventbridge-condition-headings" aria-hidden="true"><span><?php echo esc_html__( 'Veld', 'eventbridge' ); ?></span><span><?php echo esc_html__( 'Operator', 'eventbridge' ); ?></span><span><?php echo esc_html__( 'Waarde', 'eventbridge' ); ?></span><span></span></div>
			<div id="eventbridge-condition-rows">
				<?php foreach ( $conditions as $index => $condition ) : ?>
					<?php $this->render_condition_row( $condition, $index, $catalog, $locked ); ?>
				<?php endforeach; ?>
			</div>
			<template id="eventbridge-condition-template">
				<?php $this->render_condition_row( array( 'provider' => 'woocommerce', 'field' => '', 'operator' => '', 'value' => '' ), '__INDEX__', $catalog, false ); ?>
			</template>
		</div>
		<?php
	}

	private function render_condition_row( $condition, $index, $catalog, $locked, $conditions_base = 'eventbridge_event[conditions]', $condition_context = '' ) {
		$provider = isset( $condition['provider'] ) && is_scalar( $condition['provider'] ) ? sanitize_key( (string) $condition['provider'] ) : '';
		$field    = isset( $condition['field'] ) && is_scalar( $condition['field'] ) ? sanitize_key( (string) $condition['field'] ) : '';
		$operator = isset( $condition['operator'] ) && is_scalar( $condition['operator'] ) ? sanitize_key( (string) $condition['operator'] ) : '';
		$value    = array_key_exists( 'value', $condition ) ? $condition['value'] : '';
		$base     = $conditions_base . '[' . $index . ']';
		$operators = isset( $catalog[ $field ]['operators'] ) ? $catalog[ $field ]['operators'] : array();
		$incompatible = '' !== $field && '' !== $condition_context && isset( $catalog[ $field ]['contexts'] ) && ! in_array( $condition_context, $catalog[ $field ]['contexts'], true );
		?>
		<div class="eventbridge-condition-row<?php echo $incompatible ? ' is-incompatible' : ''; ?>"<?php echo $locked ? ' data-woocommerce-locked="1"' : ''; ?><?php echo $incompatible ? ' data-eventbridge-incompatible="1"' : ''; ?>>
			<input type="hidden" name="<?php echo esc_attr( $base ); ?>[provider]" value="<?php echo esc_attr( '' !== $provider ? $provider : 'woocommerce' ); ?>">
			<label><span class="screen-reader-text"><?php echo esc_html__( 'Veld', 'eventbridge' ); ?></span><select class="eventbridge-condition-field" name="<?php echo esc_attr( $base ); ?>[field]"<?php disabled( $locked ); ?><?php echo $incompatible ? ' aria-invalid="true"' : ''; ?> required><option value=""><?php echo esc_html__( 'Kies een veld', 'eventbridge' ); ?></option><?php foreach ( $catalog as $field_key => $configuration ) : ?><option value="<?php echo esc_attr( $field_key ); ?>" data-contexts="<?php echo esc_attr( isset( $configuration['contexts'] ) ? implode( ',', $configuration['contexts'] ) : '' ); ?>" <?php selected( $field, $field_key ); ?>><?php echo esc_html( $configuration['label'] ); ?></option><?php endforeach; ?></select></label>
			<label><span class="screen-reader-text"><?php echo esc_html__( 'Operator', 'eventbridge' ); ?></span><select class="eventbridge-condition-operator" name="<?php echo esc_attr( $base ); ?>[operator]"<?php disabled( $locked ); ?> required><option value=""><?php echo esc_html__( 'Kies een operator', 'eventbridge' ); ?></option><?php foreach ( $operators as $operator_key => $configuration ) : ?><option value="<?php echo esc_attr( $operator_key ); ?>" <?php selected( $operator, $operator_key ); ?>><?php echo esc_html( $configuration['label'] ); ?></option><?php endforeach; ?></select></label>
			<div class="eventbridge-condition-value">
				<?php $this->render_condition_value( $base, $field, $operator, $value, $catalog, $locked ); ?>
			</div>
			<?php if ( $locked ) : ?>
				<?php $this->render_condition_hidden_value( $base, $value ); ?>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[field]" value="<?php echo esc_attr( $field ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[operator]" value="<?php echo esc_attr( $operator ); ?>">
				<span class="eventbridge-lock-note"><?php echo esc_html__( 'Behouden', 'eventbridge' ); ?></span>
			<?php else : ?>
				<button type="button" class="button-link-delete eventbridge-remove-condition"><?php echo esc_html__( 'Verwijderen', 'eventbridge' ); ?></button>
			<?php endif; ?>
			<span class="eventbridge-compatibility-note" role="alert"<?php echo $incompatible ? '' : ' hidden'; ?>><?php echo esc_html__( 'Deze voorwaarde is niet beschikbaar voor de gekozen WooCommerce-gebeurtenis. Kies een passend veld of herstel het subtype.', 'eventbridge' ); ?></span>
		</div>
		<?php
	}

	private function render_condition_value( $base, $field, $operator, $value, $catalog, $locked ) {
		$config     = isset( $catalog[ $field ]['operators'][ $operator ] ) ? $catalog[ $field ]['operators'][ $operator ] : array();
		$value_type = isset( $config['value_type'] ) ? $config['value_type'] : '';
		$search     = isset( $config['search'] ) ? $config['search'] : '';

		if ( in_array( $value_type, array( 'reference', 'references', 'reference_string' ), true ) ) {
			$values = is_array( $value ) ? $value : ( '' === (string) $value ? array() : array( $value ) );
			$labels = $this->conditions ? $this->conditions->resolve_value_labels( 'woocommerce', $field, $values ) : array();
			$name   = $base . '[value]' . ( 'references' === $value_type ? '[]' : '' );
			?><select class="eventbridge-condition-search" name="<?php echo esc_attr( $name ); ?>" data-field="<?php echo esc_attr( $field ); ?>" data-search="<?php echo esc_attr( $search ); ?>"<?php echo 'references' === $value_type ? ' multiple' : ''; ?><?php disabled( $locked ); ?> required><?php foreach ( $values as $selected_value ) : $key = (string) $selected_value; ?><option value="<?php echo esc_attr( $key ); ?>" selected><?php echo esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ); ?></option><?php endforeach; ?></select><?php
			return;
		}

		if ( 'fixed_true' === $value_type ) {
			?><input type="text" value="<?php echo esc_attr__( 'Ja', 'eventbridge' ); ?>" disabled aria-disabled="true"><input type="hidden" name="<?php echo esc_attr( $base ); ?>[value]" value="1"><?php
			return;
		}

		$type = in_array( $value_type, array( 'decimal', 'integer' ), true ) ? 'number' : 'text';
		$step = 'decimal' === $value_type ? 'any' : ( 'integer' === $value_type ? '1' : '' );
		?><input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $base ); ?>[value]" value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>"<?php echo '' !== $step ? ' step="' . esc_attr( $step ) . '" min="0"' : ' maxlength="100"'; ?><?php disabled( $locked ); ?> required><?php
	}

	private function render_condition_hidden_value( $base, $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					?><input type="hidden" name="<?php echo esc_attr( $base ); ?>[value][]" value="<?php echo esc_attr( (string) $item ); ?>"><?php
				}
			}
			return;
		}
		if ( is_scalar( $value ) ) {
			?><input type="hidden" name="<?php echo esc_attr( $base ); ?>[value]" value="<?php echo esc_attr( (string) $value ); ?>"><?php
		}
	}

	private function render_event_data_source_section( $values, $fluent_status ) {
		$data_source     = isset( $values['data_source'] ) && is_array( $values['data_source'] ) ? $values['data_source'] : array();
		$provider        = isset( $data_source['provider'] ) ? $data_source['provider'] : '';
		$fluent_selected = 'fluent_booking' === $provider;
		$fluent_available = 'available' === $fluent_status;
		$fluent_locked   = $fluent_selected && ! $fluent_available;
		$status_messages = array(
			'available'          => array( 'success', __( 'Fluent Booking is actief en beschikbaar.', 'eventbridge' ) ),
			'installed_inactive' => array( 'warning', __( 'Fluent Booking is geïnstalleerd maar niet actief. Nieuwe Fluent-configuratie is tijdelijk geblokkeerd.', 'eventbridge' ) ),
			'unavailable'        => array( 'warning', __( 'Fluent Booking is niet beschikbaar. Nieuwe Fluent-configuratie is tijdelijk geblokkeerd.', 'eventbridge' ) ),
		);
		$status = $status_messages[ $fluent_status ];
		?>
		<section class="eventbridge-form-card" id="eventbridge-fluent-data-source-card">
			<?php $this->render_event_card_heading( 3, __( 'Waar komt aanvullende informatie vandaan?', 'eventbridge' ), __( 'Koppel desgewenst gegevens uit een bestaande Fluent Booking-boeking.', 'eventbridge' ) ); ?>
			<div class="eventbridge-inline-notice is-<?php echo esc_attr( $status[0] ); ?>" role="status"><?php echo esc_html( $status[1] ); ?></div>
			<div id="eventbridge-data-source" class="eventbridge-field">
				<label for="eventbridge_data_source_provider"><?php echo esc_html__( 'Externe databron', 'eventbridge' ); ?></label>
				<select id="eventbridge_data_source_provider"<?php echo $fluent_locked ? ' disabled aria-disabled="true"' : ' name="eventbridge_event[data_source][provider]"'; ?> aria-controls="eventbridge-fluent-booking-settings">
					<option value="" <?php selected( $provider, '' ); ?>><?php echo esc_html__( 'Geen externe databron', 'eventbridge' ); ?></option>
					<option value="fluent_booking" <?php selected( $provider, 'fluent_booking' ); ?><?php disabled( ! $fluent_available && ! $fluent_selected ); ?>><?php echo esc_html__( 'Fluent Booking', 'eventbridge' ); ?></option>
				</select>
				<?php if ( $fluent_locked ) : ?><input type="hidden" name="eventbridge_event[data_source][provider]" value="fluent_booking"><?php endif; ?>
			</div>
			<div id="eventbridge-fluent-booking-settings" class="eventbridge-form-grid eventbridge-locked-group"<?php echo $fluent_selected ? '' : ' hidden'; ?><?php echo $fluent_locked ? ' data-fluent-locked="1"' : ''; ?>>
				<?php if ( $fluent_locked ) : ?>
					<input type="hidden" name="eventbridge_event[data_source][lookup_source]" value="<?php echo esc_attr( $data_source['lookup_source'] ); ?>">
					<input type="hidden" name="eventbridge_event[data_source][lookup_value]" value="<?php echo esc_attr( $data_source['lookup_value'] ); ?>">
					<input type="hidden" name="eventbridge_event[data_source][expected_event_id]" value="<?php echo esc_attr( $data_source['expected_event_id'] ); ?>">
				<?php else : ?>
					<input type="hidden" name="eventbridge_event[data_source][lookup_source]" value="query_parameter">
				<?php endif; ?>
				<div class="eventbridge-field"><label for="eventbridge_data_source_lookup_value"><?php echo esc_html__( 'Queryparameter met booking hash', 'eventbridge' ); ?></label><input type="text" class="regular-text" id="eventbridge_data_source_lookup_value"<?php echo $fluent_locked ? ' disabled aria-disabled="true"' : ' name="eventbridge_event[data_source][lookup_value]"'; ?> value="<?php echo esc_attr( isset( $data_source['lookup_value'] ) ? $data_source['lookup_value'] : '' ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::QUERY_PARAMETER_NAME_MAX_LENGTH ); ?>" pattern="[A-Za-z0-9_]+" placeholder="booking_hash"<?php echo $fluent_selected && ! $fluent_locked ? ' required' : ''; ?>></div>
				<div class="eventbridge-field"><label for="eventbridge_data_source_expected_event_id"><?php echo esc_html__( 'Verwacht Fluent Event ID (optioneel)', 'eventbridge' ); ?></label><input type="text" class="regular-text" id="eventbridge_data_source_expected_event_id"<?php echo $fluent_locked ? ' disabled aria-disabled="true"' : ' name="eventbridge_event[data_source][expected_event_id]"'; ?> value="<?php echo esc_attr( isset( $data_source['expected_event_id'] ) ? $data_source['expected_event_id'] : '' ); ?>" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="20"></div>
				<p class="description eventbridge-field--wide"><?php echo esc_html__( 'De booking hash wordt alleen server-side gebruikt om de boeking te vinden en wordt niet als eventdata meegestuurd.', 'eventbridge' ); ?></p>
				<?php if ( $fluent_locked ) : ?><p class="eventbridge-inline-notice is-warning eventbridge-field--wide"><?php echo esc_html__( 'Deze bestaande Fluent-configuratie blijft behouden. Activeer Fluent Booking om haar te wijzigen.', 'eventbridge' ); ?></p><?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function render_event_parameters_section( $values, $fluent_available, $woocommerce_available ) {
		$is_woocommerce     = 'woocommerce' === $values['trigger_type'];
		$woocommerce_locked = $is_woocommerce && ! $woocommerce_available;
		$purchase_preset    = ! empty( $values['woocommerce']['purchase_preset'] );
		?>
		<section class="eventbridge-form-card">
			<?php $this->render_event_card_heading( 4, __( 'Welke gegevens worden meegestuurd?', 'eventbridge' ), __( 'Voeg gewone eventdata toe. Dit zijn geen persoonsgegevens voor Meta Advanced Matching.', 'eventbridge' ) ); ?>
			<div id="eventbridge-woocommerce-purchase-preset"<?php echo $is_woocommerce ? '' : ' hidden'; ?>>
				<label class="eventbridge-toggle"><input type="checkbox" id="eventbridge_woocommerce_purchase_preset" name="eventbridge_event[woocommerce][purchase_preset]" value="1" <?php checked( $purchase_preset ); ?><?php disabled( ! $is_woocommerce || $woocommerce_locked ); ?><?php echo $woocommerce_locked ? ' aria-disabled="true"' : ''; ?>> <span><?php echo esc_html__( 'WooCommerce Purchase-preset gebruiken', 'eventbridge' ); ?></span></label>
				<p class="description"><?php echo esc_html__( 'Vult value, currency, order_id, content_type, content_ids, contents en num_items automatisch. Dit werkt met een custom eventnaam; het standaardevent Purchase blijft ook mogelijk.', 'eventbridge' ); ?></p>
			</div>
			<div class="eventbridge-parameter-headings" aria-hidden="true"><span><?php echo esc_html__( 'Naam in Meta', 'eventbridge' ); ?></span><span><?php echo esc_html__( 'Bron', 'eventbridge' ); ?></span><span><?php echo esc_html__( 'Waarde of Fluent-veld', 'eventbridge' ); ?></span><span></span></div>
			<div id="eventbridge-event-parameters">
				<?php foreach ( $values['parameters'] as $index => $parameter ) : ?>
					<?php $this->render_parameter_row( $parameter, $index, $fluent_available, $woocommerce_available, $is_woocommerce ); ?>
				<?php endforeach; ?>
			</div>
			<p><button type="button" class="button" id="eventbridge-add-parameter"><?php echo esc_html__( 'Eventgegeven toevoegen', 'eventbridge' ); ?></button></p>
			<template id="eventbridge-parameter-template"><?php $this->render_parameter_row( array( 'name' => '', 'source' => 'static', 'value' => '' ), '__INDEX__', $fluent_available, $woocommerce_available, $is_woocommerce ); ?></template>
		</section>
		<?php
	}

	private function render_event_advanced_matching_section( $values, $fluent_available, $woocommerce_available ) {
		$is_woocommerce = 'woocommerce' === $values['trigger_type'];
		$billing_map    = $this->woocommerce->get_billing_field_map();
		$fields = array(
			'email'      => array( __( 'E-mail', 'eventbridge' ), __( 'Bijv. lars@example.com', 'eventbridge' ), __( 'Bijv. email', 'eventbridge' ) ),
			'phone'      => array( __( 'Telefoon', 'eventbridge' ), __( 'Bijv. +32470123456', 'eventbridge' ), __( 'Bijv. telefoon', 'eventbridge' ) ),
			'first_name' => array( __( 'Voornaam', 'eventbridge' ), __( 'Bijv. Lars', 'eventbridge' ), __( 'Bijv. voornaam', 'eventbridge' ) ),
			'last_name'  => array( __( 'Achternaam', 'eventbridge' ), __( 'Bijv. Janssens', 'eventbridge' ), __( 'Bijv. familienaam', 'eventbridge' ) ),
		);
		?>
		<section class="eventbridge-form-card">
			<?php $this->render_event_card_heading( 5, __( 'Meta Advanced Matching', 'eventbridge' ), __( 'Verwerkt klantgegevens server-side voor een betere koppeling met Meta.', 'eventbridge' ) ); ?>
			<details id="eventbridge-advanced-matching" class="eventbridge-details">
				<summary><?php echo esc_html__( 'Advanced Matching instellen', 'eventbridge' ); ?></summary>
				<div class="eventbridge-details__content">
					<p><?php echo esc_html__( 'E-mail, telefoon en naam worden server-side genormaliseerd en gehasht. Ze worden niet naar de tracking-JavaScript of het activiteitenlog gestuurd.', 'eventbridge' ); ?></p>
					<p id="eventbridge-advanced-matching-capi-warning" class="eventbridge-inline-notice is-warning"<?php echo $values['capi'] ? ' hidden' : ''; ?>><?php echo esc_html__( 'Meta Conversion API moet aanstaan om Advanced Matching te gebruiken.', 'eventbridge' ); ?></p>
					<div class="eventbridge-parameter-headings" aria-hidden="true"><span><?php echo esc_html__( 'Klantgegeven', 'eventbridge' ); ?></span><span><?php echo esc_html__( 'Bron', 'eventbridge' ); ?></span><span><?php echo esc_html__( 'Waarde', 'eventbridge' ); ?></span></div>
					<?php foreach ( $fields as $field_key => $field ) : ?>
						<?php
						$configuration = isset( $values['advanced_matching'][ $field_key ] ) ? $values['advanced_matching'][ $field_key ] : array( 'source' => '', 'value' => '' );
						$source        = isset( $configuration['source'] ) ? $configuration['source'] : '';
						$value         = isset( $configuration['value'] ) ? $configuration['value'] : '';
						$locked        = ( ! $fluent_available && 'fluent_booking' === $source ) || ( ! $woocommerce_available && 'woocommerce_billing' === $source );
						$lock_attribute = 'woocommerce_billing' === $source ? ' data-woocommerce-locked="1"' : ' data-fluent-locked="1"';
						$value_label   = 'query_parameter' === $source ? __( 'Queryparameternaam', 'eventbridge' ) : ( 'static' === $source ? __( 'Vaste waarde', 'eventbridge' ) : ( 'woocommerce_billing' === $source ? __( 'Automatisch uit facturatiegegevens', 'eventbridge' ) : ( 'fluent_booking' === $source ? __( 'Automatisch uit boeking', 'eventbridge' ) : __( 'Waarde', 'eventbridge' ) ) ) );
						$billing_value = isset( $billing_map[ $field_key ] ) ? $billing_map[ $field_key ] : '';
						?>
						<div class="eventbridge-parameter-row eventbridge-advanced-matching-row"<?php echo $locked ? $lock_attribute : ''; ?>>
							<span class="eventbridge-parameter-label"><?php echo esc_html( $field[0] ); ?></span>
							<label><span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Bron voor %s', 'eventbridge' ), $field[0] ) ); ?></span><select class="eventbridge-advanced-matching-source" data-woocommerce-value="<?php echo esc_attr( $billing_value ); ?>"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="eventbridge_event[advanced_matching][' . esc_attr( $field_key ) . '][source]"'; ?>><option value="" <?php selected( $source, '' ); ?>><?php echo esc_html__( 'Niet gebruiken', 'eventbridge' ); ?></option><option value="static" <?php selected( $source, 'static' ); ?><?php disabled( $is_woocommerce ); ?>><?php echo esc_html__( 'Vaste waarde', 'eventbridge' ); ?></option><option value="query_parameter" <?php selected( $source, 'query_parameter' ); ?><?php disabled( $is_woocommerce ); ?>><?php echo esc_html__( 'Queryparameter', 'eventbridge' ); ?></option><option value="fluent_booking" <?php selected( $source, 'fluent_booking' ); ?><?php disabled( $is_woocommerce || ( ! $fluent_available && ! $locked ) ); ?>><?php echo esc_html__( 'Fluent Booking', 'eventbridge' ); ?></option><option value="woocommerce_billing" <?php selected( $source, 'woocommerce_billing' ); ?><?php disabled( ! $is_woocommerce || ( ! $woocommerce_available && ! $locked ) ); ?>><?php echo esc_html__( 'WooCommerce-facturatiegegevens', 'eventbridge' ); ?></option></select></label>
							<label><span class="screen-reader-text eventbridge-advanced-matching-value-label-text"><?php echo esc_html( $value_label ); ?></span><input type="text" class="regular-text eventbridge-advanced-matching-value"<?php echo $locked || 'woocommerce_billing' === $source ? ' disabled aria-disabled="true"' : ' name="eventbridge_event[advanced_matching][' . esc_attr( $field_key ) . '][value]"'; ?> value="<?php echo esc_attr( 'woocommerce_billing' === $source ? $billing_value : $value ); ?>" maxlength="<?php echo esc_attr( 'query_parameter' === $source ? EventBridge_Events::QUERY_PARAMETER_NAME_MAX_LENGTH : EventBridge_Events::PARAMETER_VALUE_MAX_LENGTH ); ?>" data-static-placeholder="<?php echo esc_attr( $field[1] ); ?>" data-query-placeholder="<?php echo esc_attr( $field[2] ); ?>" placeholder="<?php echo esc_attr( 'query_parameter' === $source ? $field[2] : ( 'static' === $source ? $field[1] : ( 'woocommerce_billing' === $source ? __( 'Automatisch uit facturatiegegevens', 'eventbridge' ) : ( 'fluent_booking' === $source ? __( 'Automatisch uit boeking', 'eventbridge' ) : '' ) ) ) ); ?>"<?php echo 'query_parameter' === $source ? ' pattern="[A-Za-z0-9_]+" required' : ( 'static' === $source ? ' required' : ( $locked || 'woocommerce_billing' === $source ? '' : ' disabled' ) ); ?>></label>
							<?php if ( $locked ) : ?><input type="hidden" name="eventbridge_event[advanced_matching][<?php echo esc_attr( $field_key ); ?>][source]" value="<?php echo esc_attr( $source ); ?>"><input type="hidden" name="eventbridge_event[advanced_matching][<?php echo esc_attr( $field_key ); ?>][value]" value="<?php echo esc_attr( $value ); ?>"><span class="eventbridge-lock-note"><?php echo esc_html__( 'Behouden', 'eventbridge' ); ?></span><?php elseif ( 'woocommerce_billing' === $source ) : ?><input class="eventbridge-advanced-matching-fixed-value" type="hidden" name="eventbridge_event[advanced_matching][<?php echo esc_attr( $field_key ); ?>][value]" value="<?php echo esc_attr( $billing_value ); ?>"><?php endif; ?>
						</div>
					<?php endforeach; ?>
					<p class="description"><?php echo esc_html__( 'Bij Fluent Booking of WooCommerce haalt EventBridge het gekozen klantgegeven automatisch server-side op.', 'eventbridge' ); ?></p>
				</div>
			</details>
		</section>
		<?php
	}

	private function render_event_delivery_section( $values, $family ) {
		$channels  = isset( $values['channels'] ) && is_array( $values['channels'] ) ? $values['channels'] : array();
		$browser   = ! empty( $channels['browser'] );
		$capi      = ! empty( $channels['capi'] );
		$is_server = EventBridge_Triggers::FAMILY_SERVER === $family;
		?>
		<div class="eventbridge-event-channels" id="eventbridge-event-channels" data-family="<?php echo esc_attr( $family ); ?>">
			<h4><?php echo esc_html__( 'Verzendkanalen', 'eventbridge' ); ?></h4>
			<p id="eventbridge-channel-explanation" class="description"><?php echo esc_html( $is_server ? __( 'Backendtriggers worden uitsluitend via Meta Conversion API verstuurd.', 'eventbridge' ) : __( 'Frontendtriggers kunnen via browser, CAPI of beide worden verstuurd.', 'eventbridge' ) ); ?></p>
			<p id="eventbridge-channel-adjustment" class="eventbridge-inline-notice is-warning" role="status" hidden></p>
			<fieldset class="eventbridge-channel-options" aria-describedby="eventbridge-channel-error eventbridge-channel-explanation">
				<legend class="screen-reader-text"><?php echo esc_html__( 'Verzendkanalen', 'eventbridge' ); ?></legend>
				<label class="eventbridge-choice-card"><input type="checkbox" id="eventbridge_event_browser" name="eventbridge_event[channels][browser]" value="1" <?php checked( $browser ); ?><?php disabled( $is_server ); ?>><span><strong><?php echo esc_html__( 'Meta Pixel in de browser', 'eventbridge' ); ?></strong><small><?php echo esc_html__( 'Stuurt het event vanuit de browser van de bezoeker.', 'eventbridge' ); ?></small></span></label>
				<label class="eventbridge-choice-card"><input type="checkbox" id="eventbridge_event_capi" name="eventbridge_event[channels][capi]" value="1" <?php checked( $is_server || $capi ); ?><?php disabled( $is_server ); ?> aria-controls="eventbridge-event-diagnostics"><span><strong><?php echo esc_html__( 'Meta Conversion API', 'eventbridge' ); ?></strong><small><?php echo esc_html__( 'Stuurt het event aanvullend of afzonderlijk vanaf de server.', 'eventbridge' ); ?></small></span></label>
				<input id="eventbridge_event_capi_required" type="hidden" name="eventbridge_event[channels][capi]" value="1"<?php disabled( ! $is_server ); ?>>
			</fieldset>
			<p id="eventbridge-channel-error" class="eventbridge-inline-notice is-error"<?php echo $browser || $capi || $is_server ? ' hidden' : ''; ?>><?php echo esc_html__( 'Schakel minstens één verzendkanaal in.', 'eventbridge' ); ?></p>
		</div>
		<?php
	}

	private function render_event_diagnostics_section( $values ) {
		?>
		<section class="eventbridge-form-card" id="eventbridge-event-diagnostics"<?php echo $values['capi'] ? '' : ' hidden'; ?>>
			<?php $this->render_event_card_heading( 3, __( 'Testen en diagnose', 'eventbridge' ), __( 'Gebruik Meta Test Events om een serverevent tijdelijk te controleren.', 'eventbridge' ) ); ?>
			<p class="eventbridge-inline-notice is-warning"><?php echo esc_html__( 'Testmodus is uitsluitend bedoeld voor Meta Test Events. Schakel hem vóór productie weer uit.', 'eventbridge' ); ?></p>
			<div class="eventbridge-field"><label class="eventbridge-toggle"><input type="checkbox" id="eventbridge_event_meta_test_mode" name="eventbridge_event[meta_test_mode]" value="1" <?php checked( $values['meta_test_mode'] ); ?><?php disabled( ! $values['capi'] ); ?> aria-controls="eventbridge-meta-test-event-code-field"> <span><?php echo esc_html__( 'Meta CAPI-testmodus inschakelen', 'eventbridge' ); ?></span></label></div>
			<div class="eventbridge-field" id="eventbridge-meta-test-event-code-field"><label for="eventbridge_event_meta_test_event_code"><?php echo esc_html__( 'Meta Test Event Code', 'eventbridge' ); ?></label><input type="text" class="regular-text" id="eventbridge_event_meta_test_event_code" name="eventbridge_event[meta_test_event_code]" value="<?php echo esc_attr( $values['meta_test_event_code'] ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::META_TEST_EVENT_CODE_MAX_LENGTH ); ?>" pattern="TEST[0-9]+" placeholder="TEST12345"<?php echo $values['capi'] && $values['meta_test_mode'] ? ' required' : ''; ?>><p class="description"><?php echo esc_html__( 'Wordt alleen met Conversion API meegestuurd wanneer testmodus actief is. De code blijft bewaard als CAPI tijdelijk uit staat.', 'eventbridge' ); ?></p></div>
		</section>
		<?php
	}

	private function get_fluent_runtime_status() {
		if ( $this->fluent_booking->is_available() ) {
			return 'available';
		}

		$plugin_file = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/fluent-booking/fluent-booking.php' : '';
		if ( '' === $plugin_file || ! file_exists( $plugin_file ) ) {
			return 'unavailable';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$is_active = is_plugin_active( 'fluent-booking/fluent-booking.php' )
			|| ( is_multisite() && is_plugin_active_for_network( 'fluent-booking/fluent-booking.php' ) );

		return $is_active ? 'unavailable' : 'installed_inactive';
	}

	private function render_parameter_row( $parameter, $index, $fluent_available, $woocommerce_available, $is_woocommerce, $parameters_base = 'eventbridge_event[parameters]', $interaction_type = '' ) {
		$name   = isset( $parameter['name'] ) && is_scalar( $parameter['name'] ) ? (string) $parameter['name'] : '';
		$source = isset( $parameter['source'] ) && is_scalar( $parameter['source'] ) ? (string) $parameter['source'] : 'static';
		$value  = isset( $parameter['value'] ) && is_scalar( $parameter['value'] ) ? (string) $parameter['value'] : '';

		if ( ! in_array( $source, array( 'static', 'query_parameter', 'fluent_booking', 'woocommerce_order', 'woocommerce_interaction' ), true ) ) {
			$source = 'static';
		}
		$locked = ( ! $fluent_available && 'fluent_booking' === $source ) || ( ! $woocommerce_available && in_array( $source, array( 'woocommerce_order', 'woocommerce_interaction' ), true ) );
		$order_fields = $this->woocommerce->get_order_parameter_fields();
		$interaction_fields = $this->woocommerce->get_interaction_parameter_fields();
		$incompatible = 'woocommerce_interaction' === $source && '' !== $value && ( '' === $interaction_type || ! isset( $interaction_fields[ $value ] ) || ! in_array( $interaction_type, $interaction_fields[ $value ]['contexts'], true ) );
		?>
		<div class="eventbridge-parameter-row<?php echo $incompatible ? ' is-incompatible' : ''; ?>"<?php echo $locked ? ( in_array( $source, array( 'woocommerce_order', 'woocommerce_interaction' ), true ) ? ' data-woocommerce-locked="1"' : ' data-fluent-locked="1"' ) : ''; ?><?php echo $incompatible ? ' data-eventbridge-incompatible="1"' : ''; ?>>
			<label><span class="screen-reader-text"><?php echo esc_html__( 'Naam in Meta', 'eventbridge' ); ?></span>
				<input type="text" class="regular-text"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $parameters_base . '[' . $index . '][name]' ) . '" required'; ?> value="<?php echo esc_attr( $name ); ?>" maxlength="<?php echo esc_attr( EventBridge_Events::PARAMETER_NAME_MAX_LENGTH ); ?>" pattern="[A-Za-z0-9_]+" placeholder="content_category">
			</label>
			<label><span class="screen-reader-text"><?php echo esc_html__( 'Bron', 'eventbridge' ); ?></span>
				<select class="eventbridge-parameter-source"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $parameters_base . '[' . $index . '][source]' ) . '" required'; ?>>
					<option value="static" <?php selected( $source, 'static' ); ?>><?php echo esc_html__( 'Vaste waarde', 'eventbridge' ); ?></option>
					<option value="query_parameter" <?php selected( $source, 'query_parameter' ); ?><?php disabled( $is_woocommerce ); ?>><?php echo esc_html__( 'Queryparameter', 'eventbridge' ); ?></option>
					<option value="fluent_booking" <?php selected( $source, 'fluent_booking' ); ?><?php disabled( $is_woocommerce || ( ! $fluent_available && ! $locked ) ); ?>><?php echo esc_html__( 'Fluent Booking', 'eventbridge' ); ?></option>
					<option value="woocommerce_order" <?php selected( $source, 'woocommerce_order' ); ?><?php disabled( ! $is_woocommerce || ( ! $woocommerce_available && ! $locked ) ); ?>><?php echo esc_html__( 'WooCommerce-bestelling', 'eventbridge' ); ?></option>
					<option value="woocommerce_interaction" <?php selected( $source, 'woocommerce_interaction' ); ?><?php disabled( '' === $interaction_type || ( ! $woocommerce_available && ! $locked ) ); ?>><?php echo esc_html__( 'WooCommerce-interactie', 'eventbridge' ); ?></option>
				</select>
			</label>
			<label class="eventbridge-parameter-value-label">
				<span class="screen-reader-text eventbridge-parameter-value-label-text"><?php echo esc_html__( 'Waarde of Fluent-veld', 'eventbridge' ); ?></span>
				<input type="text" class="regular-text eventbridge-parameter-value"<?php echo $locked ? '' : ' name="' . esc_attr( $parameters_base . '[' . $index . '][value]' ) . '"'; ?> value="<?php echo in_array( $source, array( 'fluent_booking', 'woocommerce_order', 'woocommerce_interaction' ), true ) ? '' : esc_attr( $value ); ?>" maxlength="<?php echo esc_attr( 'static' === $source ? EventBridge_Events::PARAMETER_VALUE_MAX_LENGTH : EventBridge_Events::QUERY_PARAMETER_NAME_MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr( 'static' === $source ? __( 'Bijv. hypnotherapy', 'eventbridge' ) : __( 'Bijv. booking_type', 'eventbridge' ) ); ?>"<?php echo 'query_parameter' === $source ? ' pattern="[A-Za-z0-9_]+"' : ''; ?><?php echo in_array( $source, array( 'fluent_booking', 'woocommerce_order', 'woocommerce_interaction' ), true ) ? ' hidden' : ' required'; ?><?php disabled( in_array( $source, array( 'fluent_booking', 'woocommerce_order', 'woocommerce_interaction' ), true ) || $locked ); ?>>
				<select class="eventbridge-parameter-fluent-field"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $parameters_base . '[' . $index . '][value]' ) . '"'; ?><?php echo 'fluent_booking' !== $source ? ' hidden' : ( $locked ? '' : ' required' ); ?><?php disabled( 'fluent_booking' !== $source || $locked ); ?>>
					<option value="booking_id" <?php selected( $value, 'booking_id' ); ?>><?php echo esc_html__( 'Booking ID', 'eventbridge' ); ?></option>
					<option value="event_id" <?php selected( $value, 'event_id' ); ?>><?php echo esc_html__( 'Event ID', 'eventbridge' ); ?></option>
					<option value="calendar_id" <?php selected( $value, 'calendar_id' ); ?>><?php echo esc_html__( 'Calendar ID', 'eventbridge' ); ?></option>
					<option value="start_time" <?php selected( $value, 'start_time' ); ?>><?php echo esc_html__( 'Starttijd', 'eventbridge' ); ?></option>
					<option value="event_title" <?php selected( $value, 'event_title' ); ?>><?php echo esc_html__( 'Eventtitel', 'eventbridge' ); ?></option>
				</select>
				<select class="eventbridge-parameter-woocommerce-field"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $parameters_base . '[' . $index . '][value]' ) . '"'; ?><?php echo 'woocommerce_order' !== $source ? ' hidden' : ( $locked ? '' : ' required' ); ?><?php disabled( 'woocommerce_order' !== $source || $locked ); ?>><?php foreach ( $order_fields as $field_key => $field_label ) : ?><option value="<?php echo esc_attr( $field_key ); ?>" <?php selected( $value, $field_key ); ?>><?php echo esc_html( $field_label ); ?></option><?php endforeach; ?></select>
				<select class="eventbridge-parameter-interaction-field"<?php echo $locked ? ' disabled aria-disabled="true"' : ' name="' . esc_attr( $parameters_base . '[' . $index . '][value]' ) . '"'; ?><?php echo 'woocommerce_interaction' !== $source ? ' hidden' : ( $locked ? '' : ' required' ); ?><?php echo $incompatible ? ' aria-invalid="true"' : ''; ?><?php disabled( 'woocommerce_interaction' !== $source || $locked ); ?>><?php foreach ( $interaction_fields as $field_key => $field ) : ?><option value="<?php echo esc_attr( $field_key ); ?>" data-contexts="<?php echo esc_attr( implode( ',', $field['contexts'] ) ); ?>" <?php selected( $value, $field_key ); ?><?php disabled( '' !== $interaction_type && ! in_array( $interaction_type, $field['contexts'], true ) && $value !== $field_key ); ?>><?php echo esc_html( $field['label'] ); ?></option><?php endforeach; ?></select>
			</label>
			<?php if ( $locked ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $parameters_base . '[' . $index . '][name]' ); ?>" value="<?php echo esc_attr( $name ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $parameters_base . '[' . $index . '][source]' ); ?>" value="<?php echo esc_attr( $source ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $parameters_base . '[' . $index . '][value]' ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<span class="eventbridge-lock-note"><?php echo esc_html__( 'Behouden', 'eventbridge' ); ?></span>
			<?php else : ?>
				<button type="button" class="button-link-delete eventbridge-remove-parameter"><?php echo esc_html__( 'Verwijderen', 'eventbridge' ); ?></button>
			<?php endif; ?>
			<span class="eventbridge-compatibility-note" role="alert"<?php echo $incompatible ? '' : ' hidden'; ?>><?php echo esc_html__( 'Deze parameterbron is niet beschikbaar voor de gekozen WooCommerce-gebeurtenis. Kies een passend interactieveld of herstel het subtype.', 'eventbridge' ); ?></span>
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
				'interactions' => 0,
				'browser'      => 0,
				'capi_started' => 0,
			);
		}

		return $period;
	}

	private function get_dashboard_day_ranges( DateTimeImmutable $today ) {
		$ranges = array();

		for ( $offset = 6; $offset >= 0; $offset-- ) {
			$start = $today->modify( '-' . $offset . ' days' );
			$end   = $start->modify( '+1 day' );
			$key   = $start->format( 'Y-m-d' );

			$ranges[ $key ] = array(
				'start' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'end'   => $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			);
		}

		return $ranges;
	}

	private function merge_dashboard_period( $period, $daily_statistics ) {
		$daily_statistics = is_array( $daily_statistics ) ? $daily_statistics : array();

		foreach ( $period as $key => &$day ) {
			if ( ! isset( $daily_statistics[ $key ] ) || ! is_array( $daily_statistics[ $key ] ) ) {
				continue;
			}

			foreach ( array( 'interactions', 'browser', 'capi_started' ) as $metric ) {
				$day[ $metric ] = isset( $daily_statistics[ $key ][ $metric ] ) ? absint( $daily_statistics[ $key ][ $metric ] ) : 0;
			}
		}
		unset( $day );

		return $period;
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

	private function render_overview_cards( $totals ) {
		$cards = array(
			'interactions'      => array( __( 'Unieke interacties', 'eventbridge' ), __( 'Unieke event-ID’s die EventBridge logde.', 'eventbridge' ) ),
			'browser'           => array( __( 'Browseraanroepen', 'eventbridge' ), __( 'Events die EventBridge in de browser aanriep.', 'eventbridge' ) ),
			'endpoint_accepted' => array( __( 'Verzoeken geaccepteerd', 'eventbridge' ), __( 'Verzoeken die het EventBridge-endpoint accepteerde.', 'eventbridge' ) ),
			'endpoint_rejected' => array( __( 'Verzoeken afgewezen', 'eventbridge' ), __( 'Verzoeken die het EventBridge-endpoint afwees.', 'eventbridge' ) ),
			'capi_started'      => array( __( 'Serververzending gestart', 'eventbridge' ), __( 'CAPI-verzoeken die EventBridge startte.', 'eventbridge' ) ),
			'capi_not_started'  => array( __( 'Serververzending niet gestart', 'eventbridge' ), __( 'CAPI-verzoeken die EventBridge niet kon starten.', 'eventbridge' ) ),
		);
		?>
		<div class="eventbridge-admin__section-heading"><div><h2><?php echo esc_html__( 'Laatste 7 kalenderdagen', 'eventbridge' ); ?></h2><p><?php echo esc_html__( 'Vandaag en de zes voorgaande kalenderdagen, volgens de ingestelde WordPress-tijdzone.', 'eventbridge' ); ?></p></div></div>
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
		<div class="eventbridge-dashboard__panel eventbridge-dashboard__table-panel"><h2><?php echo esc_html__( 'Activiteit per event', 'eventbridge' ); ?></h2>
		<?php if ( empty( $events ) ) : ?>
			<p><?php echo esc_html__( 'Er zijn in de laatste 7 dagen geen eventactiviteiten gelogd.', 'eventbridge' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Eventnaam', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Interacties', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Browseraanroepen', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Verzoeken geaccepteerd', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Verzoeken afgewezen', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Serververzending gestart', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Niet gestart', 'eventbridge' ); ?></th></tr></thead>
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
		$per_page      = EventBridge_Log::DEFAULT_PAGE_SIZE;
		$total_logs    = $this->log->count_logs();
		$total_pages   = max( 1, (int) ceil( $total_logs / $per_page ) );
		$current_page  = isset( $_GET['eventbridge_log_page'] ) && is_scalar( $_GET['eventbridge_log_page'] )
			? max( 1, absint( wp_unslash( (string) $_GET['eventbridge_log_page'] ) ) )
			: 1;
		$current_page  = min( $current_page, $total_pages );
		$logs          = $this->log->get_logs_page( $current_page, $per_page );
		?>
		<h2 id="eventbridge-activity-log"><?php echo esc_html__( 'Activiteitenlog', 'eventbridge' ); ?></h2>
		<?php if ( empty( $logs ) ) : ?>
			<p><?php echo esc_html__( 'Er zijn nog geen activiteiten gelogd.', 'eventbridge' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Tijd', 'eventbridge' ); ?></th>
						<th><?php echo esc_html__( 'Niveau', 'eventbridge' ); ?></th>
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
			<?php $this->render_activity_log_pagination( $current_page, $total_pages ); ?>
		<?php endif; ?>
		<?php
	}

	private function render_activity_log_pagination( $current_page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg(
					array(
						'page'                 => 'eventbridge',
						'eventbridge_log_page' => '%#%',
					),
					admin_url( 'admin.php' )
				) . '#eventbridge-activity-log',
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => __( 'Vorige', 'eventbridge' ),
				'next_text' => __( 'Volgende', 'eventbridge' ),
				'type'      => 'list',
			)
		);

		if ( ! is_string( $links ) || '' === $links ) {
			return;
		}

		?>
		<div class="tablenav">
			<div class="tablenav-pages"><?php echo wp_kses_post( $links ); ?></div>
		</div>
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
