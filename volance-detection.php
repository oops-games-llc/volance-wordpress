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
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

define( 'VOLANCE_DETECTION_VERSION', '0.1.0' );
define( 'VOLANCE_DETECTION_FILE', __FILE__ );
define( 'VOLANCE_DETECTION_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOLANCE_DETECTION_URL', plugin_dir_url( __FILE__ ) );

/**
 * API and collector hosts.
 *
 * The collector is loaded from the stable /trace.js alias on purpose: a pinned
 * content-hashed URL can stop resolving after a collector release.
 */
define( 'VOLANCE_DETECTION_API_BASE', 'https://app.volance.com' );
define( 'VOLANCE_DETECTION_COLLECTOR', 'https://app.volance.com/trace.js' );

require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-settings.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-evidence.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-client.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-state.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-log.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-scorer.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-forms.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-frontend.php';
require_once VOLANCE_DETECTION_DIR . 'includes/class-volance-detection-admin.php';

/**
 * Activation: seed options, create the log table and schedule the daily job.
 *
 * @return void
 */
function volance_detection_activate() {
	add_option( Volance_Detection_Settings::OPTION, Volance_Detection_Settings::defaults() );
	Volance_Detection_Log::install();
	if ( ! wp_next_scheduled( 'volance_detection_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'volance_detection_daily' );
	}
}
register_activation_hook( __FILE__, 'volance_detection_activate' );

/**
 * Deactivation: stop the scheduled job. Data and settings are kept.
 *
 * @return void
 */
function volance_detection_deactivate() {
	wp_clear_scheduled_hook( 'volance_detection_daily' );
}
register_deactivation_hook( __FILE__, 'volance_detection_deactivate' );

/**
 * Daily job: purge old log rows and refresh the workspace's fingerprint flag.
 *
 * @return void
 */
function volance_detection_run_daily() {
	Volance_Detection_Log::purge();
	if ( Volance_Detection_Settings::is_ready() ) {
		$client = new Volance_Detection_Client( Volance_Detection_Settings::public_key(), Volance_Detection_Settings::secret_key() );
		$state  = new Volance_Detection_State();
		$result = $client->session();
		if ( $result['ok'] && isset( $result['data']['collect'] ) && is_array( $result['data']['collect'] ) ) {
			$state->set_fingerprints( true === ( $result['data']['collect']['fingerprints'] ?? false ) );
		}
	}
}
add_action( 'volance_detection_daily', 'volance_detection_run_daily' );

/**
 * Boot the plugin.
 *
 * @return void
 */
function volance_detection_boot() {
	Volance_Detection_Log::maybe_upgrade();
	Volance_Detection_Forms::init();
	Volance_Detection_Frontend::init();
	if ( is_admin() ) {
		Volance_Detection_Admin::init();
	}
}
add_action( 'plugins_loaded', 'volance_detection_boot' );
