<?php
/**
 * Usage and plan tab: current plan, monthly meter and the plan comparison, read
 * from the Volance usage report (secret key, never metered).
 *
 * Changing plan, billing and members happen in the Volance portal for now.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plan and usage rendering.
 */
class Volance_Detection_Admin_Plan {

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			echo '<div class="volance-card"><p>' . esc_html__( 'Add your keys in Setup to see your plan and usage.', 'volance-detection' ) . '</p><p><a class="button button-primary" href="' . esc_url( Volance_Detection_Admin::url( 'setup' ) ) . '">' . esc_html__( 'Go to Setup', 'volance-detection' ) . '</a></p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- refresh of a read-only cache.
		$refresh = isset( $_GET['refresh'] ) && current_user_can( 'manage_options' );
		$usage   = Volance_Detection_Admin::usage( (bool) $refresh );
		if ( ! $usage || empty( $usage['usage'] ) || ! is_array( $usage['usage'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Volance did not return your plan just now. Check the connection in Setup and try again.', 'volance-detection' ) . '</p></div>';
			return;
		}

		$name  = isset( $usage['name'] ) && is_string( $usage['name'] ) ? $usage['name'] : '';
		$used  = (int) ( $usage['usage']['monthScores'] ?? 0 );
		$limit = (int) ( $usage['usage']['limit'] ?? 0 );
		$pct   = $limit > 0 ? min( 100, (int) round( 100 * $used / $limit ) ) : 0;
		$price = isset( $usage['priceMonthly'] ) ? (float) $usage['priceMonthly'] : 0.0;

		echo '<div class="volance-card"><h2>' . esc_html( sprintf( /* translators: %s: plan name. */ __( '%s plan', 'volance-detection' ), '' !== $name ? $name : '-' ) ) . '</h2>';
		echo '<p>' . esc_html( $price > 0 ? sprintf( /* translators: %s: price per month in USD. */ __( '$%s per month', 'volance-detection' ), number_format_i18n( $price ) ) : __( 'No monthly charge', 'volance-detection' ) ) . '</p>';
		echo '<div class="volance-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span class="' . ( $pct >= 100 ? 'is-full' : ( $pct >= 80 ? 'is-warn' : '' ) ) . '" style="width:' . (int) $pct . '%"></span></div>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: scores used, 2: monthly limit, 3: percent. */
				__( '%1$s of %2$s scores used this month (%3$s%%).', 'volance-detection' ),
				number_format_i18n( $used ),
				$limit > 0 ? number_format_i18n( $limit ) : '-',
				number_format_i18n( $pct )
			)
		) . '</p>';
		if ( ! empty( $usage['notice'] ) && is_string( $usage['notice'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $usage['notice'] ) . '</p></div>';
		}
		$facts = array();
		if ( isset( $usage['limits']['projects'] ) ) {
			/* translators: %s: number of workspaces. */
			$facts[] = sprintf( _n( '%s workspace', '%s workspaces', (int) $usage['limits']['projects'], 'volance-detection' ), number_format_i18n( (int) $usage['limits']['projects'] ) );
		}
		if ( isset( $usage['limits']['seats'] ) ) {
			/* translators: %s: number of team members. */
			$facts[] = sprintf( _n( '%s team member', '%s team members', (int) $usage['limits']['seats'], 'volance-detection' ), number_format_i18n( (int) $usage['limits']['seats'] ) );
		}
		if ( isset( $usage['retentionDays'] ) ) {
			/* translators: %s: number of days. */
			$facts[] = sprintf( __( '%s-day Volance retention', 'volance-detection' ), number_format_i18n( (int) $usage['retentionDays'] ) );
		}
		if ( ! empty( $usage['support'] ) && is_string( $usage['support'] ) ) {
			$facts[] = $usage['support'];
		}
		if ( $facts ) {
			echo '<p class="description">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
		}
		if ( ! empty( $usage['requestedPlan'] ) && is_string( $usage['requestedPlan'] ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: plan id. */ __( 'An upgrade to %s has been requested and is awaiting review.', 'volance-detection' ), $usage['requestedPlan'] ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( Volance_Detection_Admin::url( 'plan', array( 'refresh' => 1 ) ) ) . '">' . esc_html__( 'Refresh', 'volance-detection' ) . '</a> <a class="button button-primary" href="https://app.volance.com/#usage" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Manage plan and billing in Volance', 'volance-detection' ) . '</a></p></div>';

		if ( ! empty( $usage['plans'] ) && is_array( $usage['plans'] ) ) {
			self::compare( $usage['plans'], isset( $usage['id'] ) ? (string) $usage['id'] : '' );
		}
	}

	/**
	 * Plan comparison table.
	 *
	 * @param array  $plans   Plan summaries.
	 * @param string $current Current plan id.
	 * @return void
	 */
	private static function compare( array $plans, $current ) {
		echo '<div class="volance-card"><h3>' . esc_html__( 'Compare plans', 'volance-detection' ) . '</h3><table class="widefat striped volance-compare"><thead><tr><th scope="col"></th>';
		foreach ( $plans as $plan ) {
			echo '<th scope="col">' . esc_html( (string) ( $plan['name'] ?? '' ) ) . ( (string) ( $plan['id'] ?? '' ) === $current ? ' <span class="volance-badge">' . esc_html__( 'Current', 'volance-detection' ) . '</span>' : '' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = array(
			__( 'Price per month', 'volance-detection' ) => static function ( $p ) {
				return ! empty( $p['priceMonthly'] ) ? '$' . number_format_i18n( (float) $p['priceMonthly'] ) : __( 'Free', 'volance-detection' );
			},
			__( 'Scores per month', 'volance-detection' ) => static function ( $p ) {
				return isset( $p['monthlyScores'] ) ? number_format_i18n( (int) $p['monthlyScores'] ) : '-';
			},
			__( 'Workspaces', 'volance-detection' )       => static function ( $p ) {
				return isset( $p['projects'] ) ? number_format_i18n( (int) $p['projects'] ) : '-';
			},
			__( 'Team members', 'volance-detection' )     => static function ( $p ) {
				return isset( $p['seats'] ) ? number_format_i18n( (int) $p['seats'] ) : '-';
			},
			__( 'At quota', 'volance-detection' )         => static function ( $p ) {
				return 'degrade' === ( $p['overage'] ?? '' ) ? __( 'Keeps scoring', 'volance-detection' ) : __( 'Pauses scoring', 'volance-detection' );
			},
			__( 'Agent decisions per month', 'volance-detection' ) => static function ( $p ) {
				return isset( $p['monthlyDecisions'] ) ? number_format_i18n( (int) $p['monthlyDecisions'] ) : '-';
			},
			__( 'Support', 'volance-detection' )          => static function ( $p ) {
				return (string) ( $p['support'] ?? '' );
			},
		);
		foreach ( $rows as $label => $cell ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $plans as $plan ) {
				echo '<td>' . esc_html( (string) $cell( $plan ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'The Business plan is arranged through the contact form in Help.', 'volance-detection' ) . '</p></div>';
	}
}
