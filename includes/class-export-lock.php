<?php
/**
 * Restrict public export of imported RunningCalendar data (REST, iCal, RSS).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the Restrict data export setting is on (default on).
 *
 * @return bool
 */
function runningsa_mec_restrict_export_enabled() {
	return (int) runningsa_mec_get_setting( 'restrict_export' ) === 1;
}

/**
 * Editors, administrators, and anyone else who can edit posts (including the MEC editor).
 *
 * @return bool
 */
function runningsa_mec_can_access_restricted_export() {
	return current_user_can( 'edit_posts' );
}

/**
 * Was this MEC event imported from RunningCalendar?
 *
 * @param int $post_id Post id.
 * @return bool
 */
function runningsa_mec_is_imported_event( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return false;
	}
	$id = get_post_meta( $post_id, RunningSA_MEC_Importer::META_ID, true );
	return '' !== (string) $id;
}

/**
 * Meta query that keeps posts that were not imported from RunningCalendar.
 *
 * @return array
 */
function runningsa_mec_not_imported_meta_query() {
	return array(
		'relation' => 'OR',
		array(
			'key'     => RunningSA_MEC_Importer::META_ID,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => RunningSA_MEC_Importer::META_ID,
			'value'   => '',
			'compare' => '=',
		),
	);
}

/**
 * 403 the current request.
 */
function runningsa_mec_forbid_export() {
	status_header( 403 );
	nocache_headers();
	wp_die(
		esc_html__( 'This calendar export is not available.', 'running-sa-mec' ),
		esc_html__( 'Export unavailable', 'running-sa-mec' ),
		array( 'response' => 403 )
	);
}

/**
 * Block the public MEC iCal feed and single-event iCal downloads of imported events.
 *
 * The site-wide feed (`/?mec-ical-feed=1`) has no per-event filter in MEC Lite,
 * so when this setting is on it is disabled for visitors entirely, including
 * any manually created MEC events in that feed. Logged-in editors can still use it.
 */
