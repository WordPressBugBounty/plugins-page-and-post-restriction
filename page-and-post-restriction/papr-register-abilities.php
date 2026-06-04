<?php
/**
 * Register Abilities handler file for Page and Post Restriction.
 *
 * Registers with the WordPress Abilities API (wp_register_ability), following the
 * same pattern as miniOrange SAML SSO when the API is available and enabled.
 *
 * @package Page_And_Post_Restriction
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to register abilities.
 */
class PAPR_Register_Abilities {

	const OPTION_ENABLE_ABILITIES_API = 'papr_enable_abilities_api';

	const CATEGORY_SLUG = 'mo-papr';

	/**
	 * Whether WordPress exposes the Abilities API functions we need.
	 *
	 * @return bool
	 */
	public static function papr_abilities_api_available() {
		if ( version_compare( get_bloginfo( 'version' ), '6.8', '<' ) ) {
			return false;
		}
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Runs on `init`: attach Abilities API registration callbacks when supported and enabled.
	 *
	 * WordPress requires `wp_register_ability_category()` only during
	 * `wp_abilities_api_categories_init` and `wp_register_ability()` only during
	 * `wp_abilities_api_init`. Registering on plain `init` leaves no abilities in
	 * the registry, so MCP cannot discover or execute them.
	 *
	 * @return void
	 */
	public static function schedule_abilities_api_hooks() {
		if ( ! self::papr_abilities_api_available() ) {
			return;
		}

		if ( 'true' !== get_option( self::OPTION_ENABLE_ABILITIES_API, 'false' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_ability_category' ), 5 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 5 );
	}

	/**
	 * Register the plugin ability category (must run on wp_abilities_api_categories_init).
	 *
	 * @return void
	 */
	public static function register_ability_category() {
		if ( ! self::papr_abilities_api_available() ) {
			return;
		}
		if ( 'true' !== get_option( self::OPTION_ENABLE_ABILITIES_API, 'false' ) ) {
			return;
		}
		self::papr_register_ability_category();
	}

	/**
	 * Register all plugin abilities (must run on wp_abilities_api_init).
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! self::papr_abilities_api_available() ) {
			return;
		}
		if ( 'true' !== get_option( self::OPTION_ENABLE_ABILITIES_API, 'false' ) ) {
			return;
		}
		self::papr_register_all_abilities();
	}

	/**
	 * Register the Page and Post Restriction ability category.
	 *
	 * @return void
	 */
	public static function papr_register_ability_category() {
		wp_register_ability_category(
			self::CATEGORY_SLUG,
			array(
				'label'       => __( 'Page and Post Restriction', 'page-and-post-restriction' ),
				'description' => __( 'miniOrange Page and Post Restriction configuration and status abilities.', 'page-and-post-restriction' ),
			)
		);
	}

	/**
	 * Register all abilities (status, guidance, or applying settings where noted).
	 *
	 * @return void
	 */
	public static function papr_register_all_abilities() {

		self::papr_register_all_page_private_status();
		self::papr_register_all_page_private();
		self::papr_register_all_post_private();
		self::papr_register_restrict_behind_login();
		self::papr_register_set_page_allowed_roles();
		self::papr_register_set_assign_parent_config_child_pages();
		self::papr_register_create_role_from_cloning();
		self::papr_register_restrict_by_page_name();
	
	}

	/**
	 * Register papr/all-page-private.
	 *
	 * Running this ability enables global “logged-in users only” for all pages
	 * (same as the “Make all Pages Private” admin toggle).
	 *
	 * @return void
	 */
	public static function papr_register_all_page_private() {
		wp_register_ability(
			'papr/all-page-private',
			array(
				'label'               => __( 'All pages private', 'page-and-post-restriction' ),
				'description'         => __( 'Makes all WordPress pages private so only authorized users can see them.', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_empty_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'             => array( 'type' => 'boolean' ),
						'message'             => array( 'type' => 'string' ),
						'what_it_does'        => array( 'type' => 'string' ),
						'admin_action'        => array( 'type' => 'string' ),
						'all_pages_private'    => array( 'type' => 'boolean' ),
						'logged_in_only_pages' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success', 'message', 'what_it_does', 'admin_action', 'all_pages_private', 'logged_in_only_pages' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function () {
					// Match page-restriction-save.php papr_access_for_only_loggedin branch when the toggle is on.
					update_option( 'papr_login_unrestricted_pages', array() );
					update_option( 'papr_access_for_only_loggedin', 1 );

					$login_only = get_option( 'papr_access_for_only_loggedin' );
					$enabled    = ( 1 === (int) $login_only || '1' === (string) $login_only );

					return array(
						'success'              => true,
						'message'              => __( 'All pages are now restricted to logged-in users only.', 'page-and-post-restriction' ),
						'what_it_does'         => __( 'Makes all your WordPress pages private. Only authorized users can see them.', 'page-and-post-restriction' ),
						'admin_action'         => __( 'Log out or check with a different user role to make sure it worked.', 'page-and-post-restriction' ),
						'all_pages_private'    => $enabled,
						'logged_in_only_pages' => is_scalar( $login_only ) ? (string) $login_only : '',
					);
				},
				'meta'                => self::papr_standard_action_meta(),
			)
		);
	}

	/**
	 * Register papr/restrict-behind-login.
	 *
	 * Per-item “logged-in only” for a single Page or Post (Make Page/Post Private),
	 * matching page-restriction-save.php mo_page_login_* / mo_post_login_* handling.
	 *
	 * @return void
	 */
	public static function papr_register_restrict_behind_login() {
		wp_register_ability(
			'papr/restrict-behind-login',
			array(
				'label'               => __( 'Restrict behind login', 'page-and-post-restriction' ),
				'description'         => __( 'Restrict a WordPress page or blog post so only logged-in users can view it (same as Make Page/Post Private). Pass require_login false to allow guests again. One item per call; page_id is the numeric post ID for either a page or a post.', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_restrict_behind_login_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'what_it_does'   => array( 'type' => 'string' ),
						'admin_action'   => array( 'type' => 'string' ),
						'page_id'        => array( 'type' => 'integer' ),
						'post_type'      => array( 'type' => 'string' ),
						'require_login' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'success', 'message', 'what_it_does', 'admin_action', 'page_id', 'post_type', 'require_login' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function ( $input = array() ) {
					$post_id = isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
					if ( $post_id < 1 ) {
						return new \WP_Error(
							'papr_invalid_post_id',
							__( 'Provide a valid page_id (positive integer): the WordPress post ID of a page or post.', 'page-and-post-restriction' )
						);
					}
					$post = get_post( $post_id );
					if ( ! $post instanceof \WP_Post ) {
						return new \WP_Error(
							'papr_invalid_post',
							__( 'No content found for that ID.', 'page-and-post-restriction' )
						);
					}
					if ( 'page' !== $post->post_type && 'post' !== $post->post_type ) {
						return new \WP_Error(
							'papr_invalid_content_type',
							__( 'Restrict behind login supports only the page and post types.', 'page-and-post-restriction' )
						);
					}

					$require_login = true;
					if ( array_key_exists( 'require_login', $input ) ) {
						$require_login = (bool) $input['require_login'];
					}

					if ( 'page' === $post->post_type ) {
						$allowed_redirect = self::papr_normalize_array_option( 'papr_allowed_redirect_for_pages' );
						$unrestricted     = self::papr_normalize_array_option( 'papr_login_unrestricted_pages' );
						$option_redirect  = 'papr_allowed_redirect_for_pages';
						$option_unrest    = 'papr_login_unrestricted_pages';
					} else {
						$allowed_redirect = self::papr_normalize_array_option( 'papr_allowed_redirect_for_posts' );
						$unrestricted     = self::papr_normalize_array_option( 'papr_login_unrestricted_posts' );
						$option_redirect  = 'papr_allowed_redirect_for_posts';
						$option_unrest    = 'papr_login_unrestricted_posts';
					}

					if ( $require_login ) {
						$allowed_redirect[ $post_id ] = true;
						unset( $unrestricted[ $post_id ] );
					} else {
						unset( $allowed_redirect[ $post_id ] );
						$unrestricted[ $post_id ] = true;
					}

					update_option( $option_redirect, $allowed_redirect );
					update_option( $option_unrest, $unrestricted );

					$what_it_does = __( 'Restricts access so only logged-in users can view selected pages or posts.', 'page-and-post-restriction' );
					$admin_action = __( 'Log out and try accessing the content to confirm it\'s blocked or redirected.', 'page-and-post-restriction' );

					if ( $require_login ) {
						$message = 'page' === $post->post_type
							? __( 'This page is now restricted to logged-in users only.', 'page-and-post-restriction' )
							: __( 'This post is now restricted to logged-in users only.', 'page-and-post-restriction' );
					} else {
						$message = 'page' === $post->post_type
							? __( 'Logged-in-only restriction for this page has been cleared.', 'page-and-post-restriction' )
							: __( 'Logged-in-only restriction for this post has been cleared.', 'page-and-post-restriction' );
					}

					return array(
						'success'        => true,
						'page_id'        => $post_id,
						'post_type'      => $post->post_type,
						'require_login'  => $require_login,
						'what_it_does'   => $what_it_does,
						'admin_action'   => $admin_action,
						'message'        => $message,
					);
				},
				'meta'                => self::papr_standard_action_meta(),
			)
		);
	}

	/**
	 * Register papr/restrict-by-role.
	 *
	 * Sets “Enter Roles who can view this Page/Post” (same options as the Page and Post Restrictions table save).
	 *
	 * @return void
	 */
	public static function papr_register_set_page_allowed_roles() {
		wp_register_ability(
			'papr/restrict-by-role',
			array(
				'label'               => __( 'Set page or post allowed roles', 'page-and-post-restriction' ),
				'description'         => __( 'Set which WordPress role slugs may view a specific page or blog post. Pass page_id as the numeric post ID (from the editor URL). Pass an empty roles array to clear role-based rules. When any roles are set, require-login is turned on for that item (same as the admin save).', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_page_allowed_roles_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'message'   => array( 'type' => 'string' ),
						'page_id'   => array( 'type' => 'integer' ),
						'post_type' => array( 'type' => 'string' ),
						'roles'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'   => array( 'success', 'message', 'page_id', 'post_type', 'roles' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function ( $input = array() ) {
					$content_id = isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
					if ( $content_id < 1 ) {
						return new \WP_Error(
							'papr_invalid_page_id',
							__( 'Provide a valid page_id (positive integer): the WordPress post ID of a page or post.', 'page-and-post-restriction' )
						);
					}
					$post = get_post( $content_id );
					if ( ! $post instanceof \WP_Post ) {
						return new \WP_Error(
							'papr_invalid_post',
							__( 'No content found for that ID.', 'page-and-post-restriction' )
						);
					}
					if ( 'page' !== $post->post_type && 'post' !== $post->post_type ) {
						return new \WP_Error(
							'papr_invalid_content_type',
							__( 'Allowed roles can only be set for the page and post types.', 'page-and-post-restriction' )
						);
					}

					$roles_in = isset( $input['roles'] ) && is_array( $input['roles'] ) ? $input['roles'] : array();
					$roles_ok = self::papr_validate_and_normalize_role_slugs( $roles_in );
					if ( is_wp_error( $roles_ok ) ) {
						return $roles_ok;
					}
					/** @var array<int, string> $roles_clean */
					$roles_clean = $roles_ok;

					if ( 'page' === $post->post_type ) {
						$allowed_roles  = self::papr_normalize_array_option( 'papr_allowed_roles_for_pages' );
						$restricted     = self::papr_normalize_array_option( 'papr_restricted_pages' );
						$restricted_ids = self::papr_flatten_restriction_id_list( $restricted );

						if ( array() === $roles_clean ) {
							unset( $allowed_roles[ $content_id ] );
							$restricted_ids = array_values(
								array_filter(
									array_map( 'intval', $restricted_ids ),
									function ( $id ) use ( $content_id ) {
										return $id !== (int) $content_id;
									}
								)
							);
						} else {
							$allowed_roles[ $content_id ] = $roles_clean;
							if ( ! in_array( (int) $content_id, $restricted_ids, true ) ) {
								$restricted_ids[] = (int) $content_id;
							}
							$restricted_ids = array_values( array_unique( array_map( 'intval', $restricted_ids ) ) );

							$allowed_redirect = self::papr_normalize_array_option( 'papr_allowed_redirect_for_pages' );
							$unrestricted     = self::papr_normalize_array_option( 'papr_login_unrestricted_pages' );
							$allowed_redirect[ $content_id ] = true;
							unset( $unrestricted[ $content_id ] );
							update_option( 'papr_allowed_redirect_for_pages', $allowed_redirect );
							update_option( 'papr_login_unrestricted_pages', $unrestricted );
						}

						update_option( 'papr_allowed_roles_for_pages', $allowed_roles );
						update_option( 'papr_restricted_pages', $restricted_ids );

						$message = array() === $roles_clean
							? __( 'Role-based access for this page has been cleared.', 'page-and-post-restriction' )
							: __( 'Allowed roles for this page have been saved.', 'page-and-post-restriction' );
					} else {
						$allowed_roles  = self::papr_normalize_array_option( 'papr_allowed_roles_for_posts' );
						$restricted     = self::papr_normalize_array_option( 'papr_restricted_posts' );
						$restricted_ids = self::papr_flatten_restriction_id_list( $restricted );

						if ( array() === $roles_clean ) {
							unset( $allowed_roles[ $content_id ] );
							$restricted_ids = array_values(
								array_filter(
									array_map( 'intval', $restricted_ids ),
									function ( $id ) use ( $content_id ) {
										return $id !== (int) $content_id;
									}
								)
							);
						} else {
							$allowed_roles[ $content_id ] = $roles_clean;
							if ( ! in_array( (int) $content_id, $restricted_ids, true ) ) {
								$restricted_ids[] = (int) $content_id;
							}
							$restricted_ids = array_values( array_unique( array_map( 'intval', $restricted_ids ) ) );

							$allowed_redirect = self::papr_normalize_array_option( 'papr_allowed_redirect_for_posts' );
							$unrestricted     = self::papr_normalize_array_option( 'papr_login_unrestricted_posts' );
							$allowed_redirect[ $content_id ] = true;
							unset( $unrestricted[ $content_id ] );
							update_option( 'papr_allowed_redirect_for_posts', $allowed_redirect );
							update_option( 'papr_login_unrestricted_posts', $unrestricted );
						}

						update_option( 'papr_allowed_roles_for_posts', $allowed_roles );
						update_option( 'papr_restricted_posts', $restricted_ids );

						$message = array() === $roles_clean
							? __( 'Role-based access for this post has been cleared.', 'page-and-post-restriction' )
							: __( 'Allowed roles for this post have been saved.', 'page-and-post-restriction' );
					}

					return array(
						'success'   => true,
						'page_id'   => $content_id,
						'post_type' => $post->post_type,
						'roles'     => array() === $roles_clean ? array() : $roles_clean,
						'message'   => $message,
					);
				},
				'meta'                => self::papr_standard_action_meta(),
			)
		);
	}

	/**
	 * Register papr/all-page-private-status.
	 *
	 * Read-only: reports whether “Make all Pages Private” (logged-in only) is on or off.
	 *
	 * @return void
	 */
	public static function papr_register_all_page_private_status() {
		wp_register_ability(
			'papr/all-page-private-status',
			array(
				'label'               => __( 'All pages private status', 'page-and-post-restriction' ),
				'description'         => __( 'Checks whether Make all Pages Private is enabled (pages visible only to logged-in users).', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_empty_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'              => array( 'type' => 'boolean' ),
						'message'              => array( 'type' => 'string' ),
						'all_pages_private'    => array( 'type' => 'boolean' ),
						'logged_in_only_pages' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success', 'message', 'all_pages_private', 'logged_in_only_pages' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function () {
					$login_only = get_option( 'papr_access_for_only_loggedin' );
					$enabled    = ( 1 === (int) $login_only || '1' === (string) $login_only );

					return array(
						'success'              => true,
						'message'              => $enabled
							? __( 'Make all Pages Private is on.', 'page-and-post-restriction' )
							: __( 'Make all Pages Private is off.', 'page-and-post-restriction' ),
						'all_pages_private'    => $enabled,
						'logged_in_only_pages' => is_scalar( $login_only ) ? (string) $login_only : '',
					);
				},
				'meta'                => self::papr_standard_readonly_meta(),
			)
		);
	}

	/**
	 * Register papr/all-post-private.
	 *
	 * Running this ability enables global “logged-in users only” for all posts
	 * (same as the post “logged in users only” admin toggle).
	 *
	 * @return void
	 */
	public static function papr_register_all_post_private() {
		wp_register_ability(
			'papr/all-post-private',
			array(
				'label'               => __( 'All posts private', 'page-and-post-restriction' ),
				'description'         => __( 'Makes all WordPress posts private so blog content is only for permitted users.', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_empty_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'             => array( 'type' => 'boolean' ),
						'message'             => array( 'type' => 'string' ),
						'what_it_does'        => array( 'type' => 'string' ),
						'admin_action'        => array( 'type' => 'string' ),
						'all_posts_private'    => array( 'type' => 'boolean' ),
						'logged_in_only_posts' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success', 'message', 'what_it_does', 'admin_action', 'all_posts_private', 'logged_in_only_posts' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function () {
					// Match page-restriction-save.php papr_access_for_only_loggedin_posts branch when the toggle is on.
					update_option( 'papr_login_unrestricted_posts', array() );
					update_option( 'papr_access_for_only_loggedin_posts', 1 );

					$login_only = get_option( 'papr_access_for_only_loggedin_posts' );
					$enabled    = ( 1 === (int) $login_only || '1' === (string) $login_only );

					return array(
						'success'              => true,
						'message'              => __( 'All posts are now restricted to logged-in users only.', 'page-and-post-restriction' ),
						'what_it_does'         => __( 'Makes all your WordPress posts private. Blog content is only for permitted users.', 'page-and-post-restriction' ),
						'admin_action'         => __( 'Check post visibility with different user roles to confirm the restriction is on.', 'page-and-post-restriction' ),
						'all_posts_private'    => $enabled,
						'logged_in_only_posts' => is_scalar( $login_only ) ? (string) $login_only : '',
					);
				},
				'meta'                => self::papr_standard_action_meta(),
			)
		);
	}

	/**
	 * Apply global “assign parent config to child pages” toggle (mirrors page-restriction-save.php).
	 *
	 * @param bool $enabled True to enable and sync children; false to disable and clear parent map.
	 * @return void
	 */
	private static function papr_apply_assign_parent_config_child_pages_toggle( $enabled ) {
		if ( ! $enabled ) {
			update_option( 'papr_default_role_parent_page_toggle', 0 );
			update_option( 'papr_select_all_pages', 'unchecked' );
			update_option( 'papr_default_role_parent', array() );
			return;
		}

		$allowed_roles          = get_option( 'papr_allowed_roles_for_pages' );
		$restrictedpages        = get_option( 'papr_restricted_pages' );
		$allowed_redirect_pages = get_option( 'papr_allowed_redirect_for_pages' );
		$default_role_parent    = get_option( 'papr_default_role_parent' );

		$allowed_roles          = $allowed_roles != '' ? $allowed_roles : array();
		$restrictedpages        = $restrictedpages != '' ? $restrictedpages : array();
		$allowed_redirect_pages = $allowed_redirect_pages != '' ? $allowed_redirect_pages : array();
		$default_role_parent    = $default_role_parent != '' ? $default_role_parent : array();

		$all_parent_pages = array(
			'post_parent' => 0,
			'numberposts' => -1,
			'post_type'   => 'page',
		);

		$total_parent_pages = get_posts( $all_parent_pages );

		foreach ( $total_parent_pages as $page ) {
			$pageid                           = $page->ID;
			$default_role_parent[ $page->ID ] = true;

			$children = get_pages( array( 'child_of' => $page->ID ) );

			if ( count( $children ) > 0 ) {
				foreach ( $children as $child ) {

					if ( ! empty( $allowed_roles[ $page->ID ] ) ) {
						$allowed_roles[ $child->ID ] = $allowed_roles[ $page->ID ];
					}

					if ( $allowed_roles[ $child->ID ] != '' ) {
						$already = false;
						if ( function_exists( 'papr_in_array' ) ) {
							$already = papr_in_array( $child->ID, $restrictedpages );
						} elseif ( is_array( $restrictedpages ) ) {
							$already = in_array( (int) $child->ID, array_map( 'intval', (array) $restrictedpages ), true );
						}
						if ( ! $already ) {
							array_push( $restrictedpages, $child->ID );
						}
					} else {
						unset( $restrictedpages[ $child->ID ] );
					}

					if ( ! empty( $allowed_redirect_pages[ $pageid ] ) ) {
						if ( $allowed_redirect_pages[ $pageid ] == 1 || $allowed_redirect_pages[ $pageid ] == 'on' || $allowed_redirect_pages[ $pageid ] == 'true' ) {
							$allowed_redirect_pages[ $child->ID ] = true;
						}
					} else {
						unset( $allowed_redirect_pages[ $child->ID ] );
					}

					$children_of_children = get_pages( array( 'child_of' => $child->ID ) );

					if ( count( $children_of_children ) > 0 ) {
						$default_role_parent[ $child->ID ] = true;
					}
				}
			}
		}

		update_option( 'papr_allowed_roles_for_pages', $allowed_roles );
		update_option( 'papr_restricted_pages', $restrictedpages );
		update_option( 'papr_allowed_redirect_for_pages', $allowed_redirect_pages );
		update_option( 'papr_default_role_parent', $default_role_parent );
		update_option( 'papr_default_role_parent_page_toggle', 1 );
		update_option( 'papr_select_all_pages', 'checked' );
	}

	/**
	 * Register papr/assign-parent-config-child-pages (read-only status).
	 *
	 * @return void
	 */
	public static function papr_register_assign_parent_config_child_pages() {
		wp_register_ability(
			'papr/assign-parent-config-child-pages',
			array(
				'label'               => __( 'Assign parent config to child pages', 'page-and-post-restriction' ),
				'description'         => __( 'Read-only: reports whether “Auto-assign Parent Configuration to Child Pages” is on (parent_rules_apply_to_children). Use papr/set-assign-parent-config-child-pages to change it.', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_empty_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                         => array( 'type' => 'boolean' ),
						'message'                         => array( 'type' => 'string' ),
						'what_it_does'                    => array( 'type' => 'string' ),
						'admin_action'                    => array( 'type' => 'string' ),
						'parent_rules_apply_to_children'  => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'success', 'message', 'what_it_does', 'admin_action', 'parent_rules_apply_to_children' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function () {
					$toggle = get_option( 'papr_default_role_parent_page_toggle' );
					$on     = ( 1 === (int) $toggle || '1' === (string) $toggle );
					return array(
						'success'                        => true,
						'message'                        => __( 'Parent/child inheritance status retrieved.', 'page-and-post-restriction' ),
						'what_it_does'                   => __( 'If you set access rules on a parent page, this automatically applies those same rules to all the child pages underneath it.', 'page-and-post-restriction' ),
						'admin_action'                   => __( 'Check a few child pages to confirm they inherited the parent\'s settings correctly.', 'page-and-post-restriction' ),
						'parent_rules_apply_to_children' => $on,
					);
				},
				'meta'                => self::papr_standard_readonly_meta(),
			)
		);
	}

	/**
	 * Register papr/set-assign-parent-config-child-pages.
	 *
	 * Turns global parent→child inheritance on or off (same as the admin checkbox save).
	 *
	 * @return void
	 */
	public static function papr_register_set_assign_parent_config_child_pages() {
		wp_register_ability(
			'papr/set-assign-parent-config-child-pages',
			array(
				'label'               => __( 'Set assign parent config to child pages', 'page-and-post-restriction' ),
				'description'         => __( 'Enable or disable automatic application of parent page access rules to child pages. When enabling, child pages are synced like the admin save.', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_assign_parent_child_toggle_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                        => array( 'type' => 'boolean' ),
						'message'                        => array( 'type' => 'string' ),
						'parent_rules_apply_to_children' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'success', 'message', 'parent_rules_apply_to_children' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function ( $input = array() ) {
					$enabled = true;
					if ( array_key_exists( 'enabled', $input ) ) {
						$enabled = (bool) $input['enabled'];
					}
					self::papr_apply_assign_parent_config_child_pages_toggle( $enabled );
					$toggle = get_option( 'papr_default_role_parent_page_toggle' );
					$on     = ( 1 === (int) $toggle || '1' === (string) $toggle );
					return array(
						'success'                        => true,
						'parent_rules_apply_to_children' => $on,
						'message'                        => $on
							? __( 'Parent configuration is now applied to child pages.', 'page-and-post-restriction' )
							: __( 'Parent configuration inheritance for child pages has been turned off.', 'page-and-post-restriction' ),
					);
				},
				'meta'                => self::papr_standard_action_meta(),
			)
		);
	}

	/**
	 * Register papr/create-role-from-cloning.
	 *
	 * Creates a new WordPress role with the same capabilities as an existing role.
	 *
	 * @return void
	 */
	public static function papr_register_create_role_from_cloning() {
		wp_register_ability(
			'papr/create-role-from-cloning',
			array(
				'label'               => __( 'Create role from cloning', 'page-and-post-restriction' ),
				'description'         => __( 'Create a new user role by copying all capabilities from an existing role (same idea as Clone in Roles and Capabilities).', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_clone_role_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'               => array( 'type' => 'boolean' ),
						'message'               => array( 'type' => 'string' ),
						'what_it_does'          => array( 'type' => 'string' ),
						'admin_action'          => array( 'type' => 'string' ),
						'source_role'           => array( 'type' => 'string' ),
						'new_role_slug'         => array( 'type' => 'string' ),
						'new_role_display_name' => array( 'type' => 'string' ),
						'roles_count'           => array( 'type' => 'integer' ),
					),
					'required'   => array( 'success', 'message', 'what_it_does', 'admin_action', 'source_role', 'new_role_slug', 'new_role_display_name', 'roles_count' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function ( $input = array() ) {
					$source = isset( $input['source_role'] ) ? sanitize_key( (string) $input['source_role'] ) : '';
					if ( '' === $source ) {
						return new \WP_Error(
							'papr_clone_missing_source',
							__( 'Provide source_role: the slug of the role to copy capabilities from.', 'page-and-post-restriction' )
						);
					}

					$source_role = get_role( $source );
					if ( ! $source_role instanceof \WP_Role ) {
						return new \WP_Error(
							'papr_clone_unknown_source',
							sprintf(
								/* translators: %s: role slug */
								__( 'Source role not found: %s', 'page-and-post-restriction' ),
								$source
							)
						);
					}

					$raw_new = isset( $input['new_role_slug'] ) ? (string) $input['new_role_slug'] : '';
					$new_slug = strtolower( str_replace( ' ', '_', sanitize_text_field( $raw_new ) ) );
					if ( '' === $new_slug || preg_match( '/[^a-z0-9\-_]/', $new_slug ) ) {
						return new \WP_Error(
							'papr_clone_invalid_slug',
							__( 'new_role_slug must be non-empty and use only letters, numbers, hyphens, and underscores.', 'page-and-post-restriction' )
						);
					}

					$wp_roles = wp_roles();
					if ( ! is_object( $wp_roles ) || ! isset( $wp_roles->roles ) ) {
						return new \WP_Error( 'papr_roles_unavailable', __( 'WordPress roles could not be loaded.', 'page-and-post-restriction' ) );
					}
					if ( ! empty( $wp_roles->roles[ $new_slug ] ) ) {
						return new \WP_Error(
							'papr_clone_role_exists',
							sprintf(
								/* translators: %s: role slug */
								__( 'A role with slug %s already exists.', 'page-and-post-restriction' ),
								$new_slug
							)
						);
					}

					$display = '';
					if ( isset( $input['new_role_display_name'] ) && is_string( $input['new_role_display_name'] ) && '' !== trim( $input['new_role_display_name'] ) ) {
						$display = sanitize_text_field( $input['new_role_display_name'] );
					} else {
						$display = ucwords( str_replace( '_', ' ', $new_slug ) );
					}

					$caps = $source_role->capabilities;
					if ( ! is_array( $caps ) ) {
						$caps = array();
					}

					$created = add_role( $new_slug, $display, $caps );
					if ( null === $created ) {
						return new \WP_Error(
							'papr_clone_add_role_failed',
							__( 'WordPress could not create the role.', 'page-and-post-restriction' )
						);
					}

					$wp_roles = wp_roles();
					$count    = is_object( $wp_roles ) && isset( $wp_roles->roles ) ? count( $wp_roles->roles ) : 0;

					$what_it_does = __( 'Lets you create a brand-new user role by copying the permissions from an existing one. Super quick way to set up custom access.', 'page-and-post-restriction' );
					$admin_action = __( 'Assign the new role to a test user and check that their permissions are what you expected.', 'page-and-post-restriction' );

					return array(
						'success'               => true,
						'message'               => sprintf(
							/* translators: 1: new role slug, 2: source role slug */
							__( 'New role %1$s created from clone of %2$s.', 'page-and-post-restriction' ),
							$new_slug,
							$source
						),
						'what_it_does'          => $what_it_does,
						'admin_action'          => $admin_action,
						'source_role'           => $source,
						'new_role_slug'         => $new_slug,
						'new_role_display_name' => $display,
						'roles_count'           => $count,
					);
				},
				'meta'                => self::papr_standard_action_meta(),
			)
		);
	}

	/**
	 * Register papr/restrict-by-page-name.
	 *
	 * @return void
	 */
	public static function papr_register_restrict_by_page_name() {
		wp_register_ability(
			'papr/restrict-by-page-name',
			array(
				'label'               => __( 'Restrict by page name', 'page-and-post-restriction' ),
				'description'         => __( 'Restrict access to specific pages using the page slug (post_name).', 'page-and-post-restriction' ),
				'category'            => self::CATEGORY_SLUG,
				'input_schema'        => self::papr_empty_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                    => array( 'type' => 'boolean' ),
						'message'                    => array( 'type' => 'string' ),
						'what_it_does'               => array( 'type' => 'string' ),
						'admin_action'               => array( 'type' => 'string' ),
						'pages_with_rules'           => array( 'type' => 'integer' ),
						'sample_page_slugs'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'   => array( 'success', 'message', 'what_it_does', 'admin_action', 'pages_with_rules', 'sample_page_slugs' ),
				),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function () {
					$allowed = self::papr_normalize_array_option( 'papr_allowed_roles_for_pages' );
					$slugs   = array();
					$count   = 0;
					foreach ( $allowed as $page_key => $roles ) {
						if ( ! is_array( $roles ) || array() === $roles ) {
							continue;
						}
						++$count;
						if ( count( $slugs ) < 10 && is_numeric( $page_key ) ) {
							$name = get_post_field( 'post_name', (int) $page_key );
							if ( is_string( $name ) && '' !== $name ) {
								$slugs[] = $name;
							}
						}
					}
					return array(
						'success'           => true,
						'message'           => __( 'Page slug restriction summary retrieved.', 'page-and-post-restriction' ),
						'what_it_does'      => __( 'Allows you to restrict access to specific pages using the page name (slug).', 'page-and-post-restriction' ),
						'admin_action'      => __( 'Try accessing the page via its URL/slug and navigation to confirm it is properly restricted.', 'page-and-post-restriction' ),
						'pages_with_rules'  => $count,
						'sample_page_slugs' => $slugs,
					);
				},
				'meta'                => self::papr_standard_readonly_meta(),
			)
		);
	}

	/**
	 * Process Abilities API enable/disable (store option). Mirrors SAML toggle behavior without UI.
	 *
	 * @param array<string, mixed> $post_array Sanitized POST-like array (e.g. papr_enable_abilities_api => 'true'|'false').
	 * @return void
	 */
	public static function papr_process_abilities_api_toggle( $post_array ) {
		$requested = isset( $post_array['papr_enable_abilities_api'] ) && 'true' === $post_array['papr_enable_abilities_api'];

		if ( $requested && ! self::papr_abilities_api_available() ) {
			update_option( self::OPTION_ENABLE_ABILITIES_API, 'false' );
			return;
		}

		update_option( self::OPTION_ENABLE_ABILITIES_API, $requested ? 'true' : 'false' );
	}

	/**
	 * MCP meta when abilities API is enabled (same pattern as SAML handler).
	 *
	 * @return array<string, array<string, bool>>|false
	 */
	private static function papr_get_mcp_public_setting() {
		if ( 'true' === get_option( self::OPTION_ENABLE_ABILITIES_API, 'false' ) ) {
			return array(
				'public' => true,
			);
		}
		return false;
	}

	/**
	 * Default meta block for read-only abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_standard_readonly_meta() {
		return array_merge(
			array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'      => true,
					'idempotent'    => true,
					'openWorldHint' => true,
				),
			),
			self::papr_get_mcp_public_setting() ? array( 'mcp' => self::papr_get_mcp_public_setting() ) : array()
		);
	}

	/**
	 * Meta for abilities that change plugin options (MCP clients may invoke these).
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_standard_action_meta() {
		return array_merge(
			array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'      => false,
					'idempotent'    => true,
					'openWorldHint' => true,
				),
			),
			self::papr_get_mcp_public_setting() ? array( 'mcp' => self::papr_get_mcp_public_setting() ) : array()
		);
	}

	/**
	 * Input schema for papr/restrict-behind-login.
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_restrict_behind_login_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_id'        => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID: use for a Page or a blog Post (the numeric ID from the editor URL).', 'page-and-post-restriction' ),
					'minimum'     => 1,
				),
				'require_login' => array(
					'type'        => 'boolean',
					'description' => __( 'If true (default), only logged-in users can view the page or post. If false, clear that restriction for this item.', 'page-and-post-restriction' ),
					'default'     => true,
				),
			),
			'required'             => array( 'page_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for papr/set-assign-parent-config-child-pages.
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_assign_parent_child_toggle_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'enabled' => array(
					'type'        => 'boolean',
					'description' => __( 'True to enable parent→child inheritance and sync children; false to disable and clear the parent map.', 'page-and-post-restriction' ),
					'default'     => true,
				),
			),
			'required'             => array( 'enabled' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for papr/create-role-from-cloning.
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_clone_role_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_role'           => array(
					'type'        => 'string',
					'description' => __( 'Slug of the existing role to copy capabilities from (e.g. editor, subscriber).', 'page-and-post-restriction' ),
				),
				'new_role_slug'         => array(
					'type'        => 'string',
					'description' => __( 'Slug for the new role: lowercase letters, numbers, hyphens, underscores only (e.g. my_custom_editor).', 'page-and-post-restriction' ),
				),
				'new_role_display_name' => array(
					'type'        => 'string',
					'description' => __( 'Optional human-readable name for the new role. If omitted, a title is derived from new_role_slug.', 'page-and-post-restriction' ),
				),
			),
			'required'             => array( 'source_role', 'new_role_slug' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for papr/restrict-by-role (page or blog post).
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_page_allowed_roles_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID: use for a Page or a blog Post (the numeric ID from the editor URL).', 'page-and-post-restriction' ),
					'minimum'     => 1,
				),
				'roles'     => array(
					'type'        => 'array',
					'description' => __( 'Role slugs that may view the content (e.g. editor, subscriber). Empty array clears role rules for this page or post.', 'page-and-post-restriction' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
			),
			'required'             => array( 'page_id', 'roles' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flatten papr_restricted_pages / papr_restricted_posts style option to a list of positive integer IDs.
	 *
	 * @param array<string|int, mixed> $restricted Raw option array.
	 * @return array<int, int>
	 */
	private static function papr_flatten_restriction_id_list( array $restricted ) {
		$ids = array();
		foreach ( $restricted as $k => $v ) {
			if ( is_numeric( $k ) && (int) $k > 0 ) {
				$ids[] = (int) $k;
			}
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				$ids[] = (int) $v;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Validate role slugs against wp_roles(); return normalized unique list or WP_Error.
	 *
	 * @param array<int, mixed> $roles Raw role strings from input.
	 * @return array<int, string>|\WP_Error
	 */
	private static function papr_validate_and_normalize_role_slugs( array $roles ) {
		$wp_roles = wp_roles();
		if ( ! is_object( $wp_roles ) || ! isset( $wp_roles->roles ) || ! is_array( $wp_roles->roles ) ) {
			return new \WP_Error(
				'papr_roles_unavailable',
				__( 'WordPress roles could not be loaded.', 'page-and-post-restriction' )
			);
		}
		$valid_slugs = array_keys( $wp_roles->roles );
		$out         = array();
		$invalid     = array();
		foreach ( $roles as $one ) {
			if ( ! is_string( $one ) && ! is_numeric( $one ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $one );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! in_array( $slug, $valid_slugs, true ) ) {
				$invalid[] = $slug;
				continue;
			}
			if ( ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		if ( array() !== $invalid ) {
			return new \WP_Error(
				'papr_invalid_roles',
				sprintf(
					/* translators: %s: comma-separated invalid role slugs */
					__( 'Unknown role slug(s): %s', 'page-and-post-restriction' ),
					implode( ', ', array_unique( $invalid ) )
				)
			);
		}
		return $out;
	}

	/**
	 * Empty object input schema (no parameters).
	 *
	 * @return array<string, mixed>
	 */
	private static function papr_empty_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * Normalize an option to an associative array.
	 *
	 * @param string $option_name Option name.
	 * @return array<string|int, mixed>
	 */
	private static function papr_normalize_array_option( $option_name ) {
		$raw = get_option( $option_name, array() );
		if ( ! is_array( $raw ) ) {
			$raw = maybe_unserialize( $raw );
		}
		return is_array( $raw ) ? $raw : array();
	}
}
