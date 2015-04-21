<?php
/*
 * Plugin Name: Pirate Parrot
 * Plugin URI: http://themeisle.com
 * Description: Pirate parrot is a plugin for Themeisle that allow customers to give access to their wordpress instances in order for the developers to help solve their issues.
 * Version: 1.0
 * Author: Themeisle
 * Author URI: http://themeisle.com
 * License: GPLv2 or later
 */


class TI_parrot {
	private $_username = "ti_parrot";
	private $_options = array();
	private $_option_name = "ti_parrot_options" ;
	private $_availability = " +1 hour";
	function __construct() {


		$this->get_options();


		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );

		register_deactivation_hook( __FILE__, array( $this, 'delete_account' ) );

		add_action( "ti_kill_parrot", array( $this, 'sleep_bird' ) );

	}
	function clear_sleep_bird() {
		wp_clear_scheduled_hook( "ti_kill_parrot");
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

	function init_parrot_kill() {
		$this->clear_sleep_bird();

		wp_schedule_event( time(), 'hourly', "ti_kill_parrot" );
	}

	function get_options() {
		$this->_options = get_option( $this->_option_name );
	}

	function register_settings_page() {
		add_submenu_page( 'tools.php', 'Themeisle Support Parrot' , 'Themeisle Support Parrot' , 'manage_options', 'ti_pirate_parrot', array( $this, 'ti_parrot_cage' ) );
	}

	function ti_parrot_cage() {
		$message = '';

		$account_exists = username_exists( $this->_username );

		$token_action = $account_exists ? 'regenerate' : 'generate';
		if ( isset( $_POST['token_delete'] ) || isset( $_POST['token_action'] ) ) {
			if ( isset( $_POST['token_delete'] ) ) {
				$message = $this->kill_bird();
			} else if ( isset( $_POST['token_action'] ) ) {
				switch ( $_POST['token_action'] ) {
					case 'generate' :
						if ( ! $account_exists ) {
							$result = $this->generate_new_parrot();

							$message = $result;
						} else {
							$message = new WP_Error( 'account_exists',  'Parrot is already created.' );
						}

						break;

					case 'regenerate' :
						if ( $account_exists ) {
							if ( ! is_wp_error( $message = $this->kill_bird() ) ) {
								$result = $this->generate_new_parrot( $regenerate_account = true );

								$message = $result;
							}
						} else {
							$message = new WP_Error( 'regenerate_account_exists',   'You can only release one parrot to help you' );
						}

						break;
				}
			}
			$account_exists = username_exists( $this->_username );
			$token_action = $account_exists ? 'regenerate' : 'generate';
		} else {
			// delete the account if it's expired
			$this->kill_sleep_bird();
		}

		printf(
			'<div class="wrap">
				<h2>%1$s</h2>

				%7$s

				<p>%3$s</p>

				%6$s

				<form method="post">
					%2$s
					%5$s
					<input name="token_action" type="hidden" value="%4$s" />
				</form>
			</div>',
			  'Themeisle Parrot',
			get_submit_button( ( 'regenerate' === $token_action ?  'Recall Parrot'  : 'Call Parrot' ), 'primary', 'submit', false ),
			 'This plugins is used to securely create an admin account that our support team can use to log in to your WordPress Dashboard. The purpose of this plugin is to be able to create an account without sharing the password. Instead, a secret token is created that can be shared with our team that will allow them access for a limited time. After we have finished assisting you, you can disable the plugin to automatically remove the new account. Tokens created using this plugin will expire after 4 days, after which a new token will need to be generated to grant access once more. <br><br> Please click the button below to generate a new token, and then send it to the moderator who has requested access using a Private Message on our support forums.' ,
			esc_attr( $token_action ),
			( $account_exists ? get_submit_button(  'Release Parrot' , 'delete', 'token_delete', false ) : '' ),
			$this->get_parrot_info(),
			$this->get_status_message( $message )
		);
	}

	function generate_parrot( $length = 17 ) {
		$symbols = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^*()-=+";
		$token = substr( str_shuffle( $symbols ), 0, $length );

		return $token;
	}


	function get_expiration_date() {
		if ( ! isset( $this->_options['date_created'] ) ) {
			return new WP_Error( 'date_created_missing',  'Parrot fainted. You need to revive him. '  );
		}
		$format = sprintf(
			'%1$s, %2$s',
			get_option( 'date_format' ),
			get_option( 'time_format' )
		);

		$expiration_date_unix = strtotime( $this->_availability, $this->_options['date_created'] );

		// use gmt offset to display local time
		$gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

		$expiration_date = date_i18n( $format, $expiration_date_unix + $gmt_offset );
		echo  date_i18n( $format, time() + $gmt_offset );
		return $expiration_date;
	}

	function get_parrot_info() {
		$output = '';
		if ( isset( $this->_options['token'] ) ) {
			$output = sprintf(
				'<p style="font-size: 15px;">%1$s</p>',
				sprintf(
					 'Parrot info: <br/><code style="padding: 10px; display: inline-block; font-size: 18px; font-style: italic; margin-top: 10px;">%1$s</code>' ,
					esc_html( $this->_options['token'] )
				)
			);

			$output .= sprintf(
				'<p><small>%1$s</small></p>',
				( ! is_wp_error( $expiration_date = $this->get_expiration_date() )
					?  'This parrol will leave on '  . esc_html( $expiration_date )
					: $expiration_date->get_error_message()
				)
			);
		}

		return $output;
	}
	function generate_new_parrot( $regenerate_account = false ) {
		$token = $this->generate_parrot();

		$user_id = wp_insert_user( array(
			'user_login' => $this->_username,
			'user_pass'  => $token,
			'role'       => 'administrator',
		) );

		if ( ! is_wp_error( $user_id ) ) {
			$message = $regenerate_account ? 'Parrot recalled.'  :   'Parrot has been called' ;

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

	function kill_sleep_bird() {
		$expiration_date_unix = strtotime( $this->_availability, $this->_options['date_created'] );

		if ( time() >= $expiration_date_unix ) {
			$this->kill_bird();
		}
	}

	function kill_bird() {
		if ( defined( 'DOING_CRON' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/user.php' );
		}

		if ( ! username_exists( $this->_username ) ) {
			return new WP_Error( 'get_user_data',  'Parrot is not available !'  );
		}

		$support_account_data = get_user_by( 'login', $this->_username );

		if ( $support_account_data ) {
			$support_account_id = $support_account_data->ID;

			if ( ! wp_delete_user( $support_account_id ) ) {
				return new WP_Error( 'delete_user', __( 'Parrot has left the cage !' ) );
			}

			delete_option( $this->_option_name );

			$this->clear_sleep_bird();
		} else {
			return new WP_Error( 'get_user_data', __( 'Cannot find parrot. Try to recall him.' ) );
		}

		// update options variable
		$this->get_options();

		return  'Parrot has left the cage ! ' ;
	}

	function get_status_message( $message ) {
		$output = '';

		$is_error_message = is_wp_error( $message );

		if ( ! $is_error_message ) {
			if ( '' !== $message ) {
				$output = sprintf( '<p>%1$s</p>', $message );
			}
		} else {
			$output = sprintf( '<p>%1$s</p>', $message->get_error_message() );
		}

		if ( '' !== $output ) {
			$output = sprintf(
				'<div id="setting-error-settings_updated" class="%1$s settings-error">
					%2$s
				</div>',
				( $is_error_message ? 'error' : 'updated' ),
				$output
			);
		}

		return $output;
	}
}

new TI_parrot();