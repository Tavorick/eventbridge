<?php

defined( 'ABSPATH' ) || exit;

interface EventBridge_Condition_Provider_Interface {
	public function get_key();

	public function supports_event( $event );

	public function get_catalog();

	public function validate_condition( $condition, $existing_conditions = array() );

	public function build_context( $trigger, $subject, $required_conditions );

	public function evaluate( $condition, $context );

	public function search_values( $field, $search, $page, $limit );

	public function resolve_value_labels( $field, $values );
}
