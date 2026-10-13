<?php
/**
 * Overview tab: totals, a daily or hourly chart and breakdowns, all computed
 * from the local activity log.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview rendering.
 */
class Volance_Detection_Admin_Overview {

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			echo '<div class="volance-card"><h2>' . esc_html__( 'Finish setup to see results', 'volance-detection' ) . '</h2><p>' . esc_html__( 'Add your Volance public and secret keys, turn detection on and choose the forms to observe. Results appear here after visitors allow detection and submit those forms.', 'volance-detection' ) . '</p><p><a class="button button-primary" href="' . esc_url( Volance_Detection_Admin::url( 'setup' ) ) . '">' . esc_html__( 'Go to Setup', 'volance-detection' ) . '</a></p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- range selection only.
		$range = Volance_Detection_Stats::range_key( isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '' );
		$span  = Volance_Detection_Stats::ranges()[ $range ];
		$now   = time();
		$unit  = Volance_Detection_Stats::unit( $range );
		$stats = Volance_Detection_Stats::build(
			Volance_Detection_Log::aggregate( $span > 0 ? $now - $span : 0, $unit ),
			$range,
			$now,
			Volance_Detection_Log::first_time()
		);

		self::range_switch( $range );

		if ( 0 === $stats['submissions'] ) {
			echo '<div class="volance-card"><h2>' . esc_html__( 'Nothing recorded in this period', 'volance-detection' ) . '</h2><p>' . esc_html__( 'Submissions appear here after a visitor allows detection and submits an observed form.', 'volance-detection' ) . '</p></div>';
			return;
		}

		echo '<div class="volance-tiles">';
		self::tile( __( 'Submissions', 'volance-detection' ), number_format_i18n( $stats['submissions'] ), __( 'observed forms', 'volance-detection' ) );
		self::tile( __( 'Scored', 'volance-detection' ), number_format_i18n( $stats['scored'] ), __( 'sent to Volance', 'volance-detection' ) );
		self::tile( __( 'Likely human', 'volance-detection' ), $stats['human_pct'] . '%', sprintf( /* translators: %s: number of submissions. */ _n( '%s submission', '%s submissions', $stats['human'], 'volance-detection' ), number_format_i18n( $stats['human'] ) ) );
		self::tile( __( 'Likely automated', 'volance-detection' ), $stats['automated_pct'] . '%', sprintf( /* translators: %s: number of submissions. */ _n( '%s submission', '%s submissions', $stats['automated'], 'volance-detection' ), number_format_i18n( $stats['automated'] ) ) );
		self::tile( __( 'Average score', 'volance-detection' ), $stats['scored'] > 0 ? number_format_i18n( $stats['avg_score'], 1 ) : '-', __( '0 to 100, higher is more human', 'volance-detection' ) );
		self::tile( __( 'No browser evidence', 'volance-detection' ), $stats['no_evidence_pct'] . '%', __( 'of submissions (not scored)', 'volance-detection' ) );
		echo '</div>';

		echo '<div class="volance-card"><h2>' . esc_html( 'hour' === $unit ? __( 'Scored submissions per hour (UTC)', 'volance-detection' ) : __( 'Scored submissions per day (UTC)', 'volance-detection' ) ) . '</h2>';
		echo wp_kses( self::chart( $stats['series'], $stats['series_max'] ), self::svg_allowed() );
		echo '</div>';

