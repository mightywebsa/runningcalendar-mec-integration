<?php
/**
 * Settings page (Settings > RunningSA MEC) with Settings and Logs tabs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunningSA_MEC_Admin {

	const SLUG = 'running-sa-mec';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'mec_notice' ) );
		add_action( 'admin_post_runningsa_mec_force_fetch', array( $this, 'force_fetch' ) );
		add_action( 'admin_post_runningsa_mec_clear_log', array( $this, 'clear_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RUNNINGSA_MEC_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_options_page(
			__( 'RunningSA integration with MEC', 'running-sa-mec' ),
			__( 'RunningSA MEC', 'running-sa-mec' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'running-sa-mec' ) . '</a>';
		return $links;
	}

	public function mec_notice() {
		if ( runningsa_mec_is_mec_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'RunningSA integration with MEC needs the Modern Events Calendar plugin. Please install and activate it; no races will be imported until then.', 'running-sa-mec' ) . '</p></div>';
	}

	public function register_settings() {
		register_setting(
			'runningsa_mec',
			'runningsa_mec_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Clean the posted settings. A blank API key field keeps the saved key.
	 */
	public function sanitize( $input ) {
		$old = (array) get_option( 'runningsa_mec_settings', array() );
		$out = array();

		$key            = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$out['api_key'] = '' !== $key ? $key : ( isset( $old['api_key'] ) ? $old['api_key'] : '' );
		if ( ! empty( $input['api_key_remove'] ) ) {
			$out['api_key'] = '';
		}

		$valid            = array_keys( runningsa_mec_provinces() );
		$provinces        = isset( $input['provinces'] ) ? array_map( 'sanitize_key', (array) $input['provinces'] ) : array();
		$out['provinces'] = array_values( array_intersect( $provinces, $valid ) );

		$out['category'] = isset( $input['category'] ) ? absint( $input['category'] ) : 0;
		$out['debug']    = empty( $input['debug'] ) ? 0 : 1;
		return $out;
	}

	public function force_fetch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'running-sa-mec' ) );
		}
		check_admin_referer( 'runningsa_mec_force_fetch' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		RunningSA_MEC_API::clear_cache();
		RunningSA_MEC_Importer::run( 'manual' );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&runningsa_done=1' ) );
		exit;
	}

	public function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'running-sa-mec' ) );
		}
		check_admin_referer( 'runningsa_mec_clear_log' );
		RunningSA_MEC_Logger::clear();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=logs' ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = ( isset( $_GET['tab'] ) && 'logs' === $_GET['tab'] ) ? 'logs' : 'settings'; // phpcs:ignore
		$base = admin_url( 'options-general.php?page=' . self::SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RunningSA integration with MEC', 'running-sa-mec' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'running-sa-mec' ); ?></a>
				<a href="<?php echo esc_url( $base . '&tab=logs' ); ?>" class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Logs', 'running-sa-mec' ); ?></a>
			</nav>
			<?php
			if ( 'logs' === $tab ) {
				$this->render_logs();
			} else {
				$this->render_settings();
			}
			?>
		</div>
		<?php
	}

	private function render_settings() {
		$provinces   = (array) runningsa_mec_get_setting( 'provinces' );
		$category    = (int) runningsa_mec_get_setting( 'category' );
		$debug       = (int) runningsa_mec_get_setting( 'debug' );
		$has_const   = defined( 'RUNNINGSA_API_KEY' ) && RUNNINGSA_API_KEY;
		$has_saved   = '' !== (string) runningsa_mec_get_setting( 'api_key' );
		$last        = get_option( 'runningsa_mec_last_run' );
		$next        = wp_next_scheduled( RUNNINGSA_MEC_CRON_HOOK );

		if ( isset( $_GET['runningsa_done'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Force fetch finished. See the status below.', 'running-sa-mec' ) . '</p></div>';
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'runningsa_mec' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="runningsa_api_key"><?php esc_html_e( 'API key', 'running-sa-mec' ); ?></label></th>
					<td>
						<?php if ( $has_const ) : ?>
							<p><strong><?php esc_html_e( 'Set in wp-config.php (RUNNINGSA_API_KEY). The field below is ignored.', 'running-sa-mec' ); ?></strong></p>
						<?php endif; ?>
						<input type="password" id="runningsa_api_key" name="runningsa_mec_settings[api_key]" value="" class="regular-text" autocomplete="new-password"
							placeholder="<?php echo $has_saved ? esc_attr__( 'Saved (leave blank to keep)', 'running-sa-mec' ) : ''; ?>" />
						<?php if ( $has_saved ) : ?>
							<label><input type="checkbox" name="runningsa_mec_settings[api_key_remove]" value="1" /> <?php esc_html_e( 'Remove saved key', 'running-sa-mec' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Recommended: add define( \'RUNNINGSA_API_KEY\', \'...\' ); to wp-config.php instead. That takes precedence over this field.', 'running-sa-mec' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Provinces', 'running-sa-mec' ); ?></th>
					<td>
						<?php foreach ( runningsa_mec_provinces() as $slug => $label ) : ?>
							<label style="display:block"><input type="checkbox" name="runningsa_mec_settings[provinces][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $provinces, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'One API call is made per selected province.', 'running-sa-mec' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="runningsa_category"><?php esc_html_e( 'MEC category', 'running-sa-mec' ); ?></label></th>
					<td>
						<?php
						if ( taxonomy_exists( 'mec_category' ) ) {
							wp_dropdown_categories(
								array(
									'taxonomy'         => 'mec_category',
									'name'             => 'runningsa_mec_settings[category]',
									'id'               => 'runningsa_category',
									'selected'         => $category,
									'hide_empty'       => false,
									'show_option_none' => __( '— None —', 'running-sa-mec' ),
									'option_none_value' => 0,
								)
							);
						} else {
							esc_html_e( 'MEC is not active.', 'running-sa-mec' );
						}
						?>
						<p class="description"><?php esc_html_e( 'Optional. Added to every imported race.', 'running-sa-mec' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug log', 'running-sa-mec' ); ?></th>
					<td><label><input type="checkbox" name="runningsa_mec_settings[debug]" value="1" <?php checked( $debug, 1 ); ?> /> <?php esc_html_e( 'Write integration logs (see the Logs tab). Errors are always logged.', 'running-sa-mec' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Status', 'running-sa-mec' ); ?></h2>
		<p>
			<?php
			echo esc_html__( 'Next scheduled run:', 'running-sa-mec' ) . ' ';
			echo $next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i' ) ) : esc_html__( 'not scheduled (deactivate and reactivate the plugin)', 'running-sa-mec' );
			?>
		</p>
		<?php if ( is_array( $last ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: time, 2: trigger, 3: fetched, 4: created, 5: updated, 6: unchanged, 7: errors */
					esc_html__( 'Last run: %1$s (%2$s) — %3$d fetched, %4$d created, %5$d updated, %6$d unchanged, %7$d errors.', 'running-sa-mec' ),
					esc_html( $last['time'] ),
					esc_html( $last['trigger'] ),
					(int) $last['fetched'],
					(int) $last['created'],
					(int) $last['updated'],
					(int) $last['skipped'],
					count( (array) $last['errors'] )
				);
				?>
			</p>
			<?php if ( ! empty( $last['errors'] ) ) : ?>
				<ul style="list-style:disc;margin-left:2em">
					<?php foreach ( array_slice( (array) $last['errors'], 0, 20 ) as $err ) : ?>
						<li><?php echo esc_html( $err ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php else : ?>
			<p><?php esc_html_e( 'No import has run yet.', 'running-sa-mec' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="runningsa_mec_force_fetch" />
			<?php wp_nonce_field( 'runningsa_mec_force_fetch' ); ?>
			<?php submit_button( __( 'Force fetch now', 'running-sa-mec' ), 'secondary', 'submit', false ); ?>
			<span class="description"><?php esc_html_e( 'Clears the 10-minute cache and imports straight away. Save settings first.', 'running-sa-mec' ); ?></span>
		</form>
		<?php
	}

	private function render_logs() {
		$rows = array_reverse( RunningSA_MEC_Logger::get() );
		if ( ! runningsa_mec_get_setting( 'debug' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Debug logging is off. Only errors are recorded.', 'running-sa-mec' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
			<input type="hidden" name="action" value="runningsa_mec_clear_log" />
			<?php wp_nonce_field( 'runningsa_mec_clear_log' ); ?>
			<?php submit_button( __( 'Clear log', 'running-sa-mec' ), 'delete', 'submit', false ); ?>
		</form>
		<table class="widefat striped">
			<thead><tr><th style="width:160px"><?php esc_html_e( 'Time', 'running-sa-mec' ); ?></th><th style="width:80px"><?php esc_html_e( 'Level', 'running-sa-mec' ); ?></th><th><?php esc_html_e( 'Message', 'running-sa-mec' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'The log is empty.', 'running-sa-mec' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['time'] ); ?></td>
					<td><?php echo esc_html( $row['level'] ); ?></td>
					<td><?php echo esc_html( $row['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
