<?php
/**
 * Plugin Name:       RunningSA integration with MEC
 * Description:       Imports upcoming races from the RunningCalendar API into Modern Events Calendar (MEC) events, once a day.
 * Version:           1.0.0
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

define( 'RUNNINGSA_MEC_VERSION', '1.0.0' );
define( 'RUNNINGSA_MEC_FILE', __FILE__ );
define( 'RUNNINGSA_MEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUNNINGSA_MEC_CRON_HOOK', 'runningsa_mec_daily_fetch' );

require_once RUNNINGSA_MEC_DIR . 'includes/class-logger.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-api.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-importer.php';
require_once RUNNINGSA_MEC_DIR . 'includes/class-admin.php';

/**
 * Activation: schedule the daily poll.
 */
function runningsa_mec_activate() {
	if ( ! wp_next_scheduled( RUNNINGSA_MEC_CRON_HOOK ) ) {
		// First run in 5 minutes, then every day.
		wp_schedule_event( time() + 300, 'daily', RUNNINGSA_MEC_CRON_HOOK );
	}
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

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'running-sa-mec', false, dirname( plugin_basename( RUNNINGSA_MEC_FILE ) ) . '/languages' );
		if ( is_admin() ) {
			new RunningSA_MEC_Admin();
		}
	}
);
