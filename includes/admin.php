<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Admin {
	private $settings;

	public function __construct( EventBridge_Settings $settings ) {
		$this->settings = $settings;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
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
			<form action="options.php" method="post">
				<?php
				settings_fields( EventBridge_Settings::OPTION_GROUP );
				do_settings_sections( EventBridge_Settings::PAGE_SLUG );
				submit_button();
				?>
			</form>
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
}
