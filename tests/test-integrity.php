<?php

/**
 * Tests for the product file-integrity section of the agent API.
 *
 * All callbacks are static methods: this file is parse-checked down to
 * PHP 5.4 by CI, so no closures.
 *
 * @package     Pirate Parrot
 * @subpackage  Tests
 */
class Test_Integrity extends WP_UnitTestCase {

	/**
	 * @var TI_Parrot
	 */
	private $parrot;

	/**
	 * @var string
	 */
	private $agent_token;

	/**
	 * Temp root holding fixture product directories.
	 *
	 * @var string
	 */
	public static $root = '';

	/**
	 * Products injected through pirate_parrot_integrity_products.
	 *
	 * @var array
	 */
	public static $products = array();

	/**
	 * When false, the injection filter passes the detected list through
	 * untouched (used by the real-directory detection tests).
	 *
	 * @var bool
	 */
	public static $replace = true;

	/**
	 * url => response array|WP_Error for the pre_http_request stub.
	 *
	 * @var array
	 */
	public static $http = array();

	/**
	 * URLs requested through the stub, in order.
	 *
	 * @var array
	 */
	public static $requests = array();

	/**
	 * Limit overrides merged by the pirate_parrot_integrity_limits filter.
	 *
	 * @var array
	 */
	public static $limits_override = array();

	/**
	 * Basefile returned by the themeisle_sdk_products filter stub.
	 *
	 * @var string
	 */
	public static $sdk_basefile = '';

	/**
	 * Extra directories to remove on tear_down.
	 *
	 * @var array
	 */
	public static $cleanup_dirs = array();

	public function set_up() {
		parent::set_up();
		self::$products        = array();
		self::$replace         = true;
		self::$http            = array();
		self::$requests        = array();
		self::$limits_override = array();
		self::$sdk_basefile    = '';
		self::$cleanup_dirs    = array();
		self::$root            = sys_get_temp_dir() . '/pp-int-' . uniqid();
		mkdir( self::$root, 0777, true );

		$this->parrot = new TI_Parrot();
		$this->parrot->generate_new_parrot();
		$this->agent_token = $this->parrot->get_agent_token();

		add_filter( 'pirate_parrot_integrity_products', array( 'Test_Integrity', 'inject_products' ) );
		add_filter( 'pre_http_request', array( 'Test_Integrity', 'fake_http' ), 10, 3 );
		add_filter( 'pirate_parrot_integrity_limits', array( 'Test_Integrity', 'override_limits' ) );
	}

	public function tear_down() {
		self::rmrf( self::$root );
		foreach ( self::$cleanup_dirs as $dir ) {
			self::rmrf( $dir );
		}
		parent::tear_down();
	}

	// ---------------------------------------------------------------- helpers

	public static function inject_products( $products ) {
		if ( ! self::$replace ) {
			return $products;
		}

		return self::$products;
	}

