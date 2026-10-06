<?php
/**
 * HTTP client for the Volance API. Every call is short, never retried, and
 * returns a result array instead of throwing, so callers can always fail open.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Volance API client.
 */
class Volance_Detection_Client {

	/**
	 * Public key.
	 *
	 * @var string
	 */
	private $public_key;

	/**
	 * Secret key. Used only in the Authorization header of server-side calls.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Constructor.
	 *
	 * @param string      $public_key Public key (pk_).
	 * @param string      $secret_key Secret key (sk_).
	 * @param string|null $base       API base URL.
	 */
	public function __construct( $public_key, $secret_key, $base = null ) {
		$this->public_key = (string) $public_key;
		$this->secret_key = (string) $secret_key;
		$this->base       = rtrim( null === $base ? VOLANCE_DETECTION_API_BASE : $base, '/' );
	}

	/**
	 * Start a session (public key).
	 *
	 * @return array Result.
	 */
	public function session() {
		return $this->request( 'GET', '/api/trace/session', $this->public_key );
	}

	/**
	 * Read workspace config (secret key). Cheap, not metered: used to test keys.
	 *
	 * @return array Result.
	 */
	public function config() {
		return $this->request( 'GET', '/api/trace/config', $this->secret_key );
	}

	/**
	 * Read plan and monthly usage (secret key).
	 *
	 * @return array Result.
	 */
	public function usage() {
		return $this->request( 'GET', '/api/trace/usage', $this->secret_key );
	}

	/**
	 * Score one submission (secret key). Metered by Volance.
	 *
	 * @param array $payload Score request body.
	 * @return array Result.
	 */
	public function score( array $payload ) {
		$payload['publicKey'] = $this->public_key;
		return $this->request( 'POST', '/api/trace/score', $this->secret_key, $payload );
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with /.
	 * @param string     $token  Bearer token.
	 * @param array|null $body   JSON body.
	 * @return array {ok:bool,status:int,data:array,error:string,retry_after:int}
	 */
	private function request( $method, $path, $token, $body = null ) {
		$result = array(
			'ok'          => false,
			'status'      => 0,
			'data'        => array(),
			'error'       => '',
			'retry_after' => 0,
		);

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'User-Agent'    => 'VolanceDetection-WP/' . VOLANCE_DETECTION_VERSION,
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => (float) apply_filters( 'volance_detection_timeout', 3 ),
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $response ) ) {
			$result['error'] = __( 'Volance could not be reached.', 'volance-detection' );
			return $result;
		}

		$status           = (int) wp_remote_retrieve_response_code( $response );
		$result['status'] = $status;
		$decoded          = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data             = is_array( $decoded ) ? $decoded : array();

		if ( $status >= 200 && $status < 300 ) {
			$result['ok']   = true;
			$result['data'] = $data;
			return $result;
		}

		$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		if ( 429 === $status ) {
			$result['retry_after'] = max( 1, min( 300, $retry > 0 ? $retry : 60 ) );
		}
		$result['error'] = self::error_text( $status, $data );
		return $result;
	}

	/**
	 * Short, key-free error text.
	 *
	 * @param int   $status HTTP status.
	 * @param array $data   Decoded body.
	 * @return string
	 */
	private static function error_text( $status, array $data ) {
		if ( 401 === $status || 403 === $status ) {
			return __( 'Volance did not accept this key.', 'volance-detection' );
		}
		if ( 429 === $status ) {
			$message = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
			return '' !== $message ? substr( $message, 0, 190 ) : __( 'Volance rate limit or monthly quota reached.', 'volance-detection' );
		}
		if ( $status >= 500 ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Volance had a problem (HTTP %d).', 'volance-detection' ), $status );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Volance rejected the request (HTTP %d).', 'volance-detection' ), $status );
	}
}
