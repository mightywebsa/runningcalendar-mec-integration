<?php
/**
 * Turns RunningCalendar events into MEC events.
 *
 * Events are saved with MEC's own $main->save_event() (the same function MEC's
 * importer uses). That writes the post, all the mec_* meta, the wp_mec_events
 * row and the wp_mec_dates schedule, so the events show in every MEC view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunningSA_MEC_Importer {

	const META_ID       = '_runningsa_id';       // RunningCalendar event id (dedupe key).
	const META_URL      = '_runningsa_url';      // Their canonical url, unchanged.
	const META_HASH     = '_runningsa_hash';     // Hash of the API data, to skip unchanged events.
	const META_PROVINCE = '_runningsa_province'; // Province slug this event was imported from.
	const META_SLUG     = '_runningsa_slug';     // RunningCalendar slug.
	const META_CLOSED   = '_runningsa_closed';   // We marked this MEC event cancelled.

	/**
	 * Run one import of all selected provinces.
	 *
	 * @param string $trigger cron|manual.
	 * @return array Status summary.
	 */
	public static function run( $trigger = 'cron' ) {
		$status = array(
			'time'     => current_time( 'mysql' ),
			'trigger'  => $trigger,
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'fetched'  => 0,
			'closed'   => 0,
		);

		if ( ! runningsa_mec_is_mec_active() ) {
			$status['errors'][] = __( 'Modern Events Calendar is not active.', 'running-sa-mec' );
			return self::finish( $status );
		}

		// Simple lock so cron and Force fetch can't run at the same time.
		if ( get_transient( 'runningsa_mec_lock' ) ) {
			$status['errors'][] = __( 'An import is already running.', 'running-sa-mec' );
			return self::finish( $status, false );
		}
		set_transient( 'runningsa_mec_lock', 1, 10 * MINUTE_IN_SECONDS );

		$provinces = (array) runningsa_mec_get_setting( 'provinces' );
		$valid     = array_keys( runningsa_mec_provinces() );
		$provinces = array_values( array_intersect( $provinces, $valid ) );

		RunningSA_MEC_Logger::log( sprintf( 'Import started (%s). Provinces: %s', $trigger, implode( ', ', $provinces ) ) );

		$seen = array();
		foreach ( $provinces as $i => $province ) {
			if ( $i > 0 ) {
				sleep( 1 ); // One call per province, spaced out for the rate limit.
			}
			$result = RunningSA_MEC_API::get_events_for_province( $province );
			if ( is_wp_error( $result ) ) {
				$status['errors'][] = $province . ': ' . $result->get_error_message();
				RunningSA_MEC_Logger::log( $province . ': ' . $result->get_error_message(), 'error', true );
				continue;
			}
			$events = isset( $result['events'] ) ? $result['events'] : array();
			if ( empty( $result['complete'] ) ) {
				RunningSA_MEC_Logger::log( $province . ': page limit reached; some races in this province were not imported.', 'warning', true );
			}
			RunningSA_MEC_Logger::log( sprintf( '%s: %d events received', $province, count( $events ) ) );
			$status['fetched'] += count( $events );

			foreach ( $events as $event ) {
				$id = isset( $event['id'] ) ? (int) $event['id'] : 0;
				if ( ! $id || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$imported    = self::import_event( $event, $province );
				if ( is_wp_error( $imported ) ) {
					$status['errors'][] = '#' . $id . ': ' . $imported->get_error_message();
					RunningSA_MEC_Logger::log( '#' . $id . ': ' . $imported->get_error_message(), 'error', true );
				} else {
					$status[ $imported ]++;
				}
			}
		}

		if ( $provinces ) {
			sleep( 1 );
		}
		self::apply_sync( $status );

		delete_transient( 'runningsa_mec_lock' );
		return self::finish( $status );
	}

	/**
	 * Save the status for the settings page.
	 */
	private static function finish( $status, $store = true ) {
		if ( $store ) {
			update_option( 'runningsa_mec_last_run', $status, false );
		}
		RunningSA_MEC_Logger::log(
			sprintf(
				'Import finished: %d fetched, %d created, %d updated, %d unchanged, %d closed, %d errors',
				$status['fetched'],
				$status['created'],
				$status['updated'],
				$status['skipped'],
				$status['closed'],
				count( $status['errors'] )
			)
		);
		return $status;
	}

	/**
	 * Create or update one MEC event.
	 *
	 * @param array  $event    API event.
	 * @param string $province Province slug this event was fetched for. Empty for a slug import.
	 * @return string|WP_Error created|updated|skipped.
	 */
	public static function import_event( array $event, $province = '' ) {
		$args = self::map_event( $event );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( '' === $province ) {
			$province = self::province_slug_from_event( $event );
		}

		$hash       = md5( wp_json_encode( $event ) . '|' . (int) runningsa_mec_get_setting( 'category' ) . '|' . RUNNINGSA_MEC_VERSION );
		$post_id    = self::find_existing( (int) $event['id'] );
		$was_closed = false;

		if ( $post_id ) {
			$post = get_post( $post_id );
			// Respect the admin: if they binned the event, leave it in the bin.
			if ( ! $post || 'trash' === $post->post_status ) {
				return 'skipped';
			}
			$was_closed = (bool) get_post_meta( $post_id, self::META_CLOSED, true );
			if ( get_post_meta( $post_id, self::META_HASH, true ) === $hash ) {
				self::remember_identity( $post_id, $event, $province );
				// Same data, but it is listed again, so take our closed flag off.
				if ( $was_closed ) {
					self::mark_open( $post_id );
					RunningSA_MEC_Logger::log( sprintf( 'Reopened #%d "%s" (post %d)', $event['id'], $event['name'], $post_id ) );
					return 'updated';
				}
				return 'skipped';
			}
			// Keep whatever status the admin chose (e.g. draft).
			$args['status'] = $post->post_status;
		}

		$args['location_id'] = self::get_location_id( $event );

		$main    = MEC::getInstance( 'app.libraries.main' );
		$new_id  = $main->save_event( $args, $post_id ? $post_id : null );
		if ( ! $new_id || is_wp_error( $new_id ) ) {
			return new WP_Error( 'runningsa_save', __( 'MEC could not save the event.', 'running-sa-mec' ) );
		}

		// Location + category taxonomies (save_event only stores mec_location_id meta).
		if ( $args['location_id'] > 1 ) {
			wp_set_object_terms( $new_id, (int) $args['location_id'], 'mec_location' );
		}
		$category = (int) runningsa_mec_get_setting( 'category' );
		if ( $category ) {
			wp_set_object_terms( $new_id, $category, 'mec_category', true );
		}

		update_post_meta( $new_id, self::META_ID, (int) $event['id'] );
		update_post_meta( $new_id, self::META_URL, $event['url'] );
		update_post_meta( $new_id, self::META_HASH, $hash );
		self::remember_identity( $new_id, $event, $province );
		if ( $was_closed ) {
			self::mark_open( $new_id );
		}

		RunningSA_MEC_Logger::log( sprintf( '%s #%d "%s" as post %d', $post_id ? 'Updated' : 'Created', $event['id'], $event['name'], $new_id ) );
		return $post_id ? 'updated' : 'created';
	}

	/**
	 * Find the MEC event we made earlier for this RunningCalendar id.
	 */
	private static function find_existing( $rc_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'mec-events',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'meta_key'       => self::META_ID, // phpcs:ignore
				'meta_value'     => $rc_id,        // phpcs:ignore
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Apply Running Calendar's sync feed.
	 *
	 * sync_status "removed" means the race is no longer public (cancelled, draft
	 * or hidden). Those MEC events are marked cancelled. A race that has simply
	 * taken place is left as a past event.
	 *
	 * The first run looks back one year so races already removed are closed too.
	 *
	 * @param array $status Status summary, updated in place.
	 */
	private static function apply_sync( array &$status ) {
		$checkpoint = (string) get_option( 'runningsa_mec_synced_at', '' );
		if ( '' === $checkpoint ) {
			$checkpoint = gmdate( 'Y-m-d\TH:i:s\Z', time() - YEAR_IN_SECONDS );
		}

		$sync = RunningSA_MEC_API::get_updated_since( $checkpoint );
		if ( is_wp_error( $sync ) ) {
			$status['errors'][] = $sync->get_error_message();
			RunningSA_MEC_Logger::log( 'Sync: ' . $sync->get_error_message(), 'error', true );
			return;
		}
		if ( empty( $sync['complete'] ) ) {
			RunningSA_MEC_Logger::log( 'Sync page limit reached. Removed races from this response were closed, and the same window will be requested next time.', 'warning', true );
		}

		$selected = array_values( array_intersect( (array) runningsa_mec_get_setting( 'provinces' ), array_keys( runningsa_mec_provinces() ) ) );
		foreach ( $sync['events'] as $event ) {
			$id = isset( $event['id'] ) ? (int) $event['id'] : 0;
			if ( ! $id ) {
				continue;
			}
			$state   = isset( $event['sync_status'] ) ? (string) $event['sync_status'] : '';
			$post_id = self::find_existing( $id );

			if ( 'removed' === $state ) {
				if ( ! $post_id || 'trash' === get_post_status( $post_id ) || ! self::mark_closed( $post_id ) ) {
					continue;
				}
				$status['closed']++;
				RunningSA_MEC_Logger::log( sprintf( 'Closed post %d (Running Calendar id %d): no longer available.', $post_id, $id ) );
				continue;
			}

			// Province imports already saved selected races. Re-import the rest we
			// already track (a slug import, or a race we had marked closed).
			if ( 'active' !== $state || ! $post_id || 'trash' === get_post_status( $post_id ) ) {
				continue;
			}
			$province   = (string) get_post_meta( $post_id, self::META_PROVINCE, true );
			$was_closed = (bool) get_post_meta( $post_id, self::META_CLOSED, true );
			if ( ! $was_closed && in_array( $province, $selected, true ) ) {
				continue;
			}
			$imported = self::import_event( $event );
			if ( is_wp_error( $imported ) ) {
				$status['errors'][] = '#' . $id . ': ' . $imported->get_error_message();
				RunningSA_MEC_Logger::log( '#' . $id . ': ' . $imported->get_error_message(), 'error', true );
			} else {
				$status[ $imported ]++;
			}
		}

		if ( empty( $sync['complete'] ) ) {
			return;
		}
		$next = ! empty( $sync['max_updated_at'] ) ? $sync['max_updated_at'] : gmdate( 'Y-m-d\TH:i:s\Z' );
		update_option( 'runningsa_mec_synced_at', $next, false );
	}

	/**
	 * Store the slug and province without rewriting the MEC event.
	 *
	 * @param int    $post_id  MEC event id.
	 * @param array  $event    API event.
	 * @param string $province Province slug.
	 */
	private static function remember_identity( $post_id, array $event, $province ) {
		if ( '' !== $province ) {
			update_post_meta( $post_id, self::META_PROVINCE, $province );
		}
		$slug = isset( $event['slug'] ) ? RunningSA_MEC_API::normalize_slug( $event['slug'] ) : '';
		if ( '' !== $slug ) {
			update_post_meta( $post_id, self::META_SLUG, $slug );
		}
	}

	/**
	 * Map a venue province label back to an API slug.
	 *
	 * @param array $event API event.
	 * @return string
	 */
	private static function province_slug_from_event( array $event ) {
		$label = ( isset( $event['venue']['province'] ) ) ? (string) $event['venue']['province'] : '';
		if ( '' === $label ) {
			return '';
		}
		foreach ( runningsa_mec_provinces() as $slug => $name ) {
			if ( 0 === strcasecmp( $name, $label ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * MEC's cancelled event status. The event stays on the calendar.
	 *
	 * @param int $post_id MEC event id.
	 * @return bool True when this call changed it.
	 */
	private static function mark_closed( $post_id ) {
		if ( get_post_meta( $post_id, self::META_CLOSED, true ) ) {
			return false;
		}
		update_post_meta( $post_id, 'mec_event_status', 'EventCancelled' );
		update_post_meta( $post_id, 'mec_cancelled_reason', __( 'This race is no longer available on Running Calendar.', 'running-sa-mec' ) );
		update_post_meta( $post_id, 'mec_display_cancellation_reason_in_single_page', 1 );
		update_post_meta( $post_id, self::META_CLOSED, 1 );
		return true;
	}

	/**
	 * Clear a cancellation this plugin applied, once the race is listed again.
	 *
	 * @param int $post_id MEC event id.
	 */
	private static function mark_open( $post_id ) {
		update_post_meta( $post_id, 'mec_event_status', 'EventScheduled' );
		update_post_meta( $post_id, 'mec_cancelled_reason', '' );
		update_post_meta( $post_id, 'mec_display_cancellation_reason_in_single_page', 0 );
		delete_post_meta( $post_id, self::META_CLOSED );
	}

	/**
	 * Build the array MEC's save_event() expects.
	 *
	 * @param array $event API event.
	 * @return array|WP_Error
	 */
	public static function map_event( array $event ) {
		$date = isset( $event['date'] ) ? (string) $event['date'] : '';
		if ( empty( $event['id'] ) || empty( $event['name'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'runningsa_bad_event', __( 'Event is missing id, name or date.', 'running-sa-mec' ) );
		}
		if ( empty( $event['url'] ) || ! filter_var( $event['url'], FILTER_VALIDATE_URL ) || 0 !== strpos( $event['url'], 'https://' ) ) {
			return new WP_Error( 'runningsa_bad_url', __( 'Event has no valid url, so it cannot be linked back (required).', 'running-sa-mec' ) );
		}
		$end_date = ( ! empty( $event['end_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $event['end_date'] ) ) ? $event['end_date'] : $date;

		$races = isset( $event['races'] ) && is_array( $event['races'] ) ? $event['races'] : array();

		// Earliest race start time = event start time.
		$times = array();
		foreach ( $races as $race ) {
			if ( ! empty( $race['start_time'] ) && preg_match( '/^(\d{1,2}):(\d{2})/', $race['start_time'], $m ) ) {
				$times[] = sprintf( '%02d:%02d', $m[1], $m[2] );
			}
		}
		sort( $times );
		$allday = empty( $times ) ? 1 : 0;
		$start  = $allday ? '00:00' : $times[0];
		list( $sh, $sm ) = array_map( 'intval', explode( ':', $start ) );

		// API has no finish time: end = last start + 1 hour, and hide the end time.
		$last = $allday ? '23:59' : end( $times );
		list( $eh, $em ) = array_map( 'intval', explode( ':', $last ) );
		if ( ! $allday ) {
			$eh = min( 23, $eh + 1 );
		}

		$start_12 = self::to_12h( $sh );
		$end_12   = self::to_12h( $eh );
		$cost     = self::format_cost( $races );

		return array(
			'title'              => wp_slash( sanitize_text_field( $event['name'] ) ),
			'content'            => wp_slash( self::build_content( $event, $races ) ),
			'status'             => 'publish',
			'organizer_id'       => 1, // MEC's "no organizer" (not in the API yet).
			'date'               => array(
				'repeat'        => array(),
				'allday'        => $allday,
				'hide_time'     => 0,
				'hide_end_time' => 1,
			),
			'allday'             => $allday,
			'start'              => $date,
			'start_time_hour'    => $start_12[0],
			'start_time_minutes' => $sm,
			'start_time_ampm'    => $start_12[1],
			'end'                => $end_date,
			'end_time_hour'      => $end_12[0],
			'end_time_minutes'   => $em,
			'end_time_ampm'      => $end_12[1],
			'repeat_status'      => 0,
			'repeat_type'        => '',
			'interval'           => null,
			'finish'             => $end_date,
			'year'               => null,
			'month'              => null,
			'day'                => null,
			'week'               => null,
			'weekday'            => null,
			'weekdays'           => null,
			'days'               => '',
			'not_in_days'        => '',
			'meta'               => array(
				'mec_source'           => 'runningcalendar',
				'mec_more_info'        => $event['url'], // Exactly as received.
				'mec_more_info_title'  => __( 'View on RunningCalendar', 'running-sa-mec' ),
				'mec_more_info_target' => '_blank',
				'mec_cost'             => $cost,
				'mec_allday'           => $allday,
				'mec_hide_time'        => 0,
				'mec_hide_end_time'    => 1,
				'mec_repeat'           => array( 'status' => 0, 'type' => '', 'interval' => null, 'end' => '', 'end_at_date' => '', 'end_at_occurrences' => '' ),
			),
		);
	}

	/** @return array [hour 1-12, 'AM'|'PM'] */
	private static function to_12h( $h ) {
		$ampm = $h >= 12 ? 'PM' : 'AM';
		$h12  = $h % 12;
		return array( 0 === $h12 ? 12 : $h12, $ampm );
	}

	/**
	 * "From R80" style cost from race_fee_from / race_fee_currency.
	 */
	public static function format_cost( array $races ) {
		$min      = null;
		$currency = '';
		foreach ( $races as $race ) {
			if ( isset( $race['race_fee_from'] ) && is_numeric( $race['race_fee_from'] ) ) {
				$fee = (float) $race['race_fee_from'];
				if ( null === $min || $fee < $min ) {
					$min      = $fee;
					$currency = isset( $race['race_fee_currency'] ) ? (string) $race['race_fee_currency'] : '';
				}
			}
		}
		if ( null === $min ) {
			return '';
		}
		if ( 0.0 === $min ) {
			return __( 'Free', 'running-sa-mec' );
		}
		/* translators: %s: amount e.g. R80 */
		return sprintf( __( 'From %s', 'running-sa-mec' ), self::money( $min, $currency ) );
	}

	private static function money( $amount, $currency ) {
		$num = ( floor( $amount ) == $amount ) ? number_format_i18n( $amount ) : number_format_i18n( $amount, 2 );
		if ( 'ZAR' === $currency || '' === $currency ) {
			return 'R' . $num;
		}
		return $currency . ' ' . $num;
	}

	/**
	 * Event description: race list, venue, and the required link back.
	 */
	public static function build_content( array $event, array $races ) {
		$html = '';
		if ( $races ) {
			$html .= '<h3>' . esc_html__( 'Races', 'running-sa-mec' ) . '</h3><ul class="runningsa-races">';
			foreach ( $races as $race ) {
				$parts = array();
				if ( ! empty( $race['start_time'] ) ) {
					/* translators: %s: start time */
					$parts[] = sprintf( __( 'starts %s', 'running-sa-mec' ), $race['start_time'] );
				}
				if ( isset( $race['race_fee_from'] ) && is_numeric( $race['race_fee_from'] ) ) {
					$parts[] = 0.0 === (float) $race['race_fee_from']
						? __( 'free entry', 'running-sa-mec' )
						: self::money( (float) $race['race_fee_from'], isset( $race['race_fee_currency'] ) ? $race['race_fee_currency'] : '' );
				}
				$name  = ! empty( $race['name'] ) ? $race['name'] : ( isset( $race['distance_label'] ) ? $race['distance_label'] : '' );
				$html .= '<li><strong>' . esc_html( $name ) . '</strong>' . ( $parts ? ' &ndash; ' . esc_html( implode( ', ', $parts ) ) : '' ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( isset( $event['verification_status'] ) && 'verified' !== $event['verification_status'] ) {
			$html .= '<p><em>' . esc_html__( 'Details for this race are not yet verified. Please check the race page before entering.', 'running-sa-mec' ) . '</em></p>';
		}

		// Required by RunningCalendar's terms: link back using their url, unchanged, no noreferrer.
		$html .= '<p class="runningsa-source"><a href="' . esc_url( $event['url'] ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'View this race on RunningCalendar', 'running-sa-mec' ) . '</a></p>';

		return $html;
	}

	/**
	 * Find or create the mec_location term for the venue.
	 * Keyed on town + province so the same venue is reused.
	 *
	 * @return int Term id (1 = MEC's "no location").
	 */
	private static function get_location_id( array $event ) {
		$venue = isset( $event['venue'] ) && is_array( $event['venue'] ) ? $event['venue'] : array();
		$town  = isset( $venue['town'] ) ? sanitize_text_field( $venue['town'] ) : '';
		$prov  = isset( $venue['province'] ) ? sanitize_text_field( $venue['province'] ) : '';
		if ( '' === $town && '' === $prov ) {
			return 1;
		}
		$name = trim( $town . ( $town && $prov ? ', ' : '' ) . $prov );
		$key  = sanitize_title( $name );

		$found = get_terms(
			array(
				'taxonomy'   => 'mec_location',
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => '_runningsa_loc', // phpcs:ignore
				'meta_value' => $key,             // phpcs:ignore
			)
		);
		if ( ! is_wp_error( $found ) && $found ) {
			return (int) $found[0];
		}

		$term = wp_insert_term( $name, 'mec_location' );
		if ( is_wp_error( $term ) ) {
			// Term with the same name made by hand: reuse it.
			$term_id = (int) $term->get_error_data( 'term_exists' );
			if ( ! $term_id ) {
				return 1;
			}
		} else {
			$term_id = (int) $term['term_id'];
			$country = ( isset( $venue['country_iso2'] ) && 'ZA' === $venue['country_iso2'] ) ? 'South Africa' : ( isset( $venue['country_iso2'] ) ? $venue['country_iso2'] : '' );
			update_term_meta( $term_id, 'address', trim( $name . ( $country ? ', ' . $country : '' ) ) );
			update_term_meta( $term_id, 'latitude', isset( $venue['lat'] ) ? (string) (float) $venue['lat'] : '' );
			update_term_meta( $term_id, 'longitude', isset( $venue['lng'] ) ? (string) (float) $venue['lng'] : '' );
		}
		update_term_meta( $term_id, '_runningsa_loc', $key );
		return $term_id;
	}
}
