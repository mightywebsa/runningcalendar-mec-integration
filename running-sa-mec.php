<?php
/**
 * Plugin Name:       Running Calendar integration with MEC
 * Description:       Imports upcoming races from the RunningCalendar API into Modern Events Calendar (MEC) events on a schedule you choose.
 * Version:           1.1.1
 * Author:            MightyWeb
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * Text Domain:       running-sa-mec
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RUNNINGSA_MEC_VERSION', '1.1.1' );
define( 'RUNNINGSA_MEC_FILE', __FILE__ );
define( 'RUNNINGSA_MEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUNNINGSA_MEC_CRON_HOOK', 'runningsa_mec_daily_fetch' );

require_once RUNNINGSA_MEC_DIR . 'includes/class-logger.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-api.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-importer.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-admin.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-export-lock.php';

/**
 * Import frequencies. Keys are stored in settings; schedule is the WP-Cron name.
 *
 * @return array
 */
function runningsa_mec_intervals() {
	return array(
		'six_hours'    => array(
			'schedule' => 'runningsa_six_hours',
			'seconds'  => 6 * HOUR_IN_SECONDS,
			'label'    => __( 'Every 6 hours', 'running-sa-mec' ),
		),
		'twelve_hours' => array(
			'schedule' => 'runningsa_twelve_hours',
			'seconds'  => 12 * HOUR_IN_SECONDS,
			'label'    => __( 'Every 12 hours', 'running-sa-mec' ),
		),
		'daily'        => array(
			'schedule' => 'daily',
			'seconds'  => DAY_IN_SECONDS,
			'label'    => __( 'Once a day', 'running-sa-mec' ),
		),
	);
}

/**
 * WP-Cron callback name for the saved frequency. Unknown values fall back to daily.
 *
 * @return string
 */
function runningsa_mec_cron_schedule_name() {
	$intervals = runningsa_mec_intervals();
	$key       = (string) runningsa_mec_get_setting( 'interval' );
	return isset( $intervals[ $key ] ) ? $intervals[ $key ]['schedule'] : 'daily';
}

/**
 * Register the intervals WordPress does not provide.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function runningsa_mec_cron_schedules( $schedules ) {
	$intervals = runningsa_mec_intervals();
	$schedules['runningsa_six_hours']    = array(
		'interval' => $intervals['six_hours']['seconds'],
		'display'  => $intervals['six_hours']['label'],
	);
	$schedules['runningsa_twelve_hours'] = array(
		'interval' => $intervals['twelve_hours']['seconds'],
		'display'  => $intervals['twelve_hours']['label'],
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'runningsa_mec_cron_schedules' );

/**
 * Make the queued event match the saved frequency.
 * A missing job starts in 5 minutes. A changed frequency starts after one full interval.
 */
function runningsa_mec_ensure_schedule() {
	$wanted = runningsa_mec_cron_schedule_name();
	$event  = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( RUNNINGSA_MEC_CRON_HOOK ) : null;
	if ( $event && $event->schedule === $wanted ) {
		return;
	}

	$intervals = runningsa_mec_intervals();
	$key       = (string) runningsa_mec_get_setting( 'interval' );
	$seconds   = isset( $intervals[ $key ] ) ? $intervals[ $key ]['seconds'] : DAY_IN_SECONDS;
	$start     = time() + ( $event ? $seconds : 300 );

	wp_clear_scheduled_hook( RUNNINGSA_MEC_CRON_HOOK );
	wp_schedule_event( $start, $wanted, RUNNINGSA_MEC_CRON_HOOK );
}

/**
 * Activation: first poll in 5 minutes, then on the saved frequency.
 */
function runningsa_mec_activate() {
	wp_clear_scheduled_hook( RUNNINGSA_MEC_CRON_HOOK );
	wp_schedule_event( time() + 300, runningsa_mec_cron_schedule_name(), RUNNINGSA_MEC_CRON_HOOK );
}
register_activation_hook( __FILE__, 'runningsa_mec_activate' );

