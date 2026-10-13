<?php
/**
 * Turns Volance's recommended action plus a form's chosen mode into what the
 * plugin does. Pure logic (no WordPress calls) so it can be tested directly.
 *
 * Volance returns an `action` of allow, flag or block for every score. Each
 * protected form has a mode: `observe` (log only), `flag` (act on flag and
 * block with the gentlest response) or `block` (block when Volance says block).
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decision helper.
 */
class Volance_Detection_Decision {

	const ALLOW = 'allow';
	const FLAG  = 'flag';
	const BLOCK = 'block';

	/**
	 * Modes a form can have, in order from least to most active.
	 *
	 * @return string[]
	 */
	public static function modes() {
		return array( 'observe', 'flag', 'block' );
	}

	/**
	 * Normalise an action string from the API.
	 *
	 * @param mixed $action Raw value.
	 * @return string allow|flag|block.
	 */
	public static function normalize_action( $action ) {
		$action = is_string( $action ) ? strtolower( trim( $action ) ) : '';
		return in_array( $action, array( self::FLAG, self::BLOCK ), true ) ? $action : self::ALLOW;
	}

	/**
	 * Decide what the plugin does.
	 *
	 * @param string $mode   Form mode: observe|flag|block (anything else = observe).
	 * @param mixed  $action  Volance's recommended action.
	 * @param bool   $enforce Whether the Volance workspace has enforcement on (the score response's
	 *                        `enforce` flag). When off, or paused because the plan quota is used up,
	 *                        nothing is flagged or blocked; the recommendation is still logged.
	 * @return string allow|flag|block, what the plugin applies.
	 */
	public static function resolve( $mode, $action, $enforce = true ) {
		$action = self::normalize_action( $action );
		if ( ! $enforce ) {
			return self::ALLOW;
		}
		if ( 'flag' === $mode ) {
			return self::ALLOW === $action ? self::ALLOW : self::FLAG;
		}
		if ( 'block' === $mode ) {
			return $action;
		}
		return self::ALLOW;
	}

	/**
	 * Whether a mode needs the answer before the request finishes.
	 *
	 * @param string $mode Form mode.
	 * @return bool
	 */
	public static function is_synchronous( $mode ) {
		return 'flag' === $mode || 'block' === $mode;
	}
}
