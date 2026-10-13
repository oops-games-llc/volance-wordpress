<?php
/**
 * Form hooks. For each protected form the plugin prints one hidden field and,
 * on submission, scores the request with Volance.
 *
 * Each form has a mode:
 *  - observe: scored after the response has been sent and logged; never changes the submission.
 *  - flag:    scored before the request completes; a suspicious submission is held or marked.
 *  - block:   scored before the request completes; a submission Volance says to block is refused.
 *
 * Any failure (timeout, quota, error) allows the request.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protects login, registration, password reset, comment, WooCommerce and Contact Form 7 submissions.
 */
class Volance_Detection_Forms {

	/** Name of the hidden field the page script fills with the snapshot. */
	const FIELD = 'volance_snapshot';

	/**
	 * Forms already handled in this request, with their result.
	 *
	 * @var array
	 */
	private static $results = array();

	/**
	 * Register hooks for the protected forms.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			return;
		}
		foreach ( Volance_Detection_Settings::form_keys() as $form ) {
			if ( Volance_Detection_Settings::form_enabled( $form ) && Volance_Detection_Settings::form_available( $form ) ) {
				self::hook( $form );
			}
		}
	}

	/**
	 * Hook one form.
	 *
	 * @param string $form Form key.
	 * @return void
	 */
	private static function hook( $form ) {
		switch ( $form ) {
			case 'login':
				add_action( 'login_form', array( __CLASS__, 'print_field' ) );
				add_filter( 'authenticate', array( __CLASS__, 'check_login' ), 1 );
				add_filter( 'authenticate', array( __CLASS__, 'apply_login' ), 101 );
				break;
			case 'register':
				add_action( 'register_form', array( __CLASS__, 'print_field' ) );
				add_action( 'register_post', array( __CLASS__, 'check_register' ), 10, 0 );
				add_filter( 'registration_errors', array( __CLASS__, 'apply_register' ), 99 );
				break;
			case 'lostpassword':
				add_action( 'lostpassword_form', array( __CLASS__, 'print_field' ) );
				add_action( 'lostpassword_post', array( __CLASS__, 'handle_lostpassword' ), 10, 1 );
				break;
			case 'comments':
				add_action( 'comment_form_after_fields', array( __CLASS__, 'print_field' ) );
				add_action( 'comment_form_logged_in_after', array( __CLASS__, 'print_field' ) );
				add_action( 'pre_comment_on_post', array( __CLASS__, 'check_comment' ), 10, 0 );
				add_filter( 'pre_comment_approved', array( __CLASS__, 'apply_comment' ), 99 );
				break;
			case 'wc_login':
				add_action( 'woocommerce_login_form', array( __CLASS__, 'print_field' ) );
				add_filter( 'woocommerce_process_login_errors', array( __CLASS__, 'handle_wc_errors' ), 10, 1 );
				break;
			case 'wc_register':
				add_action( 'woocommerce_register_form', array( __CLASS__, 'print_field' ) );
				add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'handle_wc_register' ), 10, 1 );
				break;
			case 'wc_checkout':
				add_action( 'woocommerce_checkout_after_customer_details', array( __CLASS__, 'print_field' ) );
				add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'handle_wc_checkout' ), 10, 2 );
				break;
			case 'cf7':
				add_filter( 'wpcf7_form_hidden_fields', array( __CLASS__, 'cf7_field' ) );
				add_filter( 'wpcf7_spam', array( __CLASS__, 'handle_cf7' ), 10, 1 );
				break;
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
	 * Contact Form 7 hidden field.
	 *
	 * @param array $fields Hidden fields.
	 * @return array
	 */
	public static function cf7_field( $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		$fields[ self::FIELD ] = '';
		return $fields;
	}

	/**
	 * Authenticate (early): score the attempt. Never alters the user here.
	 *
	 * @param mixed $user User or error passed through unchanged.
	 * @return mixed
	 */
	public static function check_login( $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress core's login form has no nonce; this only reads a POST marker.
		if ( isset( $_POST['log'], $_POST['pwd'] ) ) {
			self::check( 'login' );
		}
		return $user;
	}

	/**
	 * Authenticate (late): refuse the attempt when it was blocked.
	 *
	 * @param mixed $user User or error.
	 * @return mixed
	 */
	public static function apply_login( $user ) {
		return self::is_blocked( 'login' ) ? self::error( 'login' ) : $user;
	}

	/**
	 * Registration submitted: score it.
	 *
	 * @return void
	 */
	public static function check_register() {
		self::check( 'register' );
	}

	/**
	 * Registration errors: refuse when blocked.
	 *
	 * @param WP_Error $errors Errors so far.
	 * @return WP_Error
	 */
	public static function apply_register( $errors ) {
		if ( self::is_blocked( 'register' ) && is_wp_error( $errors ) ) {
			$errors->add( 'volance_blocked', self::message( 'register' ) );
		}
		return $errors;
	}

	/**
	 * Password reset requested.
	 *
	 * @param WP_Error $errors Errors so far.
	 * @return void
	 */
	public static function handle_lostpassword( $errors ) {
		self::check( 'lostpassword' );
		if ( self::is_blocked( 'lostpassword' ) && is_wp_error( $errors ) ) {
			$errors->add( 'volance_blocked', self::message( 'lostpassword' ) );
		}
	}

	/**
	 * Comment submitted: score it.
	 *
	 * @return void
	 */
	public static function check_comment() {
		self::check( 'comments' );
	}

	/**
	 * Comment approval: hold a flagged comment, refuse a blocked one.
	 *
	 * @param mixed $approved 1, 0, 'spam' or WP_Error.
	 * @return mixed
	 */
	public static function apply_comment( $approved ) {
		if ( is_wp_error( $approved ) || 'spam' === $approved ) {
			return $approved;
		}
		if ( self::is_blocked( 'comments' ) ) {
			return new WP_Error( 'volance_blocked', self::message( 'comments' ), 403 );
		}
		$result = self::$results['comments'] ?? null;
		if ( $result && 'flag' === $result['applied'] ) {
			return 0; // Held for moderation.
		}
		return $approved;
	}

	/**
	 * WooCommerce login.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @return WP_Error
	 */
	public static function handle_wc_errors( $errors ) {
		self::check( 'wc_login' );
		if ( self::is_blocked( 'wc_login' ) && is_wp_error( $errors ) ) {
			$errors->add( 'volance_blocked', self::message( 'wc_login' ) );
		}
		return $errors;
	}

	/**
	 * WooCommerce registration.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @return WP_Error
	 */
	public static function handle_wc_register( $errors ) {
		self::check( 'wc_register' );
		if ( self::is_blocked( 'wc_register' ) && is_wp_error( $errors ) ) {
			$errors->add( 'volance_blocked', self::message( 'wc_register' ) );
		}
		return $errors;
	}

	/**
	 * WooCommerce checkout.
	 *
	 * @param array    $data   Posted data (unused).
	 * @param WP_Error $errors Validation errors.
	 * @return void
	 */
	public static function handle_wc_checkout( $data, $errors ) {
		unset( $data );
		self::check( 'wc_checkout' );
		if ( self::is_blocked( 'wc_checkout' ) && is_wp_error( $errors ) ) {
			$errors->add( 'volance_blocked', self::message( 'wc_checkout' ) );
		}
	}

	/**
	 * Contact Form 7: a blocked submission is reported as spam.
	 *
	 * @param bool $spam Spam verdict so far.
	 * @return bool
	 */
	public static function handle_cf7( $spam ) {
		if ( $spam ) {
			return $spam;
		}
		self::check( 'cf7' );
		return self::is_blocked( 'cf7' ) ? true : $spam;
	}

	/**
	 * Score a submission once per request and remember the result.
	 *
	 * @param string $form Form key.
	 * @return array Result (status, recommended, applied, score, label).
	 */
	private static function check( $form ) {
		if ( isset( self::$results[ $form ] ) ) {
			return self::$results[ $form ];
		}
		$mode   = Volance_Detection_Settings::form_mode( $form );
		$result = array(
			'status'      => 'skipped',
			'recommended' => 'allow',
			'applied'     => 'allow',
			'score'       => null,
			'label'       => '',
		);
		if ( 'off' === $mode ) {
			return $result;
		}
		self::$results[ $form ] = $result;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only; the JSON string is parsed and field-by-field validated by Volance_Detection_Evidence before any use.
		$raw  = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';
		$json = is_string( $raw ) ? $raw : '';

		$disclosure = Volance_Detection_Evidence::disclosure_headers();
		$score_empty = 'score' === Volance_Detection_Settings::on_no_evidence();

		// A direct POST that carries no snapshot only gets a log row, and only a capped number per hour.
		if ( '' === $json && ! $score_empty && ! $disclosure && ! self::allow_no_evidence_row() ) {
			return $result;
		}

		$options = array(
			'mode'                   => $mode,
			'score_without_evidence' => $score_empty,
			'disclosure'             => $disclosure,
		);
		$signals = Volance_Detection_Evidence::request_signals();

		if ( Volance_Detection_Decision::is_synchronous( $mode ) ) {
			self::$results[ $form ] = self::score_now( $form, $json, $signals, $options );
		} else {
			add_action(
				'shutdown',
				static function () use ( $form, $json, $signals, $options ) {
					self::flush( $form, $json, $signals, $options );
				},
				1000
			);
		}

		return self::$results[ $form ];
	}

	/**
	 * Score before the request completes. Fails open on anything.
	 *
	 * @param string $form    Form key.
	 * @param string $json    Raw snapshot.
	 * @param array  $signals Visitor request description.
	 * @param array  $options Scorer options.
	 * @return array Result.
	 */
	private static function score_now( $form, $json, array $signals, array $options ) {
		try {
			$result = self::scorer()->evaluate( $form, $json, $signals, $options );
		} catch ( \Throwable $error ) {
			unset( $error );
			return array(
				'status'      => 'error',
				'recommended' => 'allow',
				'applied'     => 'allow',
				'score'       => null,
				'label'       => '',
			);
		}
		/**
		 * Fires after a protected request is scored.
		 *
		 * @param string $form   Form key.
		 * @param array  $result status, recommended, applied, score, label.
		 */
		do_action( 'volance_detection_decision', $form, $result );
		if ( 'flag' === $result['applied'] ) {
			do_action( 'volance_detection_flagged', $form, $result );
		}
		if ( 'block' === $result['applied'] ) {
			do_action( 'volance_detection_blocked', $form, $result );
		}
		return $result;
	}

	/**
	 * Score after the visitor's response has been sent (observe mode).
	 *
	 * @param string $form    Form key.
	 * @param string $json    Raw snapshot.
	 * @param array  $signals Visitor request description.
	 * @param array  $options Scorer options.
	 * @return void
	 */
	private static function flush( $form, $json, array $signals, array $options ) {
		// Let the visitor's response finish first where the server supports it.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		self::score_now( $form, $json, $signals, $options );
	}

	/**
	 * Build a scorer wired to the plugin's client, log and state.
	 *
	 * @return Volance_Detection_Scorer
	 */
	private static function scorer() {
		$client = new Volance_Detection_Client( Volance_Detection_Settings::public_key(), Volance_Detection_Settings::secret_key() );
		return new Volance_Detection_Scorer( $client, array( 'Volance_Detection_Log', 'add' ), new Volance_Detection_State() );
	}

	/**
	 * Whether the plugin should refuse this form's request.
	 *
	 * @param string $form Form key.
	 * @return bool
	 */
	private static function is_blocked( $form ) {
		return isset( self::$results[ $form ] ) && 'block' === self::$results[ $form ]['applied'];
	}

	/**
	 * The message shown to a blocked visitor.
	 *
	 * @param string $form Form key.
	 * @return string
	 */
	private static function message( $form ) {
		$default = __( 'We could not verify this request. Please try again, or contact the site owner if this keeps happening.', 'volance-detection' );
		return (string) apply_filters( 'volance_detection_block_message', $default, $form, self::$results[ $form ] ?? array() );
	}

	/**
	 * A WP_Error for a blocked request.
	 *
	 * @param string $form Form key.
	 * @return WP_Error
	 */
	private static function error( $form ) {
		return new WP_Error( 'volance_blocked', self::message( $form ) );
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
