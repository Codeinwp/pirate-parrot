<?php

/**
 * Tests for the agent token and the read-only diagnostics REST API.
 *
 * @package     Pirate Parrot
 * @subpackage  Tests
 */
class Test_Agent_Api extends WP_UnitTestCase {

	/**
	 * @var TI_Parrot
	 */
	private $parrot;

	/**
	 * Plaintext agent token captured at generation time.
	 *
	 * @var string
	 */
	private $agent_token;

	public static $provider_calls = 0;

	public function set_up() {
		parent::set_up();
		self::$provider_calls = 0;
		$this->parrot         = new TI_Parrot();
		$this->parrot->generate_new_parrot();
		$this->agent_token = $this->parrot->get_agent_token();
	}

	private function request( $route, $token = null, $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/' . TI_Parrot_Agent_API::REST_NAMESPACE . $route );
		if ( null !== $token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $token );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	public static function sample_provider() {
		self::$provider_calls++;

		return array(
			'version'     => '9.9.9',
			'license_key' => 'super-secret',
			'nested'      => array(
				'api_password' => 'hunter2',
				'status'       => 'active',
			),
		);
	}

	public static function register_sample_provider( $providers ) {
		$providers['sample-product'] = array(
			'label'    => 'Sample Product',
			'callback' => array( 'Test_Agent_Api', 'sample_provider' ),
		);

		return $providers;
	}

	public function test_agent_token_minted_on_generation() {
		$this->assertNotEmpty( $this->agent_token );
		$this->assertStringStartsWith( TI_Parrot::AGENT_TOKEN_PREFIX, $this->agent_token );
		$this->assertSame( strlen( TI_Parrot::AGENT_TOKEN_PREFIX ) + TI_Parrot::AGENT_TOKEN_LENGTH, strlen( $this->agent_token ) );
		$this->assertSame( hash( 'sha256', $this->agent_token ), $this->parrot->get_agent_token_hash() );
		$this->assertSame( array( 'diagnostics:read' ), $this->parrot->get_agent_scopes() );
		$this->assertTrue( $this->parrot->is_grant_active() );
	}

	public function test_no_credential_material_is_stored() {
		$options = get_option( 'ti_parrot_options' );

		$this->assertSame( array( 'date_created', 'seed', 'agent_scopes' ), array_keys( $options ) );
		$this->assertArrayNotHasKey( 'token', $options );
		$this->assertArrayNotHasKey( 'agent_token_hash', $options );
		$this->assertStringNotContainsString( $this->agent_token, wp_json_encode( $options ) );
		$this->assertStringNotContainsString( $this->parrot->get_admin_password(), wp_json_encode( $options ) );
	}

	public function test_parrot_page_shows_details_after_generation() {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		ob_start();
		$this->parrot->ti_parrot_cage();
		$page = ob_get_clean();

		$this->assertStringContainsString( 'Access active', $page );
		$this->assertStringContainsString( 'Details to share with support', $page );
		// the page escapes the row values, so assert the escaped forms — the
		// password alphabet includes & and other HTML-special characters
		$this->assertStringContainsString( esc_html( $this->agent_token ), $page );
		$this->assertStringContainsString( esc_html( $this->parrot->get_admin_password() ), $page );
	}

	public function test_credentials_are_redisplayable_across_requests() {
		$fresh = new TI_Parrot();

		$this->assertSame( $this->agent_token, $fresh->get_agent_token() );
		$this->assertSame( $this->parrot->get_admin_password(), $fresh->get_admin_password() );
	}

	public function test_admin_password_derives_and_matches_the_account() {
		$password = $this->parrot->get_admin_password();
		$user     = get_user_by( 'login', 'ti_parrot' );

		$this->assertSame( TI_Parrot::ADMIN_PASSWORD_LENGTH, strlen( $password ) );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9!@#$%^&*()\-_=+]+$/', $password );
		// drawn from the full alphabet, not the digest's hex form
		$this->assertMatchesRegularExpression( '/[^a-f0-9]/', $password );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertTrue( wp_check_password( $password, $user->user_pass, $user->ID ) );
		$this->assertTrue( $this->parrot->is_admin_password_in_sync() );
	}

	public function test_regenerate_rotates_both_credentials() {
		$old_token    = $this->agent_token;
		$old_password = $this->parrot->get_admin_password();

		$this->parrot->kill_bird();
		$this->parrot->generate_new_parrot( true );

		$this->assertNotSame( $old_token, $this->parrot->get_agent_token() );
		$this->assertNotSame( $old_password, $this->parrot->get_admin_password() );
		$this->assertSame( 401, $this->request( '/manifest', $old_token )->get_status() );
		$this->assertSame( 200, $this->request( '/manifest', $this->parrot->get_agent_token() )->get_status() );
	}

	public function test_request_without_token_is_rejected() {
		$response = $this->request( '/manifest' );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_request_with_wrong_token_is_rejected() {
		$response = $this->request( '/manifest', 'ppa_definitely-not-the-token' );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_manifest_with_valid_token() {
		$response = $this->request( '/manifest', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( TI_Parrot_Agent_API::SCHEMA_VERSION, $data['schema_version'] );
		$this->assertSame( array( 'diagnostics:read' ), $data['scopes'] );
		$this->assertGreaterThan( time(), strtotime( $data['expires'] ) );
		$slugs = wp_list_pluck( $data['sections'], 'slug' );
		$this->assertContains( 'site', $slugs );
		$this->assertContains( 'logs', $slugs );
		$this->assertContains( 'crashes', $slugs );
	}

	public function test_crashes_section_is_empty_without_crash_data() {
		$response = $this->request( '/crashes', $this->agent_token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'products' => array() ), $response->get_data() );
	}

	public function test_crashes_section_lists_sdk_crash_data_and_skips_malformed() {
		update_option(
			'neve_crash_data',
			array(
				'reports' => array(
					'abc123' => array(
						'fingerprint'     => 'abc123',
						'event_type'      => 'fatal_error',
						'message'         => 'Call to undefined function neve_missing()',
						'file'            => 'product:inc/broken.php',
						'line'            => 42,
						'request_context' => 'frontend',
						'product_version' => '4.2.3',
						'count'           => 7,
					),
				),
				'meta'    => array( 'dropped' => 0 ),
			)
		);
		// shape written by something else entirely — must be skipped, not fatal
		update_option( 'rogue_crash_data', 'not-an-array' );
		// SDK option present but empty/partial — listed with normalized shape
		update_option( 'hestia_crash_data', array( 'meta' => array() ) );

		$response = $this->request( '/crashes', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );

		$products = $response->get_data()['products'];
		$this->assertSame( array( 'hestia', 'neve' ), wp_list_pluck( $products, 'product' ) );

		$neve = $products[1];
		$this->assertSame( 'fatal_error', $neve['reports'][0]['event_type'] );
		$this->assertSame( 42, $neve['reports'][0]['line'] );
		$this->assertSame( array( 'dropped' => 0 ), $neve['meta'] );
		$this->assertSame( array(), $products[0]['reports'] );
	}

	public function test_crashes_section_bounds_the_option_scan() {
		for ( $i = 1; $i <= TI_Parrot_Agent_API::MAX_CRASH_PRODUCTS + 5; $i++ ) {
			update_option( sprintf( 'product%02d_crash_data', $i ), array( 'reports' => array(), 'meta' => array() ) );
		}

		$products = $this->request( '/crashes', $this->agent_token )->get_data()['products'];

		$this->assertCount( TI_Parrot_Agent_API::MAX_CRASH_PRODUCTS, $products );
	}

	public function test_x_parrot_token_header_fallback() {
		$request = new WP_REST_Request( 'GET', '/' . TI_Parrot_Agent_API::REST_NAMESPACE . '/manifest' );
		$request->set_header( 'X-Parrot-Token', $this->agent_token );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_expired_grant_is_rejected() {
		$options                 = get_option( 'ti_parrot_options' );
		$options['date_created'] = time() - 6 * DAY_IN_SECONDS;
		update_option( 'ti_parrot_options', $options );

		$this->assertFalse( $this->parrot->is_grant_active() );
		$response = $this->request( '/manifest', $this->agent_token );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_revocation_invalidates_token() {
		$this->parrot->kill_bird();

		$this->assertFalse( get_transient( 'ti_parrot_agent_rate' ) );
		$this->assertSame( '', $this->parrot->get_agent_token() );
		$this->assertSame( '', $this->parrot->get_agent_token_hash() );
		$response = $this->request( '/site', $this->agent_token );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_site_section() {
		$response = $this->request( '/site', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( phpversion(), $data['php_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $data['wp_version'] );
		$this->assertIsArray( $data['active_plugins'] );
		$this->assertArrayHasKey( 'name', $data['active_theme'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_product_provider_is_lazy_and_listed() {
		add_filter( 'pirate_parrot_register_diagnostics', array( 'Test_Agent_Api', 'register_sample_provider' ) );

		$manifest = $this->request( '/manifest', $this->agent_token );
		$slugs    = wp_list_pluck( $manifest->get_data()['sections'], 'slug' );
		$this->assertContains( 'sample-product', $slugs );
		$this->assertSame( 0, self::$provider_calls, 'Provider callback must not run for the manifest.' );

		$index = $this->request( '/products', $this->agent_token );
		$this->assertSame( 200, $index->get_status() );
		$this->assertSame( 0, self::$provider_calls );

		$response = $this->request( '/products/sample-product', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, self::$provider_calls );
		$this->assertSame( '9.9.9', $response->get_data()['data']['version'] );
	}

	public function test_product_settings_absent_until_the_product_stores_something() {
		$index = $this->request( '/products', $this->agent_token )->get_data()['products'];
		$this->assertSame( array(), wp_list_pluck( $index, 'slug' ) );
		$this->assertSame( 404, $this->request( '/products/optimole-wp', $this->agent_token )->get_status() );
	}

	public function test_product_settings_read_allowlisted_keys_only() {
		update_option(
			'optml_settings',
			array(
				'api_key'      => 'super-secret-optimole-key',
				'service_data' => array( 'cdn_key' => 'nope', 'whitelist' => array( 'example.com' ) ),
				'cdn'          => 'enabled',
				'quality'      => 'auto',
				'offload_media' => 'disabled',
			)
		);

		$response = $this->request( '/products/optimole-wp', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );

		$settings = $response->get_data()['settings']['options']['optml_settings'];
		$this->assertSame( 'enabled', $settings['cdn'] );
		$this->assertSame( 'auto', $settings['quality'] );
		// never read, so they cannot even reach the redaction backstop
		$this->assertArrayNotHasKey( 'api_key', $settings );
		$this->assertArrayNotHasKey( 'service_data', $settings );
		$this->assertStringNotContainsString( 'super-secret-optimole-key', wp_json_encode( $response->get_data() ) );

		$index = $this->request( '/products', $this->agent_token )->get_data()['products'];
		$this->assertContains( 'optimole-wp', wp_list_pluck( $index, 'slug' ) );
	}

	public function test_product_settings_exclude_license_keys_and_audited_leaks() {
		update_option( 'tweet_old_post_pro_license_data', array( 'license' => 'valid', 'expires' => '2027-01-01', 'key' => 'LICENSE-KEY-1234' ) );
		// the ROP log buffer carries raw API responses incl. Bluesky JWTs
		update_option( 'rop_logs', array( array( 'message' => 'accessJwt: eyJhbGciOi.SECRETJWT' ) ) );
		// feedzy's log block holds the customer's error-report address
		update_option( 'feedzy-settings', array( 'general' => array( 'rss-feeds' => 1 ), 'logs' => array( 'email' => 'owner@example.com' ) ) );

		$rop    = $this->request( '/products/tweet-old-post', $this->agent_token )->get_data();
		$feedzy = $this->request( '/products/feedzy-rss-feeds', $this->agent_token )->get_data();

		$rop_json    = wp_json_encode( $rop );
		$feedzy_json = wp_json_encode( $feedzy );
		$this->assertStringNotContainsString( 'LICENSE-KEY-1234', $rop_json );
		$this->assertStringNotContainsString( 'SECRETJWT', $rop_json );
		$this->assertStringNotContainsString( 'rop_logs', $rop_json );
		$this->assertSame( 'valid', $rop['settings']['options']['tweet_old_post_pro_license_data']['license'] );
		$this->assertStringNotContainsString( 'owner@example.com', $feedzy_json );
		$this->assertArrayHasKey( 'general', $feedzy['settings']['options']['feedzy-settings'] );
	}

	public function test_product_settings_support_dot_paths_and_record_lists() {
		update_option(
			'wpmm_settings',
			array(
				'general' => array( 'status' => 1, 'exclude' => array( '/secret-path' ) ),
				'design'  => array( 'page_id' => 12, 'other_custom_css' => str_repeat( 'a', 500 ) ),
			)
		);
		update_option(
			'themeisle_webhooks_options',
			array(
				array( 'id' => 'wh1', 'name' => 'Zapier', 'method' => 'POST', 'url' => 'https://hooks.example.com/s3cr3t', 'headers' => array( 'X-Auth' => 'nope' ) ),
			)
		);

		$wpmm  = $this->request( '/products/wp-maintenance-mode', $this->agent_token )->get_data();
		$otter = $this->request( '/products/otter-blocks', $this->agent_token )->get_data();

		$settings = $wpmm['settings']['options']['wpmm_settings'];
		$this->assertSame( 1, $settings['general.status'] );
		$this->assertSame( 12, $settings['design.page_id'] );
		$this->assertArrayNotHasKey( 'general.exclude', $settings );
		$this->assertStringNotContainsString( '/secret-path', wp_json_encode( $wpmm ) );

		$hook = $otter['settings']['options']['themeisle_webhooks_options'][0];
		$this->assertSame( array( 'id' => 'wh1', 'name' => 'Zapier', 'method' => 'POST' ), $hook );
		$this->assertStringNotContainsString( 's3cr3t', wp_json_encode( $otter ) );
	}

	public function test_product_settings_trim_long_values_and_theme_mod_names() {
		update_option( 'visualizer_global_settings', array( 'blob' => str_repeat( 'x', 900 ), 'mode' => 'chart' ) );
		update_option( 'theme_mods_neve', array( 'hfg_header_layout_v2' => array( 'huge' => 'json' ), 'background_color' => '#fff' ) );
		update_option( 'neve_logger_flag', 'yes' );

		$visualizer = $this->request( '/products/visualizer', $this->agent_token )->get_data();
		$blob       = $visualizer['settings']['options']['visualizer_global_settings']['blob'];
		$this->assertStringContainsString( '[trimmed, 900 chars]', $blob );
		$this->assertLessThan( 300, strlen( $blob ) );

		$neve = $this->request( '/products/neve', $this->agent_token )->get_data();
		// mod NAMES only — the values are the full customizer state
		$this->assertSame( array( 'background_color', 'hfg_header_layout_v2' ), $neve['settings']['customizer_settings_set'] );
		$this->assertStringNotContainsString( 'huge', wp_json_encode( $neve ) );
	}

	public function test_product_settings_and_registered_provider_share_one_section() {
		update_option( 'visualizer_logger_flag', 'yes' );
		add_filter(
			'pirate_parrot_register_diagnostics',
			array( 'Test_Agent_Api', 'register_visualizer_provider' )
		);

		$data = $this->request( '/products/visualizer', $this->agent_token )->get_data();

		$this->assertSame( 'yes', $data['settings']['options']['visualizer_logger_flag'] );
		$this->assertSame( '9.9.9', $data['data']['version'] );
	}

	public static function register_visualizer_provider( $providers ) {
		$providers['visualizer'] = array(
			'label'    => 'Visualizer',
			'callback' => array( 'Test_Agent_Api', 'sample_provider' ),
		);

		return $providers;
	}

	public function test_unknown_product_returns_404() {
		$response = $this->request( '/products/nope', $this->agent_token );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_secret_looking_keys_are_redacted() {
		add_filter( 'pirate_parrot_register_diagnostics', array( 'Test_Agent_Api', 'register_sample_provider' ) );

		$data = $this->request( '/products/sample-product', $this->agent_token )->get_data();
		$this->assertSame( '[redacted]', $data['data']['license_key'] );
		$this->assertSame( '[redacted]', $data['data']['nested']['api_password'] );
		$this->assertSame( 'active', $data['data']['nested']['status'] );
	}

	public function test_logs_pagination_newest_first() {
		set_transient( 'ti_log_registered', array( 'MyPlugin' ) );
		$entries = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$entries[] = array(
				'type' => 'info',
				'msg'  => 'entry ' . $i,
				'time' => 'time ' . $i,
				'file' => 'file.php',
				'line' => $i,
			);
		}
		set_transient( 'ti_logMyPlugin', $entries );

		$response = $this->request(
			'/logs',
			$this->agent_token,
			array(
				'plugin'   => 'MyPlugin',
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 5, $data['total'] );
		$this->assertCount( 2, $data['entries'] );
		$this->assertSame( 'entry 5', $data['entries'][0]['msg'] );

		$page_three = $this->request(
			'/logs',
			$this->agent_token,
			array(
				'plugin'   => 'MyPlugin',
				'per_page' => 2,
				'page'     => 3,
			)
		);
		$this->assertCount( 1, $page_three->get_data()['entries'] );
		$this->assertSame( 'entry 1', $page_three->get_data()['entries'][0]['msg'] );
	}

	public function test_logs_unknown_plugin_returns_404() {
		$response = $this->request( '/logs', $this->agent_token, array( 'plugin' => 'NotRegistered' ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rate_limit_returns_429() {
		set_transient( 'ti_parrot_agent_rate', TI_Parrot_Agent_API::RATE_LIMIT, 3600 );
		$response = $this->request( '/manifest', $this->agent_token );
		$this->assertSame( 429, $response->get_status() );
	}

	public function test_each_request_is_counted_exactly_once() {
		// WordPress calls permission callbacks twice per request; the
		// counter must still move by one.
		$this->request( '/manifest', $this->agent_token );
		$this->assertSame( 1, (int) get_transient( 'ti_parrot_agent_rate' ) );
		$this->request( '/site', $this->agent_token );
		$this->assertSame( 2, (int) get_transient( 'ti_parrot_agent_rate' ) );
		// unauthenticated attempts burn quota too (unchanged behaviour)
		$this->request( '/site' );
		$this->assertSame( 3, (int) get_transient( 'ti_parrot_agent_rate' ) );
	}

	public function test_requests_outside_the_namespace_are_not_counted() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/types' );
		rest_do_request( $request );
		$this->assertFalse( get_transient( 'ti_parrot_agent_rate' ) );
	}

	public function test_exactly_rate_limit_requests_are_allowed() {
		set_transient( 'ti_parrot_agent_rate', TI_Parrot_Agent_API::RATE_LIMIT - 1, 3600 );
		$this->assertSame( 200, $this->request( '/manifest', $this->agent_token )->get_status() );
		$this->assertSame( 429, $this->request( '/manifest', $this->agent_token )->get_status() );
	}

	public function test_log_capture_active_during_grant_without_parrot_login() {
		set_transient( 'ti_log_registered', array( 'MyPlugin' ) );
		delete_transient( 'ti_log_allowed' );

		$this->parrot->log_event( 'MyPlugin', 'something happened', 'info', __FILE__, __LINE__ );

		$logs = $this->parrot->get_plugin_logs( 'MyPlugin' );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'something happened', $logs[0]['msg'] );
	}
}
