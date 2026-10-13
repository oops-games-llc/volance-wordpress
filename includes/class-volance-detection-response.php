<?php
/**
 * Compacts a Volance score response for local storage and reads it back.
 *
 * Pure logic (no WordPress calls) so it can be tested without a WordPress
 * install. The stored copy never includes the trust token, and any IP address
 * that might appear in free text is redacted.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Response compaction and presentation helpers.
 */
class Volance_Detection_Response {

	/** Upper bound for one stored response, in bytes of JSON. */
	const MAX_JSON = 16000;

	/** Upper bound for one stored detail string. */
	const MAX_DETAIL = 200;

	/**
	 * Names of the signal layers.
	 *
	 * @return array layer number => label.
	 */
	public static function layer_names() {
		return array(
			1 => __( 'Client integrity', 'volance-detection' ),
			2 => __( 'Behavior', 'volance-detection' ),
			3 => __( 'Agent tells', 'volance-detection' ),
			4 => __( 'Network', 'volance-detection' ),
			5 => __( 'Disclosure', 'volance-detection' ),
			6 => __( 'Consistency', 'volance-detection' ),
		);
	}

	/**
	 * Redact IP addresses and clip free text.
	 *
	 * @param mixed $text Value to clean.
	 * @param int   $max  Maximum length.
	 * @return string
	 */
	public static function scrub( $text, $max = self::MAX_DETAIL ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = preg_replace( '/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $text );
		$text = preg_replace( '/(?<![:\w])(?:[0-9a-fA-F]{1,4}:){2,7}[0-9a-fA-F]{0,4}(?![:\w])/', '[ip]', (string) $text );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $text );
		return substr( (string) $text, 0, $max );
	}

