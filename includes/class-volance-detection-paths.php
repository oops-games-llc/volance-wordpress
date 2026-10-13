<?php
/**
 * Protected paths: score any URL on the site, such as a REST endpoint, a custom
 * form page or a whole section, using the rules saved in Setup.
 *
 * Each rule is a URL path (with * wildcards) and a mode: observe, flag or block.
 * A flagged request is logged and fires an action; a blocked request gets a 403.
 * Administrators are never blocked, so a bad rule cannot lock the owner out.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request-level protection for chosen paths.
 */
class Volance_Detection_Paths {

	/**
	 * Register hooks when there is at least one rule.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			return;
		}
		$settings = Volance_Detection_Settings::get();
		if ( empty( $settings['paths'] ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'handle' ), 1 );
	}

	/**
	 * The current request's URL path relative to the site root, without a query string.
	 * A REST request made with ?rest_route= is reported as /wp-json/<route>.
	 *
	 * @return string
	 */
	public static function current_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the path component is used, after parsing and control-character stripping.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing hint.
			$path = '/wp-json/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' );
		}
		$path = preg_replace( '/[^\x21-\x7E]/', '', $path );
		return '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * The rule that covers the current request, if any.
	 *
	 * @return array|null {pattern, mode}
	 */
	public static function current_rule() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to fixed values only.
		if ( 'OPTIONS' === $method || 'HEAD' === $method || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return null;
		}
		$settings = Volance_Detection_Settings::get();
		return Volance_Detection_Settings::match_path( self::current_path(), (array) $settings['paths'] );
	}

	/**
	 * Whether the current visitor is exempt (administrators are never scored or blocked here).
	 *
	 * @return bool
	 */
	private static function exempt() {
		$exempt = is_user_logged_in() && current_user_can( 'manage_options' );
		return (bool) apply_filters( 'volance_detection_skip_user', $exempt );
	}

	/**
	 * Score the current request if a rule covers it. Does not respond.
	 *
	 * @return array|null {rule: array, result: array} or null when nothing applies.
	 */
	public static function decide() {
		$rule = self::current_rule();
		if ( ! $rule || self::exempt() ) {
			return null;
		}
		$result = Volance_Detection_Forms::guard( $rule['mode'], self::current_path(), $rule['pattern'] );
		return array(
			'rule'   => $rule,
			'result' => $result,
		);
	}

	/**
	 * Score the request and refuse it when it is blocked.
	 *
	 * @return void
	 */
	public static function handle() {
		$decision = self::decide();
		if ( ! $decision || 'block' !== $decision['result']['applied'] ) {
			return;
		}
		$message = Volance_Detection_Forms::block_message( 'path' );
		if ( 0 === strpos( self::current_path(), '/wp-json/' ) ) {
			status_header( 403 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				array(
					'code'    => 'volance_blocked',
					'message' => $message,
					'data'    => array( 'status' => 403 ),
				)
			);
			exit;
		}
		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}
}