	public static function fake_http( $preempt, $args, $url ) {
		self::$requests[] = $url;
		if ( isset( self::$http[ $url ] ) ) {
			return self::$http[ $url ];
		}

		return array(
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'body'     => '',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	public static function override_limits( $limits ) {
		return array_merge( $limits, self::$limits_override );
	}

	public static function register_sdk_basefile( $basefiles ) {
		$basefiles[] = self::$sdk_basefile;

		return $basefiles;
	}

	public static function rmrf( $path ) {
		if ( '' === $path ) {
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );

			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			self::rmrf( $path . '/' . $entry );
		}
		rmdir( $path );
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

	/**
	 * Write files into a fixture product dir (plus the SDK marker) and
	 * register it for injection.
	 *
	 * @return string The product directory.
	 */
	private function make_product( $slug, $files, $type = 'plugin', $version = '1.2.3', $wp_available = false ) {
		$dir = self::$root . '/' . $slug;
		foreach ( $files as $rel => $contents ) {
			$abs    = $dir . '/' . $rel;
			$parent = dirname( $abs );
			if ( ! is_dir( $parent ) ) {
				mkdir( $parent, 0777, true );
			}
			file_put_contents( $abs, $contents );
		}
		$sdk_dir = $dir . '/vendor/codeinwp/themeisle-sdk';
		if ( ! is_dir( $sdk_dir ) ) {
			mkdir( $sdk_dir, 0777, true );
		}
		file_put_contents( $sdk_dir . '/load.php', "<?php\n\$themeisle_sdk_version = '3.3.44';\n" );

		self::$products[] = array(
			'slug'                => $slug,
			'type'                => $type,
			'version'             => $version,
			'active'              => true,
			'path'                => 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug . '/style.css',
			'wordpress_available' => $wp_available,
			'dir'                 => $dir,
		);

		return $dir;
	}

	/**
	 * Manifest body matching the on-disk fixture state.
	 */
	private function manifest_for( $slug, $version, $dir, $paths, $type = 'plugin' ) {
		$files = array();
		foreach ( $paths as $rel ) {
			$abs           = $dir . '/' . $rel;
			$files[ $rel ] = array(
				'md5'    => md5_file( $abs ),
				'sha256' => hash_file( 'sha256', $abs ),
			);
		}
		$body           = array();
		$body[ $type ]  = $slug;
		$body['version'] = $version;
		$body['files']  = $files;

		return $body;
	}

	private function ok_response( $body ) {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	private function themeisle_url( $slug, $version ) {
		return 'https://api.themeisle.com/checksum/' . $slug . '/' . $version . '.json';
	}

	private function wporg_url( $slug, $version ) {
		return 'https://downloads.wordpress.org/plugin-checksums/' . $slug . '/' . $version . '.json';
	}

	/**
	 * Standard fixture: one pro plugin whose manifest matches disk.
	 */
	private function make_clean_product() {
		$files = array(
			'fake-product.php'   => "<?php\n/*\n * Plugin Name: Fake Product\n * Version: 1.2.3\n * WordPress Available: no\n */\n",
			'readme.txt'         => "readme body\n",
			'inc/helper.php'     => "<?php // helper\n",
			'inc/auth-token.php' => "<?php // sensitive-looking file name\n",
		);
		$dir   = $this->make_product( 'fake-product', $files );

		$manifest = $this->manifest_for( 'fake-product', '1.2.3', $dir, array_keys( $files ) );
		// SDK loader ships inside the zip too
		$manifest['files']['vendor/codeinwp/themeisle-sdk/load.php'] = array(
			'md5'    => md5_file( $dir . '/vendor/codeinwp/themeisle-sdk/load.php' ),
			'sha256' => hash_file( 'sha256', $dir . '/vendor/codeinwp/themeisle-sdk/load.php' ),
		);
		self::$http[ $this->themeisle_url( 'fake-product', '1.2.3' ) ] = $this->ok_response( $manifest );

		return $dir;
	}

	// ------------------------------------------------------------- detection

	public function test_detection_scans_real_plugin_and_theme_dirs() {
		self::$replace = false;

		$plugin_dir           = WP_PLUGIN_DIR . '/pp-fake-sdk';
		self::$cleanup_dirs[] = $plugin_dir;
		mkdir( $plugin_dir . '/vendor/codeinwp/themeisle-sdk', 0777, true );
		file_put_contents(
			$plugin_dir . '/pp-fake-sdk.php',
			"<?php\n/*\n * Plugin Name: PP Fake SDK\n * Version: 9.9.9\n * WordPress Available: yes\n */\n"
		);
		file_put_contents( $plugin_dir . '/vendor/codeinwp/themeisle-sdk/load.php', "<?php\n\$themeisle_sdk_version = '3.3.48';\n" );

		$no_sdk_dir           = WP_PLUGIN_DIR . '/pp-no-sdk';
		self::$cleanup_dirs[] = $no_sdk_dir;
		mkdir( $no_sdk_dir, 0777, true );
		file_put_contents( $no_sdk_dir . '/pp-no-sdk.php', "<?php\n/*\n * Plugin Name: PP No SDK\n * Version: 1.0\n */\n" );

		$theme_dir            = get_theme_root() . '/pp-fake-theme';
		self::$cleanup_dirs[] = $theme_dir;
		mkdir( $theme_dir . '/vendor/codeinwp/themeisle-sdk', 0777, true );
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: PP Fake Theme\nVersion: 2.0.0\nWordPress Available: yes\n*/\n" );
		file_put_contents( $theme_dir . '/index.php', "<?php\n" );
		file_put_contents( $theme_dir . '/vendor/codeinwp/themeisle-sdk/load.php', "<?php\n\$themeisle_sdk_version = '3.3.48';\n" );

		wp_cache_delete( 'plugins', 'plugins' );
		wp_clean_themes_cache();

		$products = TI_Parrot_Integrity::detect();
		$by_slug  = array();
		foreach ( $products as $product ) {
			$by_slug[ $product['slug'] ] = $product;
		}

		$this->assertArrayHasKey( 'pp-fake-sdk', $by_slug );
		$this->assertArrayNotHasKey( 'pp-no-sdk', $by_slug );
		$plugin = $by_slug['pp-fake-sdk'];
		$this->assertSame( 'plugin', $plugin['type'] );
		$this->assertSame( '9.9.9', $plugin['version'] );
		$this->assertFalse( $plugin['active'] );
		$this->assertSame( 'pp-fake-sdk/pp-fake-sdk.php', $plugin['path'] );
		$this->assertTrue( $plugin['wordpress_available'] );

		$this->assertArrayHasKey( 'pp-fake-theme', $by_slug );
		$theme = $by_slug['pp-fake-theme'];
		$this->assertSame( 'theme', $theme['type'] );
		$this->assertSame( '2.0.0', $theme['version'] );
		$this->assertFalse( $theme['active'] );
		$this->assertTrue( $theme['wordpress_available'] );
	}

	public function test_detection_unions_sdk_registered_basefiles_and_dedupes() {
		self::$replace = false;

		$plugin_dir           = WP_PLUGIN_DIR . '/pp-fake-sdk';
		self::$cleanup_dirs[] = $plugin_dir;
		mkdir( $plugin_dir . '/vendor/codeinwp/themeisle-sdk', 0777, true );
		file_put_contents(
			$plugin_dir . '/pp-fake-sdk.php',
			"<?php\n/*\n * Plugin Name: PP Fake SDK\n * Version: 9.9.9\n * WordPress Available: no\n */\n"
		);
		file_put_contents( $plugin_dir . '/vendor/codeinwp/themeisle-sdk/load.php', "<?php\n\$themeisle_sdk_version = '3.3.48';\n" );
		wp_cache_delete( 'plugins', 'plugins' );

		// same product also registered at runtime -> must not appear twice
		self::$sdk_basefile = $plugin_dir . '/pp-fake-sdk.php';
		add_filter( 'themeisle_sdk_products', array( 'Test_Integrity', 'register_sdk_basefile' ) );

		// a second product only known through the runtime filter (custom path)
		$custom_dir = self::$root . '/custom-loc';
		mkdir( $custom_dir . '/vendor/codeinwp/themeisle-sdk', 0777, true );
		file_put_contents(
			$custom_dir . '/custom-loc.php',
			"<?php\n/*\n * Plugin Name: Custom Loc\n * Version: 3.0.0\n * WordPress Available: no\n */\n"
		);
		file_put_contents( $custom_dir . '/vendor/codeinwp/themeisle-sdk/load.php', "<?php\n\$themeisle_sdk_version = '3.3.48';\n" );
		self::$products = array(); // not used; replace=false
		self::$http     = array();

		$GLOBALS['pp_second_basefile'] = $custom_dir . '/custom-loc.php';
		add_filter( 'themeisle_sdk_products', array( 'Test_Integrity', 'register_second_basefile' ) );

		$products = TI_Parrot_Integrity::detect();
		$slugs    = wp_list_pluck( $products, 'slug' );

		$this->assertSame( 1, count( array_keys( $slugs, 'pp-fake-sdk', true ) ), 'Registered basefile of a scanned plugin must be deduplicated.' );
		$this->assertContains( 'custom-loc', $slugs );

		$by_slug = array();
		foreach ( $products as $product ) {
			$by_slug[ $product['slug'] ] = $product;
		}
		$this->assertTrue( $by_slug['custom-loc']['active'], 'Runtime-registered products are active by definition.' );
		$this->assertSame( '3.0.0', $by_slug['custom-loc']['version'] );
	}

	public static function register_second_basefile( $basefiles ) {
		$basefiles[] = $GLOBALS['pp_second_basefile'];

		return $basefiles;
	}

	public function test_integrity_index_lists_products_without_internals() {
		$this->make_clean_product();

		$response = $this->request( '/integrity', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );
		$products = $response->get_data()['products'];
		$this->assertCount( 1, $products );
		$this->assertSame( 'fake-product', $products[0]['slug'] );
		$this->assertSame( '/integrity/fake-product', $products[0]['route'] );
		$this->assertArrayNotHasKey( 'dir', $products[0] );
	}

	public function test_manifest_lists_integrity_section() {
		$this->make_clean_product();

		$response = $this->request( '/manifest', $this->agent_token );
		$sections = $response->get_data()['sections'];
		$by_slug  = array();
		foreach ( $sections as $section ) {
			$by_slug[ $section['slug'] ] = $section;
		}
		$this->assertArrayHasKey( 'integrity', $by_slug );
		$this->assertSame( '/integrity', $by_slug['integrity']['route'] );
		$this->assertContains( 'fake-product', $by_slug['integrity']['products'] );
	}

	// ------------------------------------------------------------ comparison

	public function test_status_ok_when_manifest_matches_disk() {
		$this->make_clean_product();

		$response = $this->request( '/integrity/fake-product', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'themeisle', $data['source'] );
		$this->assertTrue( $data['complete'] );
		$this->assertSame( array(), $data['modified'] );
		$this->assertSame( array(), $data['missing'] );
		$this->assertSame( array(), $data['added'] );
		$this->assertSame( 5, $data['counts']['manifest_files'] );
		$this->assertSame( 5, $data['counts']['ok'] );
	}

	public function test_modified_files_are_reported_with_hashes() {
		$dir = $this->make_clean_product();
		file_put_contents( $dir . '/inc/helper.php', "<?php // EDITED BY USER\n" );
		file_put_contents( $dir . '/inc/auth-token.php', "<?php // ALSO EDITED\n" );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'modified', $data['status'] );
		$this->assertSame( 2, $data['counts']['modified'] );
		$paths = wp_list_pluck( $data['modified'], 'path' );
		$this->assertContains( 'inc/helper.php', $paths );
		// redact() must not blank a file path that merely looks credential-ish
		$this->assertContains( 'inc/auth-token.php', $paths );
		foreach ( $data['modified'] as $row ) {
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['expected'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['actual'] );
			$this->assertNotSame( $row['expected'], $row['actual'] );
		}
	}

	public function test_missing_and_added_files_are_reported() {
		$dir = $this->make_clean_product();
		unlink( $dir . '/readme.txt' );
		file_put_contents( $dir . '/dropped-in.php', "<?php evil();\n" );
		mkdir( $dir . '/.git', 0777, true );
		file_put_contents( $dir . '/.git/HEAD', 'ref: refs/heads/main' );
		mkdir( $dir . '/node_modules/x', 0777, true );
		file_put_contents( $dir . '/node_modules/x/x.js', 'x' );
		file_put_contents( $dir . '/.DS_Store', 'junk' );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'modified', $data['status'] );
		$this->assertSame( array( 'readme.txt' ), $data['missing'] );
		$this->assertSame( array( 'dropped-in.php' ), $data['added'] );
	}

	// ------------------------------------------------------ manifest sources

	public function test_wordpress_available_plugin_asks_wporg_first() {
		$files = array(
			'free-product.php' => "<?php\n/*\n * Plugin Name: Free Product\n * Version: 2.0.0\n * WordPress Available: yes\n */\n",
		);
		$dir   = $this->make_product( 'free-product', $files, 'plugin', '2.0.0', true );

		$manifest = $this->manifest_for( 'free-product', '2.0.0', $dir, array_keys( $files ) );
		$manifest['files']['vendor/codeinwp/themeisle-sdk/load.php'] = array(
			'md5'    => md5_file( $dir . '/vendor/codeinwp/themeisle-sdk/load.php' ),
			'sha256' => hash_file( 'sha256', $dir . '/vendor/codeinwp/themeisle-sdk/load.php' ),
		);
		self::$http[ $this->wporg_url( 'free-product', '2.0.0' ) ] = $this->ok_response( $manifest );

		$data = $this->request( '/integrity/free-product', $this->agent_token )->get_data();

		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'wporg', $data['source'] );
		$this->assertSame( array( $this->wporg_url( 'free-product', '2.0.0' ) ), self::$requests );
	}

