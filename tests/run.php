<?php
/**
 * Dependency-free tests for the plugin's WordPress-independent logic.
 *
 * Run: php tests/run.php
 *
 * WordPress functions used by the classes under test are replaced with small
 * in-memory shims, so no WordPress install is needed.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'VOLANCE_DETECTION_VERSION', '0.0.0-test' );
define( 'VOLANCE_DETECTION_API_BASE', 'https://api.example.test' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['vd_options']    = array();
$GLOBALS['vd_transients'] = array();
$GLOBALS['vd_errors']     = array();
$GLOBALS['vd_http']       = array();
$GLOBALS['vd_http_reply'] = null;

function __( $text ) { return $text; }
function _n( $single, $plural, $number ) { return 1 === $number ? $single : $plural; }
function apply_filters( $hook, $value ) { return $value; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['vd_options'] ) ? $GLOBALS['vd_options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['vd_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['vd_options'][ $name ] ); return true; }
function get_transient( $name ) { return $GLOBALS['vd_transients'][ $name ] ?? false; }
function set_transient( $name, $value ) { $GLOBALS['vd_transients'][ $name ] = $value; return true; }
function add_settings_error( $setting, $code, $message ) { $GLOBALS['vd_errors'][] = $code; }

class WP_Error {
	public function get_error_message() { return 'boom'; }
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['vd_http'][] = array( 'url' => $url, 'args' => $args );
	$reply = $GLOBALS['vd_http_reply'];
	if ( is_callable( $reply ) ) {
		return $reply( $url, $args );
	}
	return $reply;
}
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }

require __DIR__ . '/../includes/class-volance-detection-settings.php';
require __DIR__ . '/../includes/class-volance-detection-evidence.php';
require __DIR__ . '/../includes/class-volance-detection-client.php';
require __DIR__ . '/../includes/class-volance-detection-scorer.php';

$passed = 0;
$failed = 0;

function check( $label, $condition ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		return;
	}
	++$failed;
	echo "FAIL: {$label}\n";
}

function reset_world() {
	$GLOBALS['vd_options']    = array();
	$GLOBALS['vd_transients'] = array();
	$GLOBALS['vd_errors']     = array();
	$GLOBALS['vd_http']       = array();
	$GLOBALS['vd_http_reply'] = null;
}

$pk = 'pk_' . str_repeat( 'A', 40 );
$sk = 'sk_' . str_repeat( 'B', 40 );

// --- Keys -------------------------------------------------------------------
check( 'valid pair accepted', '' === Volance_Detection_Settings::keys_problem( $pk, $sk ) );
check( 'legacy pair (same body) rejected', 'legacy_pair' === Volance_Detection_Settings::keys_problem( $pk, 'sk_' . substr( $pk, 3 ) ) );
check( 'bad public format rejected', 'format_public' === Volance_Detection_Settings::keys_problem( 'xx_123', $sk ) );
check( 'bad secret format rejected', 'format_secret' === Volance_Detection_Settings::keys_problem( $pk, 'pk_' . str_repeat( 'B', 40 ) ) );
check( 'empty secret rejected', 'format_secret' === Volance_Detection_Settings::keys_problem( $pk, '' ) );

// --- Settings::sanitize -----------------------------------------------------
reset_world();
$out = Volance_Detection_Settings::sanitize(
	array(
		'public_key' => $pk,
		'secret_key' => $sk,
		'enabled'    => '1',
		'forms'      => array( 'login' => '1', 'bogus' => '1' ),
	)
);
check( 'valid keys saved', $pk === $out['public_key'] );
check( 'secret stored separately, not in settings array', ! isset( $out['secret_key'] ) && $sk === get_option( 'volance_detection_secret' ) );
check( 'enabled when keys valid', true === $out['enabled'] );
check( 'unknown form dropped', array( 'login' => true ) === $out['forms'] );

update_option( 'volance_detection_settings', $out );
$out2 = Volance_Detection_Settings::sanitize( array( 'public_key' => $pk, 'secret_key' => '', 'enabled' => '1' ) );
check( 'blank secret keeps the saved one', $sk === get_option( 'volance_detection_secret' ) && true === $out2['enabled'] );

reset_world();
$out3 = Volance_Detection_Settings::sanitize( array( 'public_key' => $pk, 'secret_key' => 'sk_' . substr( $pk, 3 ), 'enabled' => '1' ) );
check( 'legacy pair is not saved', '' === $out3['public_key'] && false === get_option( 'volance_detection_secret', false ) );
check( 'legacy pair reports an error and stays off', in_array( 'volance_keys', $GLOBALS['vd_errors'], true ) && false === $out3['enabled'] );

reset_world();
$out4 = Volance_Detection_Settings::sanitize( array( 'enabled' => '1' ) );
check( 'cannot enable without keys', false === $out4['enabled'] );

// --- Evidence::sanitize_snapshot -------------------------------------------
$good = json_encode(
	array(
		'clientSignals' => array(
			'webdriver'     => false,
			'innerWidth'    => 1280,
			'platform'      => 'Win32',
			'canvasBlank'   => false,
			'fontCount'     => 12,
			'webglRenderer' => 'ANGLE',
			'unknownField'  => 'x',
			'sessionDwell'  => 5000,
		),
		'events'        => array(
			array( 'type' => 'mousemove', 't' => 100, 'x' => 10, 'y' => 20, 'pressure' => 0.5 ),
			array( 'type' => 'click', 't' => 200, 'x' => 11, 'y' => 21 ),
			array( 'type' => 'bogus', 't' => 300 ),
		),
		'keys'          => array( 'count' => 3, 'firstT' => 10, 'lastT' => 90, 'dwells' => array( 1, 2, 'x' ), 'flight' => array( 5 ) ),
		'prog'          => array( 'paste' => 1, 'evil' => 9 ),
	)
);
$clean = Volance_Detection_Evidence::sanitize_snapshot( $good );
check( 'valid snapshot parsed', is_array( $clean ) );
check( 'unknown event types skipped', 2 === count( $clean['events'] ) );
check( 'only allowlisted event fields kept', array( 'type', 't', 'x', 'y' ) === array_keys( $clean['events'][0] ) );
check( 'fingerprint-tier fields dropped', ! isset( $clean['clientSignals']['canvasBlank'], $clean['clientSignals']['fontCount'], $clean['clientSignals']['webglRenderer'] ) );
check( 'unknown clientSignals dropped', ! isset( $clean['clientSignals']['unknownField'] ) );
check( 'allowlisted clientSignals kept', 1280 === $clean['clientSignals']['innerWidth'] && 'Win32' === $clean['clientSignals']['platform'] && false === $clean['clientSignals']['webdriver'] );
check( 'keys nested under clientSignals, bad dwell dropped', array( 1, 2 ) === $clean['clientSignals']['keys']['dwells'] && 3 === $clean['clientSignals']['keys']['count'] );
check( 'prog nested and filtered', array( 'paste' => 1 ) === $clean['clientSignals']['prog'] );

check( 'invalid JSON rejected', null === Volance_Detection_Evidence::sanitize_snapshot( '{nope' ) );
check( 'non-string rejected', null === Volance_Detection_Evidence::sanitize_snapshot( array() ) );
check( 'empty string rejected', null === Volance_Detection_Evidence::sanitize_snapshot( '' ) );
check( 'oversized snapshot rejected', null === Volance_Detection_Evidence::sanitize_snapshot( str_repeat( 'a', 70000 ) ) );
check(
	'content field in an event rejects the snapshot',
	null === Volance_Detection_Evidence::sanitize_snapshot( json_encode( array( 'events' => array( array( 'type' => 'keydown', 't' => 1, 'key' => 'a' ) ) ) ) )
);
check(
	'content field in clientSignals rejects the snapshot',
	null === Volance_Detection_Evidence::sanitize_snapshot( json_encode( array( 'clientSignals' => array( 'value' => 'secret' ), 'events' => array() ) ) )
);
$clamped = Volance_Detection_Evidence::sanitize_snapshot( json_encode( array( 'events' => array( array( 'type' => 'click', 't' => -5, 'x' => 99999999 ) ) ) ) );
check( 'out-of-range numbers dropped', array( 'type' => 'click' ) === $clamped['events'][0] );
$many = array();
for ( $i = 0; $i < 400; $i++ ) {
	$many[] = array( 'type' => 'mousemove', 't' => $i );
}
$capped = Volance_Detection_Evidence::sanitize_snapshot( json_encode( array( 'events' => $many ) ) );
check( 'events capped at 256', 256 === count( $capped['events'] ) );

// --- Evidence::request_signals ---------------------------------------------
$signals = Volance_Detection_Evidence::request_signals(
	array(
		'REMOTE_ADDR'          => '203.0.113.9',
		'HTTP_USER_AGENT'      => "Mozilla/5.0 \x01 (X11)",
		'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
		'HTTP_SEC_CH_UA'       => '"Chromium";v="124", "Not-A.Brand";v="99"',
		'HTTP_SEC_FETCH_SITE'  => 'Same-Origin',
		'HTTP_SEC_FETCH_MODE'  => 'bad value!',
	)
);
check( 'visitor IP passed', '203.0.113.9' === $signals['visitorIp'] );
check( 'control characters stripped from UA', 'Mozilla/5.0  (X11)' === $signals['visitorUserAgent'] );
check( 'accept-language passed', 'en-US,en;q=0.9' === $signals['acceptLanguage'] );
check( 'Sec-CH-UA split per brand', array( '"Chromium";v="124"', '"Not-A.Brand";v="99"' ) === $signals['secChUa'] );
check( 'Sec-Fetch token lowercased', 'same-origin' === $signals['secFetchSite'] );
check( 'invalid Sec-Fetch token omitted', ! isset( $signals['secFetchMode'] ) );
check( 'no cookie or page fields sent', ! isset( $signals['cookiePresent'] ) && ! isset( $signals['page'] ) );
$bad_ip = Volance_Detection_Evidence::request_signals( array( 'REMOTE_ADDR' => 'not-an-ip' ) );
check( 'invalid IP omitted', ! isset( $bad_ip['visitorIp'] ) );
check( 'missing headers omitted', array() === $bad_ip );

// --- Client -----------------------------------------------------------------
reset_world();
$client                   = new Volance_Detection_Client( $pk, $sk );
$GLOBALS['vd_http_reply'] = array( 'code' => 200, 'body' => json_encode( array( 'sessionId' => 'sid_1', 'manifest' => 'm', 'collect' => array( 'fingerprints' => false ) ) ), 'headers' => array() );
$res                      = $client->session();
check( 'session ok', $res['ok'] && 'sid_1' === $res['data']['sessionId'] );
$call = $GLOBALS['vd_http'][0];
check( 'session is GET with public key as bearer', 'GET' === $call['args']['method'] && 'Bearer ' . $pk === $call['args']['headers']['Authorization'] );
check( 'session URL is correct', 'https://api.example.test/api/trace/session' === $call['url'] );
check( 'no redirects and a short timeout', 0 === $call['args']['redirection'] && $call['args']['timeout'] <= 3.0 );

$client->score( array( 'sid' => 'sid_1' ) );
$call = $GLOBALS['vd_http'][1];
check( 'score is POST with secret key as bearer', 'POST' === $call['args']['method'] && 'Bearer ' . $sk === $call['args']['headers']['Authorization'] );
check( 'score body carries publicKey', $pk === json_decode( $call['args']['body'], true )['publicKey'] );
check( 'relay headers do not pose as the visitor', ! isset( $call['args']['headers']['Accept-Language'], $call['args']['headers']['Sec-CH-UA'] ) );

$client->config();
$client->usage();
check( 'config and usage use the secret key', 'Bearer ' . $sk === $GLOBALS['vd_http'][2]['args']['headers']['Authorization'] && 'Bearer ' . $sk === $GLOBALS['vd_http'][3]['args']['headers']['Authorization'] );
check( 'config and usage paths', str_ends_with( $GLOBALS['vd_http'][2]['url'], '/api/trace/config' ) && str_ends_with( $GLOBALS['vd_http'][3]['url'], '/api/trace/usage' ) );

$GLOBALS['vd_http_reply'] = array( 'code' => 429, 'body' => json_encode( array( 'error' => 'Slow down.' ) ), 'headers' => array( 'retry-after' => '60' ) );
$limited                  = $client->score( array() );
check( '429 parsed with retry-after', ! $limited['ok'] && 429 === $limited['status'] && 60 === $limited['retry_after'] );
$GLOBALS['vd_http_reply'] = array( 'code' => 401, 'body' => '{}', 'headers' => array() );
$denied                   = $client->config();
check( '401 gives a key-free message', ! $denied['ok'] && false === strpos( $denied['error'], 'sk_' ) && false === strpos( $denied['error'], 'pk_' ) );
$GLOBALS['vd_http_reply'] = new WP_Error();
$broken                   = $client->session();
check( 'transport error fails soft', ! $broken['ok'] && 0 === $broken['status'] && false === strpos( $broken['error'], 'https://' ) );
$GLOBALS['vd_http_reply'] = array( 'code' => 200, 'body' => 'not json', 'headers' => array() );
check( 'non-JSON 200 still returns without throwing', $client->session()['ok'] && array() === $client->session()['data'] );

// --- Scorer -----------------------------------------------------------------
class Fake_Client {
	public $calls = array();
	public $session_reply;
	public $score_reply;
	public function session() { $this->calls[] = 'session'; return $this->session_reply; }
	public function score( array $payload ) { $this->calls[] = 'score'; $this->last_payload = $payload; return $this->score_reply; }
	public $last_payload;
}
class Fake_State {
	public $fingerprints = false;
	public $backoff = 0;
	public $backoff_set = 0;
	public $error = null;
	public function fingerprints() { return $this->fingerprints; }
	public function set_fingerprints( $on ) { $this->fingerprints = (bool) $on; }
	public function backoff_until() { return $this->backoff; }
	public function set_backoff( $seconds ) { $this->backoff_set = $seconds; }
	public function set_last_error( $message ) { $this->error = $message; }
}
function make_scorer( &$rows, $client = null, $state = null ) {
	$client = $client ?? new Fake_Client();
	$state  = $state ?? new Fake_State();
	$logger = function ( $row ) use ( &$rows ) {
		$rows[] = $row;
	};
	return array( new Volance_Detection_Scorer( $client, $logger, $state ), $client, $state );
}
function ok_client() {
	$client                = new Fake_Client();
	$client->session_reply = array( 'ok' => true, 'status' => 200, 'error' => '', 'retry_after' => 0, 'data' => array( 'sessionId' => 'sid_9', 'manifest' => 'man', 'collect' => array( 'fingerprints' => false ) ) );
	$client->score_reply   = array( 'ok' => true, 'status' => 200, 'error' => '', 'retry_after' => 0, 'data' => array( 'score' => 91.26, 'verdict' => 'human', 'classification' => array( 'label' => 'human' ), 'requestId' => 'req_1', 'evidence' => array( 'events' => 2, 'behavioural' => true ) ) );
	return $client;
}

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, ok_client() );
check( 'no snapshot: no_evidence', 'no_evidence' === $scorer->run( 'login', '', array() ) );
check( 'no snapshot: nothing sent to Volance', array() === $client->calls );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, ok_client() );
check( 'content snapshot: rejected', 'rejected' === $scorer->run( 'login', json_encode( array( 'events' => array( array( 'type' => 'keydown', 'key' => 'a' ) ) ) ), array() ) );
check( 'content snapshot: nothing sent', array() === $client->calls );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, ok_client() );
check( 'consent but zero events: no_evidence', 'no_evidence' === $scorer->run( 'login', json_encode( array( 'clientSignals' => array( 'webdriver' => false ), 'events' => array() ) ), array() ) );
check( 'zero events: nothing sent', array() === $client->calls );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, ok_client() );
$vis = array( 'visitorIp' => '203.0.113.9', 'visitorUserAgent' => 'Mozilla/5.0' );
check( 'valid snapshot: scored', 'scored' === $scorer->run( 'comments', $good, $vis ) );
check( 'session fetched, then scored', array( 'session', 'score' ) === $client->calls );
check( 'payload has sid and manifest from the session', 'sid_9' === $client->last_payload['sid'] && 'man' === $client->last_payload['manifest'] );
check( 'payload carries visitor request signals', (object) $vis == $client->last_payload['requestSignals'] );
check( 'payload has no page field', ! array_key_exists( 'page', $client->last_payload ) );
check( 'payload has no fingerprint fields', ! isset( $client->last_payload['clientSignals']->canvasBlank ) );
check( 'log row carries result', 91.26 === $rows[0]['score'] && 'human' === $rows[0]['verdict'] && 'comments' === $rows[0]['form'] && 'req_1' === $rows[0]['request_id'] && true === $rows[0]['behavioural'] );
check( 'log row stores no IP, UA or form content', ! array_key_exists( 'visitorIp', $rows[0] ) && false === strpos( json_encode( $rows[0] ), '203.0.113.9' ) );

$rows  = array();
$state = new Fake_State();
$state->fingerprints = true;
list( $scorer, $client ) = make_scorer( $rows, ok_client(), $state );
check( 'fingerprint flag on: skipped without any call', 'skipped' === $scorer->run( 'login', $good, array() ) && array() === $client->calls );

$rows   = array();
$client = ok_client();
$client->session_reply['data']['collect']['fingerprints'] = true;
$state  = new Fake_State();
list( $scorer ) = make_scorer( $rows, $client, $state );
check( 'session reports fingerprints: skipped, flag recorded, never scored', 'skipped' === $scorer->run( 'login', $good, array() ) && true === $state->fingerprints && array( 'session' ) === $client->calls );

$rows   = array();
$client = ok_client();
$client->score_reply = array( 'ok' => false, 'status' => 429, 'error' => 'Slow down.', 'retry_after' => 60, 'data' => array() );
$state  = new Fake_State();
list( $scorer ) = make_scorer( $rows, $client, $state );
check( '429 on score: limited and back-off set', 'limited' === $scorer->run( 'login', $good, array() ) && 60 === $state->backoff_set && 'Slow down.' === $state->error );

$rows   = array();
$state  = new Fake_State();
$state->backoff = time() + 30;
list( $scorer, $client ) = make_scorer( $rows, ok_client(), $state );
check( 'during back-off: no calls', 'limited' === $scorer->run( 'login', $good, array() ) && array() === $client->calls );

$rows   = array();
$client = ok_client();
$client->session_reply = array( 'ok' => false, 'status' => 0, 'error' => 'Volance could not be reached.', 'retry_after' => 0, 'data' => array() );
list( $scorer ) = make_scorer( $rows, $client );
check( 'session failure fails open as error', 'error' === $scorer->run( 'login', $good, array() ) && array( 'session' ) === $client->calls );

$rows   = array();
$client = ok_client();
$client->session_reply['data'] = array( 'collect' => array() );
list( $scorer ) = make_scorer( $rows, $client );
check( 'malformed session: error, never scored', 'error' === $scorer->run( 'login', $good, array() ) && array( 'session' ) === $client->calls );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
