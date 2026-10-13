<?php
/**
 * Help tab: how the plugin works, links to the docs and the portal.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Help tab.
 */
class Volance_Detection_Admin_Help {

	/**
	 * Register hooks (none needed; the tab is static content).
	 *
	 * @return void
	 */
	public static function init() {
		// Static page: nothing to register.
	}

	/**
	 * Links to Volance resources.
	 *
	 * @return array label => url.
	 */
	public static function links() {
		return array(
			__( 'Volance portal (sessions, audit, members, billing)', 'volance-detection' ) => 'https://app.volance.com',
			__( 'Product docs', 'volance-detection' )                                       => 'https://volance.com/docs',
			__( 'API reference', 'volance-detection' )                                      => 'https://volance.com/docs/api',
			__( 'Agent monetization (402 paygate)', 'volance-detection' )                   => 'https://volance.com/docs/agent-monetization',
			__( 'Terms', 'volance-detection' )                                              => 'https://volance.com/terms',
			__( 'Privacy', 'volance-detection' )                                            => 'https://volance.com/privacy',
		);
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public static function render() {
		echo '<div class="volance-card"><h2>' . esc_html__( 'How it works', 'volance-detection' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'A small script from Volance records how a visitor interacts with a page (timing and pointer movement, never what they type).', 'volance-detection' ) . '</li>';
		echo '<li>' . esc_html__( 'When a protected form or endpoint is used, your server sends that evidence, the visitor\'s IP address and request headers to Volance with your secret key.', 'volance-detection' ) . '</li>';
		echo '<li>' . esc_html__( 'Volance returns a score from 0 to 100, a classification (human, agent or bot), the signals behind it and a recommended action: allow, flag or block.', 'volance-detection' ) . '</li>';
		echo '<li>' . esc_html__( 'For each form you choose what the plugin does with that action: log it, hold or flag it, or block it.', 'volance-detection' ) . '</li>';
		echo '</ol><p>' . esc_html__( 'A score is an estimate, not proof that someone is human or a bot.', 'volance-detection' ) . '</p></div>';

		echo '<div class="volance-card"><h2>' . esc_html__( 'Links', 'volance-detection' ) . '</h2><ul>';
		foreach ( self::links() as $label => $url ) {
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul></div>';
	}
}
