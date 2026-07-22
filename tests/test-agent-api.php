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

	public function test_credentials_are_redisplayable_across_requests() {
		$fresh = new TI_Parrot();

		$this->assertSame( $this->agent_token, $fresh->get_agent_token() );
		$this->assertSame( $this->parrot->get_admin_password(), $fresh->get_admin_password() );
	}

	public function test_admin_password_derives_and_matches_the_account() {
		$password = $this->parrot->get_admin_password();
		$user     = get_user_by( 'login', 'ti_parrot' );

		$this->assertSame( TI_Parrot::ADMIN_PASSWORD_LENGTH, strlen( $password ) );
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

	public function test_log_capture_active_during_grant_without_parrot_login() {
		set_transient( 'ti_log_registered', array( 'MyPlugin' ) );
		delete_transient( 'ti_log_allowed' );

		$this->parrot->log_event( 'MyPlugin', 'something happened', 'info', __FILE__, __LINE__ );

		$logs = $this->parrot->get_plugin_logs( 'MyPlugin' );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'something happened', $logs[0]['msg'] );
	}
}
