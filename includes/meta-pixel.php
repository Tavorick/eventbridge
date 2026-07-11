<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Meta_Pixel {
	private $settings;
	private $pixel_id = '';
	private $script_rendered = false;
	private $noscript_rendered = false;

	public function __construct( EventBridge_Settings $settings ) {
		$this->settings = $settings;
	}

	public function init() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) ) {
			return;
		}

		$settings = $this->settings->get_settings();
		$pixel_id = isset( $settings['pixel_id'] ) && is_scalar( $settings['pixel_id'] ) ? trim( (string) $settings['pixel_id'] ) : '';

		if ( '' === $pixel_id || ! preg_match( '/^[0-9]+$/D', $pixel_id ) ) {
			return;
		}

		$this->pixel_id = $pixel_id;

		add_action( 'wp_head', array( $this, 'render_script' ) );
		add_action( 'wp_body_open', array( $this, 'render_noscript' ) );
	}

	public function render_script() {
		if ( $this->script_rendered ) {
			return;
		}

		$this->script_rendered = true;
		?>
		<!-- Meta Pixel Code -->
		<script>
		!function(f,b,e,v,n,t,s)
		{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
		n.callMethod.apply(n,arguments):n.queue.push(arguments)};
		if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
		n.queue=[];t=b.createElement(e);t.async=!0;
		t.src=v;s=b.getElementsByTagName(e)[0];
		s.parentNode.insertBefore(t,s)}(window, document,'script',
		'https://connect.facebook.net/en_US/fbevents.js');
		fbq( 'init', '<?php echo esc_js( $this->pixel_id ); ?>' );
		fbq( 'track', 'PageView' );
		</script>
		<!-- End Meta Pixel Code -->
		<?php
	}

	public function render_noscript() {
		if ( $this->noscript_rendered ) {
			return;
		}

		$this->noscript_rendered = true;
		$image_url = add_query_arg(
			array(
				'id'     => $this->pixel_id,
				'ev'     => 'PageView',
				'noscript' => '1',
			),
			'https://www.facebook.com/tr'
		);
		?>
		<noscript><img height="1" width="1" style="display:none" src="<?php echo esc_url( $image_url ); ?>" alt=""></noscript>
		<?php
	}
}
