<?php
/**
 * Evidence handling: validates the browser snapshot and describes the visitor.
 *
 * This is the plugin's own privacy boundary. Anything not on an allowlist is
 * dropped, and anything that looks content-bearing rejects the whole snapshot.
 * The Volance API enforces the same boundary again server-side.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot sanitizer and visitor-request description.
 */
class Volance_Detection_Evidence {

	const MAX_BYTES  = 65536;
	const MAX_EVENTS = 256;
	const MAX_TIME   = 86400000;
	const MAX_COORD  = 10000000;
	const MAX_COUNT  = 100000;

	const EVENT_TYPES = array( 'mousemove', 'click', 'keydown', 'scroll', 'keyup', 'pointerdown', 'wheel' );

	/** Numeric event fields; 't' is time, the rest are coordinates/deltas. */
	const EVENT_FIELDS = array( 't', 'x', 'y', 'dx', 'dy', 'deltaY' );

	/** Field names that indicate content. Their presence rejects the snapshot. */
	const FORBIDDEN = array(
		'key',
		'code',
		'value',
		'text',
		'textContent',
		'innerText',
		'data',
		'clipboardData',
		'password',
		'inputValue',
		'char',
		'character',
		'charCode',
		'keyCode',
		'formValue',
		'typedText',
		'lastInput',
	);

	const CLIENT_BOOLEANS = array(
		'webdriver',
		'cdpArtifacts',
		'headlessUA',
		'pdfViewerEnabled',
		'uaPlatformConsistent',
		'timezoneLocaleConsistent',
		'hasUntrustedEvents',
		'honeypotField',
		'honeypotLink',
		'touchSupported',
		'notificationsSupported',
		'permissionsApi',
		'audioAvailable',
		'webrtcAvailable',
		'chromeObject',
		'userAgentDataMobile',
		'webdriverDescriptorTampered',
	);

	const CLIENT_COUNTS = array(
		'pluginsCount',
		'mimeTypes',
		'languages',
		'mediaDevicesCount',
		'sessionDwell',
		'assetCount',
		'screenBands',
		'touchPoints',
		'maxTouchPoints',
		'innerWidth',
		'innerHeight',
		'outerWidth',
		'outerHeight',
		'screenWidth',
		'screenHeight',
		'deviceMemory',
		'hardwareConcurrency',
		'interactionLatency',
	);

	const CLIENT_STRINGS = array(
		'platform'              => 64,
		'userAgentDataPlatform' => 64,
		'timezone'              => 64,
	);

	/**
	 * Fingerprint-tier fields. The plugin never forwards these.
	 */
	const FINGERPRINT_FIELDS = array( 'canvasBlank', 'fontCount', 'webglRenderer', 'webglSoftwareRenderer' );

	const PROG_COUNTS = array(
		'detailZeroClicks',
		'mousedownNoMove',
		'pointerMissingFields',
		'keydown',
		'beforeinput',
		'focusNoClick',
		'scrollJump',
		'total',
		'synthetic',
		'paste',
		'clicksWithTarget',
		'centerClicks',
	);

	/**
	 * Validate a snapshot sent by the page.
	 *
	 * @param mixed $json Raw JSON string from the hidden field.
	 * @return array|null array( 'clientSignals' => array, 'events' => array ) or null when unusable.
	 */
	public static function sanitize_snapshot( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			return null;
		}
		$data = json_decode( $json, true, 8 );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$raw_client = ( isset( $data['clientSignals'] ) && is_array( $data['clientSignals'] ) ) ? $data['clientSignals'] : array();
		$raw_events = ( isset( $data['events'] ) && is_array( $data['events'] ) ) ? $data['events'] : array();

		// The collector returns keystroke cadence and programmatic-event counters
		// beside clientSignals; the Volance schema accepts them inside it.
		foreach ( array( 'keys', 'prog' ) as $nested ) {
			if ( ! isset( $raw_client[ $nested ] ) && isset( $data[ $nested ] ) ) {
				$raw_client[ $nested ] = $data[ $nested ];
			}
		}

		foreach ( array_keys( $raw_client ) as $field ) {
			if ( in_array( (string) $field, self::FORBIDDEN, true ) ) {
				return null;
			}
		}

		$events = array();
		foreach ( $raw_events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			foreach ( array_keys( $event ) as $field ) {
				if ( in_array( (string) $field, self::FORBIDDEN, true ) ) {
					return null;
				}
			}
			if ( ! isset( $event['type'] ) || ! is_string( $event['type'] ) || ! in_array( $event['type'], self::EVENT_TYPES, true ) ) {
				continue;
			}
			$clean = array( 'type' => $event['type'] );
			foreach ( self::EVENT_FIELDS as $field ) {
				if ( ! isset( $event[ $field ] ) ) {
					continue;
				}
				$limit = ( 't' === $field ) ? array( 0, self::MAX_TIME ) : array( -self::MAX_COORD, self::MAX_COORD );
				$num   = self::number( $event[ $field ], $limit[0], $limit[1] );
				if ( null !== $num ) {
					$clean[ $field ] = $num;
				}
			}
			$events[] = $clean;
			if ( count( $events ) >= self::MAX_EVENTS ) {
				break;
			}
		}

