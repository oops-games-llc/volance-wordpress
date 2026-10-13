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

require __DIR__ . '/../includes/class-volance-detection-decision.php';
require __DIR__ . '/../includes/class-volance-detection-settings.php';
require __DIR__ . '/../includes/class-volance-detection-evidence.php';
require __DIR__ . '/../includes/class-volance-detection-client.php';
require __DIR__ . '/../includes/class-volance-detection-response.php';
require __DIR__ . '/../includes/class-volance-detection-stats.php';
require __DIR__ . '/../includes/class-volance-detection-export.php';
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
		'forms'      => array( 'login' => 'observe', 'comments' => '1', 'bogus' => 'block' ),
	)
);
check( 'valid keys saved', $pk === $out['public_key'] );
check( 'secret stored separately, not in settings array', ! isset( $out['secret_key'] ) && $sk === get_option( 'volance_detection_secret' ) );
check( 'enabled when keys valid', true === $out['enabled'] );
check( 'unknown form and invalid mode dropped', array( 'login' => 'observe' ) === $out['forms'] );

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

// Current collector shape (Volance PR #98): keys/prog already inside clientSignals.
$nested = Volance_Detection_Evidence::sanitize_snapshot(
	json_encode(
		array(
			'clientSignals' => array(
				'webdriver'    => false,
				'sessionDwell' => 5000,
				'keys'         => array( 'count' => 4, 'firstT' => 5, 'lastT' => 80, 'dwells' => array( 30, 'x', 40 ), 'flight' => array( 12 ) ),
				'prog'         => array( 'detailZeroClicks' => 2, 'evil' => 9 ),
			),
			'events'        => array( array( 'type' => 'click', 't' => 200, 'x' => 11, 'y' => 21 ) ),
		)
	)
);
check( 'nested keys kept and filtered', is_array( $nested ) && array( 30, 40 ) === $nested['clientSignals']['keys']['dwells'] && 4 === $nested['clientSignals']['keys']['count'] );
check( 'nested prog kept and filtered', array( 'detailZeroClicks' => 2 ) === $nested['clientSignals']['prog'] );

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
check( 'cookie presence is a boolean only, never a value', isset( $signals['cookiePresent'] ) && is_bool( $signals['cookiePresent'] ) && ! isset( $signals['page'] ) );
$bad_ip = Volance_Detection_Evidence::request_signals( array( 'REMOTE_ADDR' => 'not-an-ip' ) );
check( 'invalid IP omitted', ! isset( $bad_ip['visitorIp'] ) );
check( 'missing headers omitted', array( 'cookiePresent' => false ) === $bad_ip );

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

$rows   = array();
$client = ok_client();
$client->session_reply['data']['collect']['fingerprints'] = true;
list( $scorer ) = make_scorer( $rows, $client, new Fake_State() );
check( 'session reports fingerprints: still scored, no fingerprint fields sent', 'scored' === $scorer->run( 'login', $good, array() ) && ! isset( $client->last_payload['clientSignals']->canvasBlank ) );

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

// --- Decisions and enforcement ---------------------------------------------
check( 'decision: observe never acts', 'allow' === Volance_Detection_Decision::resolve( 'observe', 'block' ) );
check( 'decision: flag mode allows allow', 'allow' === Volance_Detection_Decision::resolve( 'flag', 'allow' ) );
check( 'decision: flag mode flags flag', 'flag' === Volance_Detection_Decision::resolve( 'flag', 'flag' ) );
check( 'decision: flag mode softens block to flag', 'flag' === Volance_Detection_Decision::resolve( 'flag', 'block' ) );
check( 'decision: block mode passes block through', 'block' === Volance_Detection_Decision::resolve( 'block', 'block' ) );
check( 'decision: block mode keeps flag as flag', 'flag' === Volance_Detection_Decision::resolve( 'block', 'flag' ) );
check( 'decision: junk action is allow', 'allow' === Volance_Detection_Decision::resolve( 'block', 'DROP TABLE' ) && 'allow' === Volance_Detection_Decision::resolve( 'block', null ) );
check( 'decision: flag and block are synchronous, observe is not', Volance_Detection_Decision::is_synchronous( 'flag' ) && Volance_Detection_Decision::is_synchronous( 'block' ) && ! Volance_Detection_Decision::is_synchronous( 'observe' ) );