function runningsa_mec_block_ical_exports() {
	if ( ! runningsa_mec_restrict_export_enabled() || runningsa_mec_can_access_restricted_export() ) {
		return;
	}

	if ( isset( $_GET['mec-ical-feed'] ) && sanitize_text_field( wp_unslash( $_GET['mec-ical-feed'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		runningsa_mec_forbid_export();
	}

	if ( ! isset( $_GET['method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$method = sanitize_text_field( wp_unslash( $_GET['method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'ical' !== $method && 'ical-email' !== $method ) {
		return;
	}
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( runningsa_mec_is_imported_event( $id ) ) {
		runningsa_mec_forbid_export();
	}
}
add_action( 'init', 'runningsa_mec_block_ical_exports', 1 );

/**
 * Hide Google Calendar / iCal buttons on imported events, and turn off the
 * public site-wide iCal feed setting for visitors.
 *
 * @param mixed $options mec_options value.
 * @return mixed
 */
function runningsa_mec_filter_mec_options( $options ) {
	if ( ! is_array( $options ) || is_admin() || ! runningsa_mec_restrict_export_enabled() ) {
		return $options;
	}

	if ( ! isset( $options['settings'] ) || ! is_array( $options['settings'] ) ) {
		$options['settings'] = array();
	}

	if ( ! runningsa_mec_can_access_restricted_export() ) {
		$options['settings']['ical_feed'] = 0;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = absint( $_GET['id'] );
	}
	if ( ! runningsa_mec_is_imported_event( $post_id ) ) {
		return $options;
	}

	$options['settings']['export_module_status'] = 0;
	if ( ! isset( $options['settings']['sn'] ) || ! is_array( $options['settings']['sn'] ) ) {
		$options['settings']['sn'] = array();
	}
	$options['settings']['sn']['ical']      = 0;
	$options['settings']['sn']['googlecal'] = 0;

	return $options;
}
add_filter( 'option_mec_options', 'runningsa_mec_filter_mec_options' );

/**
 * Leave imported events out of unauthenticated REST collections.
 *
 * @param array           $args    Query args.
 * @param WP_REST_Request $request Request.
 * @return array
 */
function runningsa_mec_rest_events_query( $args, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! runningsa_mec_restrict_export_enabled() || runningsa_mec_can_access_restricted_export() ) {
		return $args;
	}
	$meta_query   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
	$meta_query[] = runningsa_mec_not_imported_meta_query();
	$args['meta_query'] = $meta_query;
	return $args;
}
add_filter( 'rest_mec-events_query', 'runningsa_mec_rest_events_query', 10, 2 );

/**
 * Block unauthenticated REST access to a single imported event (and its sub-routes).
 *
 * This runs before the posts controller, because returning a WP_Error from
 * rest_prepare_{type} fatals in get_item() (it calls link_header() on the result).
 *
 * @param WP_REST_Response|WP_Error|null $response Response so far.
 * @param array                          $handler  Route handler.
 * @param WP_REST_Request                $request  Request.
 * @return WP_REST_Response|WP_Error|null
 */
function runningsa_mec_rest_before_callbacks( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( ! runningsa_mec_restrict_export_enabled() || runningsa_mec_can_access_restricted_export() ) {
		return $response;
	}
	$route = $request->get_route();
	if ( ! preg_match( '#^/wp/v2/mec-events/(\d+)(?:/|$)#', $route, $matches ) ) {
		return $response;
	}
	if ( ! runningsa_mec_is_imported_event( (int) $matches[1] ) ) {
		return $response;
	}
	return new WP_Error(
		'rest_forbidden',
		__( 'Imported RunningCalendar events are not available through the public REST API.', 'running-sa-mec' ),
		array( 'status' => 401 )
	);
}
add_filter( 'rest_request_before_callbacks', 'runningsa_mec_rest_before_callbacks', 10, 3 );

/**
 * Leave imported events out of RSS and other WordPress feeds.
 *
 * @param WP_Query $query Query.
 */
function runningsa_mec_exclude_imported_from_feeds( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_feed() ) {
		return;
	}
	if ( ! runningsa_mec_restrict_export_enabled() || runningsa_mec_can_access_restricted_export() ) {
		return;
	}
	$meta_query = $query->get( 'meta_query' );
	$meta_query = is_array( $meta_query ) ? $meta_query : array();
	$meta_query[] = runningsa_mec_not_imported_meta_query();
	$query->set( 'meta_query', $meta_query );
}
add_action( 'pre_get_posts', 'runningsa_mec_exclude_imported_from_feeds' );

/**
 * MEC’s events RSS (/events/feed/) does not use the main WordPress query.
 * It loads events through the list skin, so filter that query only on feeds
 * (not on public calendar views).
 *
 * @param array $args Skin query args.
 * @param mixed $skin Skin object.
 * @return array
 */
function runningsa_mec_skin_query_args_for_feeds( $args, $skin = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! is_feed() || ! runningsa_mec_restrict_export_enabled() || runningsa_mec_can_access_restricted_export() ) {
		return $args;
	}
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
	$meta_query[] = runningsa_mec_not_imported_meta_query();
	$args['meta_query'] = $meta_query;
	return $args;
}
add_filter( 'mec_skin_query_args', 'runningsa_mec_skin_query_args_for_feeds', 10, 2 );

/**
 * Fallback: drop imported events from a MEC skin result set when building a feed.
 *
 * @param array $events Date-grouped events.
 * @param mixed $skin   Skin object.
 * @return array
 */
function runningsa_mec_skin_events_for_feeds( $events, $skin = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! is_feed() || ! runningsa_mec_restrict_export_enabled() || runningsa_mec_can_access_restricted_export() ) {
		return $events;
	}
	if ( ! is_array( $events ) ) {
		return $events;
	}
	foreach ( $events as $date => $list ) {
		if ( ! is_array( $list ) ) {
			continue;
		}
		$events[ $date ] = array_values(
			array_filter(
				$list,
				function ( $event ) {
					$id = 0;
					if ( is_object( $event ) && isset( $event->ID ) ) {
						$id = (int) $event->ID;
					} elseif ( is_object( $event ) && isset( $event->data ) && is_object( $event->data ) && isset( $event->data->ID ) ) {
						$id = (int) $event->data->ID;
					}
					return ! runningsa_mec_is_imported_event( $id );
				}
			)
		);
		if ( ! $events[ $date ] ) {
			unset( $events[ $date ] );
		}
	}
	return $events;
}
add_filter( 'mec_skin_events', 'runningsa_mec_skin_events_for_feeds', 10, 2 );