/**
 * Deactivation: remove the cron job. Imported events are left alone.
 */
function runningsa_mec_deactivate() {
	wp_clear_scheduled_hook( RUNNINGSA_MEC_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'runningsa_mec_deactivate' );

/**
 * Is Modern Events Calendar (Lite or Pro) loaded?
 *
 * @return bool
 */
function runningsa_mec_is_mec_active() {
	return class_exists( 'MEC' ) && post_type_exists( 'mec-events' );
}

/**
 * Get a plugin setting, with defaults.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function runningsa_mec_get_setting( $key ) {
	$defaults = array(
		'api_key'     => '',
		'provinces'   => array( 'free-state', 'northern-cape' ),
		'interval'    => 'daily',
		'category'    => 0,
		'debug'       => 0,
		'show_credit'     => 1,
		'restrict_export' => 1,
	);
	$settings = wp_parse_args( (array) get_option( 'runningsa_mec_settings', array() ), $defaults );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * The API key. A RUNNINGSA_API_KEY constant in wp-config.php wins over the settings field.
 *
 * @return string
 */
function runningsa_mec_get_api_key() {
	if ( defined( 'RUNNINGSA_API_KEY' ) && RUNNINGSA_API_KEY ) {
		return (string) RUNNINGSA_API_KEY;
	}
	return (string) runningsa_mec_get_setting( 'api_key' );
}

/**
 * Provinces the RunningCalendar API accepts (province slug => label).
 *
 * @return array
 */
function runningsa_mec_provinces() {
	return apply_filters(
		'runningsa_mec_provinces',
		array(
			'eastern-cape'  => __( 'Eastern Cape', 'running-sa-mec' ),
			'free-state'    => __( 'Free State', 'running-sa-mec' ),
			'gauteng'       => __( 'Gauteng', 'running-sa-mec' ),
			'kwazulu-natal' => __( 'KwaZulu-Natal', 'running-sa-mec' ),
			'limpopo'       => __( 'Limpopo', 'running-sa-mec' ),
			'mpumalanga'    => __( 'Mpumalanga', 'running-sa-mec' ),
			'north-west'    => __( 'North West', 'running-sa-mec' ),
			'northern-cape' => __( 'Northern Cape', 'running-sa-mec' ),
			'western-cape'  => __( 'Western Cape', 'running-sa-mec' ),
		)
	);
}

/**
 * Cron callback.
 */
function runningsa_mec_run_cron() {
	RunningSA_MEC_Importer::run( 'cron' );
}
add_action( RUNNINGSA_MEC_CRON_HOOK, 'runningsa_mec_run_cron' );

/**
 * Show the Running Calendar logo from its URL. The file is not copied into the media library.
 *
 * @param string $html    Existing thumbnail HTML.
 * @param int    $post_id Post id.
 * @return string
 */
function runningsa_mec_remote_thumbnail( $html, $post_id ) {
	if ( $html || 'mec-events' !== get_post_type( $post_id ) ) {
		return $html;
	}
	$url = get_post_meta( $post_id, RunningSA_MEC_Importer::META_IMAGE, true );
	if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) ) {
		return $html;
	}
	return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '" class="wp-post-image" />';
}
add_filter( 'post_thumbnail_html', 'runningsa_mec_remote_thumbnail', 10, 2 );

/**
 * Credit block: bundled RunningCalendar logo + text, linked with the tracked url.
 * Rendered at display time so existing events get it without a database rewrite.
 *
 * @param int $post_id MEC event id.
 * @return string
 */
