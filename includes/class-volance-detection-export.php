<?php
/**
 * CSV export helpers. Pure logic so the spreadsheet-safety rules are tested.
 *
 * The export has the same columns as the Activity table. It never contains an
 * IP address, user agent or form content, because the log never stores them.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV cell and row formatting.
 */
class Volance_Detection_Export {

	/**
	 * Column headings.
	 *
	 * @return string[]
	 */
	public static function headings() {
		return array( 'time_utc', 'form', 'result', 'score', 'verdict', 'class', 'agent_type', 'events', 'browser_evidence', 'request_id', 'note' );
	}

	/**
	 * Make a value safe to open in a spreadsheet. A leading =, +, - or @ (or a
	 * tab/carriage return) would be read as a formula, so it is prefixed with a
	 * single quote.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$text = is_scalar( $value ) ? (string) $value : '';
		$text = str_replace( array( "\r", "\n" ), ' ', $text );
		if ( '' !== $text && 1 === preg_match( '/^[=+\-@\t]/', $text ) ) {
			$text = "'" . $text;
		}
		return $text;
	}

	/**
	 * One CSV record for a log row.
	 *
	 * @param object $row Log row.
	 * @return string[]
	 */
	public static function record( $row ) {
		return array_map(
			array( __CLASS__, 'cell' ),
			array(
				(string) $row->created_at,
				(string) $row->form,
				(string) $row->status,
				null !== $row->score ? (string) $row->score : '',
				(string) $row->verdict,
				(string) $row->label,
				isset( $row->agent_type ) ? (string) $row->agent_type : '',
				(string) (int) $row->events,
				( (int) $row->behavioural ) ? 'yes' : 'no',
				(string) $row->request_id,
				(string) $row->note,
			)
		);
	}

	/**
	 * Encode one record as a CSV line.
	 *
	 * @param string[] $fields Cell values.
	 * @return string
	 */
	public static function line( array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			$out[] = '"' . str_replace( '"', '""', (string) $field ) . '"';
		}
		return implode( ',', $out ) . "\r\n";
	}
}
