<?php
/**
 * Built-in product settings collectors.
 *
 * Reads Themeisle product configuration straight out of wp_options with
 * core functions only — no product classes, constants or functions are
 * ever touched, so a fatal in a product can never break diagnostics and
 * a product does not need to be loaded (or even active) to be read.
 *
 * Every option is allowlisted from a source audit of each product. Where
 * an option mixes configuration with credentials, only the audited safe
 * keys are read; nothing else in the row is ever emitted. Values are
 * trimmed and capped on top of that, and the API's key-name redaction
 * runs last as a backstop.
 *
 * Parse-compatible with legacy PHP: array() syntax, no closures.
 */

// @codingStandardsIgnoreStart
class TI_Parrot_Product_Settings {
	// @codingStandardsIgnoreEnd

	// scalar values longer than this are cut — settings are short by nature
	const MAX_STRING_LENGTH = 200;

	const MAX_ARRAY_ITEMS = 50;

	const MAX_DEPTH = 4;

	/**
	 * slug => array(
	 *   'label'       => human label,
	 *   'theme_mods'  => stylesheet whose theme_mods key names to list (themes),
	 *   'options'     => option_name => true (whole row is safe)
	 *                                 | array of allowed keys / dot paths.
	 * )
	 *
	 * Keys absent from an allowlist are never read. Credentials, tokens,
	 * connected-account data, recipient emails, logs, custom CSS/HTML and
	 * import blobs are deliberately excluded — see docs in the PR.
	 */
	static function map() {
		return array(
			'neve' => array(
				'label'      => 'Neve',
				'theme_mods' => 'neve',
				'options'    => array(
					'neve_pro_addon_license_data'         => array( 'license', 'price_id', 'expires' ),
					'neve_pro_settings'                   => true,
					'neve_logger_flag'                    => true,
					'neve_imported_demo'                  => true,
					'neve_version'                        => true,
					'nv_pro_enable_local_fonts'           => true,
					'nv_pro_enable_mega_menu'             => true,
					'nv_pro_enable_emoji_removal'         => true,
					'nv_pro_enable_embedded_removal'      => true,
					'nv_pro_enable_lazy_content'          => true,
					'nv_pro_enable_featured_image_taxonomy' => true,
					'nv_pro_featured_image_taxonomies'    => true,
					'nv_pro_typekit_loading_method'       => true,
					'neve_access_restriction'             => true,
					'neve_admin_menus'                    => true,
					'neve_admin_bar_menu'                 => true,
					'ti_white_label_inputs'               => array( 'white_label', 'theme_name' ),
				),
			),
			'hestia' => array(
				'label'      => 'Hestia',
				'theme_mods' => 'hestia',
				'options'    => array(
					'hestia_pro_license_data' => array( 'license', 'plan', 'expires' ),
					'ti_white_label_inputs'   => array( 'white_label', 'theme_name' ),
				),
			),
			'optimole-wp' => array(
				'label'   => 'Optimole',
				'options' => array(
					// api_key and service_data (connected account) never read
					'optml_settings' => array(
						'cdn', 'admin_bar_item', 'lazyload', 'scale', 'network_optimization',
						'lazyload_placeholder', 'bg_replacer', 'video_lazyload', 'retina_images',
						'lazyload_type', 'limit_dimensions', 'limit_height', 'limit_width',
						'resize_smart', 'no_script', 'filters', 'compression_mode', 'cloud_sites',
						'watchers', 'quality', 'image_replacer', 'img_to_video', 'css_minify',
						'js_minify', 'avif', 'autoquality', 'native_lazyload', 'offload_media',
						'transfer_status', 'cloud_images', 'strip_metadata', 'skip_lazyload_images',
						'defined_image_sizes', 'offloading_status', 'rollback_status', 'best_format',
						'offload_limit_reached', 'offload_limit', 'show_offload_finish_notice',
						'cache_buster', 'cache_buster_assets', 'cache_buster_images',
					),
				),
			),
			'tweet-old-post' => array(
				'label'   => 'Revive Social',
				'options' => array(
					// services / active_accounts hold OAuth tokens; rop_logs
					// carries full API responses including Bluesky JWTs
					'rop_data'                        => array( 'general_settings', 'posts_buffer', 'posts_blocked', 'post_format' ),
					'rop_schedules_data'              => true,
					'rop_use_remote_cron'             => true,
					'cwp_top_global_schedule'         => true,
					'top_opt_post_formats'            => true,
					'top_opt_excluded_post'           => true,
					'tweet_old_post_logger_flag'      => true,
					'tweet_old_post_pro_license_data' => array( 'license', 'expires', 'created_at' ),
					'tweet_old_post_pro_license_plan' => true,
					'tweet_old_post_pro_failed_checks' => true,
				),
			),
			'feedzy-rss-feeds' => array(
				'label'   => 'Feedzy RSS Feeds',
				'options' => array(
					// 'logs' excluded: logs.email is the customer's address
					'feedzy-settings'            => array( 'general', 'custom_schedules', 'canonical', 'header' ),
					// third-party API keys excluded; only status/model/messages
					'feedzy-rss-feeds-settings'  => array(
						'openai_api_model', 'openai_licence', 'openai_last_check', 'openai_message',
						'openrouter_api_model', 'openrouter_licence', 'openrouter_last_check', 'openrouter_message',
						'aws_licence', 'aws_last_check', 'aws_message', 'amazon_partner_tag', 'amazon_host',
						'amazon_region', 'wordai_licence', 'wordai_last_check', 'wordai_message',
						'spinnerchief_licence', 'spinnerchief_last_check', 'spinnerchief_message',
					),
					'feedzy_managed_ai_enabled'  => true,
					'feedzy_rss_feeds_logger_flag' => true,
					'feedzy_rss_feeds_pro_license_data' => array( 'license', 'plan', 'price_id' ),
					'feedzy_rss_feeds_pro_license_plan' => true,
				),
			),
			'visualizer' => array(
				'label'   => 'Visualizer',
				'options' => array(
					'visualizer_global_settings'  => true,
					'visualizer_logger_flag'      => true,
					'visualizer_pro_license_data' => array( 'license', 'price_id' ),
				),
			),
			'woocommerce-product-addon' => array(
				'label'   => 'PPOM for WooCommerce',
				'options' => array(
					// REST secret, notification recipient list and the PDF/CSS
					// blobs are excluded
					'ppom-settings_panel' => array(
						'ppom_disable_bootstrap', 'ppom_enable_legacy_inputs_rendering', 'ppom_new_conditions',
						'ppom_legacy_price', 'ppom_permission_mfields', 'ppom_restricted_file_type',
						'ppom_allow_data_sharing', 'ppom_api_enable', 'ppom_wcfm_allow_vendors',
						'ppom_override_product_price', 'ppom_hide_option_price', 'ppom_hide_variable_product_price',
						'ppom_hide_product_price', 'ppom_taxable_option_price', 'ppom_taxable_fixed_price',
						'ppom_price_table_location', 'ppom_remove_unused_images_schedule', 'ppom_meta_overrides',
						'ppom_meta_priority', 'ppom_hide_image_cart', 'ppom_disable_crop_thumbnail',
						'ppom_hide_clear_fields', 'ppom_enable_client_validation', 'ppom_disable_meta_paypal_invoice',
						'ppom_disable_fontawesome', 'ppom_cart_enabled', 'ppom-cart-edit-popup', 'ppom_editcart_popup',
						'ppom-fieldspopup-enable', 'ppom-colapse-multiple-open', 'ppom-collapse-nextprev',
						'ppom_repeater_clone_mode', 'ppom_repeater_clone_position', 'ppom-bq-display-type',
						'ppom-eventcalendar-hide-baseqty', 'ppom-pdf-disable-hf', 'ppom_price_table_v2',
					),
					'ppom_cart_enabled'      => true,
					'ppom_cart_no_column'    => true,
					'ppom_pro_license_data'  => array( 'license', 'plan', 'is_expired' ),
					'ppom_pro_license_status' => true,
					'woocommerce_product_addon_logger_flag' => true,
				),
			),
			'wp-cloudflare-page-cache' => array(
				'label'   => 'Super Page Cache',
				'options' => array(
					// cf_apikey / cf_apitoken / cf_email / purge + preloader
					// secrets are never read
					'swcfpc_config' => array(
						'cf_cache_enabled', 'enable_cache_rule', 'cf_auth_mode', 'cf_zoneid',
						'cf_cache_settings_ruleset_id', 'cf_cache_settings_ruleset_rule_id', 'cf_page_rule_id',
						'cf_transform_rule_id', 'cf_transform_rule_name', 'cf_fallback_cache',
						'cf_fallback_cache_curl', 'cf_fallback_cache_ttl', 'cf_fallback_cache_auto_purge',
						'cf_fallback_cache_save_headers', 'cf_fallback_cache_http_response_code',
						'cf_fallback_cache_prevent_cache_urls_without_trailing_slash',
						'cf_fallback_cache_excluded_cookies', 'cf_fallback_cache_excluded_urls',
						'cf_excluded_url_params', 'cf_maxage', 'cf_browser_maxage', 'cf_old_bc_ttl',
						'stale_while_revalidate', 'stale_while_revalidate_ttl', 'cf_browser_caching_htaccess',
						'cf_cache_control_htaccess', 'cf_strip_cookies', 'cf_purge_only_html',
						'cf_disable_cache_purging_queue', 'cf_auto_purge', 'cf_auto_purge_all',
						'cf_auto_purge_on_comments', 'cf_auto_purge_on_upgrader_process_complete',
						'cf_preloader', 'cf_preloader_start_on_purge', 'cf_preloader_nav_menus',
						'cf_prefetch_urls_mode', 'cf_native_lazy_loading', 'cf_lazy_loading',
						'cf_lazy_load_behaviour', 'cf_lazy_load_video_iframe', 'cf_lazy_load_bg',
						'cf_lazy_load_excluded', 'minify_html', 'optimize_google_fonts', 'local_google_fonts',
						'unused_css', 'unused_css_excluded_paths', 'cf_defer_js', 'cf_delay_js', 'cache_tags',
						'enable_nonce_refresh', 'enable_assets_manager', 'database_optimization', 'log_enabled',
						'log_verbosity', 'log_max_file_size', 'keep_settings_on_deactivation',
						'cf_varnish_support', 'cf_varnish_hostname', 'cf_varnish_port', 'cf_varnish_auto_purge',
						'cf_seo_redirect', 'cf_purge_roles', 'cf_post_per_page', 'cf_heartbeat_admin',
						'cf_heartbeat_editor', 'cf_heartbeat_frontend', 'dns_prefetch_domains',
						'preconnect_domains', 'cf_remove_purge_option_toolbar', 'cf_remove_cache_buster',
						'cf_bypass_backend_page_rule', 'cf_bypass_wp_json_rest', 'cf_bypass_404',
						'cf_bypass_single_post', 'cf_bypass_pages', 'cf_bypass_front_page', 'cf_bypass_home',
						'cf_bypass_archives', 'cf_bypass_tags', 'cf_bypass_category', 'cf_bypass_feeds',
						'cf_bypass_search_pages', 'cf_bypass_author_pages', 'cf_bypass_amp', 'cf_bypass_ajax',
						'cf_bypass_query_var', 'cf_bypass_sitemap', 'cf_bypass_file_robots',
						'cf_bypass_woo_cart_page', 'cf_bypass_woo_checkout_page', 'cf_bypass_woo_account_page',
					),
					'wp_super_page_cache_pro_license_data' => array( 'license' ),
				),
			),
			'multiple-pages-generator-by-porthas' => array(
				'label'   => 'Multiple Pages Generator',
				'options' => array(
					'mpg_hook_name'            => true,
					'mpg_hook_priority'        => true,
					'mpg_cache_hook_name'      => true,
					'mpg_cache_hook_priority'  => true,
					'mpg_site_basepath'        => array( 'type', 'value' ),
					'mpg_branding_position'    => true,
					// result/intro templates excluded (long HTML)
					'mpg_search_settings'      => array(
						'mpg_ss_results_container', 'mpg_ss_excerpt_length', 'mpg_ss_results_count',
						'mpg_ss_is_case_sensitive', 'mpg_ss_featured_image_url',
					),
					'multi_pages_plugin_logger_flag' => true,
					'multi_pages_plugin_premium_license_data' => array( 'license', 'expires', 'plan' ),
				),
			),
			'otter-blocks' => array(
				'label'   => 'Otter Blocks',
				'options' => array(
					'themeisle_blocks_settings_css_module'        => true,
					'themeisle_blocks_settings_blocks_animation'  => true,
					'themeisle_blocks_settings_block_conditions'  => true,
					'themeisle_blocks_settings_patterns_library'  => true,
					'themeisle_blocks_settings_dynamic_content'   => true,
					'themeisle_blocks_settings_highlight_dynamic' => true,
					'themeisle_blocks_settings_optimize_animations_css' => true,
					'themeisle_blocks_settings_disable_review_schema' => true,
					'themeisle_blocks_settings_review_scale'      => true,
					'themeisle_blocks_settings_block_ai_toolbar_module' => true,
					'themeisle_blocks_settings_atomic_wind_blocks' => true,
					'themeisle_blocks_settings_default_block'     => true,
					'themeisle_blocks_settings_onboarding'        => true,
					'themeisle_disabled_blocks'                   => true,
					'otter_offload_fonts'                         => true,
					'otter_blocks_logger_flag'                    => true,
					'otter_pro_license_data'                      => array( 'license', 'expires', 'price_id' ),
					// per-form config: recipients, from/cc/bcc, integrations
					// and autoresponder bodies are excluded
					'themeisle_blocks_form_emails'                => array(
						'form', 'hasCaptcha', 'submissionsSaveLocation', 'emailNotification',
						'requiredFields', 'redirectLink',
					),
					// endpoint URLs and headers excluded — they carry secrets
					'themeisle_webhooks_options'                  => array( 'id', 'name', 'method' ),
					'themeisle_template_cloud_sources'            => array( 'name', 'url' ),
					'themeisle_otter_ai_usage'                    => array( 'usage_count' ),
				),
			),
			'templates-patterns-collection' => array(
				'label'   => 'Starter Sites',
				'options' => array(
					'templates_patterns_collection_license_data' => array( 'license', 'valid', 'expiration', 'tier' ),
				),
			),
			'wp-maintenance-mode' => array(
				'label'   => 'LightStart (WP Maintenance Mode)',
				'options' => array(
					// design.* bodies/CSS, bot messages, gdpr tails, the
					// contact recipient and the excluded-paths list are out
					'wpmm_settings' => array(
						'general.status', 'general.status_date', 'general.bypass_bots', 'general.backend_role',
						'general.frontend_role', 'general.meta_robots', 'general.redirection', 'general.notice',
						'general.admin_link', 'general.network_mode', 'design.page_id', 'design.bg_type',
						'design.bg_predefined', 'design.template_category', 'modules.countdown_status',
						'modules.countdown_start', 'modules.subscribe_status', 'modules.social_status',
						'modules.social_target', 'modules.contact_status', 'modules.ga_status',
						'modules.ga_anonymize_ip', 'bot.status', 'gdpr.status', 'gdpr.policy_page_link',
						'gdpr.policy_page_target',
					),
					'wpmm_new_look'                  => true,
					'wpmm_page_category'             => true,
					'wpmm_settings_network'          => true,
					'wp_maintenance_mode_logger_flag' => true,
				),
			),
		);
	}

