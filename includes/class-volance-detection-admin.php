<?php
/**
 * Admin screens: Settings → Volance Detection (Settings and Activity tabs).
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page, connection test and activity log.
 */
class Volance_Detection_Admin {

	const PAGE = 'volance-detection';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_volance_detection_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VOLANCE_DETECTION_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'volance-detection' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'Volance Detection', 'volance-detection' ),
			__( 'Volance Detection', 'volance-detection' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the setting.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'volance_detection',
			Volance_Detection_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Volance_Detection_Settings', 'sanitize' ),
				'default'           => Volance_Detection_Settings::defaults(),
			)
		);
	}

	/**
	 * Test the saved keys, then return to the settings page with the result.
	 *
	 * @return void
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'volance-detection' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'volance_detection_test' );

		$public  = Volance_Detection_Settings::public_key();
		$secret  = Volance_Detection_Settings::secret_key();
		$problem = Volance_Detection_Settings::keys_problem( $public, $secret );
		$result  = array(
			'ok'      => false,
			'message' => '',
		);

		if ( '' !== $problem ) {
			$result['message'] = '' === $public && '' === $secret
				? __( 'Enter your public and secret keys first.', 'volance-detection' )
				: Volance_Detection_Settings::keys_problem_message( $problem );
		} else {
			$client = new Volance_Detection_Client( $public, $secret );
			$config = $client->config();
			if ( ! $config['ok'] ) {
				$result['message'] = __( 'Secret key: ', 'volance-detection' ) . $config['error'];
			} else {
				$session = $client->session();
				if ( ! $session['ok'] ) {
					$result['message'] = __( 'Public key: ', 'volance-detection' ) . $session['error'];
				} else {
					$collect = isset( $session['data']['collect'] ) && is_array( $session['data']['collect'] ) ? $session['data']['collect'] : array();
					$state   = new Volance_Detection_State();
					$state->set_fingerprints( true === ( $collect['fingerprints'] ?? false ) );
					$state->set_last_error( '' );
					delete_transient( Volance_Detection_State::USAGE_CACHE );
					$mode         = isset( $config['data']['mode'] ) && is_string( $config['data']['mode'] ) ? $config['data']['mode'] : '';
					$result['ok'] = true;
					/* translators: %s: Volance workspace mode, for example "monitor". */
					$result['message'] = '' !== $mode ? sprintf( __( 'Connected. Workspace mode: %s.', 'volance-detection' ), $mode ) : __( 'Connected.', 'volance-detection' );
				}
			}
		}

		set_transient( 'volance_detection_test_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection only.
		$tab  = ( isset( $_GET['tab'] ) && 'activity' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'activity' : 'settings';
		$base = admin_url( 'options-general.php?page=' . self::PAGE );

		echo '<div class="wrap"><h1><img src="' . esc_url( VOLANCE_DETECTION_URL . 'assets/images/volance-logo.png' ) . '" alt="" width="32" height="32" style="vertical-align:middle;margin-right:10px;border-radius:6px;" />' . esc_html__( 'Volance Detection', 'volance-detection' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		echo '<a class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">' . esc_html__( 'Settings', 'volance-detection' ) . '</a>';
		echo '<a class="nav-tab' . ( 'activity' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', 'activity', $base ) ) . '">' . esc_html__( 'Activity', 'volance-detection' ) . '</a>';
		echo '</nav>';

		echo '<p>' . esc_html__( 'Observe-only: Volance Detection never blocks, redirects or changes a submission. Scores are estimates, not proof that a visitor is human or a bot.', 'volance-detection' ) . '</p>';

		if ( 'activity' === $tab ) {
			self::render_activity( $base );
		} else {
			self::render_settings();
		}
		echo '</div>';
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private static function render_settings() {
		$settings = Volance_Detection_Settings::get();
		$state    = new Volance_Detection_State();

		settings_errors( 'volance_detection' );

		$test = get_transient( 'volance_detection_test_' . get_current_user_id() );
		if ( is_array( $test ) && isset( $test['message'] ) ) {
			delete_transient( 'volance_detection_test_' . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				! empty( $test['ok'] ) ? 'success' : 'error',
				esc_html( (string) $test['message'] )
			);
		}

		if ( $state->fingerprints() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Your Volance workspace has the fingerprinting tier switched on. Volance Detection does not collect or send anything while it is on. Turn it off in the Volance portal, then use Test connection.', 'volance-detection' ) . '</p></div>';
		}

		$error = $state->last_error();
		if ( $error ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: error message, 2: how long ago. */
						__( 'Last problem talking to Volance: %1$s (%2$s ago). Your forms were not affected.', 'volance-detection' ),
						$error['message'],
						human_time_diff( (int) $error['time'] )
					)
				)
			);
		}

		self::render_usage( $settings );

		$has_secret = '' !== Volance_Detection_Settings::secret_key();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'volance_detection' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="volance-public"><?php esc_html_e( 'Public key', 'volance-detection' ); ?></label></th>
					<td>
						<input id="volance-public" type="text" class="regular-text code" autocomplete="off" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[public_key]" value="<?php echo esc_attr( $settings['public_key'] ); ?>" placeholder="pk_..." />
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the Volance portal. */
								esc_html__( 'Find your keys in the %s.', 'volance-detection' ),
								'<a href="https://app.volance.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Volance portal', 'volance-detection' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="volance-secret"><?php esc_html_e( 'Secret key', 'volance-detection' ); ?></label></th>
					<td>
						<?php if ( Volance_Detection_Settings::secret_from_constant() ) : ?>
							<p><?php esc_html_e( 'Set in wp-config.php (VOLANCE_DETECTION_SECRET_KEY).', 'volance-detection' ); ?></p>
						<?php else : ?>
							<input id="volance-secret" type="password" class="regular-text code" autocomplete="new-password" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[secret_key]" value="" placeholder="<?php echo esc_attr( $has_secret ? __( 'Saved. Leave blank to keep it.', 'volance-detection' ) : 'sk_...' ); ?>" />
							<p class="description"><?php esc_html_e( 'Used only on this server. It is never shown on your site or sent to a visitor\'s browser. If the plugin says your key pair is in the older format, rotate your keys in the Volance portal.', 'volance-detection' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Turn on', 'volance-detection' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> <?php esc_html_e( 'Observe the forms selected below', 'volance-detection' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Forms to observe', 'volance-detection' ); ?></th>
					<td>
						<fieldset>
							<?php
							$labels = array(
								'login'    => __( 'Login form', 'volance-detection' ),
								'register' => __( 'Registration form', 'volance-detection' ),
								'comments' => __( 'Comment form', 'volance-detection' ),
							);
							foreach ( $labels as $key => $label ) :
								?>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[forms][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['forms'][ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Visitor consent', 'volance-detection' ); ?></th>
					<td>
						<fieldset>
							<label style="display:block"><input type="radio" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[consent_mode]" value="banner" <?php checked( 'banner', $settings['consent_mode'] ); ?> /> <?php esc_html_e( 'Show the built-in Allow / Decline notice', 'volance-detection' ); ?></label>
							<label style="display:block"><input type="radio" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[consent_mode]" value="custom" <?php checked( 'custom', $settings['consent_mode'] ); ?> /> <?php esc_html_e( 'Use my own consent tool', 'volance-detection' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Nothing is collected and no Volance script is loaded until a visitor allows it. With your own tool, call window.volanceDetection.setConsent(true) when a visitor agrees (or false if they decline or withdraw).', 'volance-detection' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="volance_detection_test" />
			<?php wp_nonce_field( 'volance_detection_test' ); ?>
			<?php submit_button( __( 'Test connection', 'volance-detection' ), 'secondary', 'submit', false ); ?>
			<span class="description"><?php esc_html_e( 'Checks both saved keys. Does not use any of your monthly scores.', 'volance-detection' ); ?></span>
		</form>
		<?php
	}

	/**
	 * Plan and usage line (cached for five minutes).
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private static function render_usage( array $settings ) {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			return;
		}
		$usage = get_transient( Volance_Detection_State::USAGE_CACHE );
		if ( false === $usage ) {
			$client = new Volance_Detection_Client( Volance_Detection_Settings::public_key(), Volance_Detection_Settings::secret_key() );
			$result = $client->usage();
			$usage  = $result['ok'] ? $result['data'] : array();
			set_transient( Volance_Detection_State::USAGE_CACHE, $usage, 5 * MINUTE_IN_SECONDS );
		}
		if ( ! is_array( $usage ) || empty( $usage['usage'] ) || ! is_array( $usage['usage'] ) ) {
			return;
		}
		$name  = isset( $usage['name'] ) && is_string( $usage['name'] ) ? $usage['name'] : '';
		$used  = isset( $usage['usage']['monthScores'] ) ? (int) $usage['usage']['monthScores'] : 0;
		$limit = isset( $usage['usage']['limit'] ) ? (int) $usage['usage']['limit'] : 0;
		echo '<div class="notice notice-info inline"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: plan name, 2: scores used, 3: monthly limit. */
				__( 'Volance plan %1$s: %2$s of %3$s scores used this month.', 'volance-detection' ),
				'' !== $name ? $name : '-',
				number_format_i18n( $used ),
				$limit > 0 ? number_format_i18n( $limit ) : '-'
			)
		);
		echo '</p></div>';
		unset( $settings );
	}

	/**
	 * Activity tab.
	 *
	 * @param string $base Settings page URL.
	 * @return void
	 */
	private static function render_activity( $base ) {
		$statuses = self::status_labels();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter and page number only.
		$filter = ( isset( $_GET['status'] ) && isset( $statuses[ sanitize_key( wp_unslash( $_GET['status'] ) ) ] ) ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page number only.
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page = 25;
		$total    = Volance_Detection_Log::count( $filter );
		$rows     = Volance_Detection_Log::rows( $page, $per_page, $filter );
		$counts   = Volance_Detection_Log::verdict_counts( 7 );

		if ( $counts ) {
			$parts = array();
			foreach ( $counts as $verdict => $count ) {
				$parts[] = sprintf( '%s: %s', $verdict, number_format_i18n( $count ) );
			}
			echo '<p><strong>' . esc_html__( 'Last 7 days:', 'volance-detection' ) . '</strong> ' . esc_html( implode( ', ', $parts ) ) . '</p>';
		}

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="activity" />';
		echo '<label for="volance-status" class="screen-reader-text">' . esc_html__( 'Filter by result', 'volance-detection' ) . '</label>';
		echo '<select id="volance-status" name="status"><option value="">' . esc_html__( 'All results', 'volance-detection' ) . '</option>';
		foreach ( $statuses as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $filter, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'volance-detection' ), 'secondary', '', false );
		echo '</form>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet. Submissions appear here after a visitor allows detection and submits an observed form.', 'volance-detection' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
		foreach ( array( __( 'Time', 'volance-detection' ), __( 'Form', 'volance-detection' ), __( 'Result', 'volance-detection' ), __( 'Browser evidence', 'volance-detection' ), __( 'Request ID', 'volance-detection' ), __( 'Note', 'volance-detection' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$status = (string) $row->status;
			if ( 'scored' === $status ) {
				$result = sprintf(
					'%s%s%s',
					null !== $row->score ? number_format_i18n( (float) $row->score, 1 ) : '-',
					'' !== $row->verdict ? ' (' . $row->verdict . ')' : '',
					( '' !== $row->label && $row->label !== $row->verdict ) ? ', ' . $row->label : ''
				);
			} else {
				$result = $statuses[ $status ] ?? $status;
			}
			$evidence = ( (int) $row->behavioural ) ? sprintf(
				/* translators: %s: number of recorded events. */
				_n( '%s event', '%s events', (int) $row->events, 'volance-detection' ),
				number_format_i18n( (int) $row->events )
			) : __( 'No browser evidence', 'volance-detection' );
			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row->form ) . '</td>';
			echo '<td>' . esc_html( $result ) . '</td>';
			echo '<td>' . esc_html( $evidence ) . '</td>';
			echo '' !== (string) $row->request_id ? '<td><code>' . esc_html( (string) $row->request_id ) . '</code></td>' : '<td></td>';
			echo '<td>' . esc_html( (string) $row->note ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg(
							'paged',
							'%#%',
							add_query_arg(
								array(
									'tab'    => 'activity',
									'status' => $filter,
								),
								$base
							)
						),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '<p class="description">' . esc_html__( 'A score runs from 0 to 100 and is an estimate. A missing score means nothing was sent to Volance for that submission. Entries are deleted after 30 days. This log stores no IP address, browser details or form content.', 'volance-detection' ) . '</p>';
	}

	/**
	 * Labels for log statuses.
	 *
	 * @return array
	 */
	private static function status_labels() {
		return array(
			'scored'      => __( 'Scored', 'volance-detection' ),
			'no_evidence' => __( 'No browser evidence', 'volance-detection' ),
			'rejected'    => __( 'Rejected snapshot', 'volance-detection' ),
			'skipped'     => __( 'Skipped', 'volance-detection' ),
			'limited'     => __( 'Rate or quota limited', 'volance-detection' ),
			'error'       => __( 'Error', 'volance-detection' ),
		);
	}
}
