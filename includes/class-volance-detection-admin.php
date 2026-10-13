<?php
/**
 * Admin shell: the top-level Volance menu, the tab router, the Setup tab and
 * helpers shared by the other tabs.
 *
 * Tabs: Overview, Activity, Setup, Usage and plan, Help. Each tab beyond Setup
 * lives in its own class under includes/admin/.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu, router, Setup tab and connection test.
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
		add_action( 'admin_init', array( __CLASS__, 'redirect_old_url' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_volance_detection_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VOLANCE_DETECTION_FILE ), array( __CLASS__, 'action_links' ) );
		Volance_Detection_Admin_Activity::init();
		Volance_Detection_Admin_Help::init();
	}

	/**
	 * Tabs, in display order.
	 *
	 * @return array slug => label.
	 */
	public static function tabs() {
		return array(
			'overview' => __( 'Overview', 'volance-detection' ),
			'activity' => __( 'Activity', 'volance-detection' ),
			'setup'    => __( 'Setup', 'volance-detection' ),
			'plan'     => __( 'Usage and plan', 'volance-detection' ),
			'help'     => __( 'Help', 'volance-detection' ),
		);
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab slug ('' for the default tab).
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = '', array $args = array() ) {
		$query = array( 'page' => self::PAGE );
		if ( '' !== $tab ) {
			$query['tab'] = $tab;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Add the settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open Volance', 'volance-detection' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Volance Detection', 'volance-detection' ),
			__( 'Volance', 'volance-detection' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-shield-alt',
			80
		);
	}

	/**
	 * Send the pre-0.2 Settings → Volance Detection address to the new menu.
	 *
	 * @return void
	 */
	public static function redirect_old_url() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect only.
		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) && current_user_can( 'manage_options' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect only.
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			wp_safe_redirect( self::url( isset( self::tabs()[ $tab ] ) ? $tab : '' ) );
			exit;
		}
	}

	/**
	 * Load admin assets on this plugin's screen only.
	 *
	 * @param string $hook Screen hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'volance-detection-admin', VOLANCE_DETECTION_URL . 'assets/css/admin.css', array(), VOLANCE_DETECTION_VERSION );
		wp_enqueue_script( 'volance-detection-admin', VOLANCE_DETECTION_URL . 'assets/js/admin.js', array(), VOLANCE_DETECTION_VERSION, true );
		wp_localize_script(
			'volance-detection-admin',
			'volanceDetectionAdmin',
			array(
				'confirmDelete' => __( 'Delete this entry from the log on this site? This does not delete anything stored in your Volance workspace.', 'volance-detection' ),
			)
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
	 * Labels for log statuses.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'scored'      => __( 'Scored', 'volance-detection' ),
			'no_evidence' => __( 'No browser evidence', 'volance-detection' ),
			'rejected'    => __( 'Rejected snapshot', 'volance-detection' ),
			'skipped'     => __( 'Skipped', 'volance-detection' ),
			'limited'     => __( 'Rate or quota limited', 'volance-detection' ),
			'error'       => __( 'Error', 'volance-detection' ),
		);
	}

	/**
	 * Labels for form keys.
	 *
	 * @param string $key Form key.
	 * @return string
	 */
	public static function form_label( $key ) {
		$labels = array(
			'login'    => __( 'Login', 'volance-detection' ),
			'register' => __( 'Registration', 'volance-detection' ),
			'comments' => __( 'Comments', 'volance-detection' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * A GMT database time shown in the site's time zone.
	 *
	 * @param string $gmt Y-m-d H:i:s in UTC.
	 * @return string
	 */
	public static function format_time( $gmt ) {
		return get_date_from_gmt( (string) $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Cached plan and usage report from Volance (five minutes).
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array Empty when the keys are not set or the call failed.
	 */
	public static function usage( $refresh = false ) {
		if ( ! Volance_Detection_Settings::is_ready() ) {
			return array();
		}
		$usage = $refresh ? false : get_transient( Volance_Detection_State::USAGE_CACHE );
		if ( false === $usage ) {
			$client = new Volance_Detection_Client( Volance_Detection_Settings::public_key(), Volance_Detection_Settings::secret_key() );
			$result = $client->usage();
			$usage  = $result['ok'] ? $result['data'] : array();
			set_transient( Volance_Detection_State::USAGE_CACHE, $usage, 5 * MINUTE_IN_SECONDS );
		}
		return is_array( $usage ) ? $usage : array();
	}

	/**
	 * Test the saved keys, then return to the Setup tab with the result.
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
		wp_safe_redirect( self::url( 'setup' ) );
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
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection only.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tab       = isset( $tabs[ $requested ] ) ? $requested : ( Volance_Detection_Settings::is_ready() ? 'overview' : 'setup' );

		echo '<div class="wrap volance-wrap"><h1 class="volance-title"><img src="' . esc_url( VOLANCE_DETECTION_URL . 'assets/images/volance-logo.png' ) . '" alt="" width="32" height="32" />' . esc_html__( 'Volance Detection', 'volance-detection' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Volance sections', 'volance-detection' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $slug ) ) . '"' . ( $slug === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<p class="description volance-intro">' . esc_html__( 'Volance Detection currently only observes: it never blocks, redirects or changes a submission. Scores are estimates, not proof that a visitor is human or a bot.', 'volance-detection' ) . '</p>';

		switch ( $tab ) {
			case 'overview':
				Volance_Detection_Admin_Overview::render();
				break;
			case 'activity':
				Volance_Detection_Admin_Activity::render();
				break;
			case 'plan':
				Volance_Detection_Admin_Plan::render();
				break;
			case 'help':
				Volance_Detection_Admin_Help::render();
				break;
			default:
				self::render_setup();
		}
		echo '</div>';
	}

	/**
	 * Setup tab.
	 *
	 * @return void
	 */
	private static function render_setup() {
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
								<label class="volance-block"><input type="checkbox" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[forms][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['forms'][ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Visitor consent', 'volance-detection' ); ?></th>
					<td>
						<fieldset>
							<label class="volance-block"><input type="radio" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[consent_mode]" value="banner" <?php checked( 'banner', $settings['consent_mode'] ); ?> /> <?php esc_html_e( 'Show the built-in Allow / Decline notice', 'volance-detection' ); ?></label>
							<label class="volance-block"><input type="radio" name="<?php echo esc_attr( Volance_Detection_Settings::OPTION ); ?>[consent_mode]" value="custom" <?php checked( 'custom', $settings['consent_mode'] ); ?> /> <?php esc_html_e( 'Use my own consent tool', 'volance-detection' ); ?></label>
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
}