	/**
	 * Reduce a raw API response to the fields the plugin shows.
	 *
	 * @param array $data Decoded score response.
	 * @return array Compact response.
	 */
	public static function compact( array $data ) {
		$out = array();

		foreach ( array( 'score', 'confidence', 'threshold' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				$out[ $key ] = round( (float) $data[ $key ], 3 );
			}
		}
		foreach ( array( 'band', 'verdict', 'action', 'requestId' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$out[ $key ] = self::scrub( $data[ $key ], 80 );
			}
		}
		if ( isset( $data['enforce'] ) ) {
			$out['enforce'] = (bool) $data['enforce'];
		}

		if ( isset( $data['evidence'] ) && is_array( $data['evidence'] ) ) {
			$out['evidence'] = array(
				'events'     => isset( $data['evidence']['events'] ) ? (int) $data['evidence']['events'] : 0,
				'behavioural' => ! empty( $data['evidence']['behavioural'] ),
			);
		}

		if ( isset( $data['classification'] ) && is_array( $data['classification'] ) ) {
			$c  = $data['classification'];
			$cl = array();
			foreach ( array( 'label', 'agentType', 'model' ) as $key ) {
				if ( isset( $c[ $key ] ) && is_string( $c[ $key ] ) ) {
					$cl[ $key ] = self::scrub( $c[ $key ], 40 );
				}
			}
			if ( isset( $c['confidence'] ) && is_numeric( $c['confidence'] ) ) {
				$cl['confidence'] = round( (float) $c['confidence'], 3 );
			}
			if ( isset( $c['probabilities'] ) && is_array( $c['probabilities'] ) ) {
				foreach ( array( 'human', 'agent', 'bot' ) as $key ) {
					if ( isset( $c['probabilities'][ $key ] ) && is_numeric( $c['probabilities'][ $key ] ) ) {
						$cl['probabilities'][ $key ] = round( (float) $c['probabilities'][ $key ], 3 );
					}
				}
			}
			$out['classification'] = $cl;
		}

		if ( isset( $data['agent'] ) && is_array( $data['agent'] ) ) {
			$out['agent'] = array(
				'detected' => ! empty( $data['agent']['detected'] ),
				'type'     => isset( $data['agent']['type'] ) && is_string( $data['agent']['type'] ) ? self::scrub( $data['agent']['type'], 40 ) : '',
				'identity' => isset( $data['agent']['identity'] ) && is_string( $data['agent']['identity'] ) ? self::scrub( $data['agent']['identity'], 40 ) : '',
			);
		}

		if ( isset( $data['overrides'] ) && is_array( $data['overrides'] ) ) {
			$out['overrides'] = array();
			foreach ( array_slice( $data['overrides'], 0, 20 ) as $item ) {
				$out['overrides'][] = self::scrub( is_array( $item ) ? ( $item['id'] ?? '' ) : $item, 60 );
			}
		}

		if ( isset( $data['cascade'] ) && is_array( $data['cascade'] ) ) {
			$out['cascade'] = array(
				'needed' => ! empty( $data['cascade']['needed'] ),
				'band'   => isset( $data['cascade']['band'] ) && is_string( $data['cascade']['band'] ) ? self::scrub( $data['cascade']['band'], 40 ) : '',
			);
		}

		foreach ( array( 'signals', 'consistency' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$out[ $key ] = self::compact_signals( $data[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * Compact a signal list. The explanation text is kept only for signals that
	 * fired, which keeps a stored row small.
	 *
	 * @param array $signals Raw signals.
	 * @return array
	 */
	private static function compact_signals( array $signals ) {
		$list = array();
		foreach ( array_slice( $signals, 0, 120 ) as $signal ) {
			if ( ! is_array( $signal ) || ! isset( $signal['id'] ) || ! is_string( $signal['id'] ) ) {
				continue;
			}
			$fired = ! empty( $signal['fired'] );
			$item  = array(
				'id'    => self::scrub( $signal['id'], 60 ),
				'layer' => isset( $signal['layer'] ) ? max( 1, min( 6, (int) $signal['layer'] ) ) : 0,
				'value' => isset( $signal['value'] ) && is_numeric( $signal['value'] ) ? round( (float) $signal['value'], 3 ) : 0,
				'fired' => $fired,
			);
			if ( $fired && isset( $signal['detail'] ) ) {
				$detail = self::scrub( $signal['detail'] );
				if ( '' !== $detail ) {
					$item['detail'] = $detail;
				}
			}
			$list[] = $item;
		}
		return $list;
	}

	/**
	 * Encode a compact response, shrinking it when it would be too large.
	 *
	 * @param array $compact Output of compact().
	 * @return string JSON, or '' when it cannot be encoded.
	 */
	public static function to_json( array $compact ) {
		$json = wp_json_encode( $compact );
		if ( ! is_string( $json ) ) {
			return '';
		}
		if ( strlen( $json ) <= self::MAX_JSON ) {
			return $json;
		}
		foreach ( array( 'signals', 'consistency' ) as $key ) {
			if ( isset( $compact[ $key ] ) ) {
				foreach ( $compact[ $key ] as $i => $item ) {
					unset( $compact[ $key ][ $i ]['detail'] );
				}
			}
		}
		$json = wp_json_encode( $compact );
		if ( is_string( $json ) && strlen( $json ) <= self::MAX_JSON ) {
			return $json;
		}
		unset( $compact['signals'] );
		$json = wp_json_encode( $compact );
		return ( is_string( $json ) && strlen( $json ) <= self::MAX_JSON ) ? $json : '';
	}

	/**
	 * Decode a stored response.
	 *
	 * @param mixed $json Stored JSON.
	 * @return array
	 */
	public static function from_json( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Group signals by layer, fired ones first.
	 *
	 * @param array $signals Compact signals.
	 * @return array layer => list of signals.
	 */
	public static function group_signals( array $signals ) {
		$groups = array();
		foreach ( $signals as $signal ) {
			$layer              = isset( $signal['layer'] ) ? (int) $signal['layer'] : 0;
			$groups[ $layer ][] = $signal;
		}
		ksort( $groups );
		foreach ( $groups as $layer => $items ) {
			usort(
				$items,
				static function ( $a, $b ) {
					$af = ! empty( $a['fired'] ) ? 0 : 1;
					$bf = ! empty( $b['fired'] ) ? 0 : 1;
					return $af === $bf ? strcmp( (string) $a['id'], (string) $b['id'] ) : $af - $bf;
				}
			);
			$groups[ $layer ] = $items;
		}
		return $groups;
	}

	/**
	 * Agent type from a compact response ('' when none).
	 *
	 * @param array $compact Compact response.
	 * @return string
	 */
	public static function agent_type( array $compact ) {
		if ( ! empty( $compact['classification']['agentType'] ) ) {
			return substr( (string) $compact['classification']['agentType'], 0, 40 );
		}
		if ( ! empty( $compact['agent']['type'] ) ) {
			return substr( (string) $compact['agent']['type'], 0, 40 );
		}
		return '';
	}
}