function client_with_action( $action ) {
	$client = ok_client();
	$client->score_reply['data']['action'] = $action;
	return $client;
}

$rows = array();
list( $scorer ) = make_scorer( $rows, client_with_action( 'block' ) );
$res = $scorer->evaluate( 'login', $good, array(), array( 'mode' => 'block' ) );
check( 'evaluate block mode: applied block, recommended block', 'block' === $res['applied'] && 'block' === $res['recommended'] && 'scored' === $res['status'] );
check( 'evaluate logs recommended and applied action', 'block' === $rows[0]['action'] && 'block' === $rows[0]['applied'] );

$rows = array();
list( $scorer ) = make_scorer( $rows, client_with_action( 'block' ) );
$res = $scorer->evaluate( 'login', $good, array(), array( 'mode' => 'observe' ) );
check( 'evaluate observe mode: recommends block but applies allow', 'block' === $res['recommended'] && 'allow' === $res['applied'] );

$rows = array();
list( $scorer ) = make_scorer( $rows, client_with_action( 'flag' ) );
$res = $scorer->evaluate( 'comments', $good, array(), array( 'mode' => 'flag' ) );
check( 'evaluate flag mode: applied flag', 'flag' === $res['applied'] );

$rows = array();
$client = client_with_action( 'block' );
$client->session_reply = array( 'ok' => false, 'status' => 0, 'error' => 'down', 'retry_after' => 0, 'data' => array() );
list( $scorer ) = make_scorer( $rows, $client );
$res = $scorer->evaluate( 'login', $good, array(), array( 'mode' => 'block' ) );
check( 'API failure in block mode fails open', 'allow' === $res['applied'] && 'error' === $res['status'] );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, client_with_action( 'block' ) );
$res = $scorer->evaluate( 'login', '', array( 'visitorIp' => '203.0.113.9' ), array( 'mode' => 'block' ) );
check( 'no evidence, default policy: allowed, logged, nothing sent', 'allow' === $res['applied'] && 'no_evidence' === $res['status'] && array() === $client->calls );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, client_with_action( 'block' ) );
$res = $scorer->evaluate( 'login', '', array( 'visitorIp' => '203.0.113.9' ), array( 'mode' => 'block', 'score_without_evidence' => true ) );
check( 'no evidence, score policy: scored on request signals alone', 'block' === $res['applied'] && array( 'session', 'score' ) === $client->calls && array() === $client->last_payload['events'] );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, client_with_action( 'allow' ) );
$disc = array( 'signature-input' => 'sig1=("@authority")', 'signature' => 'sig1=:abc:', 'signature-agent' => 'https://agent.example', 'authority' => 'example.com' );
$res = $scorer->evaluate( 'login', '', array(), array( 'mode' => 'block', 'disclosure' => $disc ) );
check( 'signed agent headers: scored without a snapshot and forwarded', 'scored' === $res['status'] && (object) $disc == $client->last_payload['disclosureHeaders'] );

$rows = array();
$state = new Fake_State();
$state->fingerprints = true;
list( $scorer, $client ) = make_scorer( $rows, ok_client(), $state );
check( 'workspace fingerprint flag no longer stops scoring', 'scored' === $scorer->run( 'login', $good, array() ) );

$rows = array();
list( $scorer, $client ) = make_scorer( $rows, ok_client() );
$scorer->evaluate( 'login', $good, array(), array( 'page' => '/checkout' ) );
check( 'page option is forwarded', '/checkout' === $client->last_payload['page'] );

