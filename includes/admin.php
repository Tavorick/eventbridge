<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Admin {
	private $settings;
	private $events;
	private $event_form_values;

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events ) {
		$this->settings          = $settings;
		$this->events            = $events;
		$this->event_form_values = $events->get_form_defaults();
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_event_form' ) );
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

	private function render_event_notices() {
		$event_added = isset( $_GET['eventbridge_event_added'] ) && is_scalar( $_GET['eventbridge_event_added'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eventbridge_event_added'] ) ) : '';

		if ( '1' === $event_added ) {
			add_settings_error( EventBridge_Events::OPTION_NAME, 'eventbridge_event_added', __( 'Het event is toegevoegd.', 'eventbridge' ), 'success' );
		}

		settings_errors( EventBridge_Events::OPTION_NAME );
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
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event ) : ?>
						<?php if ( ! is_array( $event ) ) { continue; } ?>
						<tr>
							<td><?php echo esc_html( isset( $event['label'] ) && is_scalar( $event['label'] ) ? (string) $event['label'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $event['description'] ) && is_scalar( $event['description'] ) ? (string) $event['description'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $event['event_name'] ) && is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '' ); ?></td>
							<td><?php echo ! empty( $event['browser'] ) ? esc_html__( 'Ja', 'eventbridge' ) : esc_html__( 'Nee', 'eventbridge' ); ?></td>
							<td><?php echo ! empty( $event['capi'] ) ? esc_html__( 'Ja', 'eventbridge' ) : esc_html__( 'Nee', 'eventbridge' ); ?></td>
							<td><?php echo ! empty( $event['enabled'] ) ? esc_html__( 'Ja', 'eventbridge' ) : esc_html__( 'Nee', 'eventbridge' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_event_form() {
		$values = $this->event_form_values;
		?>
		<h2><?php echo esc_html__( 'Nieuw event toevoegen', 'eventbridge' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin.php?page=eventbridge' ) ); ?>" method="post">
			<input type="hidden" name="eventbridge_form" value="add_event">
			<?php wp_nonce_field( 'eventbridge_add_event', 'eventbridge_event_nonce' ); ?>
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
			<?php submit_button( __( 'Event toevoegen', 'eventbridge' ) ); ?>
		</form>
		<?php
	}
}
