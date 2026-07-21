<?php
/**
 * Read-only REST API consumed by the ThemeIsle support agent.
 *
 * Every route requires the agent token minted alongside the parrot
 * account and shares its 5-day lifetime. Responses are sectioned so the
 * agent fetches only what it needs: /manifest for discovery, /site for
 * environment data, /products/{slug} for provider-registered product
 * details and /logs for the parrot log buffer.
 *
 * This file must stay parse-compatible with legacy PHP (array() syntax,
 * no closures, no type hints) — it loads on unknown customer stacks and
 * has to fail safe rather than fatal.
 */

// @codingStandardsIgnoreStart
class TI_Parrot_Agent_API {
	// @codingStandardsIgnoreEnd

	const REST_NAMESPACE = 'pirate-parrot/v1';

	const SCHEMA_VERSION = '1.0';

	// hard cap for a single section payload, in bytes
	const MAX_SECTION_BYTES = 262144;

	const RATE_LIMIT = 30;

	const RATE_WINDOW = 3600;

	const AUDIT_LENGTH = 20;

	private $parrot;

	function __construct( $parrot ) {
		$this->parrot = $parrot;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	function register_routes() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			// WordPress < 4.7 — the agent endpoints are simply unavailable.
			return;
		}

		$routes = array(
			'/manifest'                       => 'get_manifest',
			'/site'                           => 'get_site',
			'/products'                       => 'get_products_index',
			'/products/(?P<slug>[a-z0-9_-]+)' => 'get_product',
			'/logs'                           => 'get_logs',
		);
		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'check_auth' ),
				)
			);
		}
	}

	function check_auth( $request ) {
		if ( $this->is_rate_limited() ) {
			$this->audit( $request, 'rate_limited' );

			return new WP_Error( 'pp_rate_limited', __( 'Too many requests.', 'pirate-parrot' ), array( 'status' => 429 ) );
		}

		$token  = $this->get_request_token( $request );
		$stored = $this->parrot->get_agent_token_hash();
		if ( '' === $token || '' === $stored || ! $this->parrot->is_grant_active() || ! hash_equals( $stored, hash( 'sha256', $token ) ) ) {
			$this->audit( $request, 'denied' );

			return new WP_Error( 'pp_invalid_token', __( 'Missing, invalid or expired agent token.', 'pirate-parrot' ), array( 'status' => 401 ) );
		}

		$this->audit( $request, 'ok' );

		return true;
	}

	function get_request_token( $request ) {
		$header = $request->get_header( 'authorization' );
		if ( $header && preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $matches ) ) {
			return $matches[1];
		}
		// fallback for servers that strip the Authorization header
		$header = $request->get_header( 'x-parrot-token' );

		return $header ? trim( $header ) : '';
	}

	function is_rate_limited() {
		$count = (int) get_transient( 'ti_parrot_agent_rate' );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( 'ti_parrot_agent_rate', $count + 1, self::RATE_WINDOW );

		return false;
	}

	function audit( $request, $result ) {
		$entries = get_option( 'ti_parrot_agent_audit', array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}
		$entries[] = array(
			'time'   => time(),
			'route'  => $request->get_route(),
			'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '',
			'result' => $result,
		);
		$entries   = array_slice( $entries, 0 - self::AUDIT_LENGTH );
		update_option( 'ti_parrot_agent_audit', $entries, false );
	}

	function get_manifest( $request ) {
		$sections = array(
			array(
				'slug'  => 'site',
				'label' => __( 'Site information', 'pirate-parrot' ),
				'route' => '/site',
			),
			array(
				'slug'    => 'logs',
				'label'   => __( 'Plugin logs', 'pirate-parrot' ),
				'route'   => '/logs',
				'plugins' => $this->parrot->get_registered_log_plugins(),
			),
		);
		foreach ( $this->get_providers() as $slug => $provider ) {
			$sections[] = array(
				'slug'  => $slug,
				'label' => $provider['label'],
				'route' => '/products/' . $slug,
			);
		}

		return $this->respond(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'plugin_version' => $this->parrot->get_version(),
				'expires'        => gmdate( 'c', $this->parrot->get_expiration_timestamp() ),
				'scopes'         => $this->parrot->get_agent_scopes(),
				'sections'       => $sections,
			)
		);
	}

	function get_site( $request ) {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active_plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) ) {
				continue;
			}
			$active_plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
			);
		}
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return $this->respond(
			array(
				'wp_version'          => get_bloginfo( 'version' ),
				'php_version'         => phpversion(),
				'db_version'          => $wpdb->db_version(),
				'locale'              => get_locale(),
				'home_url'            => home_url(),
				'is_multisite'        => is_multisite(),
				'is_ssl'              => is_ssl(),
				'timezone'            => get_option( 'timezone_string' ),
				'gmt_offset'          => get_option( 'gmt_offset' ),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'memory_limit'        => (string) ini_get( 'memory_limit' ),
				'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
				'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'active_theme'        => array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'parent'  => $parent ? $parent->get( 'Name' ) : '',
				),
				'active_plugins'      => $active_plugins,
			)
		);
	}

	/**
	 * Product providers registered via the `pirate_parrot_register_diagnostics`
	 * filter: `slug => array( 'label' => ..., 'callback' => callable )`.
	 * Callbacks only run when their own /products/{slug} route is hit.
	 */
	function get_providers() {
		$providers = apply_filters( 'pirate_parrot_register_diagnostics', array() );
		$valid     = array();
		if ( ! is_array( $providers ) ) {
			return $valid;
		}
		foreach ( $providers as $slug => $provider ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || ! is_array( $provider ) || empty( $provider['callback'] ) ) {
				continue;
			}
			$valid[ $slug ] = array(
				'label'    => isset( $provider['label'] ) ? (string) $provider['label'] : $slug,
				'callback' => $provider['callback'],
			);
		}

		return $valid;
	}

	function get_products_index( $request ) {
		$index = array();
		foreach ( $this->get_providers() as $slug => $provider ) {
			$index[] = array(
				'slug'  => $slug,
				'label' => $provider['label'],
				'route' => '/products/' . $slug,
			);
		}

		return $this->respond( array( 'products' => $index ) );
	}

	function get_product( $request ) {
		$slug      = sanitize_key( $request['slug'] );
		$providers = $this->get_providers();
		if ( ! isset( $providers[ $slug ] ) ) {
			return new WP_Error( 'pp_unknown_section', __( 'No diagnostics provider registered under this slug.', 'pirate-parrot' ), array( 'status' => 404 ) );
		}
		if ( ! is_callable( $providers[ $slug ]['callback'] ) ) {
			return new WP_Error( 'pp_provider_error', __( 'The diagnostics provider for this product is broken.', 'pirate-parrot' ), array( 'status' => 500 ) );
		}
		try {
			$data = call_user_func( $providers[ $slug ]['callback'] );
		} catch ( Exception $e ) {
			$data = new WP_Error( 'pp_provider_exception', $e->getMessage() );
		}
		if ( is_wp_error( $data ) ) {
			return new WP_Error( 'pp_provider_error', __( 'The diagnostics provider for this product failed.', 'pirate-parrot' ), array( 'status' => 500 ) );
		}

		return $this->respond(
			array(
				'slug' => $slug,
				'data' => $data,
			)
		);
	}

	function get_logs( $request ) {
		$registered = $this->parrot->get_registered_log_plugins();
		$plugin     = $request->get_param( 'plugin' );
		$plugin     = is_string( $plugin ) ? trim( $plugin ) : '';
		if ( '' === $plugin && ! empty( $registered ) ) {
			$plugin = $registered[0];
		}
		if ( '' === $plugin || ! in_array( $plugin, $registered, true ) ) {
			return new WP_Error( 'pp_unknown_plugin', __( 'Unknown log source.', 'pirate-parrot' ), array( 'status' => 404 ) );
		}
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page < 1 ) {
			$per_page = 50;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		$page = (int) $request->get_param( 'page' );
		if ( $page < 1 ) {
			$page = 1;
		}
		// newest first
		$logs  = array_reverse( $this->parrot->get_plugin_logs( $plugin ) );
		$total = count( $logs );

		return $this->respond(
			array(
				'plugin'   => $plugin,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'entries'  => array_slice( $logs, ( $page - 1 ) * $per_page, $per_page ),
			)
		);
	}

	function respond( $data ) {
		$data    = $this->redact( $data );
		$encoded = wp_json_encode( $data );
		if ( false !== $encoded && strlen( $encoded ) > self::MAX_SECTION_BYTES ) {
			return new WP_Error( 'pp_section_too_large', __( 'Section payload exceeds the size cap.', 'pirate-parrot' ), array( 'status' => 500 ) );
		}
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Backstop only — providers must not include secrets in the first place.
	 * Redacts by key name so a leaked license key or password never leaves
	 * the site even if a provider misbehaves.
	 */
	function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$redacted = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/(key|secret|password|credential|token|auth(?!or))/i', $key ) ) {
				$redacted[ $key ] = '[redacted]';
			} else {
				$redacted[ $key ] = $this->redact( $value );
			}
		}

		return $redacted;
	}
}
