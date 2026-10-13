<?php
/**
 * Activity tab: filterable log, per-entry detail view, delete and CSV export.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activity rendering and handlers.
 */
class Volance_Detection_Admin_Activity {

	const PER_PAGE   = 25;
	const EXPORT_MAX = 50000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_volance_detection_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_volance_detection_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Read and sanitize the filters from a request array.
	 *
	 * @param array $src Usually $_GET.
	 * @return array Filters for Volance_Detection_Log::where() plus 'sort'.
	 */
	public static function filters( array $src ) {
		$statuses = Volance_Detection_Admin::status_labels();
		$out      = array();

		$status = isset( $src['status'] ) ? sanitize_key( wp_unslash( $src['status'] ) ) : '';
		if ( isset( $statuses[ $status ] ) ) {
			$out['status'] = $status;
		}
		foreach ( array( 'verdict', 'label', 'form' ) as $key ) {
			$value = isset( $src[ $key ] ) ? sanitize_key( wp_unslash( $src[ $key ] ) ) : '';
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
		foreach ( array( 'min_score', 'max_score' ) as $key ) {
			$value = isset( $src[ $key ] ) ? trim( (string) wp_unslash( $src[ $key ] ) ) : '';
			if ( '' !== $value && is_numeric( $value ) ) {
				$out[ $key ] = max( 0, min( 100, (float) $value ) );
			}
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			$value = isset( $src[ $key ] ) ? trim( (string) wp_unslash( $src[ $key ] ) ) : '';
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$out[ $key ] = $value;
			}
		}
		$q = isset( $src['q'] ) ? trim( sanitize_text_field( wp_unslash( $src['q'] ) ) ) : '';
		if ( '' !== $q ) {
			$out['q'] = substr( $q, 0, 80 );
		}
		$sort        = isset( $src['sort'] ) ? sanitize_key( wp_unslash( $src['sort'] ) ) : '';
		$out['sort'] = in_array( $sort, array( 'oldest', 'score' ), true ) ? $sort : 'newest';
		return $out;
	}