	/**
	 * Neve Pro module toggles: one nv_pro_{slug}_status row per module.
	 *
	 * @return array Option names.
	 */
	static function neve_module_options() {
		$modules = array(
			'access_restriction', 'blog_pro', 'block_editor_booster', 'custom_layouts', 'custom_sidebars',
			'dashboard_customizer', 'easy_digital_downloads', 'elementor_booster', 'hfg_module',
			'lifterlms_booster', 'performance_features', 'post_type_enhancements', 'typekit_fonts',
			'white_label', 'woocommerce_booster',
		);
		$options = array();
		foreach ( $modules as $module ) {
			$options[] = 'nv_pro_' . $module . '_status';
		}

		return $options;
	}

	/**
	 * Slugs whose product stores something on this site.
	 *
	 * @return array Slug => label.
	 */
	static function available() {
		$available = array();
		foreach ( self::map() as $slug => $product ) {
			$settings = self::collect( $slug );
			if ( null !== $settings ) {
				$available[ $slug ] = $product['label'];
			}
		}

		return $available;
	}

	/**
	 * Collect the allowlisted settings of one product.
	 *
	 * @param string $slug Product slug.
	 *
	 * @return array|null Null when the slug is unknown or nothing is stored.
	 */
	static function collect( $slug ) {
		$map = self::map();
		if ( ! isset( $map[ $slug ] ) ) {
			return null;
		}
		$product = $map[ $slug ];
		$options = $product['options'];

		if ( 'neve' === $slug ) {
			foreach ( self::neve_module_options() as $name ) {
				$options[ $name ] = true;
			}
		}

		$collected = array();
		foreach ( $options as $name => $allowed ) {
			$value = get_option( $name, null );
			if ( null === $value || false === $value ) {
				continue;
			}
			$value = self::filter_value( $value, $allowed );
			if ( null === $value ) {
				continue;
			}
			$collected[ $name ] = self::trim_value( $value, 0 );
		}

		$result = array();
		if ( ! empty( $collected ) ) {
			$result['options'] = $collected;
		}

		if ( ! empty( $product['theme_mods'] ) ) {
			$mods = self::theme_mod_keys( $product['theme_mods'] );
			if ( ! empty( $mods ) ) {
				// key names only: the values are the full customizer state,
				// including owner-authored HTML and layout blobs
				$result['customizer_settings_set'] = $mods;
			}
		}

		return empty( $result ) ? null : $result;
	}

