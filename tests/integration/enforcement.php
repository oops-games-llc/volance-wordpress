<?php
/**
 * Integration test: runs inside a real WordPress (WordPress Playground), with the
 * Volance API mocked through the `pre_http_request` filter.
 *
 * It exercises the real hooks for login, registration, comments, the log table
 * and every admin tab. See tests/integration/README.md.
 */

ob_start();
register_shutdown_function(
	static function () {
		$out = ob_get_contents();
		$err = error_get_last();
		file_put_contents( '/tests/result.txt', $out . ( $err ? "\nLAST ERROR: " . print_r( $err, true ) : '' ) );
	}
);

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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

// --- Mock Volance -----------------------------------------------------------
$GLOBALS['mock'] = array( 'action' => 'allow', 'score_status' => 200, 'calls' => array() );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'app.volance.com' ) ) {
			return $pre;
		}
		$GLOBALS['mock']['calls'][] = $url;
		$json = static function ( $code, $body ) {
			return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => array(), 'body' => wp_json_encode( $body ), 'cookies' => array() );
		};
		if ( false !== strpos( $url, '/api/trace/session' ) ) {
			return $json( 200, array( 'sessionId' => 'sid_1', 'manifest' => 'man_1', 'collect' => array( 'fingerprints' => ! empty( $GLOBALS['mock']['fp'] ) ) ) );
		}
		if ( false !== strpos( $url, '/api/trace/score' ) ) {
			$GLOBALS['mock']['last_score_body'] = isset( $args['body'] ) ? (string) $args['body'] : '';
			if ( 200 !== $GLOBALS['mock']['score_status'] ) {
				return $json( $GLOBALS['mock']['score_status'], array( 'error' => 'boom' ) );
			}
			return $json(
				200,
				array(
					'score'          => 'block' === $GLOBALS['mock']['action'] ? 8.4 : 91.0,
					'verdict'        => 'block' === $GLOBALS['mock']['action'] ? 'bot' : 'likely_human',
					'action'         => $GLOBALS['mock']['action'],
					'classification' => array( 'label' => 'block' === $GLOBALS['mock']['action'] ? 'bot' : 'human' ),
					'evidence'       => array( 'events' => 3, 'behavioural' => true ),
					'signals'        => array( array( 'id' => 'webdriver', 'layer' => 1, 'value' => 0, 'fired' => true, 'detail' => 'test' ) ),
					'requestId'      => 'req_' . count( $GLOBALS['mock']['calls'] ),
					'enforce'        => ! isset( $GLOBALS['mock']['enforce'] ) || $GLOBALS['mock']['enforce'],
					'trustToken'     => 'tok_secret',
				)
			);
		}
		if ( false !== strpos( $url, '/api/trace/config' ) ) {
			return $json( 200, array( 'enabled' => true, 'mode' => 'enforce', 'thresholds' => array( 'allow' => 70, 'block' => 40 ) ) );
		}
		if ( false !== strpos( $url, '/api/trace/usage' ) ) {
			return $json( 200, array( 'id' => 'free', 'name' => 'Free', 'limits' => array( 'monthlyScores' => 10000 ), 'usage' => array( 'monthScores' => 5, 'limit' => 10000, 'pct' => 0 ) ) );
		}
		return $json( 404, array() );
	},
	10,
	3
);

$good_snapshot = wp_json_encode(
	array(
		'clientSignals' => array( 'webdriver' => false ),
		'events'        => array(
			array( 'type' => 'mousemove', 't' => 10, 'x' => 0.1, 'y' => 0.2 ),
			array( 'type' => 'click', 't' => 50, 'x' => 0.3, 'y' => 0.4 ),
			array( 'type' => 'keydown', 't' => 90 ),
		),
	)
);