	/**
	 * Render the tab: a single entry when ?view= is set, otherwise the list.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		$view = isset( $_GET['view'] ) ? absint( wp_unslash( $_GET['view'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice flag only.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Entry deleted from this site\'s log. Anything stored in your Volance workspace is unchanged.', 'volance-detection' ) . '</p></div>';
		}
		if ( $view > 0 ) {
			self::render_detail( $view );
			return;
		}
		self::render_list();
	}

	/**
	 * Filter form, table and pagination.
	 *
	 * @return void
	 */
	private static function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filters and page number only.
		$filters = self::filters( $_GET );
		$sort    = $filters['sort'];
		unset( $filters['sort'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page number only.
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$total = Volance_Detection_Log::total( $filters );
		$rows  = Volance_Detection_Log::query( $filters, $page, self::PER_PAGE, $sort );

		$statuses = Volance_Detection_Admin::status_labels();
		$query    = array_merge( $filters, array( 'sort' => $sort ) );

		echo '<form method="get" class="volance-filters"><input type="hidden" name="page" value="' . esc_attr( Volance_Detection_Admin::PAGE ) . '" /><input type="hidden" name="tab" value="activity" />';

		echo '<label>' . esc_html__( 'Result', 'volance-detection' ) . '<select name="status"><option value="">' . esc_html__( 'All', 'volance-detection' ) . '</option>';
		foreach ( $statuses as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $filters['status'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		self::select( 'verdict', __( 'Verdict', 'volance-detection' ), Volance_Detection_Log::distinct( 'verdict' ), $filters['verdict'] ?? '' );
		self::select( 'label', __( 'Class', 'volance-detection' ), Volance_Detection_Log::distinct( 'label' ), $filters['label'] ?? '' );
		self::select( 'form', __( 'Form', 'volance-detection' ), Volance_Detection_Log::distinct( 'form' ), $filters['form'] ?? '', true );

		echo '<label>' . esc_html__( 'Score from', 'volance-detection' ) . '<input type="number" min="0" max="100" step="1" name="min_score" value="' . esc_attr( (string) ( $filters['min_score'] ?? '' ) ) . '" class="small-text" /></label>';
		echo '<label>' . esc_html__( 'to', 'volance-detection' ) . '<input type="number" min="0" max="100" step="1" name="max_score" value="' . esc_attr( (string) ( $filters['max_score'] ?? '' ) ) . '" class="small-text" /></label>';
		echo '<label>' . esc_html__( 'From', 'volance-detection' ) . '<input type="date" name="from" value="' . esc_attr( $filters['from'] ?? '' ) . '" /></label>';
		echo '<label>' . esc_html__( 'To', 'volance-detection' ) . '<input type="date" name="to" value="' . esc_attr( $filters['to'] ?? '' ) . '" /></label>';
		echo '<label>' . esc_html__( 'Search', 'volance-detection' ) . '<input type="search" name="q" value="' . esc_attr( $filters['q'] ?? '' ) . '" placeholder="' . esc_attr__( 'Request ID, verdict, note', 'volance-detection' ) . '" /></label>';

		echo '<label>' . esc_html__( 'Sort', 'volance-detection' ) . '<select name="sort">';
		foreach ( array(
			'newest' => __( 'Newest first', 'volance-detection' ),
			'oldest' => __( 'Oldest first', 'volance-detection' ),
			'score'  => __( 'Highest score', 'volance-detection' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $sort, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'Filter', 'volance-detection' ), 'secondary', '', false );
		echo ' <a class="button-link" href="' . esc_url( Volance_Detection_Admin::url( 'activity' ) ) . '">' . esc_html__( 'Reset', 'volance-detection' ) . '</a>';
		echo '</form>';

		$export = wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => 'volance_detection_export' ), $query ), admin_url( 'admin-post.php' ) ),
			'volance_detection_export'
		);
		echo '<p class="volance-actions">' . esc_html(
			sprintf(
				/* translators: %s: number of entries. */
				_n( '%s entry', '%s entries', $total, 'volance-detection' ),
				number_format_i18n( $total )
			)
		) . ' &middot; <a href="' . esc_url( $export ) . '">' . esc_html__( 'Download CSV', 'volance-detection' ) . '</a></p>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing matches. Submissions appear here after a visitor allows detection and submits an observed form.', 'volance-detection' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped volance-table"><thead><tr>';
		foreach ( array( __( 'Time', 'volance-detection' ), __( 'Form', 'volance-detection' ), __( 'Result', 'volance-detection' ), __( 'Action', 'volance-detection' ), __( 'Class', 'volance-detection' ), __( 'Browser evidence', 'volance-detection' ), __( 'Request ID', 'volance-detection' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$class = (string) $row->label;
			if ( '' !== (string) $row->agent_type && $class !== (string) $row->agent_type ) {
				$class .= ' (' . $row->agent_type . ')';
			}
			echo '<tr>';
			echo '<td>' . esc_html( Volance_Detection_Admin::format_time( (string) $row->created_at ) ) . '</td>';
			echo '<td>' . esc_html( Volance_Detection_Admin::form_label( (string) $row->form ) ) . '</td>';
			echo '<td>' . esc_html( self::result_text( $row, $statuses ) ) . '</td>';
			echo '<td>' . esc_html( self::action_text( $row ) ) . '</td>';
			echo '<td>' . esc_html( $class ) . '</td>';
			echo '<td>' . esc_html( self::evidence_text( $row ) ) . '</td>';
			echo '<td>' . ( '' !== (string) $row->request_id ? '<code>' . esc_html( (string) $row->request_id ) . '</code>' : '' ) . '</td>';
			echo '<td><a href="' . esc_url( Volance_Detection_Admin::url( 'activity', array( 'view' => (int) $row->id ) ) ) . '">' . esc_html__( 'Details', 'volance-detection' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', Volance_Detection_Admin::url( 'activity', $query ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '<p class="description">' . esc_html__( 'A score runs from 0 to 100 and is an estimate. A missing score means nothing was sent to Volance for that submission. Entries are deleted after 30 days. This log stores a summary of the Volance result, but no IP address, browser details or form content.', 'volance-detection' ) . '</p>';
	}

	/**
	 * A filter dropdown built from values seen in the log.
	 *
	 * @param string   $name    Field name.
	 * @param string   $label   Label.
	 * @param string[] $values  Options.
	 * @param string   $current Selected value.
	 * @param bool     $forms   Translate form keys.
	 * @return void
	 */
	private static function select( $name, $label, array $values, $current, $forms = false ) {
		echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'All', 'volance-detection' ) . '</option>';
		foreach ( $values as $value ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $forms ? Volance_Detection_Admin::form_label( $value ) : $value ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * Action column text: what the plugin did, and what Volance recommended if that differs.
	 *
	 * @param object $row Log row.
	 * @return string
	 */
	private static function action_text( $row ) {
		$recommended = (string) ( $row->action ?? '' );
		$applied     = (string) ( $row->applied ?? '' );
		if ( '' === $recommended ) {
			return '';
		}
		$labels = array(
			'allow' => __( 'Allowed', 'volance-detection' ),
			'flag'  => __( 'Flagged', 'volance-detection' ),
			'block' => __( 'Blocked', 'volance-detection' ),
		);
		$done   = $labels[ $applied ] ?? $applied;
		if ( $recommended === $applied || 'flag' === $applied ) {
			return $done;
		}
		/* translators: 1: what the plugin did (Allowed), 2: what Volance recommended (block). */
		return sprintf( __( '%1$s (Volance: %2$s)', 'volance-detection' ), $done, $recommended );
	}

	/**
	 * Result column text.
	 *
	 * @param object $row      Log row.
	 * @param array  $statuses Status labels.
	 * @return string
	 */
	private static function result_text( $row, array $statuses ) {
		$status = (string) $row->status;
		if ( 'scored' !== $status ) {
			return $statuses[ $status ] ?? $status;
		}
		return sprintf(
			'%s%s',
			null !== $row->score ? number_format_i18n( (float) $row->score, 1 ) : '-',
			'' !== (string) $row->verdict ? ' (' . $row->verdict . ')' : ''
		);
	}

	/**
	 * Browser evidence column text.
	 *
	 * @param object $row Log row.
	 * @return string
	 */
	private static function evidence_text( $row ) {
		if ( ! (int) $row->behavioural ) {
			return __( 'No browser evidence', 'volance-detection' );
		}
		/* translators: %s: number of recorded events. */
		return sprintf( _n( '%s event', '%s events', (int) $row->events, 'volance-detection' ), number_format_i18n( (int) $row->events ) );
	}

	/**
	 * Detail view for one entry.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	private static function render_detail( $id ) {
		$row  = Volance_Detection_Log::get( $id );
		$back = Volance_Detection_Admin::url( 'activity' );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to activity', 'volance-detection' ) . '</a></p>';
		if ( ! $row ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'That entry no longer exists. Entries are deleted after 30 days.', 'volance-detection' ) . '</p></div>';
			return;
		}

		$statuses = Volance_Detection_Admin::status_labels();
		$data     = Volance_Detection_Response::from_json( $row->response ?? '' );

		echo '<div class="volance-card"><h2>' . esc_html( self::result_text( $row, $statuses ) ) . '</h2><dl class="volance-dl">';
		self::dl( __( 'Time', 'volance-detection' ), Volance_Detection_Admin::format_time( (string) $row->created_at ) );
		self::dl( __( 'Form', 'volance-detection' ), Volance_Detection_Admin::form_label( (string) $row->form ) );
		self::dl( __( 'Result', 'volance-detection' ), $statuses[ (string) $row->status ] ?? (string) $row->status );
		self::dl( __( 'Browser evidence', 'volance-detection' ), self::evidence_text( $row ) );
		if ( '' !== (string) $row->request_id ) {
			self::dl( __( 'Request ID', 'volance-detection' ), (string) $row->request_id, true );
		}
		if ( '' !== (string) $row->note ) {
			self::dl( __( 'Note', 'volance-detection' ), (string) $row->note );
		}
		foreach ( array(
			'band'      => __( 'Band', 'volance-detection' ),
			'action'    => __( 'Action', 'volance-detection' ),
			'threshold' => __( 'Threshold', 'volance-detection' ),
		) as $key => $label ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				self::dl( $label, (string) $data[ $key ] );
			}
		}
		if ( isset( $data['confidence'] ) ) {
			self::dl( __( 'Confidence', 'volance-detection' ), (string) $data['confidence'] );
		}
		echo '</dl></div>';

		if ( ! $data ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No further detail was kept for this entry. Only scored entries recorded by version 0.2 or later include the signal breakdown.', 'volance-detection' ) . '</p></div>';
		} else {
			self::render_data( $data );
		}

		$delete = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'volance_detection_delete',
					'id'     => (int) $row->id,
				),
				admin_url( 'admin-post.php' )
			),
			'volance_detection_delete_' . (int) $row->id
		);
		echo '<p><a class="button button-link-delete volance-confirm-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete this entry', 'volance-detection' ) . '</a></p>';
	}

	/**
	 * Classification, signals and the raw copy.
	 *
	 * @param array $data Compact response.
	 * @return void
	 */
	private static function render_data( array $data ) {
		if ( ! empty( $data['classification'] ) ) {
			$c = $data['classification'];
			echo '<div class="volance-card"><h3>' . esc_html__( 'Classification', 'volance-detection' ) . '</h3><dl class="volance-dl">';
			foreach ( array(
				'label'     => __( 'Class', 'volance-detection' ),
				'agentType' => __( 'Agent type', 'volance-detection' ),
				'model'     => __( 'Model', 'volance-detection' ),
			) as $key => $label ) {
				if ( ! empty( $c[ $key ] ) ) {
					self::dl( $label, (string) $c[ $key ] );
				}
			}
			if ( ! empty( $c['probabilities'] ) ) {
				$parts = array();
				foreach ( $c['probabilities'] as $name => $p ) {
					$parts[] = $name . ' ' . round( (float) $p * 100 ) . '%';
				}
				self::dl( __( 'Probabilities', 'volance-detection' ), implode( ', ', $parts ) );
			}
			echo '</dl></div>';
		}

		if ( ! empty( $data['signals'] ) && is_array( $data['signals'] ) ) {
			echo '<div class="volance-card"><h3>' . esc_html__( 'Signals by layer', 'volance-detection' ) . '</h3>';
			$names = Volance_Detection_Response::layer_names();
			foreach ( Volance_Detection_Response::group_signals( $data['signals'] ) as $layer => $items ) {
				$fired = count( array_filter( $items, static function ( $s ) {
					return ! empty( $s['fired'] );
				} ) );
				echo '<h4>' . esc_html( ( $names[ $layer ] ?? __( 'Other', 'volance-detection' ) ) . ' (' . $fired . '/' . count( $items ) . ')' ) . '</h4>';
				echo '<table class="widefat striped volance-signals"><tbody>';
				foreach ( $items as $s ) {
					$on = ! empty( $s['fired'] );
					echo '<tr class="' . ( $on ? 'volance-fired' : 'volance-quiet' ) . '"><td><code>' . esc_html( (string) $s['id'] ) . '</code></td><td>' . esc_html( $on ? __( 'Fired', 'volance-detection' ) : __( 'Quiet', 'volance-detection' ) ) . '</td><td>' . esc_html( (string) ( $s['value'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $s['detail'] ?? '' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

		if ( ! empty( $data['consistency'] ) && is_array( $data['consistency'] ) ) {
			echo '<div class="volance-card"><h3>' . esc_html__( 'Consistency checks', 'volance-detection' ) . '</h3><table class="widefat striped volance-signals"><tbody>';
			foreach ( $data['consistency'] as $s ) {
				$on = ! empty( $s['fired'] );
				echo '<tr class="' . ( $on ? 'volance-fired' : 'volance-quiet' ) . '"><td><code>' . esc_html( (string) $s['id'] ) . '</code></td><td>' . esc_html( $on ? __( 'Fired', 'volance-detection' ) : __( 'Quiet', 'volance-detection' ) ) . '</td><td>' . esc_html( (string) ( $s['detail'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		if ( ! empty( $data['overrides'] ) || ! empty( $data['cascade'] ) ) {
			echo '<div class="volance-card"><h3>' . esc_html__( 'Overrides and cascade', 'volance-detection' ) . '</h3><dl class="volance-dl">';
			if ( ! empty( $data['overrides'] ) ) {
				self::dl( __( 'Overrides', 'volance-detection' ), implode( ', ', array_map( 'strval', $data['overrides'] ) ) );
			}
			if ( ! empty( $data['cascade'] ) ) {
				self::dl( __( 'Cascade', 'volance-detection' ), ( ! empty( $data['cascade']['needed'] ) ? __( 'needed', 'volance-detection' ) : __( 'not needed', 'volance-detection' ) ) . ( ! empty( $data['cascade']['band'] ) ? ' (' . $data['cascade']['band'] . ')' : '' ) );
			}
			echo '</dl></div>';
		}

		echo '<details class="volance-card"><summary>' . esc_html__( 'Stored copy of the result (JSON)', 'volance-detection' ) . '</summary><pre class="volance-raw">' . esc_html( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) ) . '</pre></details>';
	}

	/**
	 * One definition-list row.
	 *
	 * @param string $term  Label.
	 * @param string $value Value.
	 * @param bool   $code  Render as code.
	 * @return void
	 */
	private static function dl( $term, $value, $code = false ) {
		echo '<dt>' . esc_html( $term ) . '</dt><dd>' . ( $code ? '<code>' . esc_html( $value ) . '</code>' : esc_html( $value ) ) . '</dd>';
	}

	/**
	 * Delete one local entry.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'volance-detection' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'volance_detection_delete_' . $id );
		if ( $id > 0 ) {
			Volance_Detection_Log::delete( $id );
		}
		wp_safe_redirect( Volance_Detection_Admin::url( 'activity', array( 'deleted' => 1 ) ) );
		exit;
	}

	/**
	 * Stream the filtered log as CSV.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'volance-detection' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'volance_detection_export' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$filters = self::filters( $_GET );
		unset( $filters['sort'] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="volance-activity-' . gmdate( 'Ymd-His' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response.
		$out = fopen( 'php://output', 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to the response.
		fwrite( $out, Volance_Detection_Export::line( Volance_Detection_Export::headings() ) );
		$after = 0;
		$sent  = 0;
		do {
			$batch = Volance_Detection_Log::export_batch( $filters, $after, 500 );
			foreach ( $batch as $row ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to the response.
				fwrite( $out, Volance_Detection_Export::line( Volance_Detection_Export::record( $row ) ) );
				$after = (int) $row->id;
				++$sent;
			}
		} while ( count( $batch ) === 500 && $sent < self::EXPORT_MAX );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming to the response.
		fclose( $out );
		exit;
	}
}
