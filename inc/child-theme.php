<?php
/**
 * Child-theme inspection for the agent API.
 *
 * When the active theme is a CHILD of a ThemeIsle theme, the child's code
 * is the customer's own — there is no checksum manifest to compare it
 * against, but support still needs to see it: a filter in the child's
 * functions.php or an overriding template is a frequent root cause the
 * read-only diagnostics could otherwise never reach. This section lists
 * the child theme's files (flagging which ones shadow a parent file) and
 * serves their contents through the same bounded chunk reader the
 * integrity section uses.
 *
 * This file must stay parse-compatible with legacy PHP (array() syntax,
 * no closures, no type hints) — it loads on unknown customer stacks and
 * has to fail safe rather than fatal.
 */

// @codingStandardsIgnoreStart
class TI_Parrot_Child_Theme {
	// @codingStandardsIgnoreEnd

	/**
	 * The active child theme of a ThemeIsle parent, or null when the
	 * active theme is not a child or its parent is not one of ours.
	 *
	 * Keys: slug, name, version, dir (absolute; internal), parent_slug,
	 * parent_name, parent_version, parent_dir (absolute; internal).
	 * The `pirate_parrot_child_theme` filter is a test seam and lets a
	 * site opt out by returning null.
	 *
	 * @return array|null
	 */
	public static function detect() {
		$detected = null;

		if ( function_exists( 'get_stylesheet' ) && get_stylesheet() !== get_template() ) {
			$child_dir  = get_stylesheet_directory();
			$parent_dir = get_template_directory();
			if ( is_dir( $child_dir ) && self::is_themeisle_parent( $parent_dir ) ) {
				$child  = wp_get_theme( get_stylesheet() );
				$parent = wp_get_theme( get_template() );

				$detected = array(
					'slug'           => basename( $child_dir ),
					'name'           => (string) $child->get( 'Name' ),
					'version'        => (string) $child->get( 'Version' ),
					'dir'            => $child_dir,
					'parent_slug'    => basename( $parent_dir ),
					'parent_name'    => (string) $parent->get( 'Name' ),
					'parent_version' => (string) $parent->get( 'Version' ),
					'parent_dir'     => $parent_dir,
				);
			}
		}

		$detected = apply_filters( 'pirate_parrot_child_theme', $detected );

		if ( ! is_array( $detected ) || empty( $detected['slug'] ) || empty( $detected['dir'] ) || ! is_dir( $detected['dir'] ) ) {
			return null;
		}

		return $detected;
	}

	/**
	 * Whether the parent directory belongs to a ThemeIsle theme: it
	 * bundles the SDK, or the integrity detection (which also covers
	 * runtime-registered products) already lists it.
	 */
	public static function is_themeisle_parent( $parent_dir ) {
		if ( ! is_dir( $parent_dir ) ) {
			return false;
		}
		if ( TI_Parrot_Integrity::has_sdk( $parent_dir ) ) {
			return true;
		}
		$real = realpath( $parent_dir );
		if ( false === $real ) {
			return false;
		}
		foreach ( TI_Parrot_Integrity::detect() as $product ) {
			if ( 'theme' !== $product['type'] ) {
				continue;
			}
			if ( realpath( $product['dir'] ) === $real ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * /child-theme payload: the child/parent identity plus the child's
	 * file list. `shadows_parent` marks files whose relative path also
	 * exists in the parent — for templates that means the child's copy is
	 * the one WordPress uses (functions.php is the known exception: both
	 * parent and child load).
	 *
	 * @param array $info From detect().
	 *
	 * @return array
	 */
	public static function report( $info ) {
		$limits   = TI_Parrot_Integrity::limits();
		$complete = true;
		$files    = TI_Parrot_Integrity::walk( $info['dir'], $limits, $complete );
		ksort( $files );

		$list      = array();
		$truncated = false;
		foreach ( $files as $rel => $size ) {
			if ( count( $list ) >= $limits['max_list_items'] ) {
				$truncated = true;
				break;
			}
			$abs    = $info['dir'] . '/' . $rel;
			$mtime  = @filemtime( $abs );
			$list[] = array(
				'path'           => $rel,
				'size'           => (int) $size,
				'mtime'          => false === $mtime ? '' : gmdate( 'c', (int) $mtime ),
				'shadows_parent' => is_file( $info['parent_dir'] . '/' . $rel ),
			);
		}

		return array(
			'slug'           => $info['slug'],
			'name'           => $info['name'],
			'version'        => $info['version'],
			'parent_slug'    => $info['parent_slug'],
			'parent_name'    => $info['parent_name'],
			'parent_version' => $info['parent_version'],
			'counts'         => array(
				'files' => count( $files ),
			),
			'files'          => $list,
			'truncated'      => $truncated,
			'complete'       => $complete,
			'checked_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * One base64 chunk of a child-theme file, via the integrity chunk
	 * reader (same path safety, symlink refusal, and size caps).
	 *
	 * @param array  $info   From detect().
	 * @param string $rel    Relative path inside the child theme.
	 * @param int    $offset Byte offset.
	 * @param int    $length Requested chunk length.
	 *
	 * @return array|WP_Error
	 */
	public static function read_chunk( $info, $rel, $offset, $length ) {
		$product = array(
			'slug' => $info['slug'],
			'dir'  => $info['dir'],
		);

		return TI_Parrot_Integrity::read_chunk( $product, $rel, $offset, $length );
	}
}
