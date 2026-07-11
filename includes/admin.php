<?php

defined( 'ABSPATH' ) || exit;

function eventbridge_add_admin_menu() {
	add_menu_page(
		__( 'EventBridge', 'eventbridge' ),
		__( 'EventBridge', 'eventbridge' ),
		'manage_options',
		'eventbridge',
		'eventbridge_render_admin_page',
		'dashicons-share'
	);
}
add_action( 'admin_menu', 'eventbridge_add_admin_menu' );

function eventbridge_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'EventBridge', 'eventbridge' ); ?></h1>
		<p><?php echo esc_html__( 'EventBridge is actief.', 'eventbridge' ); ?></p>
	</div>
	<?php
}