	/**
	 * Apply an option's allowlist. `true` passes the row through; an array
	 * keeps only those keys (dot paths supported), and is mapped over each
	 * record when the row is a list of records.
	 *
	 * @param mixed $value   Option value.
	 * @param mixed $allowed True or an array of allowed keys.
	 *
	 * @return mixed|null Null when nothing allowed survived.
	 */
	static function filter_value( $value, $allowed ) {
		if ( true === $allowed ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			// an allowlist was declared but the row is not an array — the
			// product changed its storage shape; emit nothing rather than
			// a value nobody audited
			return null;
		}
		if ( self::is_record_list( $value ) ) {
			$records = array();
			foreach ( $value as $record ) {
				$picked = self::pick_keys( $record, $allowed );
				if ( ! empty( $picked ) ) {
					$records[] = $picked;
				}
			}

			return empty( $records ) ? null : $records;
		}

		$picked = self::pick_keys( $value, $allowed );

		return empty( $picked ) ? null : $picked;
	}

	/**
	 * A numerically indexed array whose entries are all arrays.
	 *
	 * @param array $value Value.
	 *
	 * @return bool
	 */
	static function is_record_list( $value ) {
		if ( empty( $value ) || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array $value   Source array.
	 * @param array $allowed Keys, optionally dot paths.
	 *
	 * @return array
	 */
	static function pick_keys( $value, $allowed ) {
		$picked = array();
		foreach ( $allowed as $path ) {
			$segments = explode( '.', $path );
			$cursor   = $value;
			$found    = true;
			foreach ( $segments as $segment ) {
				if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
					$cursor = $cursor[ $segment ];
					continue;
				}
				$found = false;
				break;
			}
			if ( $found ) {
				$picked[ $path ] = $cursor;
			}
		}

		return $picked;
	}

	/**
	 * Cut long strings and big arrays; settings are small by nature, so
	 * anything large is either a blob or unbounded product data.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 *
	 * @return mixed
	 */
	static function trim_value( $value, $depth ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_DEPTH ) {
				return '[depth limit: ' . count( $value ) . ' entries]';
			}
			$trimmed = array();
			$count   = 0;
			foreach ( $value as $key => $entry ) {
				if ( $count >= self::MAX_ARRAY_ITEMS ) {
					$trimmed['[trimmed]'] = ( count( $value ) - self::MAX_ARRAY_ITEMS ) . ' more entries';
					break;
				}
				$trimmed[ $key ] = self::trim_value( $entry, $depth + 1 );
				$count++;
			}

			return $trimmed;
		}
		if ( is_string( $value ) && strlen( $value ) > self::MAX_STRING_LENGTH ) {
			return substr( $value, 0, self::MAX_STRING_LENGTH ) . '… [trimmed, ' . strlen( $value ) . ' chars]';
		}

		return $value;
	}

	/**
	 * Names of the theme mods a theme has stored — never their values.
	 *
	 * @param string $stylesheet Stylesheet slug.
	 *
	 * @return array Mod names.
	 */
	static function theme_mod_keys( $stylesheet ) {
		$mods = get_option( 'theme_mods_' . $stylesheet, array() );
		if ( ! is_array( $mods ) ) {
			return array();
		}
		$names = array();
		foreach ( array_keys( $mods ) as $name ) {
			if ( is_string( $name ) && 0 !== strpos( $name, '0' ) ) {
				$names[] = $name;
			}
		}
		sort( $names );

		return $names;
	}
}
