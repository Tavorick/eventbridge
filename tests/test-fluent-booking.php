<?php

class EventBridge_Fluent_Booking_Test_Query {
	private $records;
	private $filters = array();

	public function __construct( $records ) {
		$this->records = $records;
	}

	public function get() {
		$records = $this->records;
		foreach ( $this->filters as $filter ) {
			$records = array_values( array_filter( $records, function ( $record ) use ( $filter ) { return is_object( $record ) && isset( $record->{$filter[0]} ) && in_array( (string) $record->{$filter[0]}, $filter[1], true ); } ) );
		}
		return $records;
	}

	public function orderBy( $column, $direction ) {
		return $this;
	}

	public function whereIn( $column, $values ) {
		$this->filters[] = array( $column, array_map( 'strval', (array) $values ) );
		return $this;
	}
}

class EventBridge_Fluent_Booking_Test_Search_Group {
	private $predicates = array();

	public function where( $column, $operator, $value ) {
		$this->predicates[] = function ( $record ) use ( $column, $operator, $value ) {
			$actual = is_object( $record ) && isset( $record->{$column} ) ? (string) $record->{$column} : '';
			return 'LIKE' === strtoupper( $operator ) ? $this->matches_like( $actual, (string) $value ) : (string) $value === $actual;
		};
		return $this;
	}

	public function orWhere( $column, $operator, $value ) {
		return $this->where( $column, $operator, $value );
	}

	public function orWhereHas( $relation, $callback ) {
		$this->predicates[] = function ( $record ) use ( $relation, $callback ) {
			if ( ! is_object( $record ) || ! isset( $record->{$relation} ) || ! is_object( $record->{$relation} ) ) return false;
			$group = new self();
			$callback( $group );
			return $group->matches( $record->{$relation} );
		};
		return $this;
	}

	public function matches( $record ) {
		foreach ( $this->predicates as $predicate ) if ( $predicate( $record ) ) return true;
		return false;
	}

	private function matches_like( $actual, $pattern ) {
		$regex = '';
		$length = strlen( $pattern );
		for ( $offset = 0; $offset < $length; $offset++ ) {
			$character = $pattern[ $offset ];
			if ( '\\' === $character && $offset + 1 < $length ) {
				$regex .= preg_quote( $pattern[ ++$offset ], '/' );
			} elseif ( '%' === $character ) {
				$regex .= '.*';
			} elseif ( '_' === $character ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $character, '/' );
			}
		}
		return 1 === preg_match( '/^' . $regex . '$/iu', $actual );
	}
}

class EventBridge_Fluent_Booking_Test_Search_Query {
	private $records;
	private $group;

	public function __construct( $records ) {
		$this->records = $records;
	}

	public function where( $callback ) {
		$this->group = new EventBridge_Fluent_Booking_Test_Search_Group();
		$callback( $this->group );
		return $this;
	}

	public function pluck( $column ) {
		$values = array();
		foreach ( $this->records as $record ) {
			if ( $this->group && $this->group->matches( $record ) && isset( $record->{$column} ) ) $values[] = $record->{$column};
		}
		return $values;
	}
}

if ( ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
	eval( 'namespace FluentBooking\\App\\Models; class Booking { public static $records = array(); public static $query_count = 0; public $id; public $first_name; public $last_name; public $full_name; public $phone; public $email; public $calendar_event; public $calendar; public static function with( $relations ) { self::$query_count++; return new \\EventBridge_Fluent_Booking_Test_Query( self::$records ); } public static function query() { self::$query_count++; return new \\EventBridge_Fluent_Booking_Test_Search_Query( self::$records ); } } class CalendarSlot { public static $records = array(); public static function with( $relations ) { return new \\EventBridge_Fluent_Booking_Test_Query( self::$records ); } }' );
}

class EventBridge_Fluent_Booking_Test_Provider extends EventBridge_Fluent_Booking {
	public function is_available() {
		return true;
	}
}

class EventBridge_Fluent_Booking_Test extends WP_UnitTestCase {
	private $settings;

