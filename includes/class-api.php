<?php
/**
 * Small client for the RunningCalendar API (read-only, GET v1/events).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunningSA_MEC_API {

	const BASE_URL  = 'https://api.runningcalendar.co.za/v1/events';
	const CACHE_TTL = 600; // 10 minutes, same as the API's own cache.
	const PER_PAGE  = 100; // API maximum.
	const MAX_PAGES = 20;  // Safety stop.

	/**
	 * Fetch all upcoming events for one province (all pages).
	 *
	 * @param string $province Province slug, e.g. free-state.
	 * @return array|WP_Error {events: array, complete: bool} or an error.
	 *                        complete is false when pagination stopped early, so the
	 *                        caller must not treat missing ids as removed races.
	 */
	public static function get_events_for_province( $province ) {
		$api_key = runningsa_mec_get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'runningsa_no_key', __( 'No API key set.', 'running-sa-mec' ) );
		}

		$events   = array();
		$page     = 1;
		$complete = true;
		do {
			$body = self::request( $api_key, $province, $page );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				$events = array_merge( $events, $body['data'] );
			}
			$has_next = ! empty( $body['links']['next'] );
			$page++;
			if ( $has_next && $page > self::MAX_PAGES ) {
				$complete = false;
				break;
			}
			if ( $has_next ) {
				sleep( 1 ); // Be gentle: limit is 60 requests/minute.
			}
		} while ( $has_next );

		return array(
			'events'   => $events,
			'complete' => $complete,
		);
	}

	/**
	 * Changes since a checkpoint. Includes races that are no longer public.
	 *
	 * A removed item has sync_status "removed" and only id, slug, sync_status
	 * and updated_at. Browse filters are ignored in this mode.
	 *
	 * @param string $updated_since ISO-8601 timestamp.
	 * @return array|WP_Error {events: array, complete: bool, max_updated_at: string}
	 */
	public static function get_updated_since( $updated_since ) {
		$api_key = runningsa_mec_get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'runningsa_no_key', __( 'No API key set.', 'running-sa-mec' ) );
		}

		$events   = array();
		$page     = 1;
		$complete = true;
		$max      = '';
		do {
			$url  = add_query_arg(
				array(
					'updated_since' => $updated_since,
					'per_page'      => self::PER_PAGE,
					'page'          => (int) $page,
				),
				self::BASE_URL
			);
			$body = self::get_json( $api_key, $url );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				foreach ( $body['data'] as $event ) {
					$events[] = $event;
					if ( ! empty( $event['updated_at'] ) && (string) $event['updated_at'] > $max ) {
						$max = (string) $event['updated_at'];
					}
				}
			}
			$has_next = ! empty( $body['links']['next'] );
			$page++;
			if ( $has_next && $page > self::MAX_PAGES ) {
				$complete = false;
				break;
			}
			if ( $has_next ) {
				sleep( 1 );
			}
		} while ( $has_next );

		return array(
			'events'         => $events,
			'complete'       => $complete,
			'max_updated_at' => $max,
		);
	}

	/**
	 * Fetch one event edition by slug or numeric id.
	 *
	 * @param string $id_or_slug Slug or id, already validated.
	 * @return array|WP_Error Event array.
	 */
	public static function get_event( $id_or_slug ) {
		$api_key = runningsa_mec_get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'runningsa_no_key', __( 'No API key set.', 'running-sa-mec' ) );
		}

		$body = self::get_json( $api_key, self::BASE_URL . '/' . rawurlencode( $id_or_slug ) );
		if ( is_wp_error( $body ) ) {
			if ( 'runningsa_http_404' === $body->get_error_code() ) {
				return new WP_Error( 'runningsa_not_found', __( 'No upcoming race was found for that slug.', 'running-sa-mec' ) );
			}
			return $body;
		}
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'runningsa_not_found', __( 'No upcoming race was found for that slug.', 'running-sa-mec' ) );
		}
		return $body['data'];
	}

	/**
	 * Turn a pasted slug or Running Calendar event URL into an API slug.
	 *
	 * @param string $raw User input.
	 * @return string Empty when it is not a slug.
	 */
	public static function normalize_slug( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '#runningcalendar\.co\.za/events/([A-Za-z0-9-]+)#i', $raw, $matches ) ) {
			$raw = $matches[1];
		}
		$raw = strtolower( $raw );
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $raw ) || strlen( $raw ) > 120 ) {
			return '';
		}
		return $raw;
	}

	/**
	 * One GET request, cached in a transient for 10 minutes.
	 *
	 * @param string $api_key  Key (sent as Bearer token, never logged).
	 * @param string $province Province slug.
	 * @param int    $page     Page number.
	 * @return array|WP_Error Decoded JSON body.
	 */
	private static function request( $api_key, $province, $page ) {
		$url = add_query_arg(
			array(
				'country'  => 'ZA',
				'province' => rawurlencode( $province ),
				'per_page' => self::PER_PAGE,
				'page'     => (int) $page,
			),
			self::BASE_URL
		);

		return self::get_json( $api_key, $url );
	}

	/**
	 * GET a URL and decode the JSON body. Successful responses are cached.
	 *
	 * @param string $api_key Key (sent as Bearer token, never logged).
	 * @param string $url     Full request URL.
	 * @return array|WP_Error
	 */
	private static function get_json( $api_key, $url ) {
		$cache_key = 'runningsa_mec_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			RunningSA_MEC_Logger::log( sprintf( 'Cache hit: %s', $url ) );
			return $cached;
		}

		RunningSA_MEC_Logger::log( sprintf( 'GET %s', $url ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Unexpected response', 'running-sa-mec' );
			/* translators: 1: HTTP status code, 2: error message */
			return new WP_Error( 'runningsa_http_' . $code, sprintf( __( 'API returned HTTP %1$d: %2$s', 'running-sa-mec' ), $code, $msg ) );
		}

		set_transient( $cache_key, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Remove our cached API responses (used by Force fetch).
	 */
	public static function clear_cache() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_runningsa\_mec\_%' OR option_name LIKE '\_transient\_timeout\_runningsa\_mec\_%'" ); // phpcs:ignore
	}
}
