<?php
/**
 * Uninstall Volance Detection.
 *
 * Removes the plugin's own options. It does not touch data already stored in the
 * Volance workspace — delete that in the Volance portal.
 *
 * @package Volance_Detection
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'volance_detection_settings' );

if ( is_multisite() ) {
	$volance_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $volance_site_ids as $volance_site_id ) {
		switch_to_blog( $volance_site_id );
		delete_option( 'volance_detection_settings' );
		restore_current_blog();
	}
}
