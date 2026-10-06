<?php
/**
 * Small state holder: the workspace's fingerprint flag and the 429 back-off.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persistent plugin state.
 */
class Volance_Detection_State {

	const FLAGS_OPTION  = 'volance_detection_flags';
	const BACKOFF_KEY   = 'volance_detection_backoff';
	const LAST_ERROR    = 'volance_detection_last_error';
	const USAGE_CACHE   = 'volance_detection_usage';

	/**
	 * Whether the Volance workspace has the fingerprint tier switched on.
	 *
	 * @return bool
	 */
	public function fingerprints() {
		$flags = get_option( self::FLAGS_OPTION, array() );
		return is_array( $flags ) && ! empty( $flags['fingerprints'] );
	}

	/**
	 * Record the workspace's fingerprint flag.
	 *
	 * @param bool $on Flag.
	 * @return void
	 */
	public function set_fingerprints( $on ) {
		update_option(
			self::FLAGS_OPTION,
			array(
				'fingerprints' => (bool) $on,
				'checked'      => time(),
			)
		);
	}

	/**
	 * Unix time until which scoring is paused after a 429, or 0.
	 *
	 * @return int
	 */
	public function backoff_until() {
		$until = (int) get_transient( self::BACKOFF_KEY );
		return $until > time() ? $until : 0;
	}

	/**
	 * Pause scoring for a number of seconds.
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	public function set_backoff( $seconds ) {
		$seconds = max( 1, (int) $seconds );
		set_transient( self::BACKOFF_KEY, time() + $seconds, $seconds );
	}

	/**
	 * Remember the most recent problem so the settings page can show it.
	 *
	 * @param string $message Short message ('' clears it).
	 * @return void
	 */
	public function set_last_error( $message ) {
		if ( '' === $message ) {
			delete_option( self::LAST_ERROR );
			return;
		}
		update_option(
			self::LAST_ERROR,
			array(
				'message' => substr( $message, 0, 190 ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Most recent problem.
	 *
	 * @return array|null array( 'message' => string, 'time' => int ).
	 */
	public function last_error() {
		$value = get_option( self::LAST_ERROR, null );
		return ( is_array( $value ) && isset( $value['message'], $value['time'] ) ) ? $value : null;
	}
}
