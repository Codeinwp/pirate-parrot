<?php
/**
 * Product file-integrity checks for the agent API.
 *
 * Detects installed ThemeIsle products (anything bundling themeisle-sdk),
 * compares their files against the release checksum manifest
 * (api.themeisle.com/checksum or wp.org plugin-checksums, chosen by the
 * product's "WordPress Available" header) and reports modified, missing and
 * added files. A companion route returns file contents in bounded base64
 * chunks so the support agent can inspect an edited file.
 *
 * This file must stay parse-compatible with legacy PHP (array() syntax,
 * no closures, no type hints) — it loads on unknown customer stacks and
 * has to fail safe rather than fatal. Constants are literal integers:
 * constant expressions are a parse error before PHP 5.6.
 */

// @codingStandardsIgnoreStart
class TI_Parrot_Integrity {
	// @codingStandardsIgnoreEnd

	const SDK_DIR = 'vendor/codeinwp/themeisle-sdk';

	const THEMEISLE_CHECKSUM_BASE = 'https://api.themeisle.com/checksum/';

	const WPORG_CHECKSUM_BASE = 'https://downloads.wordpress.org/plugin-checksums/';

	const HTTP_TIMEOUT = 10;

	// 4 MB manifest body cap (limit_response_size)
	const MAX_MANIFEST_BYTES = 4194304;

	// per-file hashing cap: 4 MB; larger files are reported as skipped
	const MAX_FILE_BYTES = 4194304;

	// total hashed-bytes budget per request: 64 MB
	const MAX_TOTAL_BYTES = 67108864;

	// wall-clock budget per request, seconds (HTTP + hashing)
	const TIME_BUDGET = 20;

	// directory-walk cap
	const MAX_FILES = 20000;

	const MAX_WALK_DEPTH = 32;

	// per-list cap in the report (modified/missing/added/skipped)
	const MAX_LIST_ITEMS = 200;

	// /file chunk sizes: default 128 KB, max 176 KB — the base64 of the max
	// chunk plus JSON escaping and envelope stays under the 256 KB response
	// cap enforced by TI_Parrot_Agent_API::respond()
	const DEFAULT_CHUNK_BYTES = 131072;

	const MAX_CHUNK_BYTES = 180224;

	// /file refuses files larger than 8 MB outright
	const FILE_MAX_BYTES = 8388608;

	const MAX_PATH_LENGTH = 512;

	/**
	 * Effective limits, overridable via filter (used by tests to shrink
	 * budgets; not meant as a public tuning surface).
	 *
	 * @return array
	 */
	public static function limits() {
		$time_budget = self::TIME_BUDGET;
		$max_execution = (int) ini_get( 'max_execution_time' );
		if ( $max_execution > 5 && ( $max_execution - 5 ) < $time_budget ) {
			$time_budget = $max_execution - 5;
		}
		$limits = array(
			'max_file_bytes'  => self::MAX_FILE_BYTES,
			'max_total_bytes' => self::MAX_TOTAL_BYTES,
			'time_budget'     => $time_budget,
			'max_files'       => self::MAX_FILES,
			'max_list_items'  => self::MAX_LIST_ITEMS,
			'max_chunk_bytes' => self::MAX_CHUNK_BYTES,
			'file_max_bytes'  => self::FILE_MAX_BYTES,
		);

		return apply_filters( 'pirate_parrot_integrity_limits', $limits );
	}

	/**
	 * Directory / file names never reported as "added" and never recursed.
	 *
	 * @return array
	 */
	public static function ignored_names() {
		return array( '.git', '.svn', '.hg', 'node_modules', '.DS_Store', 'Thumbs.db' );
	}

	/**
	 * All detected ThemeIsle products, each an array with keys:
	 * slug, type (plugin|theme), version, active, path (relative, safe to
	 * emit), wordpress_available and dir (absolute; internal, stripped
	 * before output).
	 *
	 * @return array List of product arrays.
	 */
	public static function detect() {
		$products = array();
		$seen     = array();

		self::detect_plugins( $products, $seen );
		self::detect_themes( $products, $seen );
		self::detect_registered( $products, $seen );

		$products = apply_filters( 'pirate_parrot_integrity_products', $products );

		$valid = array();
		if ( is_array( $products ) ) {
			foreach ( $products as $product ) {
				if ( ! is_array( $product ) || empty( $product['slug'] ) || empty( $product['type'] ) || empty( $product['dir'] ) ) {
					continue;
				}
				if ( ! is_dir( $product['dir'] ) ) {
					continue;
				}
				$valid[] = $product;
			}
		}

		usort( $valid, array( 'TI_Parrot_Integrity', 'compare_products' ) );

		return $valid;
	}