// --- Form modes and request signals ---------------------------------------
reset_world();
update_option( 'volance_detection_settings', array( 'forms' => array( 'login' => true, 'register' => 'block', 'comments' => 'flag', 'cf7' => 'observe', 'wc_login' => 'junk' ) ) );
check( 'legacy true reads as observe', 'observe' === Volance_Detection_Settings::form_mode( 'login' ) );
check( 'stored modes are read', 'block' === Volance_Detection_Settings::form_mode( 'register' ) && 'flag' === Volance_Detection_Settings::form_mode( 'comments' ) );
check( 'junk or missing mode is off', 'off' === Volance_Detection_Settings::form_mode( 'wc_login' ) && 'off' === Volance_Detection_Settings::form_mode( 'lostpassword' ) );
check( 'form_enabled follows the mode', Volance_Detection_Settings::form_enabled( 'register' ) && ! Volance_Detection_Settings::form_enabled( 'lostpassword' ) );
check( 'default no-evidence policy is allow', 'allow' === Volance_Detection_Settings::on_no_evidence() );

reset_world();
$pk  = 'pk_abcdefgh12345678';
$sk  = 'sk_zyxwvuts98765432';
$out = Volance_Detection_Settings::sanitize( array( 'public_key' => $pk, 'secret_key' => $sk, 'enabled' => '1', 'consent_mode' => 'always', 'on_no_evidence' => 'score', 'forms' => array( 'login' => 'block', 'comments' => 'nonsense', 'cf7' => 'flag' ) ) );
check( 'sanitize keeps valid modes and drops invalid ones', array( 'login' => 'block', 'cf7' => 'flag' ) === $out['forms'] );
check( 'sanitize accepts always-on consent and score policy', 'always' === $out['consent_mode'] && 'score' === $out['on_no_evidence'] );
$out = Volance_Detection_Settings::sanitize( array( 'consent_mode' => 'nope', 'on_no_evidence' => 'nope' ) );
check( 'sanitize falls back for unknown consent and policy values', 'banner' === $out['consent_mode'] && 'allow' === $out['on_no_evidence'] );

$server = array(
	'REMOTE_ADDR'            => '203.0.113.9',
	'HTTP_HOST'              => 'example.com',
	'HTTP_USER_AGENT'        => 'Mozilla/5.0',
	'HTTP_ACCEPT_LANGUAGE'   => 'en-US',
	'HTTP_X_FORWARDED_FOR'   => '198.51.100.1',
	'HTTP_COOKIE'            => 'secret=session-token',
	'HTTP_SIGNATURE_INPUT'   => 'sig1=("@authority");keyid="k"',
	'HTTP_SIGNATURE'         => 'sig1=:abc:',
	'HTTP_SIGNATURE_AGENT'   => 'https://agent.example',
);
$signals = Volance_Detection_Evidence::request_signals( $server );
check( 'request signals: header order in arrival order', array( 'host', 'user-agent', 'accept-language', 'x-forwarded-for', 'cookie', 'signature-input', 'signature', 'signature-agent' ) === $signals['headerOrder'] );
check( 'request signals: proxy headers named', array( 'x-forwarded-for' ) === $signals['proxyHeaders'] );
check( 'request signals: cookie presence is a boolean, value never sent', true === $signals['cookiePresent'] && false === strpos( json_encode( $signals ), 'session-token' ) );
$none = Volance_Detection_Evidence::request_signals( array( 'REMOTE_ADDR' => '203.0.113.9' ) );
check( 'request signals: no cookie gives false', false === $none['cookiePresent'] );
$disc = Volance_Detection_Evidence::disclosure_headers( $server );
check( 'disclosure headers collected with authority', 'sig1=:abc:' === $disc['signature'] && 'https://agent.example' === $disc['signature-agent'] && 'example.com' === $disc['authority'] );
check( 'no signature headers: no disclosure', array() === Volance_Detection_Evidence::disclosure_headers( array( 'HTTP_HOST' => 'example.com' ) ) );

