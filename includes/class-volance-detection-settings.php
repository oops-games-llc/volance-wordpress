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
		return array( 'login', 'register', 'lostpassword', 'comments', 'wc_login', 'wc_register', 'wc_checkout', 'cf7', 'wpforms', 'gravity' );
	}

	/**
	 * Whether the plugin a form belongs to is active on this site.
	 *
	 * @param string $form Form key.
	 * @return bool
	 */
	public static function form_available( $form ) {
		switch ( $form ) {
			case 'wc_login':
			case 'wc_register':
			case 'wc_checkout':
				return class_exists( 'WooCommerce' );
			case 'cf7':
				return defined( 'WPCF7_VERSION' );
			case 'wpforms':
				return function_exists( 'wpforms' );
			case 'gravity':
				return class_exists( 'GFForms' );
			default:
				return true;
		}
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'public_key'     => '',
			'enabled'        => false,
			'consent_mode'   => 'banner',
			'forms'          => array(),
			'on_no_evidence' => 'allow',
			'paths'          => array(),
		);
	}

	/**
	 * Parse the "protected paths" textarea. One rule per line: an optional mode
	 * (observe, flag or block) then a URL path, where * matches anything.
	 * Examples: `/contact/`, `flag /pricing/*`, `block /wp-json/myplugin/v1/*`.
	 *
	 * @param string $text Raw textarea value.
	 * @return array[] Each {pattern: string, mode: string}.
	 */
	public static function parse_paths( $text ) {
		$rules = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$mode = 'observe';
			if ( 1 === preg_match( '/^(observe|flag|block)\s+(\S.*)$/i', $line, $m ) ) {
				$mode = strtolower( $m[1] );
				$line = trim( $m[2] );
			}
			// A path only: strip any scheme/host and query, keep a leading slash, no control characters.
			$line = preg_replace( '#^https?://[^/]+#i', '', $line );
			$line = preg_replace( '/[?#].*$/', '', (string) $line );
			$line = preg_replace( '/[^\x21-\x7E]/', '', (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '/' !== $line[0] ) {
				$line = '/' . $line;
			}
			$rules[] = array(
				'pattern' => substr( $line, 0, 200 ),
				'mode'    => $mode,
			);
			if ( count( $rules ) >= 50 ) {
				break;
			}
		}
		return $rules;
	}

	/**
	 * The protected-path rules as editable text.
	 *
	 * @return string
	 */
	public static function paths_text() {
		$settings = self::get();
		$lines    = array();
		foreach ( (array) $settings['paths'] as $rule ) {
			if ( isset( $rule['pattern'], $rule['mode'] ) ) {
				$lines[] = $rule['mode'] . ' ' . $rule['pattern'];
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * The first rule whose pattern matches a URL path.
	 *
	 * Matching ignores a trailing slash and case; * matches any characters.
	 *
	 * @param string  $path  URL path (no query), relative to the site root.
	 * @param array[] $rules Rules from parse_paths().
	 * @return array|null The rule, or null.
	 */
	public static function match_path( $path, array $rules ) {
		$path = strtolower( '/' . ltrim( rtrim( (string) $path, '/' ), '/' ) );
		foreach ( $rules as $rule ) {
			if ( ! isset( $rule['pattern'] ) ) {
				continue;
			}
			$pattern = strtolower( '/' . ltrim( rtrim( (string) $rule['pattern'], '/' ), '/' ) );
			$regex   = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
			if ( 1 === preg_match( $regex, $path ) ) {
				return $rule;
			}
		}
		return null;
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
	 * The mode a form is in: off, observe (log only), flag or block.
	 *
	 * @param string $form Form key.
	 * @return string off|observe|flag|block.
	 */
	public static function form_mode( $form ) {
		$settings = self::get();
		$value    = $settings['forms'][ $form ] ?? false;
		if ( true === $value || 1 === $value || '1' === $value ) {
			return 'observe'; // Stored by 0.1.x.
		}
		if ( is_string( $value ) && in_array( $value, Volance_Detection_Decision::modes(), true ) ) {
			return $value;
		}
		return 'off';
	}

	/**
	 * Whether a form is protected in any mode.
	 *
	 * @param string $form Form key.
	 * @return bool
	 */
	public static function form_enabled( $form ) {
		return 'off' !== self::form_mode( $form );
	}

	/**
	 * What to do when a protected form arrives with no browser evidence.
	 *
	 * @return string allow|score  allow = let it through and log it; score = score the request signals alone.
	 */
	public static function on_no_evidence() {
		$settings = self::get();
		return 'score' === ( $settings['on_no_evidence'] ?? 'allow' ) ? 'score' : 'allow';
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

		$out['enabled']        = ! empty( $input['enabled'] );
		$consent               = isset( $input['consent_mode'] ) ? (string) $input['consent_mode'] : 'banner';
		$out['consent_mode']   = in_array( $consent, array( 'banner', 'custom', 'always' ), true ) ? $consent : 'banner';
		$out['on_no_evidence'] = ( isset( $input['on_no_evidence'] ) && 'score' === $input['on_no_evidence'] ) ? 'score' : 'allow';

		$forms = array();
		foreach ( self::form_keys() as $key ) {
			$mode = isset( $input['forms'][ $key ] ) ? (string) $input['forms'][ $key ] : 'off';
			if ( in_array( $mode, Volance_Detection_Decision::modes(), true ) ) {
				$forms[ $key ] = $mode;
			}
		}
		$out['forms'] = $forms;
		$out['paths'] = isset( $input['paths_text'] ) ? self::parse_paths( wp_unslash( (string) $input['paths_text'] ) ) : (array) $current['paths'];

		if ( $out['enabled'] && '' !== self::keys_problem( $out['public_key'], self::secret_key() ) ) {
			// Cannot switch on without a valid key pair.
			$out['enabled'] = false;
			add_settings_error( 'volance_detection', 'volance_enable', __( 'Volance Detection stays off until a valid key pair is saved.', 'volance-detection' ), 'error' );
		}

		return $out;
	}
}
