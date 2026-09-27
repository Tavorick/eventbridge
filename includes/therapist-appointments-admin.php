<?php

defined( 'ABSPATH' ) || exit;

/** A deliberately small, owner-scoped interface for therapists. */
class EventBridge_Therapist_Appointments_Admin {
	const PAGE_SLUG  = 'eventbridge-telefoonafspraken';
	const CAPABILITY = 'publish_posts';
	const ACTION     = 'eventbridge_convert_therapist_appointment';
	const PER_PAGE   = 50;

	private $fluent_booking;
	private $conversions;
	private $conversion_service;

	public function __construct( EventBridge_Fluent_Booking $fluent_booking, EventBridge_Conversion_Repository $conversions, EventBridge_Conversion_Service $conversion_service ) {
		$this->fluent_booking    = $fluent_booking;
		$this->conversions       = $conversions;
		$this->conversion_service = $conversion_service;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 80 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_conversion_action' ) );
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) return;
		$style_path = dirname( __DIR__ ) . '/assets/css/eventbridge-therapist-appointments.css';
		wp_enqueue_style(
			'eventbridge-therapist-appointments',
			plugins_url( 'assets/css/eventbridge-therapist-appointments.css', dirname( __FILE__ ) ),
			array( 'dashicons' ),
			is_readable( $style_path ) ? (string) filemtime( $style_path ) : EVENTBRIDGE_VERSION
		);
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'Telefoonafspraken', 'eventbridge' ),
			__( 'Telefoonafspraken', 'eventbridge' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-phone',
			26
		);
	}

	public function add_admin_bar_link( $admin_bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAPABILITY ) || ! is_object( $admin_bar ) || ! method_exists( $admin_bar, 'add_node' ) ) return;
		$admin_bar->add_node( array(
			'id'    => 'eventbridge-telefoonafspraken',
			'title' => __( 'Telefoonafspraken', 'eventbridge' ),
			'href'  => add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ),
		) );
	}

	public function handle_conversion_action() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) wp_die( esc_html__( 'Ongeldige aanvraag.', 'eventbridge' ), '', array( 'response' => 405 ) );
		if ( ! current_user_can( self::CAPABILITY ) ) wp_die( esc_html__( 'Onvoldoende rechten.', 'eventbridge' ), '', array( 'response' => 403 ) );
		$conversion_id = isset( $_POST['conversion_id'] ) ? absint( $_POST['conversion_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $conversion_id );
		$result = $this->execute_owned_conversion( $conversion_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html__( 'Onvoldoende rechten.', 'eventbridge' ), '', array( 'response' => 403 ) );
		}
		$code = is_array( $result ) && isset( $result['code'] ) ? sanitize_key( $result['code'] ) : 'unknown_error';
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'eventbridge_appointment_status' => $code ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Executes only after the canonical Fluent booking proves ownership. */
	public function execute_owned_conversion( $conversion_id, $user_id ) {
		$conversion = $conversion_id ? $this->conversions->get_by_id( $conversion_id ) : false;
		$is_fluent = is_array( $conversion ) && isset( $conversion['provider'], $conversion['entity_type'], $conversion['external_id'] ) && 'fluent_booking' === $conversion['provider'] && 'booking' === $conversion['entity_type'];
		if ( ! $is_fluent || ! $this->fluent_booking->user_owns_conversion_booking( $conversion['external_id'], $user_id ) ) return new WP_Error( 'eventbridge_appointment_not_allowed' );
		return $this->conversion_service->execute( $conversion_id );
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'eventbridge' ) );
		$requested_page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) ) : '';
		if ( strlen( $search ) > 100 ) $search = substr( $search, 0, 100 );
		$booking_ids = $this->fluent_booking->find_owned_conversion_booking_ids( get_current_user_id(), $search );
		$page_data = $this->conversions->get_for_fluent_booking_ids( $booking_ids, $requested_page, self::PER_PAGE );
		$records = isset( $page_data['records'] ) && is_array( $page_data['records'] ) ? $page_data['records'] : array();
		$presentations = $this->fluent_booking->get_conversion_presentations( wp_list_pluck( $records, 'external_id' ) );
		$notice = isset( $_GET['eventbridge_appointment_status'] ) ? sanitize_key( wp_unslash( $_GET['eventbridge_appointment_status'] ) ) : '';
		$notices = array(
			'converted'         => array( 'success', __( 'De afspraak is als geconverteerd gemarkeerd.', 'eventbridge' ) ),
			'already_converted' => array( 'info', __( 'Deze afspraak was al geconverteerd.', 'eventbridge' ) ),
			'incomplete'        => array( 'warning', __( 'De verwerking kon niet volledig worden afgerond. Probeer het later opnieuw.', 'eventbridge' ) ),
			'mapping_missing'   => array( 'error', __( 'Deze afspraak kan momenteel niet worden verwerkt.', 'eventbridge' ) ),
			'storage_failed'    => array( 'error', __( 'De status kon niet veilig worden opgeslagen.', 'eventbridge' ) ),
			'runtime_unavailable' => array( 'error', __( 'Deze afspraak kan momenteel niet worden verwerkt.', 'eventbridge' ) ),
			'not_found'         => array( 'error', __( 'Deze afspraak is niet meer beschikbaar.', 'eventbridge' ) ),
			'unknown_error'     => array( 'error', __( 'Deze afspraak kan momenteel niet worden verwerkt.', 'eventbridge' ) ),
		);
		?>
		<div class="wrap eventbridge-therapist">
		<header class="eventbridge-therapist__hero">
			<span class="eventbridge-therapist__hero-icon dashicons dashicons-phone" aria-hidden="true"></span>
			<div><h1><?php echo esc_html__( 'Telefoonafspraken', 'eventbridge' ); ?></h1>
			<p><?php echo esc_html__( 'Heeft een telefonische afspraak geleid tot een geboekte sessie? Markeer die afspraak dan hieronder.', 'eventbridge' ); ?></p></div>
		</header>
		<?php if ( isset( $notices[ $notice ] ) ) : ?><div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div><?php endif; ?>
		<section class="eventbridge-therapist__search" aria-label="<?php echo esc_attr__( 'Afspraken zoeken', 'eventbridge' ); ?>">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><label for="eventbridge-appointment-search"><?php echo esc_html__( 'Zoek een cliënt', 'eventbridge' ); ?></label><div class="eventbridge-therapist__search-controls"><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" id="eventbridge-appointment-search" name="s" value="<?php echo esc_attr( $search ); ?>" maxlength="100" placeholder="<?php echo esc_attr__( 'Naam, e-mail of telefoonnummer', 'eventbridge' ); ?>"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Zoeken', 'eventbridge' ); ?></button><?php if ( '' !== $search ) : ?><a class="button eventbridge-therapist__clear" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Filter wissen', 'eventbridge' ); ?></a><?php endif; ?></div></form>
		</section>
		<section class="eventbridge-therapist__appointments" aria-labelledby="eventbridge-appointments-heading">
		<div class="eventbridge-therapist__section-heading"><div><h2 id="eventbridge-appointments-heading"><?php echo esc_html__( 'Jouw afspraken', 'eventbridge' ); ?></h2><p><?php echo esc_html__( 'jouw belafspraken', 'eventbridge' ); ?></p></div><span class="eventbridge-therapist__count"><?php echo esc_html( sprintf( _n( '%s afspraak', '%s afspraken', isset( $page_data['total'] ) ? absint( $page_data['total'] ) : 0, 'eventbridge' ), number_format_i18n( isset( $page_data['total'] ) ? absint( $page_data['total'] ) : 0 ) ) ); ?></span></div>
		<?php if ( empty( $records ) ) : ?><div class="eventbridge-therapist__empty"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><h3><?php echo esc_html( '' !== $search ? __( 'Geen afspraken gevonden', 'eventbridge' ) : __( 'Nog geen telefoonafspraken', 'eventbridge' ) ); ?></h3><p><?php echo esc_html( '' !== $search ? __( 'Probeer een andere naam, e-mail of telefoonnummer.', 'eventbridge' ) : __( 'Nieuwe opvolgbare afspraken verschijnen automatisch in deze lijst.', 'eventbridge' ) ); ?></p></div>
		<?php else : ?><div class="eventbridge-therapist__table-scroll"><table class="widefat eventbridge-therapist__table"><caption class="screen-reader-text"><?php echo esc_html__( 'Overzicht van jouw telefoonafspraken', 'eventbridge' ); ?></caption><thead><tr><th><?php echo esc_html__( 'Datum en tijd', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Cliënt', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Contact', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Afspraak', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Status', 'eventbridge' ); ?></th><th><?php echo esc_html__( 'Actie', 'eventbridge' ); ?></th></tr></thead><tbody>
		<?php foreach ( $records as $record ) :
			$external_id = isset( $record['external_id'] ) ? (string) $record['external_id'] : '';
			$presentation = isset( $presentations[ $external_id ] ) && is_array( $presentations[ $external_id ] ) ? $presentations[ $external_id ] : array();
			$snapshot = $this->conversions->decode_snapshot( isset( $record['conversion_event_ids'] ) ? $record['conversion_event_ids'] : '' );
			$processing = false;
			foreach ( isset( $record['deliveries'] ) && is_array( $record['deliveries'] ) ? $record['deliveries'] : array() as $delivery ) {
				if ( EventBridge_Conversion_Repository::DELIVERY_PROCESSING === $delivery['status'] && ! empty( $delivery['lease_expires_at'] ) && $delivery['lease_expires_at'] >= current_time( 'mysql', true ) ) $processing = true;
			}
			$name = trim( ( isset( $presentation['first_name'] ) ? $presentation['first_name'] : '' ) . ' ' . ( isset( $presentation['last_name'] ) ? $presentation['last_name'] : '' ) );
			if ( '' === $name && ! empty( $presentation['name'] ) ) $name = $presentation['name'];
			$can_convert = EventBridge_Conversion_Repository::STATUS_OPEN === $record['status'] && ! $processing && is_array( $snapshot ) && ! empty( $snapshot );
			if ( EventBridge_Conversion_Repository::STATUS_CONVERTED === $record['status'] ) { $status_label = __( 'Geconverteerd', 'eventbridge' ); $status_class = 'is-converted'; }
			elseif ( $processing ) { $status_label = __( 'Wordt verwerkt', 'eventbridge' ); $status_class = 'is-processing'; }
			elseif ( false === $snapshot || empty( $snapshot ) ) { $status_label = __( 'Actie niet beschikbaar', 'eventbridge' ); $status_class = 'is-unavailable'; }
			else { $status_label = __( 'Nog niet geconverteerd', 'eventbridge' ); $status_class = 'is-open'; }
		?>
		<tr><td data-label="<?php echo esc_attr__( 'Datum en tijd', 'eventbridge' ); ?>"><span class="eventbridge-therapist__date"><span class="dashicons dashicons-clock" aria-hidden="true"></span><span><?php $this->render_utc_time( isset( $presentation['start_time'] ) ? $presentation['start_time'] : '' ); ?></span></span></td><td data-label="<?php echo esc_attr__( 'Cliënt', 'eventbridge' ); ?>"><strong class="eventbridge-therapist__client"><?php echo esc_html( '' !== $name ? $name : __( 'Niet beschikbaar', 'eventbridge' ) ); ?></strong></td><td data-label="<?php echo esc_attr__( 'Contact', 'eventbridge' ); ?>"><span class="eventbridge-therapist__contact"><?php echo esc_html( ! empty( $presentation['email'] ) ? $presentation['email'] : __( 'Niet beschikbaar', 'eventbridge' ) ); ?><small><?php echo esc_html( ! empty( $presentation['phone'] ) ? $presentation['phone'] : __( 'Niet beschikbaar', 'eventbridge' ) ); ?></small></span></td><td data-label="<?php echo esc_attr__( 'Afspraak', 'eventbridge' ); ?>"><?php echo esc_html( ! empty( $presentation['event_title'] ) ? $presentation['event_title'] : __( 'Niet beschikbaar', 'eventbridge' ) ); ?><?php if ( ! empty( $presentation['calendar_name'] ) ) : ?><small class="eventbridge-therapist__calendar"><?php echo esc_html( $presentation['calendar_name'] ); ?></small><?php endif; ?></td><td data-label="<?php echo esc_attr__( 'Status', 'eventbridge' ); ?>"><span class="eventbridge-therapist__status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td><td data-label="<?php echo esc_attr__( 'Actie', 'eventbridge' ); ?>" class="eventbridge-therapist__action"><?php if ( $can_convert ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>"><input type="hidden" name="conversion_id" value="<?php echo esc_attr( $record['id'] ); ?>"><?php wp_nonce_field( self::ACTION . '_' . $record['id'] ); ?><button type="submit" class="button button-primary"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php echo esc_html__( 'Sessie geboekt', 'eventbridge' ); ?></button></form><?php else : ?><span class="eventbridge-therapist__no-action">&mdash;</span><?php endif; ?></td></tr>
		<?php endforeach; ?></tbody></table></div><?php endif; ?>
		<?php $this->render_pagination( $page_data, $search ); ?></section></div>
		<?php
	}

	private function render_pagination( array $page_data, $search ) {
		$total = isset( $page_data['total'] ) ? absint( $page_data['total'] ) : 0;
		$total_pages = isset( $page_data['total_pages'] ) ? max( 1, absint( $page_data['total_pages'] ) ) : 1;
		$current = isset( $page_data['page'] ) ? max( 1, absint( $page_data['page'] ) ) : 1;
		if ( $total_pages < 2 ) return;
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $search ) $args['s'] = $search;
		$links = paginate_links( array( 'base' => add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ), 'current' => $current, 'total' => $total_pages, 'type' => 'list' ) );
		if ( is_string( $links ) && '' !== $links ) echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( _n( '%s afspraak', '%s afspraken', $total, 'eventbridge' ), number_format_i18n( $total ) ) ) . '</span>' . wp_kses_post( $links ) . '</div></div>';
	}

	private function render_utc_time( $value ) {
		if ( ! is_scalar( $value ) || '' === (string) $value ) { echo '&mdash;'; return; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== (string) $value ) { echo '&mdash;'; return; }
		echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp(), wp_timezone() ) );
	}
}