	/**
	 * usort callback: order by type then slug.
	 */
	public static function compare_products( $a, $b ) {
		if ( $a['type'] !== $b['type'] ) {
			return strcmp( $a['type'], $b['type'] );
		}

		return strcmp( $a['slug'], $b['slug'] );
	}

	/**
	 * Whether the directory bundles the ThemeIsle SDK.
	 */
	public static function has_sdk( $dir ) {
		return is_dir( $dir . '/' . self::SDK_DIR );
	}

	/**
	 * The "WordPress Available" file header of a product basefile.
	 */
	public static function is_wordpress_available( $basefile ) {
		if ( ! is_file( $basefile ) ) {
			return false;
		}
		$headers = get_file_data( $basefile, array( 'WordPress Available' => 'WordPress Available' ) );

		return isset( $headers['WordPress Available'] ) && 'yes' === strtolower( trim( $headers['WordPress Available'] ) );
	}

	/**
	 * Register one detected product, deduplicating by resolved directory.
	 */
	private static function add_product( &$products, &$seen, $product ) {
		$real = realpath( $product['dir'] );
		if ( false === $real ) {
			return;
		}
		if ( isset( $seen[ $real ] ) ) {
			return;
		}
		$seen[ $real ]  = true;
		$products[]     = $product;
	}

	private static function detect_plugins( &$products, &$seen ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( $plugins as $file => $data ) {
			$rel_dir = dirname( $file );
			if ( '.' === $rel_dir || '' === $rel_dir ) {
				// single-file plugins cannot bundle the SDK
				continue;
			}
			$dir = WP_PLUGIN_DIR . '/' . $rel_dir;
			if ( ! self::has_sdk( $dir ) ) {
				continue;
			}
			self::add_product(
				$products,
				$seen,
				array(
					'slug'                => basename( $rel_dir ),
					'type'                => 'plugin',
					'version'             => isset( $data['Version'] ) ? (string) $data['Version'] : '',
					'active'              => is_plugin_active( $file ),
					'path'                => $file,
					'wordpress_available' => self::is_wordpress_available( WP_PLUGIN_DIR . '/' . $file ),
					'dir'                 => $dir,
				)
			);
		}
	}