	public function set_up() {
		parent::set_up();
		$this->settings = new EventBridge_Fluent_Booking_Settings();
		$this->settings->register_settings();
		delete_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME );
	}

	public function tear_down() {
		delete_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME );
		delete_option( EventBridge_Settings::OPTION_NAME );
		parent::tear_down();
	}

	public function test_appointment_types_use_calendar_slot_id_title_and_calendar_relation() {
		if ( ! property_exists( '\\FluentBooking\\App\\Models\\CalendarSlot', 'records' ) ) {
			$this->markTestSkipped( 'The installed Fluent Booking model is active.' );
		}

		\FluentBooking\App\Models\CalendarSlot::$records = array(
			(object) array( 'id' => 42, 'title' => 'Intakegesprek', 'calendar' => (object) array( 'title' => 'Praktijk Lars' ) ),
			(object) array( 'id' => 84, 'title' => 'Vervolgafspraak', 'calendar' => (object) array( 'title' => 'Online consulten' ) ),
		);
		$provider = new EventBridge_Fluent_Booking_Test_Provider( $this->settings );

		$this->assertSame(
			array(
				array( 'id' => '42', 'title' => 'Intakegesprek', 'calendar_name' => 'Praktijk Lars' ),
				array( 'id' => '84', 'title' => 'Vervolgafspraak', 'calendar_name' => 'Online consulten' ),
			),
			$provider->get_appointment_types()
		);
	}

	public function test_appointment_type_does_not_infer_a_name_when_calendar_relation_is_missing() {
		if ( ! property_exists( '\\FluentBooking\\App\\Models\\CalendarSlot', 'records' ) ) {
			$this->markTestSkipped( 'The installed Fluent Booking model is active.' );
		}

		\FluentBooking\App\Models\CalendarSlot::$records = array(
			(object) array( 'id' => 42, 'title' => 'Agenda Lars - Intakegesprek' ),
		);
		$provider = new EventBridge_Fluent_Booking_Test_Provider( $this->settings );

		$this->assertSame(
			array( array( 'id' => '42', 'title' => 'Agenda Lars - Intakegesprek', 'calendar_name' => '' ) ),
			$provider->get_appointment_types()
		);
	}

	public function test_conversion_presentations_are_batched_with_relations_and_request_cached() {
		$booking_class = '\\FluentBooking\\App\\Models\\Booking';
		if ( ! property_exists( $booking_class, 'records' ) ) {
			$this->markTestSkipped( 'The installed Fluent Booking model is active.' );
		}
		$first = new $booking_class();
		$first->id = 4821; $first->first_name = 'Lars'; $first->last_name = 'Test'; $first->full_name = 'Lars Test';
		$first->phone = '+32470123456'; $first->email = 'lead@example.test';
		$first->calendar_event = (object) array( 'title' => 'Intake' ); $first->calendar = (object) array( 'title' => 'Praktijk Lars' );
		$second = new $booking_class();
		$second->id = 4822; $second->first_name = 'Anna'; $second->last_name = 'Voorbeeld'; $second->phone = ''; $second->email = 'anna@example.test';
		$second->calendar_event = (object) array( 'title' => 'Vervolg' ); $second->calendar = (object) array( 'title' => 'Online' );
		$booking_class::$records = array( $first, $second ); $booking_class::$query_count = 0;
		$provider = new EventBridge_Fluent_Booking_Test_Provider( $this->settings );

		$result = $provider->get_conversion_presentations( array( '4821', '4821', '4822', '9999', 'invalid' ) );
		$this->assertSame( 'Lars', $result['4821']['first_name'] );
		$this->assertSame( 'Intake', $result['4821']['event_title'] );
		$this->assertSame( 'Praktijk Lars', $result['4821']['calendar_name'] );
		$this->assertSame( 'Anna Voorbeeld', $result['4822']['name'] );
		$this->assertSame( array(), $result['9999'] );
		$this->assertSame( 1, $booking_class::$query_count );

		$provider->get_conversion_presentations( array( '4821', '9999' ) );
		$this->assertSame( 1, $booking_class::$query_count );
	}

	public function test_conversion_booking_search_covers_contact_id_and_relations_with_request_cache() {
		$booking_class = '\\FluentBooking\\App\\Models\\Booking';
		if ( ! property_exists( $booking_class, 'records' ) || ! method_exists( $booking_class, 'query' ) ) {
			$this->markTestSkipped( 'The installed Fluent Booking model is active.' );
		}
		$booking = new $booking_class();
		$booking->id = 4821;
		$booking->first_name = 'Lars';
		$booking->last_name = 'Voorbeeld';
		$booking->email = 'lead@example.test';
		$booking->phone = '+32470123456';
		$booking->calendar_event = (object) array( 'title' => 'Intakegesprek' );
		$booking->calendar = (object) array( 'title' => 'Praktijk Lars' );
		$booking_class::$records = array( $booking );
		$booking_class::$query_count = 0;
		$provider = new EventBridge_Fluent_Booking_Test_Provider( $this->settings );

		foreach ( array( 'Lars', 'Voorbeeld', 'example.test', '+32470', '4821', 'Intake', 'Praktijk' ) as $term ) {
			$this->assertSame( array( '4821' ), $provider->find_conversion_booking_ids( $term ), $term );
		}
		$queries = $booking_class::$query_count;
		$this->assertSame( array( '4821' ), $provider->find_conversion_booking_ids( 'Lars' ) );
		$this->assertSame( $queries, $booking_class::$query_count );
		$this->assertSame( array(), $provider->find_conversion_booking_ids( '%' ) );
		$this->assertSame( array(), $provider->find_conversion_booking_ids( '_' ) );
	}

	public function test_selected_ids_are_normalized_without_touching_meta_settings() {
		$meta = array( 'pixel_id' => '123456789', 'capi_token' => 'secret', 'debug' => true );
		update_option( EventBridge_Settings::OPTION_NAME, $meta, false );
		$sanitized = sanitize_option(
			EventBridge_Fluent_Booking_Settings::OPTION_NAME,
			array( 'followup_event_ids_present' => '1', 'followup_event_ids' => array( ' 42 ', '42', '', array( '84' ), 84 ) )
		);
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, $sanitized, false );

		$this->assertSame( array( '42', '84' ), $this->settings->get_followup_event_ids() );
		$this->assertSame( $meta, get_option( EventBridge_Settings::OPTION_NAME ) );
	}

	public function test_settings_api_save_flow_persists_selected_ids_and_allows_explicit_emptying() {
		$meta = array( 'pixel_id' => '123456789', 'capi_token' => 'secret', 'debug' => true );
		update_option( EventBridge_Settings::OPTION_NAME, $meta, false );

		$submitted = array( 'followup_event_ids_present' => '1', 'followup_event_ids' => array( '42', 84 ) );
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, sanitize_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, $submitted ), false );
		$this->assertSame( array( '42', '84' ), $this->settings->get_followup_event_ids() );
		$this->assertSame( $meta, get_option( EventBridge_Settings::OPTION_NAME ) );

		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, sanitize_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids_present' => '1' ) ), false );
		$this->assertSame( array(), $this->settings->get_followup_event_ids() );
		$this->assertSame( $meta, get_option( EventBridge_Settings::OPTION_NAME ) );
	}

	public function test_connections_group_allows_the_fluent_option_without_reusing_meta_storage() {
		global $new_allowed_options;
		$this->assertContains(
			EventBridge_Fluent_Booking_Settings::OPTION_NAME,
			$new_allowed_options[ EventBridge_Settings::CONNECTIONS_OPTION_GROUP ]
		);
		$this->assertNotSame( EventBridge_Fluent_Booking_Settings::OPTION_NAME, EventBridge_Settings::OPTION_NAME );
	}

	public function test_absent_form_marker_preserves_selection_and_explicit_empty_clears_it() {
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids' => array( '42' ) ), false );

		$this->assertSame( array( '42' ), $this->settings->sanitize_settings( array() )['followup_event_ids'] );
		$this->assertSame( array(), $this->settings->sanitize_settings( array( 'followup_event_ids_present' => '1' ) )['followup_event_ids'] );
	}

	public function test_followup_relevance_is_a_pure_event_id_membership_check() {
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids' => array( '42' ) ), false );
		$provider = new EventBridge_Fluent_Booking( $this->settings );

		$this->assertTrue( $provider->is_followup_relevant( (object) array( 'event_id' => 42 ) ) );
		$this->assertFalse( $provider->is_followup_relevant( (object) array( 'event_id' => 84 ) ) );
		$this->assertFalse( $provider->is_followup_relevant( (object) array() ) );
		$this->assertSame( array( '42' ), $this->settings->get_followup_event_ids() );
	}

	public function test_followups_store_deduplicated_eventbridge_keys_per_appointment_type() {
		$first = 'evt_11111111-1111-4111-8111-111111111111';
		$second = 'evt_22222222-2222-4222-8222-222222222222';
		$sanitized = $this->settings->sanitize_settings( array(
			'followups_present' => '1',
			'followups' => array( '42' => array( 'enabled' => '1', 'calendar_name' => 'Niet opslaan', 'conversion_event_ids' => array( $first, $first, 'Lead', $second ) ) ),
		) );
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, $sanitized, false );

		$this->assertSame( array( 'followups' => array( '42' => array( 'conversion_event_ids' => array( $first, $second ) ) ) ), $sanitized );
		$this->assertSame( array( '42' ), $this->settings->get_followup_event_ids() );
		$this->assertSame( array( $first, $second ), $this->settings->get_conversion_event_ids( '42' ) );
	}

	public function test_legacy_followup_ids_remain_relevant_without_conversion_mapping() {
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids' => array( '42' ) ), false );
		$provider = new EventBridge_Fluent_Booking( $this->settings );
		$this->assertTrue( $provider->is_followup_relevant( (object) array( 'event_id' => 42 ) ) );
		$this->assertSame( array(), $provider->get_conversion_event_ids( (object) array( 'event_id' => 42 ) ) );
	}
}