		return array(
			'clientSignals' => self::client_signals( $raw_client ),
			'events'        => $events,
		);
	}

	/**
	 * Reduce clientSignals to the allowlist.
	 *
	 * @param array $raw Raw client signals.
	 * @return array
	 */
	private static function client_signals( array $raw ) {
		$out = array();
		foreach ( self::CLIENT_BOOLEANS as $field ) {
			if ( isset( $raw[ $field ] ) && is_bool( $raw[ $field ] ) ) {
				$out[ $field ] = $raw[ $field ];
			}
		}
		foreach ( self::CLIENT_COUNTS as $field ) {
			if ( isset( $raw[ $field ] ) ) {
				$num = self::number( $raw[ $field ], 0, self::MAX_COUNT );
				if ( null !== $num ) {
					$out[ $field ] = $num;
				}
			}
		}
		foreach ( self::CLIENT_STRINGS as $field => $max ) {
			if ( isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) && strlen( $raw[ $field ] ) <= $max && 1 === preg_match( '/^[A-Za-z0-9 ._\-+\/:(),;#]*$/', $raw[ $field ] ) ) {
				$out[ $field ] = $raw[ $field ];
			}
		}
		if ( isset( $raw['prog'] ) && is_array( $raw['prog'] ) ) {
			$prog = array();
			foreach ( self::PROG_COUNTS as $field ) {
				if ( isset( $raw['prog'][ $field ] ) ) {
					$num = self::number( $raw['prog'][ $field ], 0, self::MAX_COUNT );
					if ( null !== $num ) {
						$prog[ $field ] = $num;
					}
				}
			}
			if ( $prog ) {
				$out['prog'] = $prog;
			}
		}
		if ( isset( $raw['keys'] ) && is_array( $raw['keys'] ) ) {
			$keys = array();
			foreach ( array( 'dwells', 'flight' ) as $list ) {
				if ( isset( $raw['keys'][ $list ] ) && is_array( $raw['keys'][ $list ] ) ) {
					$values = array();
					foreach ( array_slice( array_values( $raw['keys'][ $list ] ), 0, 128 ) as $value ) {
						$num = self::number( $value, 0, self::MAX_TIME );
						if ( null !== $num ) {
							$values[] = $num;
						}
					}
					if ( $values ) {
						$keys[ $list ] = $values;
					}
				}
			}
			$key_limits = array(
				'count'  => self::MAX_COUNT,
				'firstT' => self::MAX_TIME,
				'lastT'  => self::MAX_TIME,
			);
			foreach ( $key_limits as $field => $max ) {
				if ( isset( $raw['keys'][ $field ] ) ) {
					$num = self::number( $raw['keys'][ $field ], 0, $max );
					if ( null !== $num ) {
						$keys[ $field ] = $num;
					}
				}
			}
			if ( $keys ) {
				$out['keys'] = $keys;
			}
		}
		return $out;
	}

	/**
	 * Finite number within bounds, or null.
	 *
	 * @param mixed $value Value.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return int|float|null
	 */
	private static function number( $value, $min, $max ) {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		if ( is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ) {
			return null;
		}
		if ( $value < $min || $value > $max ) {
			return null;
		}
		return $value;
	}

	/**
	 * Describe the visitor's own request for Volance (requestSignals).
	 *
	 * The scoring call is made by this server, so its transport headers describe
	 * the relay. These fields tell Volance about the visitor instead. Raw values
	 * are not kept by this plugin.
	 *
	 * @param array|null $server Server variables; defaults to $_SERVER.
	 * @return array
	 */
	public static function request_signals( $server = null ) {
		if ( null === $server ) {
			$server = $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read-only, each value is filtered below.
		}
		$out = array();

		$ip = self::visitor_ip( $server );
		if ( '' !== $ip ) {
			$out['visitorIp'] = $ip;
		}

		$ua = self::printable( $server['HTTP_USER_AGENT'] ?? '', 512 );
		if ( '' !== $ua ) {
			$out['visitorUserAgent'] = $ua;
		}

		$lang = self::printable( $server['HTTP_ACCEPT_LANGUAGE'] ?? '', 256 );
		if ( '' !== $lang ) {
			$out['acceptLanguage'] = $lang;
		}

		$ch_ua = self::printable( $server['HTTP_SEC_CH_UA'] ?? '', 2048 );
		if ( '' !== $ch_ua ) {
			$items = array();
			foreach ( explode( ',', $ch_ua ) as $item ) {
				$item = trim( $item );
				if ( '' !== $item ) {
					$items[] = substr( $item, 0, 128 );
				}
				if ( count( $items ) >= 16 ) {
					break;
				}
			}
			if ( $items ) {
				$out['secChUa'] = $items;
			}
		}

		foreach ( array(
			'secFetchSite' => 'HTTP_SEC_FETCH_SITE',
			'secFetchMode' => 'HTTP_SEC_FETCH_MODE',
		) as $field => $var ) {
			$token = strtolower( self::printable( $server[ $var ] ?? '', 64 ) );
			if ( 1 === preg_match( '/^[a-z-]{1,64}$/', $token ) ) {
				$out[ $field ] = $token;
			}
		}

		return $out;
	}

	/**
	 * Visitor IP. Uses REMOTE_ADDR only; sites behind a trusted proxy can supply
	 * the real address with the 'volance_detection_visitor_ip' filter.
	 *
	 * @param array $server Server variables.
	 * @return string Valid IP or ''.
	 */
	private static function visitor_ip( array $server ) {
		$ip = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		if ( function_exists( 'apply_filters' ) ) {
			$ip = (string) apply_filters( 'volance_detection_visitor_ip', $ip, $server );
		}
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || strlen( $ip ) > 45 ) {
			return '';
		}
		return $ip;
	}

	/**
	 * Printable ASCII only, length-capped.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum length.
	 * @return string
	 */
	private static function printable( $value, $max ) {
		$value = preg_replace( '/[^\x20-\x7E]/', '', (string) $value );
		return substr( (string) $value, 0, $max );
	}
}