function runningsa_mec_credit_block( $post_id = 0 ) {
	static $printed = array();

	if ( ! runningsa_mec_get_setting( 'show_credit' ) ) {
		return '';
	}
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id || 'mec-events' !== get_post_type( $post_id ) ) {
		return '';
	}
	if ( isset( $printed[ $post_id ] ) ) {
		return '';
	}
	$url = get_post_meta( $post_id, RunningSA_MEC_Importer::META_URL, true );
	if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return '';
	}

	$printed[ $post_id ] = true;
	$src = plugins_url( 'assets/runningcalendar-logo.svg', RUNNINGSA_MEC_FILE );
	return '<div class="runningsa-credit"><a class="runningsa-credit-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
		. '<img class="runningsa-credit-logo" src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'RunningCalendar', 'running-sa-mec' ) . '" width="48" height="48" />'
		. '<span class="runningsa-credit-text">' . esc_html__( 'Race data from RunningCalendar', 'running-sa-mec' ) . '</span>'
		. '</a></div>';
}

/**
 * Append the credit block to the event content when MEC uses the_content.
 *
 * @param string $content Post content.
 * @return string
 */
function runningsa_mec_append_credit( $content ) {
	if ( is_admin() || ! is_singular( 'mec-events' ) ) {
		return $content;
	}
	if ( false !== strpos( $content, 'runningsa-credit' ) ) {
		return $content;
	}
	return $content . runningsa_mec_credit_block();
}
add_filter( 'the_content', 'runningsa_mec_append_credit', 20 );

/**
 * MEC single-event templates often skip the_content. Hook their after-content action.
 *
 * @param mixed $event MEC event object or id, when the hook provides one.
 */
function runningsa_mec_echo_credit( $event = null ) {
	$post_id = 0;
	if ( is_object( $event ) ) {
		if ( isset( $event->ID ) ) {
			$post_id = (int) $event->ID;
		} elseif ( isset( $event->data ) && is_object( $event->data ) && isset( $event->data->ID ) ) {
			$post_id = (int) $event->data->ID;
		}
	} elseif ( is_numeric( $event ) ) {
		$post_id = (int) $event;
	}
	echo runningsa_mec_credit_block( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
}
add_action( 'mec_single_after_content', 'runningsa_mec_echo_credit', 20 );
add_action( 'mec_single_event_after_content', 'runningsa_mec_echo_credit', 20 );
add_action( 'mec_skin_single_after_content', 'runningsa_mec_echo_credit', 20 );

/**
 * Small stylesheet for the credit block.
 */
function runningsa_mec_enqueue_credit_css() {
	if ( ! runningsa_mec_get_setting( 'show_credit' ) ) {
		return;
	}
	wp_enqueue_style(
		'runningsa-mec-credit',
		plugins_url( 'assets/credit.css', RUNNINGSA_MEC_FILE ),
		array(),
		RUNNINGSA_MEC_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'runningsa_mec_enqueue_credit_css' );


/**
 * Saved “weekly” frequencies are no longer offered. Move them to daily and
 * reschedule the cron job to start in five minutes.
 */
function runningsa_mec_migrate_weekly_interval() {
	$settings = get_option( 'runningsa_mec_settings', array() );
	if ( ! is_array( $settings ) || ! isset( $settings['interval'] ) || 'weekly' !== $settings['interval'] ) {
		return;
	}

	$settings['interval'] = 'daily';
	remove_action( 'update_option_runningsa_mec_settings', 'runningsa_mec_ensure_schedule' );
	update_option( 'runningsa_mec_settings', $settings );
	add_action( 'update_option_runningsa_mec_settings', 'runningsa_mec_ensure_schedule' );

	wp_clear_scheduled_hook( RUNNINGSA_MEC_CRON_HOOK );
	wp_schedule_event( time() + 300, 'daily', RUNNINGSA_MEC_CRON_HOOK );
}

add_action( 'init', 'runningsa_mec_migrate_weekly_interval', 9 );
add_action( 'init', 'runningsa_mec_ensure_schedule' );
add_action( 'update_option_runningsa_mec_settings', 'runningsa_mec_ensure_schedule' );
add_action( 'add_option_runningsa_mec_settings', 'runningsa_mec_ensure_schedule' );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'running-sa-mec', false, dirname( plugin_basename( RUNNINGSA_MEC_FILE ) ) . '/languages' );
		if ( is_admin() ) {
			new RunningSA_MEC_Admin();
		}
	}
);
