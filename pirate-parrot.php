<?php

/*
 * Plugin Name: Themeisle Support Parrot
 * Plugin URI: http://themeisle.com
 * Description: A Themeisle plugin that allows users to securely share WordPress access with developers for fast, efficient troubleshooting.
 * Version: 1.3.0
 * Author: Themeisle
 * Author URI: http://themeisle.com
 * License: GPLv2 or later
 */
// @codingStandardsIgnoreStart
class TI_Parrot {
    // @codingStandardsIgnoreEnd
	private $_username     = 'ti_parrot';
	private $_email     = 'friends@themeisle.com';
	private $_options      = array();
	private $_option_name  = 'ti_parrot_options';
	private $_availability = ' +5 days';

	static $_log_types = array( 'error', 'warn', 'info', 'debug' );

	// make this true to mimic parrot user functionality
	const MIMIC_PARROT_USER = true;

	const LOG_OPTION_EXPIRY_MINS = 5;

	const LOG_LENGTH = 100;

	function __construct() {
		$this->get_options();
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		register_activation_hook( __FILE__, array( $this, 'wake_bird' ) );
		register_deactivation_hook( __FILE__, array( $this, 'sleep_bird' ) );
		add_action( 'ti_kill_parrot', array( $this, 'sleep_bird' ) );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );
	}

	function wake_bird() {
		set_transient( 'ti_parrot_activation_redirect', true, 30 );
	}

	function maybe_activation_redirect() {
		if ( ! get_transient( 'ti_parrot_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'ti_parrot_activation_redirect' );

		// Don't redirect when activating multiple plugins at once.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'tools.php?page=ti_pirate_parrot' ) );
		exit;
	}

	function init() {
		if ( $this->is_user_parrot() ) {
			$this->log_register( apply_filters( 'pirate_parrot_log', array() ) );
			add_action( 'themeisle_log_event', array( $this, 'log_event' ), 10, 5 );
			add_action( 'wp_ajax_parrot', array( $this, 'ajax' ) );
		}
	}

	function get_version() {
		$version     = '';
		$plugin_data = get_plugin_data( __FILE__ );
		if ( $plugin_data ) {
			$version = $plugin_data['Version'];
		}
		return $version;
	}

	function ajax() {
		check_ajax_referer( 'parrot', 'nonce' );

		switch ( $_POST['_action'] ) {
			case 'flush_logs':
				delete_transient( 'ti_log' . $_POST['plugin_name'] );
				echo wp_send_json_success();
				break;
			case 'download_logs':
				$logs = get_transient( 'ti_log' . $_POST['plugin_name'] );
				if ( $logs ) {
					$logs = array_reverse( $logs );
					$rows = array();
					foreach ( $logs as $log ) {
						$rows[] = $log['time'] . ': (' . ucwords( $log['type'] ) . ') - ' . basename( $log['file'] ) . ':' . $log['line'] . ' - ' . $log['msg'];
					}

					echo wp_send_json_success(
						array(
							'csv'  => implode( PHP_EOL, $rows ),
							'name' => 'themeisle_logs_' . $_POST['plugin_name'] . '_' . date( 'F_j_Y_H_i_s', current_time( 'timestamp', true ) ) . '.txt',
						)
					);

				}
				break;
		}

	}

	function load_js_and_css() {
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	function admin_enqueue_scripts() {
		$url = trailingslashit( plugins_url( '', __FILE__ ) );
		wp_enqueue_script( 'pirate-parrot', $url . 'inc/js/parrot.js', array( 'jquery' ), $this->get_version() );
		wp_localize_script(
			'pirate-parrot',
			'pp',
			array(
				'nonce'  => wp_create_nonce( 'parrot' ),
				'copied' => __( 'Copied!', 'pirate-parrot' ),
			)
		);

		wp_register_style( 'pirate-parrot', $url . 'inc/css/parrot.css', array(), $this->get_version() );
		wp_enqueue_style( 'pirate-parrot' );
	}

	function log_register( $plugins ) {
		$registered = get_transient( 'ti_log_registered' );
		if ( ! $registered ) {
			$registered = array();
		}
		if ( $plugins ) {
			foreach ( $plugins as $plugin_name ) {
				if ( ! in_array( $plugin_name, $registered ) ) {
					$registered[] = $plugin_name;
				}
			}
		}
		set_transient( 'ti_log_registered', $registered );
	}

	function log_event( $plugin_name, $log_msg, $log_type, $file, $line ) {
		// first check if this plugin has registered?
		$allowed = get_transient( 'ti_log_allowed' );
		if ( is_array( $allowed ) && in_array( $plugin_name, $allowed ) ) {
			$logs = get_transient( 'ti_log' . $plugin_name );
			if ( ! $logs ) {
				$logs = array();
			}
			$logs[] = array(
				'type' => $log_type,
				'msg'  => $log_msg,
				'time' => date( 'F j, Y H:i:s', current_time( 'timestamp', true ) ),
				'file' => $file,
				'line' => $line,
			);
			// keep only the last LOG_LENGTH logs
			$logs = array_slice( $logs, 0 - self::LOG_LENGTH );
			set_transient( 'ti_log' . $plugin_name, $logs, self::LOG_OPTION_EXPIRY_MINS * MINUTE_IN_SECONDS );
		}
	}

	function handle_logging() {
		// show this only to the parrot user
		if ( ! $this->is_user_parrot() ) {
			return;
		}

		if ( isset( $_POST['pp-allow-plugins'] ) && wp_verify_nonce( $_POST['nonce'], 'pp-allow' ) ) {
			set_transient( 'ti_log_allowed', isset( $_POST['allow_plugin'] ) ? $_POST['allow_plugin'] : array() );
		}

		$logs = null;
		if ( isset( $_POST['pp_plugin_name'] ) && wp_verify_nonce( $_POST['nonce'], 'pp-view' ) ) {
			$logs = get_transient( 'ti_log' . $_POST['pp_plugin_name'] );
		} else {
			// show the first one by default
			$allowed = get_transient( 'ti_log_allowed' );
			if ( $allowed && count( $allowed ) > 0 ) {
				$logs = get_transient( 'ti_log' . $allowed[0] );
			}
		}

		$registered = get_transient( 'ti_log_registered' );
		$allowed    = get_transient( 'ti_log_allowed' );
		if ( $registered ) {
			include_once trailingslashit( plugin_dir_path( __FILE__ ) ) . 'inc/logging.php';
		}
	}

	function get_log_types() {
		return self::$_log_types;
	}

	function get_options() {
		$this->_options = get_option( $this->_option_name );
	}

	function sleep_bird() {
		if ( ! username_exists( $this->_username ) ) {
			return false;
		}
		if ( isset( $this->_options['date_created'] ) ) {
			$this->kill_sleep_bird();
		} else {
			$this->kill_bird();
		}
	}

	function kill_sleep_bird() {
		if ( $this->_options && isset( $this->_options['date_created'] ) ) {
			$expiration_date_unix = strtotime( $this->_availability, $this->_options['date_created'] );
			if ( time() >= $expiration_date_unix ) {
				$this->kill_bird();
			}
		}
	}

	function kill_bird() {
		if ( defined( 'DOING_CRON' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/user.php' );
		}
		if ( ! username_exists( $this->_username ) ) {
			return new WP_Error( 'get_user_data', 'Parrot is not available !' );
		}
		$support_account_data = get_user_by( 'login', $this->_username );
		if ( $support_account_data ) {
			$support_account_id = $support_account_data->ID;
			if ( ! wp_delete_user( $support_account_id ) ) {
				return new WP_Error( 'delete_user', __( 'Parrot has left the cage !', 'pirate-parrot' ) );
			}
			delete_option( $this->_option_name );
			$this->clear_sleep_bird();
		} else {
			return new WP_Error( 'get_user_data', __( 'Cannot find parrot. Try to recall him.', 'pirate-parrot' ) );
		}
		// update options variable
		$this->get_options();

		return 'Parrot has left the cage ! ';
	}

	function clear_sleep_bird() {
		wp_clear_scheduled_hook( 'ti_kill_parrot' );
	}

	function register_settings_page() {
		$submenu = add_submenu_page(
			'tools.php',
			'Themeisle Support Parrot',
			'Themeisle Support Parrot',
			'manage_options',
			'ti_pirate_parrot',
			array(
				$this,
				'ti_parrot_cage',
			)
		);

		add_action( 'load-' . $submenu, array( $this, 'load_js_and_css' ) );
	}

	function is_user_parrot() {
		if ( self::MIMIC_PARROT_USER ) {
			return true;
		}
		$current_user = wp_get_current_user();
		return $current_user->user_login === $this->_username;
	}

	function ti_parrot_cage() {
		$message        = '';
		$account_exists = username_exists( $this->_username );
		$token_action   = $account_exists ? 'regenerate' : 'generate';
		if ( isset( $_POST['token_delete'] ) || isset( $_POST['token_action'] ) ) {
			if ( isset( $_POST['token_delete'] ) ) {
				$message = $this->kill_bird();
			} elseif ( isset( $_POST['token_action'] ) ) {
				switch ( $_POST['token_action'] ) {
					case 'generate':
						if ( ! $account_exists ) {
							$result  = $this->generate_new_parrot();
							$message = $result;
						} else {
							$message = new WP_Error( 'account_exists', 'Parrot is already created.' );
						}
						break;
					case 'regenerate':
						if ( $account_exists ) {
							if ( ! is_wp_error( $message = $this->kill_bird() ) ) {
								$result  = $this->generate_new_parrot( $regenerate_account = true );
								$message = $result;
							}
						} else {
							$message = new WP_Error( 'regenerate_account_exists', 'You can only release one parrot to help you' );
						}
						break;
				}
			}
			$account_exists = username_exists( $this->_username );
			$token_action   = $account_exists ? 'regenerate' : 'generate';
		} else {
			// delete the account if it's expired
			$this->kill_sleep_bird();
		}
		$is_active     = $account_exists && isset( $this->_options['token'] );
		$primary_label = $account_exists ? __( 'Regenerate token', 'pirate-parrot' ) : __( 'Call the parrot', 'pirate-parrot' );
		?>
		<div class="wrap ti-parrot-wrap">
			<div class="ti-parrot-header">
				<img class="ti-parrot-logo" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'themeisle-logo.png' ); ?>" alt="Themeisle" width="98" height="32" />
				<span class="ti-parrot-header-sep" aria-hidden="true"></span>
				<h1><?php esc_html_e( 'Support Parrot', 'pirate-parrot' ); ?></h1>
			</div>

			<hr class="wp-header-end" />

			<?php echo $this->get_status_message( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within the method. ?>

			<?php if ( $is_active ) : ?>
				<?php $expiration = $this->get_expiration_date(); ?>
				<div class="ti-parrot-status ti-parrot-status--active">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<span>
						<strong><?php esc_html_e( 'Access active', 'pirate-parrot' ); ?></strong>
						<?php if ( ! is_wp_error( $expiration ) ) : ?>
							&mdash; <?php printf( esc_html__( 'expires %s', 'pirate-parrot' ), esc_html( $expiration ) ); ?>
						<?php endif; ?>
					</span>
				</div>
			<?php else : ?>
				<div class="ti-parrot-status ti-parrot-status--inactive">
					<span class="dashicons dashicons-marker" aria-hidden="true"></span>
					<span><?php esc_html_e( 'No active access', 'pirate-parrot' ); ?></span>
				</div>
			<?php endif; ?>

			<div class="ti-parrot-card ti-parrot-intro">
				<p><?php esc_html_e( 'This creates a temporary admin account so our support team can access your dashboard. It is removed automatically after 5 days, or you can remove it at any time by clicking on the Release parrot button.', 'pirate-parrot' ); ?></p>
				<p class="ti-parrot-intro-hint"><?php esc_html_e( 'When asked, copy the details below and send them to the agent helping you through our private messaging.', 'pirate-parrot' ); ?></p>
			</div>

			<?php if ( $is_active ) : ?>
				<?php $this->render_parrot_details(); ?>
			<?php endif; ?>

			<form method="post" class="ti-parrot-actions">
				<?php submit_button( $primary_label, ( $is_active ? 'secondary' : 'primary' ), 'submit', false ); ?>
				<?php if ( $account_exists ) : ?>
					<?php submit_button( __( 'Release parrot', 'pirate-parrot' ), 'ti-parrot-release', 'token_delete', false ); ?>
				<?php endif; ?>
				<input type="hidden" name="token_action" value="<?php echo esc_attr( $token_action ); ?>" />
			</form>
		</div>
		<?php

		$this->handle_logging();
	}

	function generate_new_parrot( $regenerate_account = false ) {
		$token   = $this->generate_parrot();
		$user_id = wp_insert_user(
			array(
				'user_login' => $this->_username,
				'user_pass'  => $token,
				'role'       => 'administrator',
				'user_email' => $this->_email,
				'description' => 'The admin user created by ThemeIsle Support Plugin',
			)
		);
		if ( ! is_wp_error( $user_id ) ) {
			$message          = $regenerate_account ? 'Parrot recalled.' : 'Parrot has been called';
			$account_settings = array(
				'date_created' => time(),
				'token'        => $token,
			);
			update_option( $this->_option_name, $account_settings );
			// update options variable
			$this->get_options();
			$this->init_parrot_kill();
		} else {
			$message = new WP_Error( 'create_user_error', $user_id->get_error_message() );
		}

		return $message;
	}

	function generate_parrot( $length = 17 ) {
		$symbols = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^*()-=+';
		$token   = substr( str_shuffle( $symbols ), 0, $length );

		return $token;
	}

	function init_parrot_kill() {
		$this->clear_sleep_bird();
		wp_schedule_event( time(), 'twicedaily', 'ti_kill_parrot' );
	}

	function get_parrot_info_rows() {
		$theme = wp_get_theme();

		return array(
			array(
				'label' => __( 'Access token', 'pirate-parrot' ),
				'value' => isset( $this->_options['token'] ) ? $this->_options['token'] : '',
				'mono'  => true,
			),
			array(
				'label' => __( 'Login URL', 'pirate-parrot' ),
				'value' => wp_login_url(),
			),
			array(
				'label' => __( 'WordPress version', 'pirate-parrot' ),
				'value' => get_bloginfo( 'version' ),
			),
			array(
				'label' => __( 'PHP version', 'pirate-parrot' ),
				'value' => phpversion(),
			),
			array(
				'label' => __( 'Site locale', 'pirate-parrot' ),
				'value' => get_locale(),
			),
			array(
				'label' => __( 'Theme', 'pirate-parrot' ),
				'value' => trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
			),
		);
	}

	function render_parrot_details() {
		$rows = $this->get_parrot_info_rows();

		// Build the "copy all" payload, one "Label: value" per line.
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = $row['label'] . ': ' . $row['value'];
		}
		$copy_all   = implode( "\n", $lines );
		$expiration = $this->get_expiration_date();
		?>
		<div class="ti-parrot-card ti-parrot-details">
			<div class="ti-parrot-details-header">
				<span class="ti-parrot-details-title"><?php esc_html_e( 'Details to share with support', 'pirate-parrot' ); ?></span>
				<button type="button" class="button button-primary ti-parrot-copy" data-clipboard-text="<?php echo esc_attr( $copy_all ); ?>">
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<span class="ti-parrot-copy-label"><?php esc_html_e( 'Copy all details', 'pirate-parrot' ); ?></span>
				</button>
			</div>
			<?php foreach ( $rows as $row ) : ?>
				<div class="ti-parrot-row">
					<span class="ti-parrot-row-label"><?php echo esc_html( $row['label'] ); ?></span>
					<span class="ti-parrot-row-value<?php echo empty( $row['mono'] ) ? '' : ' ti-parrot-mono'; ?>"><?php echo esc_html( $row['value'] ); ?></span>
				</div>
			<?php endforeach; ?>
			<p class="ti-parrot-expiry">
				<?php
				if ( ! is_wp_error( $expiration ) ) {
					printf( esc_html__( 'This parrot will leave on %s', 'pirate-parrot' ), esc_html( $expiration ) );
				} else {
					echo esc_html( $expiration->get_error_message() );
				}
				?>
			</p>
		</div>
		<?php
	}

	function get_expiration_date() {
		if ( ! isset( $this->_options['date_created'] ) ) {
			return new WP_Error( 'date_created_missing', 'Parrot fainted. You need to revive him. ' );
		}
		$format               = sprintf(
			'%1$s, %2$s',
			get_option( 'date_format' ),
			get_option( 'time_format' )
		);
		$expiration_date_unix = strtotime( $this->_availability, $this->_options['date_created'] );
		// use gmt offset to display local time
		$gmt_offset      = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$expiration_date = date_i18n( $format, $expiration_date_unix + $gmt_offset );

		return $expiration_date;
	}

	function get_status_message( $message ) {
		$output           = '';
		$is_error_message = is_wp_error( $message );
		if ( ! $is_error_message ) {
			if ( '' !== $message ) {
				$output = sprintf( '<p>%1$s</p>', esc_html( $message ) );
			}
		} else {
			$output = sprintf( '<p>%1$s</p>', esc_html( $message->get_error_message() ) );
		}
		if ( '' !== $output ) {
			$output = sprintf(
				'<div class="notice %1$s ti-parrot-notice">%2$s</div>',
				( $is_error_message ? 'notice-error' : 'notice-success' ),
				$output
			);
		}

		return $output;
	}
}

new TI_Parrot();
