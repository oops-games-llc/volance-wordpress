<?php
/**
 * Activity log: one row per observed submission, kept locally for 30 days.
 *
 * The log stores no IP address, no user agent and no form content.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log table access.
 */
class Volance_Detection_Log {

	const DB_VERSION_OPTION = 'volance_detection_db_version';
	const DB_VERSION        = '1';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'volance_detection_log';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			form varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			score decimal(5,1) DEFAULT NULL,
			verdict varchar(40) NOT NULL DEFAULT '',
			label varchar(20) NOT NULL DEFAULT '',
			events int(11) NOT NULL DEFAULT 0,
			behavioural tinyint(1) NOT NULL DEFAULT 0,
			request_id varchar(64) NOT NULL DEFAULT '',
			note varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Install the table if this site was updated without re-activation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Insert one row.
	 *
	 * @param array $row Row values (form, status, score, verdict, label, events, behavioural, request_id, note).
	 * @return void
	 */
	public static function add( array $row ) {
		global $wpdb;
		$data   = array(
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'form'        => substr( (string) ( $row['form'] ?? '' ), 0, 20 ),
			'status'      => substr( (string) ( $row['status'] ?? '' ), 0, 20 ),
			'verdict'     => substr( (string) ( $row['verdict'] ?? '' ), 0, 40 ),
			'label'       => substr( (string) ( $row['label'] ?? '' ), 0, 20 ),
			'events'      => (int) ( $row['events'] ?? 0 ),
			'behavioural' => empty( $row['behavioural'] ) ? 0 : 1,
			'request_id'  => substr( (string) ( $row['request_id'] ?? '' ), 0, 64 ),
			'note'        => substr( (string) ( $row['note'] ?? '' ), 0, 190 ),
		);
		$format = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
		if ( isset( $row['score'] ) && null !== $row['score'] ) {
			$data['score'] = round( (float) $row['score'], 1 );
			$format[]      = '%f';
		}
		$wpdb->insert( self::table(), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin's own table.
	}

	/**
	 * Fetch a page of rows, newest first.
	 *
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Rows per page.
	 * @param string $status   Optional status filter.
	 * @return array
	 */
	public static function rows( $page = 1, $per_page = 25, $status = '' ) {
		global $wpdb;
		$table  = self::table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, (int) $per_page, $offset ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ) );
	}

	/**
	 * Count rows.
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public static function count( $status = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Verdict counts for the last N days (scored rows only).
	 *
	 * @param int $days Days.
	 * @return array verdict => count.
	 */
	public static function verdict_counts( $days = 7 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT verdict, COUNT(*) AS total FROM {$table} WHERE status = 'scored' AND created_at >= %s GROUP BY verdict", $since ) );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row->verdict ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Delete rows older than the retention period (default 30 days).
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		$days   = max( 1, (int) apply_filters( 'volance_detection_log_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Drop the table (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall of the plugin's own table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