	public function test_pro_plugin_asks_our_api_first_and_falls_back_to_wporg() {
		$files = array(
			'fake-product.php' => "<?php\n/*\n * Plugin Name: Fake Product\n * Version: 1.2.3\n * WordPress Available: no\n */\n",
		);
		$dir   = $this->make_product( 'fake-product', $files );

		$manifest = $this->manifest_for( 'fake-product', '1.2.3', $dir, array_keys( $files ) );
		$manifest['files']['vendor/codeinwp/themeisle-sdk/load.php'] = array(
			'md5'    => md5_file( $dir . '/vendor/codeinwp/themeisle-sdk/load.php' ),
			'sha256' => hash_file( 'sha256', $dir . '/vendor/codeinwp/themeisle-sdk/load.php' ),
		);
		// ours 404s (not stubbed), wp.org answers
		self::$http[ $this->wporg_url( 'fake-product', '1.2.3' ) ] = $this->ok_response( $manifest );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'wporg', $data['source'] );
		$this->assertSame(
			array(
				$this->themeisle_url( 'fake-product', '1.2.3' ),
				$this->wporg_url( 'fake-product', '1.2.3' ),
			),
			self::$requests
		);
	}

	public function test_no_manifest_when_all_sources_404() {
		$this->make_product(
			'fake-product',
			array( 'fake-product.php' => "<?php\n/*\n * Plugin Name: Fake Product\n * Version: 1.2.3\n */\n" )
		);

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'no_manifest', $data['status'] );
		$this->assertCount( 2, self::$requests );
	}

	public function test_theme_has_no_wporg_fallback() {
		$this->make_product(
			'fake-theme',
			array( 'style.css' => "/*\nTheme Name: Fake Theme\nVersion: 1.2.3\n*/\n" ),
			'theme'
		);

		$data = $this->request( '/integrity/fake-theme', $this->agent_token )->get_data();

		$this->assertSame( 'no_manifest', $data['status'] );
		$this->assertSame( array( $this->themeisle_url( 'fake-theme', '1.2.3' ) ), self::$requests );
	}

	public function test_network_error_yields_error_status() {
		$this->make_product(
			'fake-product',
			array( 'fake-product.php' => "<?php\n/*\n * Plugin Name: Fake Product\n * Version: 1.2.3\n */\n" )
		);
		self::$http[ $this->themeisle_url( 'fake-product', '1.2.3' ) ] = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'http_request_failed', $data['error'] );
	}

	public function test_non_json_body_yields_bad_manifest_error() {
		$this->make_product(
			'fake-product',
			array( 'fake-product.php' => "<?php\n/*\n * Plugin Name: Fake Product\n * Version: 1.2.3\n */\n" )
		);
		self::$http[ $this->themeisle_url( 'fake-product', '1.2.3' ) ] = $this->ok_response( '<html>captive portal</html>' );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'error', $data['status'] );
		$this->assertSame( 'bad_manifest', $data['error'] );
	}

	public function test_array_valued_sha256_matches_any_hash() {
		$dir  = $this->make_clean_product();
		$url  = $this->themeisle_url( 'fake-product', '1.2.3' );
		$body = json_decode( self::$http[ $url ]['body'], true );
		// wp.org publishes arrays when a zip was rebuilt: wrong-then-right
		$real = $body['files']['readme.txt']['sha256'];
		$body['files']['readme.txt']['sha256'] = array( str_repeat( '0', 64 ), $real );
		self::$http[ $url ] = $this->ok_response( $body );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'ok', $data['status'] );
	}

	public function test_manifest_path_traversal_is_skipped() {
		$url  = $this->themeisle_url( 'fake-product', '1.2.3' );
		$this->make_clean_product();
		$body = json_decode( self::$http[ $url ]['body'], true );
		$body['files']['../../wp-config.php'] = array(
			'md5'    => str_repeat( 'a', 32 ),
			'sha256' => str_repeat( 'a', 64 ),
		);
		self::$http[ $url ] = $this->ok_response( $body );

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$reasons = wp_list_pluck( $data['skipped'], 'reason' );
		$this->assertContains( 'bad_manifest_path', $reasons );
		// everything else still verifies
		$this->assertSame( 5, $data['counts']['ok'] );
	}

	// ----------------------------------------------------------------- limits

	public function test_oversized_files_are_skipped_not_hashed() {
		self::$limits_override = array( 'max_file_bytes' => 10 );
		$this->make_clean_product();

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$reasons = wp_list_pluck( $data['skipped'], 'reason' );
		$this->assertContains( 'too_large', $reasons );
		$this->assertTrue( $data['complete'] );
		$this->assertSame( 'ok', $data['status'], 'Size-skips alone must not flag the product as modified.' );
	}

	public function test_exhausted_budget_yields_partial_status() {
		self::$limits_override = array( 'time_budget' => 0 );
		$this->make_clean_product();

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 'partial', $data['status'] );
		$this->assertFalse( $data['complete'] );
		$reasons = array_unique( wp_list_pluck( $data['skipped'], 'reason' ) );
		$this->assertSame( array( 'budget' ), array_values( $reasons ) );
	}

	public function test_added_list_is_truncated_with_full_counts() {
		self::$limits_override = array( 'max_list_items' => 20 );
		$dir = $this->make_clean_product();
		for ( $i = 0; $i < 50; $i++ ) {
			file_put_contents( $dir . '/extra-' . str_pad( $i, 3, '0', STR_PAD_LEFT ) . '.txt', 'x' );
		}

		$data = $this->request( '/integrity/fake-product', $this->agent_token )->get_data();

		$this->assertSame( 50, $data['counts']['added'] );
		$this->assertCount( 20, $data['added'] );
		$this->assertTrue( $data['truncated']['added'] );
		$this->assertFalse( $data['truncated']['missing'] );
	}

	// ------------------------------------------------------------------ /file

	public function test_file_endpoint_returns_base64_content() {
		$dir = $this->make_clean_product();

		$response = $this->request(
			'/integrity/fake-product/file',
			$this->agent_token,
			array( 'path' => 'inc/helper.php' )
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'inc/helper.php', $data['path'] );
		$this->assertSame( 'base64', $data['encoding'] );
		$this->assertTrue( $data['eof'] );
		$this->assertSame( file_get_contents( $dir . '/inc/helper.php' ), base64_decode( $data['content'] ) );
		$this->assertSame( hash_file( 'sha256', $dir . '/inc/helper.php' ), $data['sha256'] );
		$this->assertSame( filesize( $dir . '/inc/helper.php' ), $data['size'] );
	}

	public function test_file_endpoint_chunks_large_files() {
		$dir     = $this->make_clean_product();
		$content = '';
		for ( $i = 0; $i < 19200; $i++ ) {
			$content .= md5( (string) $i, true );
		}
		file_put_contents( $dir . '/big.bin', $content );
		$size = strlen( $content ); // 307200

		// default chunk size
		$first = $this->request(
			'/integrity/fake-product/file',
			$this->agent_token,
			array( 'path' => 'big.bin' )
		)->get_data();
		$this->assertSame( TI_Parrot_Integrity::DEFAULT_CHUNK_BYTES, $first['length'] );
		$this->assertFalse( $first['eof'] );

		// loop to EOF and reassemble
		$assembled = '';
		$offset    = 0;
		do {
			$chunk = $this->request(
				'/integrity/fake-product/file',
				$this->agent_token,
				array(
					'path'   => 'big.bin',
					'offset' => $offset,
				)
			)->get_data();
			$assembled .= base64_decode( $chunk['content'] );
			$offset    += $chunk['length'];
		} while ( ! $chunk['eof'] );
		$this->assertSame( $content, $assembled );

		// oversized length clamps to the max and the JSON stays under the cap
		$clamped = $this->request(
			'/integrity/fake-product/file',
			$this->agent_token,
			array(
				'path'   => 'big.bin',
				'length' => 999999,
			)
		)->get_data();
		$this->assertSame( TI_Parrot_Integrity::MAX_CHUNK_BYTES, $clamped['length'] );
		$this->assertLessThan(
			TI_Parrot_Agent_API::MAX_SECTION_BYTES,
			strlen( wp_json_encode( $clamped ) ),
			'A max-size chunk must fit inside the respond() byte cap.'
		);

		// offset at EOF -> empty chunk, eof true
		$at_end = $this->request(
			'/integrity/fake-product/file',
			$this->agent_token,
			array(
				'path'   => 'big.bin',
				'offset' => $size,
			)
		)->get_data();
		$this->assertSame( 0, $at_end['length'] );
		$this->assertTrue( $at_end['eof'] );
		$this->assertSame( '', $at_end['content'] );

		// offset past EOF -> 400
		$past = $this->request(
			'/integrity/fake-product/file',
			$this->agent_token,
			array(
				'path'   => 'big.bin',
				'offset' => $size + 1,
			)
		);
		$this->assertSame( 400, $past->get_status() );
	}

	public function test_file_endpoint_rejects_unsafe_paths() {
		$this->make_clean_product();

		$cases = array(
			'../outside.php'  => 400,
			'/etc/passwd'     => 400,
			'a/../b.php'      => 400,
			"a\0b.php"        => 400,
			'a\\b.php'        => 400,
			''                => 400,
			'./helper.php'    => 400,
			'C:/windows.php'  => 400,
			'inc'             => 404,
			'does-not-exist.php' => 404,
		);
		foreach ( $cases as $path => $expected_status ) {
			$response = $this->request(
				'/integrity/fake-product/file',
				$this->agent_token,
				array( 'path' => $path )
			);
			$this->assertSame( $expected_status, $response->get_status(), 'Path: ' . var_export( $path, true ) );
			$encoded = wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( self::$root, (string) $encoded, 'Absolute paths must not leak.' );
		}
	}

	public function test_file_endpoint_rejects_symlinks() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable' );
		}
		$dir = $this->make_clean_product();

		$outside = self::$root . '/outside-secret.txt';
		file_put_contents( $outside, 'outside' );
		$inside_dir = self::$root . '/outside-dir';
		mkdir( $inside_dir );
		file_put_contents( $inside_dir . '/x.txt', 'x' );

		if ( ! @symlink( $outside, $dir . '/link-outside.txt' ) ) {
			$this->markTestSkipped( 'symlink() not permitted' );
		}
		symlink( $dir . '/readme.txt', $dir . '/link-inside.txt' );
		symlink( $inside_dir, $dir . '/linked-dir' );

		foreach ( array( 'link-outside.txt', 'link-inside.txt', 'linked-dir/x.txt' ) as $path ) {
			$response = $this->request(
				'/integrity/fake-product/file',
				$this->agent_token,
				array( 'path' => $path )
			);
			$this->assertSame( 400, $response->get_status(), 'Path: ' . $path );
		}
	}

	// ------------------------------------------------------------------- auth

	public function test_integrity_routes_require_token() {
		$this->make_clean_product();

		foreach ( array( '/integrity', '/integrity/fake-product', '/integrity/fake-product/file' ) as $route ) {
			$response = $this->request( $route );
			$this->assertSame( 401, $response->get_status(), 'Route: ' . $route );
		}
	}

	public function test_unknown_slug_is_404() {
		$this->assertSame( 404, $this->request( '/integrity/nope', $this->agent_token )->get_status() );
		$this->assertSame(
			404,
			$this->request( '/integrity/nope/file', $this->agent_token, array( 'path' => 'x.php' ) )->get_status()
		);
	}
}
