<?php
/**
 * Plugin Name:       Volance Detection
 * Plugin URI:        https://volance.com
 * Description:       Observe-only: relays consented form-submission signals to Volance and logs the human-likeness score in wp-admin. Never blocks; fails open.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Oops Games LLC
 * Author URI:        https://volance.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       volance-detection
 * Domain Path:       /languages
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

define( 'VOLANCE_DETECTION_VERSION', '0.1.0' );
define( 'VOLANCE_DETECTION_FILE', __FILE__ );
define( 'VOLANCE_DETECTION_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOLANCE_DETECTION_URL', plugin_dir_url( __FILE__ ) );

/** API + collector hosts. The collector is content-addressed for pinning. */
define( 'VOLANCE_DETECTION_API_BASE', 'https://app.volance.com' );
define( 'VOLANCE_DETECTION_COLLECTOR', 'https://app.volance.com/trace.js' );

/**
 * Default option values.
 *
 * @return array
 */
function volance_detection_defaults() {
	return array(
		'public_key'       => '',
		'secret_key'       => '',
		'enabled'          => false,
		'consent_required' => true,
	);
}

/**
 * Seed options on activation without clobbering existing values.
 *
 * @return void
 */
function volance_detection_activate() {
	$current = get_option( 'volance_detection_settings', array() );
	add_option(
		'volance_detection_settings',
		array_merge( volance_detection_defaults(), is_array( $current ) ? $current : array() )
	);
}
register_activation_hook( __FILE__, 'volance_detection_activate' );

/**
 * Read the plugin settings.
 *
 * @return array
 */
function volance_detection_settings() {
	$stored = get_option( 'volance_detection_settings', array() );
	return array_merge( volance_detection_defaults(), is_array( $stored ) ? $stored : array() );
}

/**
 * Whether collection may run: enabled, both keys present, and consent given.
 *
 * @param bool $consent Visitor consent.
 * @return bool
 */
function volance_detection_may_collect( $consent ) {
	$settings = volance_detection_settings();
	return ! empty( $settings['enabled'] )
		&& true === (bool) $consent
		&& '' !== $settings['public_key']
		&& '' !== $settings['secret_key'];
}

/*
 * TODO(connector) — implement the observe-only flow:
 *   1. A Settings page (Settings → Volance Detection) for the pk_/sk_ keys and
 *      the consent toggle; sanitize and nonce every field.
 *   2. After consent, enqueue VOLANCE_DETECTION_COLLECTOR as a module and start
 *      it with { consent: true }. Never load it before consent.
 *   3. On form submit, POST the snapshot from PHP (never the browser) to
 *      VOLANCE_DETECTION_API_BASE . '/api/trace/score' with the sk_ key.
 *   4. Log the score/verdict in the admin. Never block; fail open on any error.
 * See AGENTS.md and docs/consent-and-privacy.md for the exact boundaries and copy.
 */
