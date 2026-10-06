<?php
/**
 * Front-end loader: shows the consent banner and loads the Volance collector
 * only after the visitor allows it.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the consent loader on the pages that have an observed form.
 */
class Volance_Detection_Frontend {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );

		if ( ! Volance_Detection_Settings::is_ready() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_front' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
		add_action( 'login_footer', array( __CLASS__, 'print_login' ) );
	}

	/**
	 * Whether the Volance workspace allows loading (fingerprint tier is off).
	 *
	 * @return bool
	 */
	private static function allowed() {
		$state = new Volance_Detection_State();
		return ! $state->fingerprints();
	}

	/**
	 * Banner styles (only needed when the built-in banner is used).
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		$settings = Volance_Detection_Settings::get();
		if ( 'banner' !== $settings['consent_mode'] || ! self::allowed() ) {
			return;
		}
		wp_enqueue_style( 'volance-detection', VOLANCE_DETECTION_URL . 'assets/css/banner.css', array(), VOLANCE_DETECTION_VERSION );
	}

	/**
	 * Front-end pages: only singular pages whose comment form is observed.
	 *
	 * @return void
	 */
	public static function print_front() {
		if ( ! Volance_Detection_Settings::form_enabled( 'comments' ) || ! is_singular() || ! comments_open() ) {
			return;
		}
		self::print_assets();
	}

	/**
	 * Login screen: the login and registration forms only, never other actions.
	 *
	 * @return void
	 */
	public static function print_login() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which core screen is showing; changes nothing.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( 'login' === $action && Volance_Detection_Settings::form_enabled( 'login' ) ) {
			self::print_assets();
		} elseif ( 'register' === $action && Volance_Detection_Settings::form_enabled( 'register' ) ) {
			self::print_assets();
		}
	}

	/**
	 * Print the config and the module script.
	 *
	 * @return void
	 */
	private static function print_assets() {
		if ( ! self::allowed() ) {
			return;
		}
		$settings = Volance_Detection_Settings::get();
		$config   = array(
			'collector'      => VOLANCE_DETECTION_COLLECTOR,
			'consentMode'    => $settings['consent_mode'],
			'field'          => Volance_Detection_Forms::FIELD,
			'storageKey'     => 'volanceDetectionConsent',
			'sitePrivacyUrl' => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
			'volanceUrl'     => 'https://volance.com/privacy',
			'strings'        => array(
				'title'         => __( 'Bot detection (optional)', 'volance-detection' ),
				'text'          => __( 'We use an on-site script to estimate whether a form submission comes from a human, an agent, or a bot. It records interaction timing, pointer movement, and coarse browser and device details on this site only. It never records the characters you type, form values, or your clipboard. This data, your IP address and your browser headers are sent to our server, which passes them to Volance for scoring. Volance stores your IP only as a one-way hash. Nothing is collected unless you allow it.', 'volance-detection' ),
				'allow'         => __( 'Allow', 'volance-detection' ),
				'decline'       => __( 'Decline', 'volance-detection' ),
				'sitePrivacy'   => __( 'Read our privacy policy', 'volance-detection' ),
				'volancePolicy' => __( 'How Volance handles data', 'volance-detection' ),
			),
		);
		wp_print_inline_script_tag(
			'window.volanceDetectionConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';'
		);
		wp_print_script_tag(
			array(
				'type' => 'module',
				'src'  => add_query_arg( 'ver', VOLANCE_DETECTION_VERSION, VOLANCE_DETECTION_URL . 'assets/js/volance-detection.js' ),
				'id'   => 'volance-detection-js',
			)
		);
	}

	/**
	 * Suggested text for the WordPress privacy policy guide.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested wording if you use Volance Detection. Review it against your own practices.', 'volance-detection' ) . '</p>'
			. '<p>' . esc_html__( 'We use Volance to estimate whether a form submission on this site comes from a human, an agent, or a bot. Collection happens only after you opt in. The script records interaction timing, pointer movement, and coarse browser and device details on this site only. It never records the characters you type, form values, or clipboard contents. When you submit a form, your IP address and browser headers are sent with that timing data to Volance, which stores your IP address only as a one-way hash. Raw scores are deleted after 30 days. No cross-site identifiers are used. Volance is operated by Oops Games LLC; see https://volance.com/privacy.', 'volance-detection' ) . '</p>';
		wp_add_privacy_policy_content( 'Volance Detection', wp_kses_post( wpautop( $text, false ) ) );
	}
}