function reset_request( $form = null ) {
	$_POST   = array();
	$_SERVER = array_merge( $_SERVER, array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_ACCEPT_LANGUAGE' => 'en-US' ) );
	$ref     = new ReflectionProperty( 'Volance_Detection_Forms', 'results' );
	$ref->setAccessible( true );
	$ref->setValue( null, array() );
	$GLOBALS['mock']['calls'] = array();
	delete_transient( Volance_Detection_State::BACKOFF_KEY );
}

function configure( array $forms, $on_no_evidence = 'allow' ) {
	update_option( Volance_Detection_Settings::SECRET_OPTION, 'sk_zyxwvuts98765432' );
	update_option(
		Volance_Detection_Settings::OPTION,
		array(
			'public_key'     => 'pk_abcdefgh12345678',
			'enabled'        => true,
			'consent_mode'   => 'banner',
			'forms'          => $forms,
			'on_no_evidence' => $on_no_evidence,
		)
	);
}

// --- Plugin loaded, table created --------------------------------------------
t( 'plugin classes loaded', class_exists( 'Volance_Detection_Forms' ) && class_exists( 'Volance_Detection_Decision' ) );
Volance_Detection_Log::install();
global $wpdb;
$table = Volance_Detection_Log::table();
t( 'log table exists', $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
t( 'log table has action and applied columns', in_array( 'action', $cols, true ) && in_array( 'applied', $cols, true ) );

// --- Login: block / allow / fail open ---------------------------------------
wp_create_user( 'vtest', 'Passw0rd!xyz123', 'vtest@example.com' );
configure( array( 'login' => 'block' ) );
Volance_Detection_Forms::init();

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'log' => 'vtest', 'pwd' => 'Passw0rd!xyz123', 'volance_snapshot' => wp_slash( $good_snapshot ) );
$user = wp_authenticate( 'vtest', 'Passw0rd!xyz123' );
t( 'login block: blocked even with correct password', is_wp_error( $user ) && 'volance_blocked' === $user->get_error_code() );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'log' => 'vtest', 'pwd' => 'Passw0rd!xyz123', 'volance_snapshot' => wp_slash( $good_snapshot ) );
$user = wp_authenticate( 'vtest', 'Passw0rd!xyz123' );
t( 'login allow: a human signs in', $user instanceof WP_User );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$GLOBALS['mock']['score_status'] = 500;
$_POST = array( 'log' => 'vtest', 'pwd' => 'Passw0rd!xyz123', 'volance_snapshot' => wp_slash( $good_snapshot ) );
$user = wp_authenticate( 'vtest', 'Passw0rd!xyz123' );
t( 'login: Volance error fails open', $user instanceof WP_User );
$GLOBALS['mock']['score_status'] = 200;

// --- Registration ------------------------------------------------------------
configure( array( 'register' => 'block' ) );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $good_snapshot ) );
do_action( 'register_post', 'newuser', 'new@example.com', new WP_Error() );
$errors = apply_filters( 'registration_errors', new WP_Error(), 'newuser', 'new@example.com' );
t( 'register block: error added', $errors->get_error_code() === 'volance_blocked' );

// --- Comments: flag holds, block refuses ---------------------------------------
configure( array( 'comments' => 'flag' ) );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action'] = 'flag';
$_POST = array( 'volance_snapshot' => wp_slash( $good_snapshot ) );
do_action( 'pre_comment_on_post', 1 );
t( 'comment flag: held for moderation', 0 === apply_filters( 'pre_comment_approved', 1, array() ) );

reset_request();
$GLOBALS['mock']['action'] = 'allow';
$_POST = array( 'volance_snapshot' => wp_slash( $good_snapshot ) );
do_action( 'pre_comment_on_post', 1 );
t( 'comment allow: approved unchanged', 1 === apply_filters( 'pre_comment_approved', 1, array() ) );

reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $good_snapshot ) );
do_action( 'pre_comment_on_post', 1 );
t( 'comment flag mode softens a block to a hold', 0 === apply_filters( 'pre_comment_approved', 1, array() ) );

configure( array( 'comments' => 'block' ) );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $good_snapshot ) );
do_action( 'pre_comment_on_post', 1 );
$approved = apply_filters( 'pre_comment_approved', 1, array() );
t( 'comment block: refused with an error', is_wp_error( $approved ) && 'volance_blocked' === $approved->get_error_code() );

// --- No evidence policy -------------------------------------------------------
configure( array( 'login' => 'block' ), 'score' );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'log' => 'vtest', 'pwd' => 'wrong' );
$user = wp_authenticate( 'vtest', 'wrong' );
t( 'no snapshot + score policy: scored on request signals and blocked', is_wp_error( $user ) && 'volance_blocked' === $user->get_error_code() && 2 === count( $GLOBALS['mock']['calls'] ) );

configure( array( 'login' => 'block' ), 'allow' );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'log' => 'vtest', 'pwd' => 'Passw0rd!xyz123' );
$user = wp_authenticate( 'vtest', 'Passw0rd!xyz123' );
t( 'no snapshot + allow policy: not blocked, nothing sent', $user instanceof WP_User && array() === $GLOBALS['mock']['calls'] );

// --- Observe never scores synchronously ----------------------------------------
configure( array( 'comments' => 'observe' ) );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action'] = 'block';
$_POST = array( 'volance_snapshot' => wp_slash( $good_snapshot ) );
do_action( 'pre_comment_on_post', 1 );
t( 'observe: no API call before the response', array() === $GLOBALS['mock']['calls'] );
t( 'observe: comment untouched', 1 === apply_filters( 'pre_comment_approved', 1, array() ) );

