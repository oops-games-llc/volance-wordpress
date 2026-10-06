<?php
/**
 * Uninstall Volance Detection.
 *
 * Removes the plugin's own options, transients, log table and scheduled job. It
 * does not touch data already stored in the Volance workspace: delete that in
 * the Volance portal.
 *
 * @package Volance_Detection
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything this plugin stored on the current site.
 *
 * @return void
 */
function volance_detection_uninstall_site() {
	global $wpdb;

	foreach ( array(
		'volance_detection_settings',
		'volance_detection_secret',
		'volance_detection_flags',
		'volance_detection_last_error',
		'volance_detection_db_version',
	) as $volance_option ) {
		delete_option( $volance_option );
	}
	foreach ( array(
		'volance_detection_backoff',
		'volance_detection_usage',
		'volance_detection_noev',
	) as $volance_transient ) {
		delete_transient( $volance_transient );
	}
	wp_clear_scheduled_hook( 'volance_detection_daily' );

	$volance_table = $wpdb->prefix . 'volance_detection_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall of the plugin's own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$volance_table}" );
}

if ( is_multisite() ) {
	$volance_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $volance_site_ids as $volance_site_id ) {
		switch_to_blog( $volance_site_id );
		volance_detection_uninstall_site();
		restore_current_blog();
	}
} else {
	volance_detection_uninstall_site();
}