	private static function detect_themes( &$products, &$seen ) {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return;
		}
		$themes = wp_get_themes();
		foreach ( $themes as $stylesheet => $theme ) {
			$dir = $theme->get_stylesheet_directory();
			if ( ! self::has_sdk( $dir ) ) {
				continue;
			}
			// parent counts as active while its child runs
			$active = ( get_stylesheet() === $stylesheet || get_template() === $stylesheet );
			self::add_product(
				$products,
				$seen,
				array(
					'slug'                => basename( $dir ),
					'type'                => 'theme',
					'version'             => (string) $theme->get( 'Version' ),
					'active'              => $active,
					'path'                => $stylesheet . '/style.css',
					'wordpress_available' => self::is_wordpress_available( $dir . '/style.css' ),
					'dir'                 => $dir,
				)
			);
		}
	}

	/**
	 * Products registered with the SDK at runtime — authoritative, and the
	 * only way to reach mu-plugins or custom locations. These are active by
	 * definition (their code ran to register the filter).
	 */
	private static function detect_registered( &$products, &$seen ) {
		$basefiles = apply_filters( 'themeisle_sdk_products', array() );
		if ( ! is_array( $basefiles ) ) {
			return;
		}
		foreach ( $basefiles as $basefile ) {
			if ( ! is_string( $basefile ) || '' === $basefile || ! is_file( $basefile ) ) {
				continue;
			}
			$dir  = dirname( $basefile );
			$type = ( 'style.css' === basename( $basefile ) ) ? 'theme' : 'plugin';
			$data = get_file_data( $basefile, array( 'Version' => 'Version' ) );
			self::add_product(
				$products,
				$seen,
				array(
					'slug'                => basename( $dir ),
					'type'                => $type,
					'version'             => isset( $data['Version'] ) ? (string) $data['Version'] : '',
					'active'              => true,
					'path'                => basename( $dir ) . '/' . basename( $basefile ),
					'wordpress_available' => self::is_wordpress_available( $basefile ),
					'dir'                 => $dir,
				)
			);
		}
	}

	/**
	 * Find a detected product by slug (and optionally type, for the rare
	 * slug shared between a plugin and a theme).
	 *
	 * @return array|null
	 */
	public static function find( $slug, $type = '' ) {
		$slug = strtolower( (string) $slug );
		$type = (string) $type;
		foreach ( self::detect() as $product ) {
			if ( strtolower( $product['slug'] ) !== $slug ) {
				continue;
			}
			if ( '' !== $type && $product['type'] !== $type ) {
				continue;
			}

			return $product;
		}

		return null;
	}

	/**
	 * Public shape of a product entry: internal dir stripped, route added.
	 */
	public static function public_product( $product ) {
		$route = '/integrity/' . $product['slug'];

		return array(
			'slug'                => $product['slug'],
			'type'                => $product['type'],
			'version'             => $product['version'],
			'active'              => $product['active'],
			'path'                => $product['path'],
			'wordpress_available' => $product['wordpress_available'],
			'route'               => $route,
		);
	}

	/**
	 * /integrity payload.
	 */
	public static function index() {
		$out = array();
		foreach ( self::detect() as $product ) {
			$out[] = self::public_product( $product );
		}

		return array( 'products' => $out );
	}

	/**
	 * Reject anything that is not a plain relative path inside the product:
	 * no NUL/control chars, no backslashes, no absolute paths, no drive
	 * letters, no empty/./.. segments. Rejects rather than normalizes, and
	 * is applied to requested paths AND manifest paths (the manifest is
	 * remote data).
	 */
	public static function is_safe_relative_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > self::MAX_PATH_LENGTH ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return false;
		}
		if ( false !== strpos( $path, '\\' ) ) {
			return false;
		}
		if ( '/' === $path[0] ) {
			return false;
		}
		if ( preg_match( '/^[A-Za-z]:/', $path ) ) {
			return false;
		}
		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Manifest fetch. Source order is decided by the product's
	 * "WordPress Available" header: wp.org first for free plugins, our API
	 * first for everything else. Themes only have our API (wp.org publishes
	 * no theme checksums). A 404 falls through to the other source; network
	 * or decode failures do not.
	 *
	 * @return array {status, source, url, files, error}
	 */
	public static function fetch_manifest( $product ) {
		$result = array(
			'status' => 'no_manifest',
			'source' => '',
			'url'    => '',
			'files'  => array(),
			'error'  => '',
		);

		$version = (string) $product['version'];
		if ( '' === $version || ! preg_match( '/^[A-Za-z0-9._-]+$/', $version ) ) {
			return $result;
		}

		$base = apply_filters( 'pirate_parrot_checksum_api', self::THEMEISLE_CHECKSUM_BASE );
		$ours = array(
			'source' => 'themeisle',
			'url'    => $base . rawurlencode( $product['slug'] ) . '/' . rawurlencode( $version ) . '.json',
		);

		$candidates = array( $ours );
		if ( 'plugin' === $product['type'] ) {
			$wporg = array(
				'source' => 'wporg',
				'url'    => self::WPORG_CHECKSUM_BASE . rawurlencode( $product['slug'] ) . '/' . rawurlencode( $version ) . '.json',
			);
			if ( ! empty( $product['wordpress_available'] ) ) {
				$candidates = array( $wporg, $ours );
			} else {
				$candidates = array( $ours, $wporg );
			}
		}

		foreach ( $candidates as $candidate ) {
			$fetched = self::fetch_manifest_url( $candidate['url'] );
			if ( 'not_found' === $fetched['status'] ) {
				continue;
			}
			$result['source'] = $candidate['source'];
			$result['url']    = $candidate['url'];
			if ( 'ok' === $fetched['status'] ) {
				$result['status'] = 'ok';
				$result['files']  = $fetched['files'];
			} else {
				$result['status'] = 'error';
				$result['error']  = $fetched['error'];
			}

			return $result;
		}

		return $result;
	}

	/**
	 * One manifest URL fetch.
	 *
	 * @return array {status: ok|not_found|error, files, error}
	 */
	public static function fetch_manifest_url( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::HTTP_TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_MANIFEST_BYTES,
				'user-agent'          => 'PirateParrot',
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'error',
				'files'  => array(),
				'error'  => $response->get_error_code() . ': ' . $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array(
				'status' => 'not_found',
				'files'  => array(),
				'error'  => '',
			);
		}
		if ( 200 !== $code ) {
			return array(
				'status' => 'error',
				'files'  => array(),
				'error'  => 'http_' . $code,
			);
		}
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
			return array(
				'status' => 'error',
				'files'  => array(),
				'error'  => 'bad_manifest',
			);
		}

		return array(
			'status' => 'ok',
			'files'  => $decoded['files'],
			'error'  => '',
		);
	}

	/**
	 * Expected sha256 hashes of one manifest entry. wp.org emits an array
	 * of hashes when a version zip was rebuilt; accept both shapes.
	 *
	 * @return array Lowercased hex strings, possibly empty.
	 */
	public static function expected_hashes( $entry ) {
		$raw = array();
		if ( is_array( $entry ) && isset( $entry['sha256'] ) ) {
			$raw = is_array( $entry['sha256'] ) ? $entry['sha256'] : array( $entry['sha256'] );
		}
		$out = array();
		foreach ( $raw as $hash ) {
			if ( ! is_string( $hash ) ) {
				continue;
			}
			$hash = strtolower( $hash );
			if ( preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				$out[] = $hash;
			}
		}

		return $out;
	}

	/**
	 * RecursiveCallbackFilterIterator accept callback: prune symlinks,
	 * VCS/junk names.
	 */
	public static function filter_entry( $current, $key, $iterator ) {
		if ( $current->isLink() ) {
			return false;
		}

		return ! in_array( $current->getFilename(), self::ignored_names(), true );
	}

	/**
	 * List regular files under $root as relative-path => size.
	 *
	 * @param string $root     Resolved product directory.
	 * @param array  $limits   From limits().
	 * @param bool   $complete By-ref; false when the walk was cut short.
	 *
	 * @return array
	 */
	public static function walk( $root, $limits, &$complete ) {
		$files     = array();
		$root_norm = wp_normalize_path( $root );
		try {
			$dir_iterator = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
			$filtered     = new RecursiveCallbackFilterIterator( $dir_iterator, array( 'TI_Parrot_Integrity', 'filter_entry' ) );
			$iterator     = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::LEAVES_ONLY );
			$iterator->setMaxDepth( self::MAX_WALK_DEPTH );
			foreach ( $iterator as $info ) {
				if ( ! $info->isFile() ) {
					continue;
				}
				if ( count( $files ) >= $limits['max_files'] ) {
					$complete = false;
					break;
				}
				$rel = substr( wp_normalize_path( $info->getPathname() ), strlen( $root_norm ) + 1 );
				if ( ! is_string( $rel ) || '' === $rel ) {
					continue;
				}
				$files[ $rel ] = (int) $info->getSize();
			}
		} catch ( Exception $e ) {
			// unreadable subdirectory: partial listing only degrades "added"
			$complete = false;
		}

		return $files;
	}

	/**
	 * The integrity report for one product.
	 */
	public static function check( $product ) {
		$limits   = self::limits();
		$start    = microtime( true );
		$deadline = $start + $limits['time_budget'];

		$report = array(
			'slug'         => $product['slug'],
			'type'         => $product['type'],
			'version'      => $product['version'],
			'active'       => $product['active'],
			'path'         => $product['path'],
			'status'       => 'error',
			'source'       => '',
			'manifest_url' => '',
			'error'        => '',
			'complete'     => true,
			'counts'       => array(
				'manifest_files' => 0,
				'local_files'    => 0,
				'checked'        => 0,
				'ok'             => 0,
				'modified'       => 0,
				'missing'        => 0,
				'added'          => 0,
				'skipped'        => 0,
			),
			'modified'     => array(),
			'missing'      => array(),
			'added'        => array(),
			'skipped'      => array(),
			'truncated'    => array(
				'modified' => false,
				'missing'  => false,
				'added'    => false,
				'skipped'  => false,
			),
			'elapsed_ms'   => 0,
			'hashed_bytes' => 0,
			'checked_at'   => gmdate( 'c' ),
		);

		$manifest = self::fetch_manifest( $product );
		$report['source']       = $manifest['source'];
		$report['manifest_url'] = $manifest['url'];
		if ( 'ok' !== $manifest['status'] ) {
			$report['status'] = $manifest['status'];
			$report['error']  = $manifest['error'];
			$report['elapsed_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

			return $report;
		}

		$root = realpath( $product['dir'] );
		if ( false === $root ) {
			$report['status'] = 'error';
			$report['error']  = 'unresolvable_product_dir';

			return $report;
		}

		$complete = true;
		$local    = self::walk( $root, $limits, $complete );
		$report['counts']['local_files'] = count( $local );

		$manifest_files = $manifest['files'];
		ksort( $manifest_files );
		$report['counts']['manifest_files'] = count( $manifest_files );

		$modified = array();
		$missing  = array();
		$skipped  = array();
		$hashed   = 0;

		foreach ( $manifest_files as $rel => $entry ) {
			if ( ! self::is_safe_relative_path( $rel ) ) {
				$skipped[] = array(
					'path'   => is_string( $rel ) ? substr( $rel, 0, self::MAX_PATH_LENGTH ) : '',
					'reason' => 'bad_manifest_path',
				);
				continue;
			}
			// stat manifest entries directly: an aborted walk must degrade
			// "added" only, never fabricate "missing"
			unset( $local[ $rel ] );
			$abs = $root . '/' . $rel;
			if ( is_link( $abs ) ) {
				$skipped[] = array(
					'path'   => $rel,
					'reason' => 'symlink',
				);
				continue;
			}
			if ( ! is_file( $abs ) ) {
				$missing[] = $rel;
				continue;
			}
			$report['counts']['checked']++;
			$size = (int) filesize( $abs );
			if ( $size > $limits['max_file_bytes'] ) {
				$skipped[] = array(
					'path'   => $rel,
					'reason' => 'too_large',
					'size'   => $size,
				);
				continue;
			}
			if ( microtime( true ) > $deadline || ( $hashed + $size ) > $limits['max_total_bytes'] ) {
				$complete  = false;
				$skipped[] = array(
					'path'   => $rel,
					'reason' => 'budget',
				);
				continue;
			}
			if ( ! is_readable( $abs ) ) {
				$skipped[] = array(
					'path'   => $rel,
					'reason' => 'unreadable',
				);
				continue;
			}
			$expected = self::expected_hashes( $entry );
			if ( empty( $expected ) ) {
				$skipped[] = array(
					'path'   => $rel,
					'reason' => 'no_hash',
				);
				continue;
			}
			$actual  = hash_file( 'sha256', $abs );
			$hashed += $size;
			if ( false === $actual ) {
				$skipped[] = array(
					'path'   => $rel,
					'reason' => 'unreadable',
				);
				continue;
			}
			if ( in_array( $actual, $expected, true ) ) {
				$report['counts']['ok']++;
			} else {
				$modified[] = array(
					'path'     => $rel,
					'expected' => $expected[0],
					'actual'   => $actual,
					'size'     => $size,
				);
			}
		}

		$added = array_keys( $local );
		sort( $added );

		$report['counts']['modified'] = count( $modified );
		$report['counts']['missing']  = count( $missing );
		$report['counts']['added']    = count( $added );
		$report['counts']['skipped']  = count( $skipped );

		$cap = $limits['max_list_items'];
		if ( count( $modified ) > $cap ) {
			$modified = array_slice( $modified, 0, $cap );
			$report['truncated']['modified'] = true;
		}
		if ( count( $missing ) > $cap ) {
			$missing = array_slice( $missing, 0, $cap );
			$report['truncated']['missing'] = true;
		}
		if ( count( $added ) > $cap ) {
			$added = array_slice( $added, 0, $cap );
			$report['truncated']['added'] = true;
		}
		if ( count( $skipped ) > $cap ) {
			$skipped = array_slice( $skipped, 0, $cap );
			$report['truncated']['skipped'] = true;
		}

		$report['modified']     = $modified;
		$report['missing']      = $missing;
		$report['added']        = $added;
		$report['skipped']      = $skipped;
		$report['complete']     = $complete;
		$report['hashed_bytes'] = $hashed;
		$report['elapsed_ms']   = (int) round( ( microtime( true ) - $start ) * 1000 );
		$report['status']       = 'ok';
		if ( count( $modified ) > 0 || count( $missing ) > 0 || count( $added ) > 0 ) {
			$report['status'] = 'modified';
		}
		if ( ! $complete ) {
			$report['status'] = 'partial';
		}
		$report['source']       = $manifest['source'];
		$report['manifest_url'] = $manifest['url'];

		return $report;
	}

	/**
	 * Resolve a requested relative path to a real file inside the product
	 * directory. Every prefix is checked for symlinks BEFORE realpath so we
	 * never resolve through a link (also avoids open_basedir warnings).
	 * Error messages deliberately never echo the requested path.
	 *
	 * @return string|WP_Error Absolute path on success.
	 */
	public static function resolve_file( $product, $rel ) {
		if ( ! self::is_safe_relative_path( $rel ) ) {
			return new WP_Error( 'pp_bad_path', __( 'Invalid file path.', 'pirate-parrot' ), array( 'status' => 400 ) );
		}
		$root = realpath( $product['dir'] );
		if ( false === $root ) {
			return new WP_Error( 'pp_file_not_found', __( 'File not found.', 'pirate-parrot' ), array( 'status' => 404 ) );
		}
		$segments = explode( '/', $rel );
		$current  = $root;
		foreach ( $segments as $segment ) {
			$current .= '/' . $segment;
			if ( is_link( $current ) ) {
				return new WP_Error( 'pp_bad_path', __( 'Invalid file path.', 'pirate-parrot' ), array( 'status' => 400 ) );
			}
		}
		$real = realpath( $current );
		if ( false === $real || ! is_file( $real ) || 'file' !== filetype( $real ) ) {
			return new WP_Error( 'pp_file_not_found', __( 'File not found.', 'pirate-parrot' ), array( 'status' => 404 ) );
		}
		$root_norm = wp_normalize_path( $root );
		$real_norm = wp_normalize_path( $real );
		if ( 0 !== strpos( $real_norm, $root_norm . '/' ) ) {
			return new WP_Error( 'pp_bad_path', __( 'Invalid file path.', 'pirate-parrot' ), array( 'status' => 400 ) );
		}

		return $real;
	}

	/**
	 * One base64 chunk of a product file.
	 *
	 * @return array|WP_Error
	 */
	public static function read_chunk( $product, $rel, $offset, $length ) {
		$limits = self::limits();
		$real   = self::resolve_file( $product, $rel );
		if ( is_wp_error( $real ) ) {
			return $real;
		}
		$size = (int) filesize( $real );
		if ( $size > $limits['file_max_bytes'] ) {
			return new WP_Error( 'pp_file_too_large', __( 'File exceeds the retrievable size cap.', 'pirate-parrot' ), array( 'status' => 413 ) );
		}
		$offset = (int) $offset;
		if ( $offset < 0 || $offset > $size ) {
			return new WP_Error( 'pp_bad_range', __( 'Offset out of range.', 'pirate-parrot' ), array( 'status' => 400 ) );
		}
		$length = (int) $length;
		if ( $length <= 0 ) {
			$length = self::DEFAULT_CHUNK_BYTES;
		}
		if ( $length > $limits['max_chunk_bytes'] ) {
			$length = $limits['max_chunk_bytes'];
		}
		if ( ! is_readable( $real ) ) {
			return new WP_Error( 'pp_file_unreadable', __( 'File is not readable.', 'pirate-parrot' ), array( 'status' => 500 ) );
		}
		$data = '';
		if ( $offset < $size ) {
			$data = file_get_contents( $real, false, null, $offset, $length );
			if ( false === $data ) {
				return new WP_Error( 'pp_file_unreadable', __( 'File is not readable.', 'pirate-parrot' ), array( 'status' => 500 ) );
			}
		}
		$sha256 = hash_file( 'sha256', $real );

		return array(
			'slug'     => $product['slug'],
			'path'     => $rel,
			'size'     => $size,
			'sha256'   => false === $sha256 ? '' : $sha256,
			'mtime'    => gmdate( 'c', (int) filemtime( $real ) ),
			'offset'   => $offset,
			'length'   => strlen( $data ),
			'eof'      => ( $offset + strlen( $data ) ) >= $size,
			'encoding' => 'base64',
			'content'  => base64_encode( $data ),
		);
	}
}