// --- Log rows -------------------------------------------------------------------
$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
t( 'log has rows from the scored requests', count( $rows ) >= 6 );
$blocked = array_filter( $rows, static function ( $r ) {
	return 'block' === $r->applied;
} );
t( 'log records applied=block rows', count( $blocked ) >= 2 );
t( 'log never stores the trust token or the IP', false === strpos( wp_json_encode( $rows ), 'tok_secret' ) && false === strpos( wp_json_encode( $rows ), '203.0.113.9' ) );

// --- Fingerprint tier follows the workspace setting -------------------------------
$fp_snapshot = wp_json_encode(
	array(
		'clientSignals' => array( 'webdriver' => false, 'canvasBlank' => false, 'fontCount' => 14, 'webglRenderer' => 'ANGLE (Test GPU)' ),
		'events'        => array( array( 'type' => 'click', 't' => 50, 'x' => 0.3, 'y' => 0.4 ) ),
	)
);
configure( array( 'login' => 'observe' ) );
Volance_Detection_Forms::init();
( new Volance_Detection_State() )->set_fingerprints( false );

reset_request();
$GLOBALS['mock']['fp'] = false;
$_POST = array( 'log' => 'vtest', 'pwd' => 'x', 'volance_snapshot' => wp_slash( $fp_snapshot ) );
Volance_Detection_Forms::guard( 'block', '', '' );
$GLOBALS['mock']['fp'] = false;
t( 'tier off: fingerprint fields are not sent', false === strpos( $GLOBALS['mock']['last_score_body'], 'fontCount' ) && false === strpos( $GLOBALS['mock']['last_score_body'], 'webglRenderer' ) && false !== strpos( $GLOBALS['mock']['last_score_body'], 'webdriver' ) );

reset_request();
$GLOBALS['mock']['fp'] = true;
$_POST = array( 'log' => 'vtest', 'pwd' => 'x', 'volance_snapshot' => wp_slash( $fp_snapshot ) );
Volance_Detection_Forms::guard( 'block', '', '' );
t( 'tier on: fingerprint fields are sent', false !== strpos( $GLOBALS['mock']['last_score_body'], '"fontCount":14' ) && false !== strpos( $GLOBALS['mock']['last_score_body'], 'ANGLE (Test GPU)' ) && false !== strpos( $GLOBALS['mock']['last_score_body'], 'canvasBlank' ) );
t( 'tier on: the flag is remembered for the page script', ( new Volance_Detection_State() )->fingerprints() );

update_option( Volance_Detection_Settings::OPTION, array_merge( Volance_Detection_Settings::get(), array( 'consent_mode' => 'always' ) ) );
ob_start();
$ref = new ReflectionMethod( 'Volance_Detection_Frontend', 'print_assets' );
$ref->setAccessible( true );
$ref->invoke( null );
$assets = ob_get_clean();
t( 'tier on: consent is stored under a separate key so visitors are asked again', false !== strpos( $assets, 'volanceDetectionConsentFp' ) );
t( 'tier on: page config tells the script to collect fingerprints and the notice says so', false !== strpos( $assets, '"collect":{"fingerprints":true}' ) && false !== strpos( $assets, 'graphics renderer' ) );

$GLOBALS['mock']['fp'] = false;
reset_request();
$_POST = array( 'log' => 'vtest', 'pwd' => 'x', 'volance_snapshot' => wp_slash( $fp_snapshot ) );
Volance_Detection_Forms::guard( 'block', '', '' );
t( 'tier switched off in the workspace: the cached flag corrects itself on the next score', ! ( new Volance_Detection_State() )->fingerprints() );
$GLOBALS['mock']['fp'] = false;

// --- Workspace enforcement off ----------------------------------------------------
configure( array( 'login' => 'block' ) );
Volance_Detection_Forms::init();
reset_request();
$GLOBALS['mock']['action']  = 'block';
$GLOBALS['mock']['enforce'] = false;
$_POST = array( 'log' => 'vtest', 'pwd' => 'Passw0rd!xyz123', 'volance_snapshot' => wp_slash( $good_snapshot ) );
$user = wp_authenticate( 'vtest', 'Passw0rd!xyz123' );
t( 'enforcement off: block mode does not block', $user instanceof WP_User );
unset( $GLOBALS['mock']['enforce'] );
$GLOBALS['mock']['action'] = 'allow';
echo 'WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . "\n";

// --- Protected paths ---------------------------------------------------------------
function configure_paths( $text, $on_no_evidence = 'score' ) {
	configure( array(), $on_no_evidence );
	$settings          = get_option( Volance_Detection_Settings::OPTION );
	$settings['paths'] = Volance_Detection_Settings::parse_paths( $text );
	update_option( Volance_Detection_Settings::OPTION, $settings );
}
function request_to( $uri, $method = 'POST' ) {
	reset_request();
	$_GET                      = array();
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['REQUEST_METHOD'] = $method;
}

