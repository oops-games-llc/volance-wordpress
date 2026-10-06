<?php
/**
 * Form hooks. For each enabled form the plugin prints one hidden field and, on
 * submission, queues one score that runs after the response has been sent.
 *
 * The plugin never blocks, redirects or changes a submission.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Observes login, registration and comment submissions.
 */
class Volance_Detection_Forms {

	/** Name of the hidden field the page script fills with the snapshot. */
	const FIELD = 'volance_snapshot';

	/**
	 * Forms already queued in this request.
	 *
	 * @var array
	 */
	private static $queued = array();

	/**
	 * Register hooks for the enabled forms.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			return;
		}
		if ( Volance_Detection_Settings::form_enabled( 'login' ) ) {
			add_action( 'login_form', array( __CLASS__, 'print_field' ) );
			add_filter( 'authenticate', array( __CLASS__, 'observe_login' ), 1 );
		}
		if ( Volance_Detection_Settings::form_enabled( 'register' ) ) {
			add_action( 'register_form', array( __CLASS__, 'print_field' ) );
			add_action( 'register_post', array( __CLASS__, 'observe_register' ), 10, 0 );
		}
		if ( Volance_Detection_Settings::form_enabled( 'comments' ) ) {
			add_action( 'comment_form_after_fields', array( __CLASS__, 'print_field' ) );
			add_action( 'comment_form_logged_in_after', array( __CLASS__, 'print_field' ) );
			add_action( 'pre_comment_on_post', array( __CLASS__, 'observe_comment' ), 10, 0 );
		}
	}

	/**
	 * Print the hidden field. It is empty until the visitor consents and the
	 * page script fills it on submit.
	 *
	 * @return void
	 */
	public static function print_field() {
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="" data-volance-snapshot="1" />';
	}

	/**
	 * Authenticate filter: observe, never alter.
	 *
	 * @param mixed $user User or error passed through unchanged.
	 * @return mixed
	 */
	public static function observe_login( $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress core's login form has no nonce; this only reads a POST marker and changes nothing.
		if ( isset( $_POST['log'], $_POST['pwd'] ) ) {
			self::queue( 'login' );
		}
		return $user;
	}

	/**
	 * Registration submitted.
	 *
	 * @return void
	 */
	public static function observe_register() {
		self::queue( 'register' );
	}

	/**
	 * Comment submitted.
	 *
	 * @return void
	 */
	public static function observe_comment() {
		self::queue( 'comments' );
	}

	/**
	 * Capture what is needed now and schedule the score for shutdown.
	 *
	 * @param string $form Form key.
	 * @return void
	 */
	private static function queue( $form ) {
		if ( isset( self::$queued[ $form ] ) ) {
			return;
		}
		self::$queued[ $form ] = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; the value is validated by Volance_Detection_Evidence before any use.
		$raw  = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';
		$json = is_string( $raw ) ? $raw : '';

		if ( '' === $json && ! self::allow_no_evidence_row() ) {
			return;
		}

		$signals = Volance_Detection_Evidence::request_signals();
		add_action(
			'shutdown',
			static function () use ( $form, $json, $signals ) {
				self::flush( $form, $json, $signals );
			},
			1000
		);
	}

	/**
	 * Run the score. Fails open on anything.
	 *
	 * @param string $form    Form key.
	 * @param string $json    Raw snapshot.
	 * @param array  $signals Visitor request description.
	 * @return void
	 */
	private static function flush( $form, $json, array $signals ) {
		// Let the visitor's response finish first where the server supports it.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		try {
			$client = new Volance_Detection_Client( Volance_Detection_Settings::public_key(), Volance_Detection_Settings::secret_key() );
			$scorer = new Volance_Detection_Scorer( $client, array( 'Volance_Detection_Log', 'add' ), new Volance_Detection_State() );
			$scorer->run( $form, $json, $signals );
		} catch ( \Throwable $error ) {
			unset( $error ); // Observe-only: a failure here must never reach the visitor.
		}
	}

	/**
	 * Cap how many "no evidence" rows can be written per hour so direct POSTs
	 * (which carry no snapshot) cannot flood the log.
	 *
	 * @return bool
	 */
	private static function allow_no_evidence_row() {
		$key   = 'volance_detection_noev';
		$count = (int) get_transient( $key );
		$cap   = (int) apply_filters( 'volance_detection_no_evidence_per_hour', 100 );
		if ( $count >= $cap ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