		echo '<div class="volance-columns">';
		self::bars( __( 'By verdict', 'volance-detection' ), $stats['verdicts'] );
		self::bars( __( 'By class', 'volance-detection' ), $stats['labels'] );
		self::bars( __( 'By form', 'volance-detection' ), $stats['forms'], true );
		self::bars( __( 'By result', 'volance-detection' ), $stats['statuses'], false, true );
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Percentages count scored submissions that Volance classified. A submission without consented browser evidence is never sent to Volance, so it has no score. Figures cover what this site kept: entries are deleted after 30 days.', 'volance-detection' ) . '</p>';
	}

	/**
	 * Range links.
	 *
	 * @param string $current Current range key.
	 * @return void
	 */
	private static function range_switch( $current ) {
		$labels = array(
			'24h' => __( '24 hours', 'volance-detection' ),
			'7d'  => __( '7 days', 'volance-detection' ),
			'30d' => __( '30 days', 'volance-detection' ),
			'all' => __( 'Everything kept', 'volance-detection' ),
		);
		echo '<ul class="subsubsub volance-range">';
		$i = 0;
		foreach ( $labels as $key => $label ) {
			echo '<li><a href="' . esc_url( Volance_Detection_Admin::url( 'overview', array( 'range' => $key ) ) ) . '"' . ( $key === $current ? ' class="current" aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>' . ( ++$i < count( $labels ) ? ' |' : '' ) . '</li> ';
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * One stat tile.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $note  Small note.
	 * @return void
	 */
	private static function tile( $label, $value, $note ) {
		echo '<div class="volance-tile"><span class="volance-tile-label">' . esc_html( $label ) . '</span><strong class="volance-tile-value">' . esc_html( $value ) . '</strong><span class="volance-tile-note">' . esc_html( $note ) . '</span></div>';
	}

	/**
	 * Horizontal bars for a name => count map.
	 *
	 * @param string $title     Card title.
	 * @param array  $data      name => count.
	 * @param bool   $is_form   Translate form keys.
	 * @param bool   $is_status Translate status keys.
	 * @return void
	 */
	private static function bars( $title, array $data, $is_form = false, $is_status = false ) {
		echo '<div class="volance-card"><h3>' . esc_html( $title ) . '</h3>';
		if ( ! $data ) {
			echo '<p class="description">' . esc_html__( 'No data.', 'volance-detection' ) . '</p></div>';
			return;
		}
		$max      = max( $data );
		$statuses = Volance_Detection_Admin::status_labels();
		echo '<ul class="volance-bars">';
		foreach ( $data as $name => $count ) {
			$label = (string) $name;
			if ( $is_form ) {
				$label = Volance_Detection_Admin::form_label( $label );
			} elseif ( $is_status ) {
				$label = $statuses[ $label ] ?? $label;
			}
			$width = $max > 0 ? max( 2, (int) round( 100 * $count / $max ) ) : 0;
			echo '<li><span class="volance-bar-name">' . esc_html( $label ) . '</span><span class="volance-bar"><span style="width:' . (int) $width . '%"></span></span><span class="volance-bar-count">' . esc_html( number_format_i18n( $count ) ) . '</span></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Allowed SVG markup for wp_kses.
	 *
	 * @return array
	 */
	private static function svg_allowed() {
		return array(
			'svg'   => array(
				'class'       => true,
				'viewbox'     => true,
				'role'        => true,
				'aria-label'  => true,
				'preserveaspectratio' => true,
			),
			'g'     => array( 'class' => true ),
			'rect'  => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'class'  => true,
				'rx'     => true,
			),
			'text'  => array(
				'x'           => true,
				'y'           => true,
				'class'       => true,
				'text-anchor' => true,
			),
			'title' => array(),
			'line'  => array(
				'x1'    => true,
				'x2'    => true,
				'y1'    => true,
				'y2'    => true,
				'class' => true,
			),
		);
	}

	/**
	 * Bar chart as inline SVG.
	 *
	 * @param array $series Bucket label => count (already zero-filled).
	 * @param int   $max    Largest count.
	 * @return string
	 */
	private static function chart( array $series, $max ) {
		$count = count( $series );
		if ( 0 === $count ) {
			return '';
		}
		$w     = 720;
		$h     = 160;
		$pad_l = 34;
		$pad_b = 22;
		$plot  = $h - $pad_b - 8;
		$top   = max( 1, (int) $max );
		$slot  = ( $w - $pad_l ) / $count;
		$bar   = max( 1, $slot * 0.7 );

		$svg  = '<svg class="volance-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Scored submissions over time', 'volance-detection' ) . '">';
		$svg .= '<line class="volance-axis" x1="' . $pad_l . '" x2="' . $w . '" y1="' . ( $h - $pad_b ) . '" y2="' . ( $h - $pad_b ) . '" />';
		$svg .= '<text class="volance-axis-label" x="' . ( $pad_l - 6 ) . '" y="14" text-anchor="end">' . (int) $top . '</text>';
		$svg .= '<text class="volance-axis-label" x="' . ( $pad_l - 6 ) . '" y="' . ( $h - $pad_b ) . '" text-anchor="end">0</text>';
		$i    = 0;
		foreach ( $series as $label => $n ) {
			$bh    = (int) round( $plot * $n / $top );
			$x     = round( $pad_l + $i * $slot + ( $slot - $bar ) / 2, 2 );
			$svg  .= '<g><rect class="volance-chart-bar" x="' . $x . '" y="' . ( $h - $pad_b - $bh ) . '" width="' . round( $bar, 2 ) . '" height="' . max( 0, $bh ) . '" rx="2"><title>' . esc_html( $label . ': ' . $n ) . '</title></rect></g>';
			++$i;
		}
		$labels = array_keys( $series );
		$svg   .= '<text class="volance-axis-label" x="' . $pad_l . '" y="' . ( $h - 6 ) . '">' . esc_html( (string) reset( $labels ) ) . '</text>';
		$svg   .= '<text class="volance-axis-label" x="' . $w . '" y="' . ( $h - 6 ) . '" text-anchor="end">' . esc_html( (string) end( $labels ) ) . '</text>';
		return $svg . '</svg>';
	}
}
