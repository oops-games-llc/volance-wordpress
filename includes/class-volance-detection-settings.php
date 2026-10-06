<?php
/**
 * Settings storage and validation.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and sanitizes the plugin settings.
 */
class Volance_Detection_Settings {

	const OPTION        = 'volance_detection_settings';
	const SECRET_OPTION = 'volance_detection_secret';

	/**
	 * Forms the plugin can observe.
	 *
	 * @return string[]
	 */
	public static function form_keys() {
		return array( 'login', 'register', 'comments' );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'public_key'   => '',
			'enabled'      => false,
			'consent_mode' => 'banner',
			'forms'        => array(),
		);
	}

	/**
	 * Read the (non-secret) settings.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$merged = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		if ( ! is_array( $merged['forms'] ) ) {
			$merged['forms'] = array();
		}
		return $merged;
	}

	/**
	 * Public key.
	 *
	 * @return string
	 */
	public static function public_key() {
		$settings = self::get();
		return is_string( $settings['public_key'] ) ? $settings['public_key'] : '';
	}

	/**
	 * Whether the secret key is supplied by a wp-config.php constant.
	 *
	 * @return bool
	 */
	public static function secret_from_constant() {
		return defined( 'VOLANCE_DETECTION_SECRET_KEY' ) && is_string( VOLANCE_DETECTION_SECRET_KEY ) && '' !== VOLANCE_DETECTION_SECRET_KEY;
	}

	/**
	 * Secret key. The constant, if defined, wins over the stored option.
	 * It is only ever used in PHP and never printed.
	 *
	 * @return string
	 */
	public static function secret_key() {
		if ( self::secret_from_constant() ) {
			return VOLANCE_DETECTION_SECRET_KEY;
		}
		$stored = get_option( self::SECRET_OPTION, '' );
		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Check a key pair. Returns an empty string when it is acceptable, or a code.
	 *
	 * Volance's first key scheme made the secret key the public key with the
	 * prefix swapped, so the secret key could be derived from the public one
	 * (which is visible in page code). Such a pair must be rotated.
	 *
	 * @param string $public_key Public key.
	 * @param string $secret_key Secret key.
	 * @return string '' | 'format_public' | 'format_secret' | 'legacy_pair'
	 */
	public static function keys_problem( $public_key, $secret_key ) {
		if ( 1 !== preg_match( '/^pk_[A-Za-z0-9_-]{8,128}$/', (string) $public_key ) ) {
			return 'format_public';
		}
		if ( 1 !== preg_match( '/^sk_[A-Za-z0-9_-]{8,128}$/', (string) $secret_key ) ) {
			return 'format_secret';
		}
		if ( substr( (string) $secret_key, 3 ) === substr( (string) $public_key, 3 ) ) {
			return 'legacy_pair';
		}
		return '';
	}

	/**
	 * Human-readable text for a keys_problem() code.
	 *
	 * @param string $code Problem code.
	 * @return string
	 */
	public static function keys_problem_message( $code ) {
		switch ( $code ) {
			case 'format_public':
				return __( 'The public key should start with pk_ and contain only letters, numbers, dashes and underscores.', 'volance-detection' );
			case 'format_secret':
				return __( 'The secret key should start with sk_ and contain only letters, numbers, dashes and underscores.', 'volance-detection' );
			case 'legacy_pair':
				return __( 'This key pair uses Volance\'s older key format, where the secret key can be worked out from the public key. Rotate your keys in the Volance portal and enter the new pair.', 'volance-detection' );
			default:
				return '';
		}
	}

	/**
	 * Whether a form is enabled for observation.
	 *
	 * @param string $form Form key.
	 * @return bool
	 */
	public static function form_enabled( $form ) {
		$settings = self::get();
		return ! empty( $settings['forms'][ $form ] );
	}

	/**
	 * Whether the plugin is switched on and has an acceptable key pair.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$settings = self::get();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		return '' === self::keys_problem( self::public_key(), self::secret_key() );
	}

	/**
	 * Sanitize callback for the settings form.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array Sanitized (non-secret) settings.
	 */
	public static function sanitize( $input ) {
		$current = self::get();
		$input   = is_array( $input ) ? $input : array();

		$public = isset( $input['public_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['public_key'] ) ) ) : $current['public_key'];
		$secret = isset( $input['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['secret_key'] ) ) ) : '';

		$new_secret = '' !== $secret ? $secret : self::secret_key();
		$out        = $current;

		$keys_changed = ( $public !== $current['public_key'] ) || '' !== $secret;
		if ( $keys_changed && ( '' !== $public || '' !== $new_secret ) ) {
			$problem = self::keys_problem( $public, $new_secret );
			if ( '' !== $problem ) {
				add_settings_error( 'volance_detection', 'volance_keys', self::keys_problem_message( $problem ), 'error' );
			} else {
				$out['public_key'] = $public;
				if ( '' !== $secret ) {
					update_option( self::SECRET_OPTION, $secret, false );
				}
			}
		} elseif ( '' === $public && '' === $secret && '' !== $current['public_key'] && isset( $input['public_key'] ) ) {
			// Public key cleared on purpose: disconnect.
			$out['public_key'] = '';
			delete_option( self::SECRET_OPTION );
		}

		$out['enabled']      = ! empty( $input['enabled'] );
		$out['consent_mode'] = ( isset( $input['consent_mode'] ) && 'custom' === $input['consent_mode'] ) ? 'custom' : 'banner';

		$forms = array();
		foreach ( self::form_keys() as $key ) {
			if ( ! empty( $input['forms'][ $key ] ) ) {
				$forms[ $key ] = true;
			}
		}
		$out['forms'] = $forms;

		if ( $out['enabled'] && '' !== self::keys_problem( $out['public_key'], self::secret_key() ) ) {
			// Cannot switch on without a valid key pair.
			$out['enabled'] = false;
			add_settings_error( 'volance_detection', 'volance_enable', __( 'Volance Detection stays off until a valid key pair is saved.', 'volance-detection' ), 'error' );
		}

		return $out;
	}
}
