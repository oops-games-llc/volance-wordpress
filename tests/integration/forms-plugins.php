<?php
/**
 * Integration test for third-party form plugins. WooCommerce, Contact Form 7 and
 * WPForms Lite are installed for real by forms-plugins.blueprint.json; Gravity
 * Forms is not free, so a `GFForms` stub class stands in for it.
 *
 * Results go to /tests/result-forms.txt. See tests/integration/README.md.
 */

ob_start();
register_shutdown_function(
	static function () {
		$out = ob_get_contents();
		$err = error_get_last();
		file_put_contents( '/tests/result-forms.txt', $out . ( $err ? "\nLAST ERROR: " . print_r( $err, true ) : '' ) );
	}
);

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$passed = 0;
$failed = 0;
function t( $label, $ok ) {
	global $passed, $failed;
	if ( $ok ) {
		++$passed;
		return;
	}
	++$failed;
	echo "FAIL: {$label}\n";
}

$GLOBALS['mock'] = array( 'action' => 'allow', 'calls' => array() );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'app.volance.com' ) ) {
			return $pre;
		}
		$GLOBALS['mock']['calls'][] = $url;
		if ( false !== strpos( $url, '/api/trace/session' ) ) {
			return array( 'response' => array( 'code' => 200, 'message' => '' ), 'headers' => array(), 'body' => wp_json_encode( array( 'sessionId' => 'sid_1', 'manifest' => 'man_1', 'collect' => array( 'fingerprints' => false ) ) ), 'cookies' => array() );
		}
		$body = array(
			'score'          => 'block' === $GLOBALS['mock']['action'] ? 8.4 : 91.0,
			'verdict'        => 'block' === $GLOBALS['mock']['action'] ? 'bot' : 'likely_human',
			'action'         => $GLOBALS['mock']['action'],
			'classification' => array( 'label' => 'block' === $GLOBALS['mock']['action'] ? 'bot' : 'human' ),
			'evidence'       => array( 'events' => 3, 'behavioural' => true ),
			'signals'        => array(),
			'requestId'      => 'req_x',
		);
		return array( 'response' => array( 'code' => 200, 'message' => '' ), 'headers' => array(), 'body' => wp_json_encode( $body ), 'cookies' => array() );
	},
	10,
	3
);

$snapshot = wp_json_encode(
	array(
		'clientSignals' => array( 'webdriver' => false ),
		'events'        => array(
			array( 'type' => 'mousemove', 't' => 10, 'x' => 0.1, 'y' => 0.2 ),
			array( 'type' => 'click', 't' => 50, 'x' => 0.3, 'y' => 0.4 ),
		),
	)
);

