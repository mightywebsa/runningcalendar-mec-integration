<?php
/**
 * Debug log, stored in one non-autoloaded option and capped at 500 lines.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunningSA_MEC_Logger {

	const OPTION   = 'runningsa_mec_log';
	const MAX_ROWS = 500;

	/**
	 * Add a line to the log (only when debug is on, unless $force).
	 *
	 * @param string $message Message.
	 * @param string $level   info|warning|error.
	 * @param bool   $force   Log even when debug is off (used for errors).
	 */
	public static function log( $message, $level = 'info', $force = false ) {
		if ( ! $force && ! runningsa_mec_get_setting( 'debug' ) ) {
			return;
		}
		$rows   = self::get();
		$rows[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => self::scrub( (string) $message ),
		);
		if ( count( $rows ) > self::MAX_ROWS ) {
			$rows = array_slice( $rows, -self::MAX_ROWS );
		}
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * Never let the API key end up in the log.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function scrub( $message ) {
		$key = runningsa_mec_get_api_key();
		if ( $key ) {
			$message = str_replace( $key, '[api key hidden]', $message );
		}
		return preg_replace( '/rc_live_[A-Za-z0-9]+/', 'rc_live_[hidden]', $message );
	}

	/** @return array */
	public static function get() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
