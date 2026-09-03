<?php

/**
 * Tests for the child-theme section of the agent API.
 *
 * All callbacks are static methods: this file is parse-checked down to
 * PHP 5.4 by CI, so no closures.
 *
 * @package     Pirate Parrot
 * @subpackage  Tests
 */
class Test_Child_Theme extends WP_UnitTestCase {

	/**
	 * @var TI_Parrot
	 */
	private $parrot;

	/**
	 * @var string
	 */
	private $agent_token;

	/**
	 * Temp root holding the fixture parent/child theme directories.
	 *
	 * @var string
	 */
	public static $root = '';

	/**
	 * Child-theme info injected through pirate_parrot_child_theme.
	 *
	 * @var array|null
	 */
	public static $child = null;

	public function set_up() {
		parent::set_up();
		self::$child = null;
		self::$root  = sys_get_temp_dir() . '/pp-child-' . uniqid();
		mkdir( self::$root, 0777, true );

		$this->parrot = new TI_Parrot();
		$this->parrot->generate_new_parrot();
		$this->agent_token = $this->parrot->get_agent_token();

		add_filter( 'pirate_parrot_child_theme', array( 'Test_Child_Theme', 'inject_child' ) );
	}

	public function tear_down() {
		self::rmrf( self::$root );
		parent::tear_down();
	}

	// ---------------------------------------------------------------- helpers

	public static function inject_child( $detected ) {
		return self::$child;
	}

	private static function rmrf( $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );

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
	 * Build a parent + child fixture pair and inject the child info.
	 *
	 * @return array The injected info array.
	 */
	private function make_child_theme( $child_files, $parent_files = array() ) {
		$parent_dir = self::$root . '/hestia-pro';
		$child_dir  = self::$root . '/hestia-pro-child';
		foreach ( array_merge( array( 'style.css' => "/* parent */\n" ), $parent_files ) as $rel => $contents ) {
			self::write_file( $parent_dir . '/' . $rel, $contents );
		}
		foreach ( $child_files as $rel => $contents ) {
			self::write_file( $child_dir . '/' . $rel, $contents );
		}
		self::$child = array(
			'slug'           => 'hestia-pro-child',
			'name'           => 'Hestia Pro Child',
			'version'        => '0.1',
			'dir'            => $child_dir,
			'parent_slug'    => 'hestia-pro',
			'parent_name'    => 'Hestia Pro',
			'parent_version' => '3.3.5',
			'parent_dir'     => $parent_dir,
		);

		return self::$child;
	}

	private static function write_file( $abs, $contents ) {
		$parent = dirname( $abs );
		if ( ! is_dir( $parent ) ) {
			mkdir( $parent, 0777, true );
		}
		file_put_contents( $abs, $contents );
	}

	// ------------------------------------------------------------------ tests

	public function test_manifest_lists_child_theme_section_when_detected() {
		$this->make_child_theme( array( 'style.css' => "/* child */\n" ) );

		$response = $this->request( '/manifest', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );

		$found = null;
		foreach ( $response->get_data()['sections'] as $section ) {
			if ( 'child-theme' === $section['slug'] ) {
				$found = $section;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( '/child-theme', $found['route'] );
		$this->assertSame( 'hestia-pro-child', $found['theme'] );
		$this->assertSame( 'hestia-pro', $found['parent'] );
	}

	public function test_manifest_omits_child_theme_section_when_not_detected() {
		$response = $this->request( '/manifest', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );

		foreach ( $response->get_data()['sections'] as $section ) {
			$this->assertNotSame( 'child-theme', $section['slug'] );
		}
	}

	public function test_report_lists_files_and_flags_parent_shadows() {
		$this->make_child_theme(
			array(
				'style.css'                => "/* child */\n",
				'functions.php'            => "<?php // child functions\n",
				'template-parts/footer.php' => "<?php // override\n",
				'assets/extra.css'         => "body {}\n",
			),
			array(
				'functions.php'             => "<?php // parent functions\n",
				'template-parts/footer.php' => "<?php // parent\n",
			)
		);

		$response = $this->request( '/child-theme', $this->agent_token );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'hestia-pro-child', $data['slug'] );
		$this->assertSame( 'hestia-pro', $data['parent_slug'] );
		$this->assertSame( 4, $data['counts']['files'] );
		$this->assertFalse( $data['truncated'] );
		$this->assertTrue( $data['complete'] );

		$by_path = array();
		foreach ( $data['files'] as $file ) {
			$by_path[ $file['path'] ] = $file;
		}
		$this->assertTrue( $by_path['functions.php']['shadows_parent'] );
		$this->assertTrue( $by_path['template-parts/footer.php']['shadows_parent'] );
		// every theme has style.css, so the child's copy always shadows
		$this->assertTrue( $by_path['style.css']['shadows_parent'] );
		$this->assertFalse( $by_path['assets/extra.css']['shadows_parent'] );
		$this->assertSame( strlen( "body {}\n" ), $by_path['assets/extra.css']['size'] );
	}

	public function test_report_is_404_when_no_child_theme() {
		$response = $this->request( '/child-theme', $this->agent_token );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pp_no_child_theme', $response->as_error()->get_error_code() );
	}

	public function test_file_route_returns_chunked_content() {
		$contents = "<?php\nadd_filter( 'is_active_sidebar', '__return_false' );\n";
		$this->make_child_theme( array( 'functions.php' => $contents ) );

		$response = $this->request(
			'/child-theme/file',
			$this->agent_token,
			array( 'path' => 'functions.php' )
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'hestia-pro-child', $data['slug'] );
		$this->assertSame( 'functions.php', $data['path'] );
		$this->assertSame( 'base64', $data['encoding'] );
		$this->assertSame( $contents, base64_decode( $data['content'] ) );
		$this->assertTrue( $data['eof'] );
	}

	public function test_file_route_rejects_traversal_and_missing_paths() {
		$this->make_child_theme( array( 'functions.php' => "<?php\n" ), array( 'secret.php' => "<?php // parent only\n" ) );

		$traversal = $this->request( '/child-theme/file', $this->agent_token, array( 'path' => '../hestia-pro/secret.php' ) );
		$this->assertSame( 400, $traversal->get_status() );

		$absolute = $this->request( '/child-theme/file', $this->agent_token, array( 'path' => '/etc/passwd' ) );
		$this->assertSame( 400, $absolute->get_status() );

		$missing = $this->request( '/child-theme/file', $this->agent_token, array( 'path' => 'nope.php' ) );
		$this->assertSame( 404, $missing->get_status() );
	}

	public function test_routes_require_token() {
		$this->make_child_theme( array( 'style.css' => "/* child */\n" ) );

		$this->assertSame( 401, $this->request( '/child-theme' )->get_status() );
		$this->assertSame( 401, $this->request( '/child-theme/file', null, array( 'path' => 'style.css' ) )->get_status() );
	}

	public function test_detection_rejects_incomplete_or_vanished_injections() {
		self::$child = array( 'slug' => 'x' );
		$this->assertSame( 404, $this->request( '/child-theme', $this->agent_token )->get_status() );

		self::$child = array(
			'slug' => 'gone',
			'dir'  => self::$root . '/does-not-exist',
		);
		$this->assertSame( 404, $this->request( '/child-theme', $this->agent_token )->get_status() );
	}
}
