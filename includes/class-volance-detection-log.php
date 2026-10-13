<?php
/**
 * Activity log: one row per observed submission, kept locally for 30 days.
 *
 * Each scored row also keeps a compact copy of the Volance response (signals,
 * classification, consistency, overrides) so the detail view can explain a
 * verdict. The log stores no IP address, no raw user agent and no form content,
 * and never the trust token.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log table access.
 */
class Volance_Detection_Log {

	const DB_VERSION_OPTION = 'volance_detection_db_version';
	const DB_VERSION        = '2';

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
			agent_type varchar(40) NOT NULL DEFAULT '',
			events int(11) NOT NULL DEFAULT 0,
			behavioural tinyint(1) NOT NULL DEFAULT 0,
			request_id varchar(64) NOT NULL DEFAULT '',
			note varchar(190) NOT NULL DEFAULT '',
			response longtext,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY verdict (verdict),
			KEY form (form)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Install or upgrade the table if this site was updated without re-activation.
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
	 * @param array $row Row values (form, status, score, verdict, label, agent_type, events,
	 *                   behavioural, request_id, note, response). `response` is a compact
	 *                   response array from Volance_Detection_Response::compact().
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
			'agent_type'  => substr( (string) ( $row['agent_type'] ?? '' ), 0, 40 ),
			'events'      => (int) ( $row['events'] ?? 0 ),
			'behavioural' => empty( $row['behavioural'] ) ? 0 : 1,
			'request_id'  => substr( (string) ( $row['request_id'] ?? '' ), 0, 64 ),
			'note'        => substr( (string) ( $row['note'] ?? '' ), 0, 190 ),
		);
		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
		if ( isset( $row['score'] ) && null !== $row['score'] ) {
			$data['score'] = round( (float) $row['score'], 1 );
			$format[]      = '%f';
		}
		if ( isset( $row['response'] ) && is_array( $row['response'] ) && $row['response'] ) {
			$json = Volance_Detection_Response::to_json( $row['response'] );
			if ( '' !== $json ) {
				$data['response'] = $json;
				$format[]         = '%s';
			}
		}
		$wpdb->insert( self::table(), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin's own table.
	}

	/**
	 * Build a WHERE clause from filters.
	 *
	 * @param array $f Filters: status, verdict, label, form, min_score, max_score, from, to, q, since.
	 * @return array {0: string SQL (starts with ' WHERE' or empty), 1: array args}.
	 */
	public static function where( array $f ) {
		global $wpdb;
		$parts = array();
		$args  = array();

		foreach ( array( 'status', 'verdict', 'label', 'form' ) as $key ) {
			if ( isset( $f[ $key ] ) && '' !== (string) $f[ $key ] ) {
				$parts[] = "{$key} = %s";
				$args[]  = (string) $f[ $key ];
			}
		}
		if ( isset( $f['min_score'] ) && '' !== (string) $f['min_score'] && is_numeric( $f['min_score'] ) ) {
			$parts[] = 'score >= %f';
			$args[]  = (float) $f['min_score'];
		}
		if ( isset( $f['max_score'] ) && '' !== (string) $f['max_score'] && is_numeric( $f['max_score'] ) ) {
			$parts[] = 'score <= %f';
			$args[]  = (float) $f['max_score'];
		}
		if ( ! empty( $f['from'] ) ) {
			$parts[] = 'created_at >= %s';
			$args[]  = (string) $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) ) {
			$parts[] = 'created_at <= %s';
			$args[]  = (string) $f['to'] . ' 23:59:59';
		}
		if ( ! empty( $f['since'] ) ) {
			$parts[] = 'created_at >= %s';
			$args[]  = (string) $f['since'];
		}
		if ( isset( $f['q'] ) && '' !== (string) $f['q'] ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
			$parts[] = '(request_id LIKE %s OR verdict LIKE %s OR label LIKE %s OR form LIKE %s OR agent_type LIKE %s OR note LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like, $like );
		}

		return array( $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '', $args );
	}

	/**
	 * ORDER BY clause for a sort key.
	 *
	 * @param string $sort newest|oldest|score.
	 * @return string
	 */
	private static function order( $sort ) {
		if ( 'oldest' === $sort ) {
			return ' ORDER BY id ASC';
		}
		if ( 'score' === $sort ) {
			return ' ORDER BY score DESC, id DESC';
		}
		return ' ORDER BY id DESC';
	}

	/**
	 * Fetch a page of rows.
	 *
	 * @param array  $filters  See where().
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Rows per page.
	 * @param string $sort     newest|oldest|score.
	 * @return array
	 */
	public static function query( array $filters = array(), $page = 1, $per_page = 25, $sort = 'newest' ) {
		global $wpdb;
		$table  = esc_sql( self::table() );
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		list( $where, $args ) = self::where( $filters );
		$args[] = (int) $per_page;
		$args[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own table; $where is built from fixed fragments with placeholders.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where}" . self::order( $sort ) . ' LIMIT %d OFFSET %d', $args ) );
	}

	/**
	 * Count rows matching filters.
	 *
	 * @param array $filters See where().
	 * @return int
	 */
	public static function total( array $filters = array() ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		list( $where, $args ) = self::where( $filters );
		$sql = "SELECT COUNT(*) FROM {$table}{$where}";
		if ( $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own table; fixed fragments with placeholders.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own table; no user input.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Fetch a page of rows, newest first (status filter only).
	 *
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Rows per page.
	 * @param string $status   Optional status filter.
	 * @return array
	 */
	public static function rows( $page = 1, $per_page = 25, $status = '' ) {
		return self::query( array( 'status' => $status ), $page, $per_page );
	}

	/**
	 * Count rows (status filter only).
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public static function count( $status = '' ) {
		return self::total( array( 'status' => $status ) );
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		return $row ? $row : null;
	}

	/**
	 * Delete one row from the local log.
	 *
	 * @param int $id Row id.
	 * @return bool Whether a row was removed.
	 */
	public static function delete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table.
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Oldest row time (unix), or 0 when empty.
	 *
	 * @return int
	 */
	public static function first_time() {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; no user input.
		$value = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" );
		return $value ? (int) strtotime( $value . ' UTC' ) : 0;
	}

	/**
	 * Raw aggregates for the Overview.
	 *
	 * @param int    $since Unix time lower bound, or 0 for everything kept.
	 * @param string $unit  'hour' or 'day' for the series.
	 * @return array See Volance_Detection_Stats::build().
	 */
	public static function aggregate( $since, $unit ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		$where = '';
		$args  = array();
		if ( $since > 0 ) {
			$where  = ' WHERE created_at >= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', (int) $since );
		}
		$and = '' === $where ? ' WHERE' : ' AND';

		$run = static function ( $sql, $params ) use ( $wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; fixed fragments with placeholders.
			return (array) $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
		};

		$out = array(
			'statuses'  => array(),
			'verdicts'  => array(),
			'labels'    => array(),
			'forms'     => array(),
			'series'    => array(),
			'score_sum' => 0.0,
		);

		foreach ( $run( "SELECT status AS k, COUNT(*) AS n FROM {$table}{$where} GROUP BY status", $args ) as $r ) {
			$out['statuses'][ (string) $r->k ] = (int) $r->n;
		}
		foreach ( $run( "SELECT verdict AS k, COUNT(*) AS n FROM {$table}{$where}{$and} status = 'scored' GROUP BY verdict", $args ) as $r ) {
			if ( '' !== (string) $r->k ) {
				$out['verdicts'][ (string) $r->k ] = (int) $r->n;
			}
		}
		foreach ( $run( "SELECT label AS k, COUNT(*) AS n FROM {$table}{$where}{$and} status = 'scored' GROUP BY label", $args ) as $r ) {
			if ( '' !== (string) $r->k ) {
				$out['labels'][ (string) $r->k ] = (int) $r->n;
			}
		}
		foreach ( $run( "SELECT form AS k, COUNT(*) AS n FROM {$table}{$where} GROUP BY form", $args ) as $r ) {
			$out['forms'][ (string) $r->k ] = (int) $r->n;
		}
		$fmt = 'hour' === $unit ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
		if ( $args ) {
			// prepare() would treat the date specifiers as placeholders.
			$fmt = str_replace( '%', '%%', $fmt );
		}
		foreach ( $run( "SELECT DATE_FORMAT(created_at, '{$fmt}') AS k, COUNT(*) AS n FROM {$table}{$where}{$and} status = 'scored' GROUP BY k", $args ) as $r ) {
			$out['series'][ (string) $r->k ] = (int) $r->n;
		}
		foreach ( $run( "SELECT SUM(score) AS s FROM {$table}{$where}{$and} status = 'scored'", $args ) as $r ) {
			$out['score_sum'] = (float) $r->s;
		}
		return $out;
	}

	/**
	 * Verdict counts for the last N days (scored rows only).
	 *
	 * @param int $days Days.
	 * @return array verdict => count.
	 */
	public static function verdict_counts( $days = 7 ) {
		$aggregate = self::aggregate( time() - ( (int) $days * DAY_IN_SECONDS ), 'day' );
		return $aggregate['verdicts'];
	}

	/**
	 * Distinct values for a filter dropdown.
	 *
	 * @param string $column verdict|label|form.
	 * @return string[]
	 */
	public static function distinct( $column ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'verdict', 'label', 'form' ), true ) ) {
			return array();
		}
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; $column is allow-listed above.
		$values = (array) $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} <> '' ORDER BY {$column} ASC LIMIT 50" );
		return array_map( 'strval', $values );
	}

	/**
	 * A batch of rows for export, oldest first, after a given id.
	 *
	 * @param array $filters  See where().
	 * @param int   $after_id Return rows with a larger id.
	 * @param int   $limit    Batch size.
	 * @return array
	 */
	public static function export_batch( array $filters, $after_id, $limit = 500 ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		list( $where, $args ) = self::where( $filters );
		$where .= '' === $where ? ' WHERE id > %d' : ' AND id > %d';
		$args[] = (int) $after_id;
		$args[] = (int) $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own table; fixed fragments with placeholders.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY id ASC LIMIT %d", $args ) );
	}

	/**
	 * Delete rows older than the retention period (default 30 days), and keep
	 * the table below a row cap so a busy site cannot fill the database.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		$days   = max( 1, (int) apply_filters( 'volance_detection_log_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );

		$max = max( 1000, (int) apply_filters( 'volance_detection_log_max_rows', 50000 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
		$edge = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) );
		if ( $edge ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own table; name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $edge ) );
		}
	}

	/**
	 * Drop the table (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall of the plugin's own table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