// --- Client -----------------------------------------------------------------
reset_world();
$GLOBALS['vd_http_reply'] = array( 'code' => 200, 'body' => json_encode( array( 'enabled' => true ) ), 'headers' => array() );
$GLOBALS['vd_http'] = array();
$client = new Volance_Detection_Client( 'pk_abcdefgh12345678', 'sk_zyxwvuts98765432', 'https://api.example.test' );
$client->set_config( array( 'enabled' => true, 'thresholds' => array( 'allow' => 70, 'block' => 40 ) ) );
check( 'set_config POSTs to /api/trace/config with the secret key', 'POST' === $GLOBALS['vd_http'][0]['args']['method'] && false !== strpos( $GLOBALS['vd_http'][0]['url'], '/api/trace/config' ) && 'Bearer sk_zyxwvuts98765432' === $GLOBALS['vd_http'][0]['args']['headers']['Authorization'] );
$client->verify_token( 'tok_1' );
check( 'verify_token POSTs the trust token', false !== strpos( $GLOBALS['vd_http'][1]['url'], '/api/trace/verify-token' ) && false !== strpos( $GLOBALS['vd_http'][1]['args']['body'], 'tok_1' ) );

// --- Protected paths --------------------------------------------------------
$rules = Volance_Detection_Settings::parse_paths( "block /wp-json/myplugin/v1/*\nflag /pricing/\n/contact/\n# a comment\n\n  BLOCK   https://example.com/checkout?x=1#top  \nsubscribe\nobserve\t/a b" );
check( 'paths: modes parsed', 'block' === $rules[0]['mode'] && 'flag' === $rules[1]['mode'] && 'observe' === $rules[2]['mode'] );
check( 'paths: comments and blanks skipped', 6 === count( $rules ) );
check( 'paths: URL host, query and fragment stripped', '/checkout' === $rules[3]['pattern'] && 'block' === $rules[3]['mode'] );
check( 'paths: leading slash added', '/subscribe' === $rules[4]['pattern'] );
check( 'paths: whitespace inside a path removed', '/ab' === $rules[5]['pattern'] );
check( 'paths: empty text gives no rules', array() === Volance_Detection_Settings::parse_paths( "  \n#x" ) );
$many = implode( "\n", array_map( static function ( $i ) { return "/p{$i}"; }, range( 1, 80 ) ) );
check( 'paths: capped at 50 rules', 50 === count( Volance_Detection_Settings::parse_paths( $many ) ) );

$rules = Volance_Detection_Settings::parse_paths( "block /wp-json/myplugin/v1/*\nflag /pricing/\n/contact/" );
$m = Volance_Detection_Settings::match_path( '/wp-json/myplugin/v1/orders/12', $rules );
check( 'match: wildcard', $m && 'block' === $m['mode'] );
$m = Volance_Detection_Settings::match_path( '/pricing', $rules );
check( 'match: trailing slash ignored', $m && 'flag' === $m['mode'] );
$m = Volance_Detection_Settings::match_path( '/CONTACT/', $rules );
check( 'match: case ignored', $m && 'observe' === $m['mode'] );
check( 'match: no partial matches', null === Volance_Detection_Settings::match_path( '/pricing/enterprise', $rules ) && null === Volance_Detection_Settings::match_path( '/contact-us', $rules ) && null === Volance_Detection_Settings::match_path( '/x/wp-json/myplugin/v1/a', $rules ) );
check( 'match: regex characters in a rule are literal', null === Volance_Detection_Settings::match_path( '/aXb', Volance_Detection_Settings::parse_paths( '/a.b' ) ) );
check( 'match: no rules, no match', null === Volance_Detection_Settings::match_path( '/', array() ) );

reset_world();
$out = Volance_Detection_Settings::sanitize( array( 'paths_text' => "flag /pricing/\njunk" ) );
check( 'sanitize saves path rules', 2 === count( $out['paths'] ) && 'flag' === $out['paths'][0]['mode'] );
update_option( 'volance_detection_settings', $out );
check( 'paths_text round-trips', "flag /pricing/\nobserve /junk" === Volance_Detection_Settings::paths_text() );
$out = Volance_Detection_Settings::sanitize( array( 'enabled' => '' ) );
check( 'sanitize keeps saved rules when the field is absent', 2 === count( $out['paths'] ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
