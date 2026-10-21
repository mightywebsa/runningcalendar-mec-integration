<?php
/**
 * Plugin Name:       Running Calendar integration with MEC
 * Description:       Imports upcoming races from the RunningCalendar API into Modern Events Calendar (MEC) events on a schedule you choose.
 * Version:           1.0.2
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

define( 'RUNNINGSA_MEC_VERSION', '1.0.2' );
define( 'RUNNINGSA_MEC_FILE', __FILE__ );
define( 'RUNNINGSA_MEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUNNINGSA_MEC_CRON_HOOK', 'runningsa_mec_daily_fetch' );

require_once RUNNINGSA_MEC_DIR . 'includes/class-logger.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-api.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-importer.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-admin.php';

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
		'weekly'       => array(
			'schedule' => 'weekly',
			'seconds'  => WEEK_IN_SECONDS,
			'label'    => __( 'Once a week', 'running-sa-mec' ),
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
		'api_key'   => '',
		'provinces' => array( 'free-state', 'northern-cape' ),
		'interval'  => 'daily',
		'category'  => 0,
		'debug'     => 0,
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
