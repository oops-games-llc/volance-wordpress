<?php
/**
 * Builds the Overview numbers from raw aggregates. Pure logic, no WordPress
 * calls, so it is covered by tests/run.php.
 *
 * Definitions match the Volance portal: "human" is the classification label
 * `human`; "automated" is `agent` or `bot`; the average score covers scored
 * rows only.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview statistics.
 */
class Volance_Detection_Stats {

	/**
	 * Selectable ranges: key => seconds (0 means everything kept).
	 *
	 * @return array
	 */
	public static function ranges() {
		return array(
			'24h' => 86400,
			'7d'  => 7 * 86400,
			'30d' => 30 * 86400,
			'all' => 0,
		);
	}

	/**
	 * Normalize a requested range key.
	 *
	 * @param mixed $key Requested key.
	 * @return string
	 */
	public static function range_key( $key ) {
		$ranges = self::ranges();
		return ( is_string( $key ) && isset( $ranges[ $key ] ) ) ? $key : '7d';
	}

	/**
	 * Bucket unit for a range.
	 *
	 * @param string $range Range key.
	 * @return string 'hour' or 'day'.
	 */
	public static function unit( $range ) {
		return '24h' === $range ? 'hour' : 'day';
	}

	/**
	 * Bucket label for a unix time (UTC).
	 *
	 * @param int    $time Unix time.
	 * @param string $unit 'hour' or 'day'.
	 * @return string
	 */
	public static function bucket( $time, $unit ) {
		return 'hour' === $unit ? gmdate( 'Y-m-d H:00', (int) $time ) : gmdate( 'Y-m-d', (int) $time );
	}

	/**
	 * Zero-filled bucket labels from the start of the window to now.
	 *
	 * @param string $range Range key.
	 * @param int    $now   Unix time.
	 * @param int    $first Unix time of the oldest row (used for "all").
	 * @return string[]
	 */
	public static function bucket_labels( $range, $now, $first = 0 ) {
		$unit = self::unit( $range );
		$step = 'hour' === $unit ? 3600 : 86400;
		$span = self::ranges()[ $range ];
		if ( 0 === $span ) {
			$span = max( $step, min( 30 * 86400, $now - ( $first > 0 ? $first : $now ) ) );
		}
		$labels = array();
		for ( $t = $now - $span; $t <= $now; $t += $step ) {
			$labels[ self::bucket( $t, $unit ) ] = true;
		}
		$labels[ self::bucket( $now, $unit ) ] = true;
		return array_keys( $labels );
	}

	/**
	 * Compute the view model.
	 *
	 * @param array  $raw   Aggregates: statuses, verdicts, labels, forms (each name => count),
	 *                      score_sum (float), series (bucket => count).
	 * @param string $range Range key.
	 * @param int    $now   Unix time.
	 * @param int    $first Unix time of the oldest row, or 0.
	 * @return array
	 */
	public static function build( array $raw, $range, $now, $first = 0 ) {
		$statuses = isset( $raw['statuses'] ) && is_array( $raw['statuses'] ) ? $raw['statuses'] : array();
		$verdicts = isset( $raw['verdicts'] ) && is_array( $raw['verdicts'] ) ? $raw['verdicts'] : array();
		$labels   = isset( $raw['labels'] ) && is_array( $raw['labels'] ) ? $raw['labels'] : array();
		$forms    = isset( $raw['forms'] ) && is_array( $raw['forms'] ) ? $raw['forms'] : array();
		$series   = isset( $raw['series'] ) && is_array( $raw['series'] ) ? $raw['series'] : array();

		$submissions = (int) array_sum( array_map( 'intval', $statuses ) );
		$scored      = isset( $statuses['scored'] ) ? (int) $statuses['scored'] : 0;
		$no_evidence = isset( $statuses['no_evidence'] ) ? (int) $statuses['no_evidence'] : 0;

		$human     = isset( $labels['human'] ) ? (int) $labels['human'] : 0;
		$automated = ( isset( $labels['agent'] ) ? (int) $labels['agent'] : 0 ) + ( isset( $labels['bot'] ) ? (int) $labels['bot'] : 0 );
		$classed   = array_sum( array_map( 'intval', $labels ) );
		$score_sum = isset( $raw['score_sum'] ) ? (float) $raw['score_sum'] : 0.0;

		$buckets = array();
		foreach ( self::bucket_labels( $range, $now, $first ) as $label ) {
			$buckets[ $label ] = isset( $series[ $label ] ) ? (int) $series[ $label ] : 0;
		}

		arsort( $verdicts );
		arsort( $labels );
		arsort( $forms );

		return array(
			'range'          => $range,
			'unit'           => self::unit( $range ),
			'submissions'    => $submissions,
			'scored'         => $scored,
			'no_evidence'    => $no_evidence,
			'no_evidence_pct' => $submissions > 0 ? (int) round( 100 * $no_evidence / $submissions ) : 0,
			'human'          => $human,
			'automated'      => $automated,
			'human_pct'      => $classed > 0 ? (int) round( 100 * $human / $classed ) : 0,
			'automated_pct'  => $classed > 0 ? (int) round( 100 * $automated / $classed ) : 0,
			'avg_score'      => $scored > 0 ? round( $score_sum / $scored, 1 ) : 0.0,
			'statuses'       => $statuses,
			'verdicts'       => array_map( 'intval', $verdicts ),
			'labels'         => array_map( 'intval', $labels ),
			'forms'          => array_map( 'intval', $forms ),
			'series'         => $buckets,
			'series_max'     => $buckets ? max( $buckets ) : 0,
		);
	}
}