configure_paths( "block /wp-json/myplugin/v1/*\nobserve /pricing/\n/contact/" );
$GLOBALS['mock']['action'] = 'block';

request_to( '/wp-json/myplugin/v1/orders?x=1' );
$d = Volance_Detection_Paths::decide();
t( 'path block: REST route blocked on request signals alone', $d && 'block' === $d['result']['applied'] && 2 === count( $GLOBALS['mock']['calls'] ) );

request_to( '/index.php' );
$_GET['rest_route'] = '/myplugin/v1/orders';
t( 'path: ?rest_route= is treated as /wp-json/…', '/wp-json/myplugin/v1/orders' === Volance_Detection_Paths::current_path() );
$d = Volance_Detection_Paths::decide();
t( 'path block: rest_route request blocked', $d && 'block' === $d['result']['applied'] );

request_to( '/somewhere-else/' );
t( 'path: unmatched URL is not scored', null === Volance_Detection_Paths::decide() && array() === $GLOBALS['mock']['calls'] );

request_to( '/wp-json/myplugin/v1/orders', 'OPTIONS' );
t( 'path: OPTIONS preflight is never scored', null === Volance_Detection_Paths::decide() );

request_to( '/pricing/' , 'GET' );
$d = Volance_Detection_Paths::decide();
t( 'path observe: recommends block but allows, no call before the response', $d && 'allow' === $d['result']['applied'] && array() === $GLOBALS['mock']['calls'] );

$_SERVER['REQUEST_URI'] = '/contact/';
reset_request();
$_SERVER['REQUEST_URI']    = '/contact/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$d = Volance_Detection_Paths::decide();
t( 'path: rule without a mode defaults to observe', $d && 'allow' === $d['result']['applied'] );

configure_paths( 'block /wp-json/myplugin/v1/*' );
request_to( '/wp-json/myplugin/v1/orders' );
$admin_user = get_user_by( 'login', 'vtest' );
$admin_user->set_role( 'administrator' );
wp_set_current_user( $admin_user->ID );
t( 'path: administrators are exempt', null === Volance_Detection_Paths::decide() && array() === $GLOBALS['mock']['calls'] );
wp_set_current_user( 0 );

configure_paths( 'block /wp-json/myplugin/v1/*' );
request_to( '/wp-json/myplugin/v1/orders' );
$GLOBALS['mock']['score_status'] = 500;
$d = Volance_Detection_Paths::decide();
t( 'path: Volance error fails open', $d && 'allow' === $d['result']['applied'] );
$GLOBALS['mock']['score_status'] = 200;

$path_rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE form = 'path' AND note <> '' ORDER BY id DESC LIMIT 5" );
t( 'path rows logged with the rule as the note', $path_rows && '/wp-json/myplugin/v1/*' === $path_rows[ count( $path_rows ) - 1 ]->note );

// --- Admin screens render ---------------------------------------------------------
configure( array( 'login' => 'block', 'comments' => 'flag', 'register' => 'observe' ) );
$admin = get_user_by( 'login', 'vtest' );
$admin->set_role( 'administrator' );
wp_set_current_user( $admin->ID );
set_current_screen( 'toplevel_page_volance-detection' );
foreach ( array( 'overview', 'activity', 'setup', 'plan', 'help' ) as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	try {
		Volance_Detection_Admin::render();
		$html = ob_get_clean();
		t( "admin tab '{$tab}' renders", strlen( $html ) > 200 );
		if ( 'setup' === $tab ) {
			t( 'setup shows mode selects and policy panel', false !== strpos( $html, 'name="volance_detection_settings[forms][login]"' ) && false !== strpos( $html, 'Workspace policy' ) && false !== strpos( $html, 'fixed by Volance' ) && false === strpos( $html, 'name="policy_allow"' ) && false !== strpos( $html, 'value="always"' ) );
			t( 'setup never prints the secret key', false === strpos( $html, 'sk_zyxwvuts98765432' ) );
		}
		if ( 'activity' === $tab ) {
			t( 'activity shows the Action column', false !== strpos( $html, 'Action' ) && false !== strpos( $html, 'Blocked' ) );
		}
	} catch ( \Throwable $e ) {
		ob_end_clean();
		t( "admin tab '{$tab}' renders (threw: " . $e->getMessage() . ')', false );
	}
}

// --- Front end config -------------------------------------------------------------
ob_start();
$front = new ReflectionMethod( 'Volance_Detection_Frontend', 'print_assets' );
$front->setAccessible( true );
$front->invoke( null );
$js = ob_get_clean();
t( 'front end prints loader config', false !== strpos( $js, 'volanceDetectionConfig' ) && false === strpos( $js, 'sk_' ) );

echo "\nINTEGRATION: {$passed} passed, {$failed} failed\n";