function reset_request() {
	$_POST = array();
	$_SERVER = array_merge( $_SERVER, array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_ACCEPT_LANGUAGE' => 'en-US' ) );
	$ref = new ReflectionProperty( 'Volance_Detection_Forms', 'results' );
	$ref->setAccessible( true );
	$ref->setValue( null, array() );
	$GLOBALS['mock']['calls'] = array();
	delete_transient( Volance_Detection_State::BACKOFF_KEY );
}

function configure( array $forms ) {
	update_option( Volance_Detection_Settings::SECRET_OPTION, 'sk_zyxwvuts98765432' );
	update_option(
		Volance_Detection_Settings::OPTION,
		array(
			'public_key'     => 'pk_abcdefgh12345678',
			'enabled'        => true,
			'consent_mode'   => 'banner',
			'forms'          => $forms,
			'on_no_evidence' => 'allow',
		)
	);
}

// --- Which plugins are really present ----------------------------------------
$has_woo   = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
$has_cf7   = defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7' );
$has_wpf   = function_exists( 'wpforms' );
echo 'plugins present: woo=' . (int) $has_woo . ' cf7=' . (int) $has_cf7 . ' wpforms=' . (int) $has_wpf . "\n";
t( 'WooCommerce installed', $has_woo );
t( 'Contact Form 7 installed', $has_cf7 );
t( 'WPForms installed', $has_wpf );

t( 'form_available(wc_login) reflects WooCommerce', Volance_Detection_Settings::form_available( 'wc_login' ) === $has_woo );
t( 'form_available(cf7) reflects CF7', Volance_Detection_Settings::form_available( 'cf7' ) === $has_cf7 );
t( 'form_available(wpforms) reflects WPForms', Volance_Detection_Settings::form_available( 'wpforms' ) === $has_wpf );
t( 'form_available(gravity) false without Gravity', false === Volance_Detection_Settings::form_available( 'gravity' ) );

// Gravity Forms is a paid plugin: stub the detection class.
if ( ! class_exists( 'GFForms' ) ) {
	class GFForms {}
}
t( 'form_available(gravity) true with stub', true === Volance_Detection_Settings::form_available( 'gravity' ) );

// --- WooCommerce -------------------------------------------------------------
configure( array( 'wc_login' => 'block', 'wc_register' => 'block', 'wc_checkout' => 'block' ) );
Volance_Detection_Forms::init();

t( 'woocommerce_login_form prints the hidden field', has_action( 'woocommerce_login_form' ) );
ob_start();
do_action( 'woocommerce_login_form' );
$html = ob_get_clean();
t( 'wc login hidden field markup', false !== strpos( $html, 'volance_snapshot' ) );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$errors = apply_filters( 'woocommerce_process_login_errors', new WP_Error(), 'u', 'p' );
t( 'wc login block adds volance_blocked', 'volance_blocked' === $errors->get_error_code() );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$errors = apply_filters( 'woocommerce_process_login_errors', new WP_Error(), 'u', 'p' );
t( 'wc login allow adds no error', ! $errors->has_errors() );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$errors = apply_filters( 'woocommerce_process_registration_errors', new WP_Error(), 'u', 'u@example.com' );
t( 'wc register block adds volance_blocked', 'volance_blocked' === $errors->get_error_code() );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$errors = new WP_Error();
do_action( 'woocommerce_after_checkout_validation', array(), $errors );
t( 'wc checkout block adds volance_blocked', 'volance_blocked' === $errors->get_error_code() );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$errors = new WP_Error();
do_action( 'woocommerce_after_checkout_validation', array(), $errors );
t( 'wc checkout allow adds no error', ! $errors->has_errors() );

// --- Contact Form 7 ----------------------------------------------------------
configure( array( 'cf7' => 'block' ) );
Volance_Detection_Forms::init();

t( 'cf7 spam filter registered', false !== has_filter( 'wpcf7_spam', array( 'Volance_Detection_Forms', 'handle_cf7' ) ) ); // Other CF7 modules (Stripe) expect a submission object, so call our callback directly.
$fields = apply_filters( 'wpcf7_form_hidden_fields', array() );
t( 'cf7 hidden field added', isset( $fields['volance_snapshot'] ) );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
t( 'cf7 block marks submission as spam', true === Volance_Detection_Forms::handle_cf7( false ) );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
t( 'cf7 allow leaves spam false', false === Volance_Detection_Forms::handle_cf7( false ) );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
t( 'cf7 does not re-score an existing spam verdict', true === Volance_Detection_Forms::handle_cf7( true ) && array() === $GLOBALS['mock']['calls'] );

// --- WPForms -----------------------------------------------------------------
configure( array( 'wpforms' => 'block' ) );
Volance_Detection_Forms::init();

ob_start();
do_action( 'wpforms_display_submit_before', array() );
$html = ob_get_clean();
t( 'wpforms hidden field markup', false !== strpos( $html, 'volance_snapshot' ) );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ), 'wpforms' => array( 'id' => 7 ) );
$errors = apply_filters( 'wpforms_process_initial_errors', array(), array( 'id' => 7 ) );
t( 'wpforms block adds header error for the form', ! empty( $errors[7]['header'] ) );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ), 'wpforms' => array( 'id' => 7 ) );
$errors = apply_filters( 'wpforms_process_initial_errors', array(), array( 'id' => 7 ) );
t( 'wpforms allow adds no error', empty( $errors ) );

// --- Gravity Forms (stubbed) ---------------------------------------------------
configure( array( 'gravity' => 'block' ) );
Volance_Detection_Forms::init();

$tag = apply_filters( 'gform_form_tag', "<form method='post'>", array() );
t( 'gravity form tag gains hidden field', false !== strpos( $tag, 'volance_snapshot' ) && 0 === strpos( $tag, "<form method='post'>" ) );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$result = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => array() ) );
t( 'gravity block fails validation', false === $result['is_valid'] );
t( 'gravity block message shown', false !== strpos( apply_filters( 'gform_validation_message', '' ), 'validation_error' ) );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'volance_snapshot' => wp_slash( $snapshot ) );
$result = apply_filters( 'gform_validation', array( 'is_valid' => true, 'form' => array() ) );
t( 'gravity allow keeps validation valid', true === $result['is_valid'] );

echo "INTEGRATION: {$passed} passed, {$failed} failed\n";
