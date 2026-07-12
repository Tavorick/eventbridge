<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Settings {
	const OPTION_NAME  = 'eventbridge_meta_settings';
	const OPTION_GROUP = 'eventbridge_meta_settings_group';
	const PAGE_SLUG    = 'eventbridge-settings';

	private $admin;

	public function set_admin( EventBridge_Admin $admin ) {
		$this->admin = $admin;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_defaults(),
			)
		);

		add_settings_section(
			'eventbridge_meta_section',
			__( 'Meta-instellingen', 'eventbridge' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field( 'eventbridge_pixel_id', __( 'Meta Pixel ID', 'eventbridge' ), array( $this->admin, 'render_pixel_id_field' ), self::PAGE_SLUG, 'eventbridge_meta_section' );
		add_settings_field( 'eventbridge_capi_token', __( 'Conversion API access token', 'eventbridge' ), array( $this->admin, 'render_capi_token_field' ), self::PAGE_SLUG, 'eventbridge_meta_section' );
		add_settings_field( 'eventbridge_debug', __( 'Debugmodus inschakelen', 'eventbridge' ), array( $this->admin, 'render_debug_field' ), self::PAGE_SLUG, 'eventbridge_meta_section' );
	}

	public function get_defaults() {
		return array(
			'pixel_id'   => '',
			'capi_token' => '',
			'debug'      => false,
		);
	}

	public function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, $this->get_defaults() );
	}

	public function sanitize_settings( $input ) {
		$current = $this->get_settings();
		$input   = is_array( $input ) ? $input : array();

		$pixel_id = isset( $input['pixel_id'] ) && is_scalar( $input['pixel_id'] ) ? trim( (string) $input['pixel_id'] ) : '';
		$capi_token = isset( $input['capi_token'] ) && is_scalar( $input['capi_token'] ) ? trim( (string) $input['capi_token'] ) : '';
		$debug = isset( $input['debug'] ) && is_scalar( $input['debug'] ) && '1' === (string) $input['debug'];

		if ( '' !== $pixel_id && ! preg_match( '/^[0-9]+$/D', $pixel_id ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'eventbridge_invalid_pixel_id',
				__( 'De Meta Pixel ID mag alleen uit cijfers bestaan.', 'eventbridge' )
			);
			$pixel_id = $current['pixel_id'];
		}

		return array(
			'pixel_id'   => $pixel_id,
			'capi_token' => $capi_token,
			'debug'      => $debug,
		);
	}
}
