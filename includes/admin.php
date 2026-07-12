<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Admin {
	private $settings;
	private $events;
	private $event_form_values;
	private $editing_event_key = '';
	private $is_editing_event  = false;

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events ) {
		$this->settings          = $settings;
		$this->events            = $events;
		$this->event_form_values = $events->get_form_defaults();
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_event_form' ) );
		add_action( 'admin_init', array( $this, 'handle_update_event_form' ) );
		add_action( 'admin_init', array( $this, 'handle_delete_event_form' ) );
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
				'page'                    => 'eventbridge',
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
				'page'                      => 'eventbridge',
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
				'page'                      => 'eventbridge',
				'eventbridge_delete_status' => $status,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'EventBridge – Meta', 'eventbridge' ),
			__( 'EventBridge', 'eventbridge' ),
			'manage_options',
			'eventbridge',
			array( $this, 'render_page' ),
			'dashicons-share'
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'eventbridge' ) );
		}

		$this->load_editing_event();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'EventBridge – Meta', 'eventbridge' ); ?></h1>
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
		$this->event_form_values = wp_parse_args( $event, $this->events->get_form_defaults() );
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
						<th><?php echo esc_html__( 'Acties', 'eventbridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event_key => $event ) : ?>
						<?php if ( ! is_array( $event ) ) { continue; } ?>
						<?php
						$edit_url = add_query_arg(
							array(
								'page'       => 'eventbridge',
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
							<td>
								<a href="<?php echo esc_url( $edit_url ) . '#event-form'; ?>"><?php echo esc_html__( 'Bewerken', 'eventbridge' ); ?></a>
								<form action="<?php echo esc_url( admin_url( 'admin.php?page=eventbridge' ) ); ?>" method="post">
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
		<form id="event-form" action="<?php echo esc_url( admin_url( 'admin.php?page=eventbridge' ) ); ?>" method="post">
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=eventbridge' ) ); ?>"><?php echo esc_html__( 'Annuleren', 'eventbridge' ); ?></a>
			<?php else : ?>
				<?php submit_button( __( 'Event toevoegen', 'eventbridge' ) ); ?>
			<?php endif; ?>
		</form>
		<?php
	}
}
