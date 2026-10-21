<?php
/**
 * Plugin Name: Page and Post Restriction
 * Description: This plugin allows frontend page and post restriction based on user roles and login status.
 * Version: 1.6.1
 * Author: miniOrange
 * Author URI: https://miniorange.com
 * License: Expat
 * License URI: https://plugins.miniorange.com/mit-license
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} 

define( 'PAPR_PLUGIN_DIR', dirname( __FILE__ ) );
define( 'PAPR_CONSTANTS_FILE', '/page-restriction-constants.php' );
require_once PAPR_PLUGIN_DIR . PAPR_CONSTANTS_FILE;
require_once PAPR_PLUGIN_DIR . '/papr-register-abilities.php';
require_once 'page-restriction-save.php';
require_once 'feedback-form.php';
require_once 'page-restriction-menu-settings.php';
require_once 'page-restriction-utility.php';
require_once PAPR_PLUGIN_DIR . '/papr-abilities-api-page.php';
require_once 'page-restriction-custom-roles-sub-menu.php';

class papr_page_and_post_restriction {

	/**
	 * Stores restricted post IDs to filter.
	 *
	 * @var array
	 */
	private $restricted_posts_to_filter = array();

	function __construct() {
		update_option( 'papr_host_name', 'https://login.xecurify.com' );
		add_action( 'init', array( 'PAPR_Register_Abilities', 'schedule_abilities_api_hooks' ), 1 );
		add_action( 'admin_menu', array( $this, 'papr_menu' ), 11 );
		add_action( 'admin_init', 'papr_save_setting', 1, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'papr_plugin_settings_script' ) );
		register_deactivation_hook( __FILE__, array( $this, 'papr_deactivate' ) );
		add_action( 'save_post', array( $this, 'papr_save_meta_box_info' ), 10, 3 );
		add_action( 'wp', array( $this, 'papr_initialize_page_restrict' ), 0 );
		add_action( 'add_meta_boxes', array( $this, 'papr_add_custom_meta_box' ) );
		add_action( 'admin_footer', array( $this, 'papr_feedback_request' ) );
		add_filter( 'manage_page_posts_columns', array( $this, 'papr_page_add_column' ) );
		add_filter( 'manage_post_posts_columns', array( $this, 'papr_post_add_column' ) );
		add_action( 'manage_page_posts_custom_column', array( $this, 'papr_page_custom_columns' ), 10, 2 );
		add_action( 'manage_post_posts_custom_column', array( $this, 'papr_post_custom_columns' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'papr_display_custom_quick_edit_fields' ), 10, 2 );
		add_shortcode( 'restrict_content', array( $this, 'papr_restrict_content' ) );
		add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'papr_add_plugin_settings' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'papr_restrict_page_post_rest_api' ), 10, 3 );
		add_filter( 'pre_get_posts', array( $this, 'papr_filter_posts' ) );
		add_filter( 'parse_comment_query', array( $this, 'papr_parse_comment_query' ) );
		add_filter( 'the_content_feed', array( $this, 'papr_restrict_feed_content' ), 10, 2 );
		add_filter( 'the_excerpt_rss', array( $this, 'papr_restrict_feed_content' ) );
		add_filter( 'comment_feed_where', array( $this, 'papr_filter_comment_feed_where' ), 10, 2 );
		add_filter( 'oembed_request_post_id', array( $this, 'papr_restrict_oembed_post_id' ), 10, 2 );
	}

	/**
	 * Resolve a URL to a post ID without this plugin's query filtering.
	 *
	 * url_to_postid() runs a WP_Query for pretty permalinks, and during REST
	 * requests papr_filter_restricted_posts() strips restricted posts from every
	 * query, so a restricted URL would otherwise resolve to 0.
	 *
	 * @param string $url URL to resolve.
	 * @return int Post ID, or 0 if the URL does not match a post.
	 */
	private function papr_url_to_postid_unfiltered( $url ) {
		$had_posts_filter = has_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ) );
		remove_filter( 'pre_get_posts', array( $this, 'papr_filter_posts' ) );
		remove_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ), 10 );

		$post_id = (int) url_to_postid( $url );

		add_filter( 'pre_get_posts', array( $this, 'papr_filter_posts' ) );
		if ( false !== $had_posts_filter ) {
			add_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ), 10, 2 );
		}

		return $post_id;
	}

	/**
	 * Block oEmbed data for restricted pages/posts.
	 *
	 * `oembed_request_post_id` runs both for the /oembed/1.0/embed REST endpoint
	 * and for internal embeds of this site's URLs (wp_filter_pre_oembed_result),
	 * neither of which goes through the `wp` action, so without this the title,
	 * author, excerpt and embed iframe of a restricted item leak. Returning 0
	 * makes internal embeds fall back to a plain link; the REST endpoint is
	 * answered with a 403 earlier, in papr_restrict_page_post_rest_api().
	 *
	 * @param int    $post_id Post ID resolved from the oEmbed URL.
	 * @param string $url     The requested URL.
	 * @return int Original post ID when accessible, 0 when restricted.
	 */
	public function papr_restrict_oembed_post_id( $post_id, $url ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return $post_id;
		}

		$type = get_post_type( $post_id );
		if ( 'page' !== $type && 'post' !== $type ) {
			return $post_id;
		}

		if ( $this->papr_is_post_restricted_for_current_user( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Restrict the /pages, /posts and /comments REST API endpoints.
	 *
	 * Hooked on `rest_request_before_callbacks` so the current user is already
	 * authenticated by the REST server (cookie + nonce, Application Passwords,
	 * etc.) and the URL params are set. `$request['id']` is then exactly the
	 * value the controller will use - a query-string/body `id` takes precedence
	 * over the one in the route, so the route ID alone cannot be trusted.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param array           $handler Route handler used for the request.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed Original $result when allowed, or a WP_Error (403) when restricted.
	 */
	public function papr_restrict_page_post_rest_api( $result, $handler, $request ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( ! get_current_user_id() && in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) && ( $user_id = wp_validate_auth_cookie( '', 'logged_in' ) ) ) {
			wp_set_current_user( $user_id );
		}

		// WordPress matches REST routes case-insensitively, so normalize before comparing.
		$route = strtolower( untrailingslashit( $request->get_route() ) );

		if ( 0 === strpos( $route, '/wp/v2/pages' ) || 0 === strpos( $route, '/wp/v2/posts' ) ) {
			$ids = array();
			if ( preg_match( '#^/wp/v2/(?:posts|pages)/(\d+)#', $route, $matches ) ) {
				$ids[] = (int) $matches[1];
				$ids[] = (int) $request['id'];
			}

			foreach ( array_unique( $ids ) as $id ) {
				if ( $id > 0 && ! $this->papr_is_post_accessible_to_current_user( $id, 'page' === get_post_type( $id ) ) ) {
					return $this->papr_send_rest_forbidden();
				}
			}
			return $result;
		}

		if ( 0 === strpos( $route, '/wp/v2/comments' ) ) {
			return $this->papr_restrict_comments_rest_api( $route, $request, $result );
		}

		// oEmbed for a restricted item: answer 403 like the other REST routes rather
		// than core's 404. papr_restrict_oembed_post_id() still covers internal embeds.
		if ( 0 === strpos( $route, '/oembed/1.0/embed' ) && ! empty( $request['url'] ) ) {
			$post_id = $this->papr_url_to_postid_unfiltered( $request['url'] );
			$type    = $post_id > 0 ? get_post_type( $post_id ) : '';
			if ( ( 'page' === $type || 'post' === $type ) && ! $this->papr_is_post_accessible_to_current_user( $post_id, 'page' === $type ) ) {
				return $this->papr_send_rest_forbidden();
			}
			return $result;
		}

		return $result;
	}

	/**
	 * Restrict the /comments REST API endpoints for comments belonging to
	 * restricted posts/pages, mirroring papr_restrict_page_post_rest_api().
	 *
	 * Handles both the single-comment route (/wp/v2/comments/{id}) - which
	 * never runs through WP_Comment_Query, so `the_comments` never fires for
	 * it - and the collection route when a `post` parameter is supplied. For a
	 * single comment both the route ID and `$request['id']` are checked.
	 *
	 * @param string          $route   REST route being dispatched.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @param mixed           $result  Original dispatch result to pass through when allowed.
	 * @return mixed Original $result when allowed, or a WP_Error (403) when restricted.
	 */
	private function papr_restrict_comments_rest_api( $route, $request, $result ) {
		$post_ids = array();

		if ( preg_match( '#^/wp/v2/comments/(\d+)#', $route, $matches ) ) {
			foreach ( array_unique( array( (int) $matches[1], (int) $request['id'] ) ) as $comment_id ) {
				$comment = $comment_id > 0 ? get_comment( $comment_id ) : null;
				if ( $comment ) {
					$post_ids[] = (int) $comment->comment_post_ID;
				}
			}
		} else {
			$post_param = $request->get_param( 'post' );
			if ( ! empty( $post_param ) ) {
				$post_ids = array_map( 'intval', (array) $post_param );
			}
		}

		foreach ( $post_ids as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( ! $this->papr_is_post_accessible_to_current_user( $post_id, 'page' === get_post_type( $post_id ) ) ) {
				return $this->papr_send_rest_forbidden();
			}
		}

		return $result;
	}

	/**
	 * Determine whether the current REST requester (guest or logged-in) is
	 * allowed to access the given page/post, combining the guest-restriction
	 * and role-restriction checks used across posts, pages and comments.
	 *
	 * @param int  $id      Page or post ID.
	 * @param bool $is_page Whether the item is a page (true) or post (false).
	 * @return bool True if accessible to the current user, false if restricted.
	 */
	private function papr_is_post_accessible_to_current_user( $id, $is_page ) {
		if ( ! is_user_logged_in() ) {
			$restricted_posts = papr_get_restricted_posts_id();
			if ( in_array( $id, $restricted_posts, true ) ) {
				return false;
			}
			if ( $this->papr_is_private_for_guests_by_toggle( $id, $is_page ) ) {
				return false;
			}
			return true;
		}

		return ! $this->papr_is_restricted_by_role_for_current_user( $id );
	}

	/**
	 * Determine whether a page/post must be private to non-logged-in users because
	 * the global "make all private" toggle is enabled and the item has not been
	 * individually exempted (its per-item "Require Login" checkbox has no value).
	 *
	 * Mirrors the front-end logic in papr_restrict_logged_in_users().
	 *
	 * @param int  $id      Page or post ID requested through the REST API.
	 * @param bool $is_page Whether the requested item is a page (true) or post (false).
	 * @return bool True if the item must be hidden from non-logged-in users.
	 */
	private function papr_is_private_for_guests_by_toggle( $id, $is_page ) {
		if ( $is_page ) {
			$toggle       = get_option( 'papr_access_for_only_loggedin' );
			$unrestricted = get_option( 'papr_login_unrestricted_pages', array() );
		} else {
			$toggle       = get_option( 'papr_access_for_only_loggedin_posts' );
			$unrestricted = get_option( 'papr_login_unrestricted_posts', array() );
		}

		if ( 1 != $toggle ) {
			return false;
		}

		$unrestricted = is_array( $unrestricted ) ? $unrestricted : array();
		return empty( $unrestricted[ $id ] );
	}

	/**
	 * Check whether the current logged-in user's role is restricted from the given page/post.
	 *
	 * @param int $id Page or post ID requested through the REST API.
	 * @return bool True if the current user's role is not allowed to access the page/post.
	 */
	private function papr_is_restricted_by_role_for_current_user( $id ) {
		$allowed_roles_pages = get_option( 'papr_allowed_roles_for_pages', array() );
		$allowed_roles_posts = get_option( 'papr_allowed_roles_for_posts', array() );

		$allowed_roles = array();
		if ( is_array( $allowed_roles_pages ) && ! empty( $allowed_roles_pages[ $id ] ) ) {
			$allowed_roles = $allowed_roles_pages[ $id ];
		} elseif ( is_array( $allowed_roles_posts ) && ! empty( $allowed_roles_posts[ $id ] ) ) {
			$allowed_roles = $allowed_roles_posts[ $id ];
		}

		if ( empty( $allowed_roles ) || ! is_array( $allowed_roles ) ) {
			return false;
		}

		$current_user = wp_get_current_user();
		$user_roles   = $current_user->roles;
		return empty( array_intersect( $allowed_roles, $user_roles ) );
	}

	/**
	 * Determine whether the given page/post must be hidden from the current visitor,
	 * covering "Require Login", the global "make all private" toggle, and role-based
	 * restriction. Used to strip restricted content out of feeds.
	 *
	 * @param int $post_id Page or post ID.
	 * @return bool True if the current visitor should not see this page/post.
	 */
	private function papr_is_post_restricted_for_current_user( $post_id ) {
		$post_id = (int) $post_id;
		$is_page = ( 'page' === get_post_type( $post_id ) );

		if ( ! is_user_logged_in() ) {
			if ( in_array( $post_id, papr_get_restricted_posts_id(), true ) ) {
				return true;
			}
			return $this->papr_is_private_for_guests_by_toggle( $post_id, $is_page );
		}

		return $this->papr_is_restricted_by_role_for_current_user( $post_id );
	}

	/**
	 * Strip the content/excerpt of a restricted page/post out of RSS/Atom feeds.
	 * Defense-in-depth alongside the `pre_get_posts` feed filtering in papr_filter_posts(),
	 * in case a restricted item still ends up in a feed loop (e.g. custom feed queries).
	 *
	 * @param string $content  Feed content or excerpt.
	 * @param string $feed_type Optional feed type, passed by `the_content_feed`.
	 * @return string
	 */
	public function papr_restrict_feed_content( $content, $feed_type = null ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		if ( $this->papr_is_post_restricted_for_current_user( $post_id ) ) {
			return '';
		}

		return $content;
	}

	/**
	 * Build a 403 forbidden response for restricted REST API requests.
	 *
	 * Returned from `rest_pre_dispatch` so the REST server converts it into a
	 * proper 403 JSON response.
	 *
	 * @return WP_Error
	 */
	private function papr_send_rest_forbidden() {
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access this endpoint.', 'page-and-post-restriction' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Halt a restricted single post/page feed request (e.g. /post-slug/feed/) with a
	 * JSON 403 response instead of letting the feed template render, and instead of the
	 * plain-text wp_die() used for normal browser page views.
	 *
	 * @return void This function terminates the request.
	 */
	private function papr_send_feed_forbidden_json() {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_bloginfo( 'charset' ) );
		echo wp_json_encode(
			array(
				'code'    => 'papr_forbidden',
				'message' => __( 'Oops! You are not authorized to access this', 'page-and-post-restriction' ),
				'data'    => array( 'status' => 403 ),
			)
		);
		exit;
	}


	/**
	 * Filter the posts when the WP_Query object is created.
	 *
	 * @param WP_Query $query Object of the WP_Query that needs to be filtered.
	 * @return void
	 */
	public function papr_filter_posts( $query ) {
		if ( $query->is_search && ! is_admin() && $query->is_main_query() ) {
			if ( ! is_user_logged_in() ) {
				$this->restricted_posts_to_filter = array_merge(
					papr_get_restricted_posts_id(),
					$this->papr_get_toggle_private_ids( $query )
				);
				add_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ), 10, 2 );
			} else {
				$allowed_roles_posts = get_option( 'papr_allowed_roles_for_posts', array() );
				$allowed_roles_pages = get_option( 'papr_allowed_roles_for_pages', array() );
				$current_user  = wp_get_current_user();
				$user_roles = $current_user->roles;
				$not_accessible_posts = array();

				if ( is_array( $allowed_roles_posts ) ) {
					foreach ( $allowed_roles_posts as $post_id => $roles ) {
						if ( ! array_intersect( $roles, $user_roles ) ) {
							$not_accessible_posts[] = $post_id;
						}
					}
				}

				if ( is_array( $allowed_roles_pages ) ) {
					foreach ( $allowed_roles_pages	 as $post_id => $roles ) {
						if ( ! array_intersect( $roles, $user_roles ) ) {
							$not_accessible_posts[] = $post_id;
						}
					}
				}
				$this->restricted_posts_to_filter = array_merge( $not_accessible_posts );
				add_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ), 10, 2 );
			}
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$not_accessible_posts = array();
			$accessible_posts     = array();
			$user_roles           = is_user_logged_in() ? wp_get_current_user()->roles : array();
			$allowed_roles_posts  = get_option( 'papr_allowed_roles_for_posts', array() );
			$allowed_roles_pages  = get_option( 'papr_allowed_roles_for_pages', array() );
			foreach ( array( $allowed_roles_posts, $allowed_roles_pages ) as $allowed_roles ) {
				if ( ! is_array( $allowed_roles ) ) {
					continue;
				}
				foreach ( $allowed_roles as $post_id => $roles ) {
					if ( 'mo_page_0' === $post_id || empty( $roles ) || ! is_array( $roles ) ) {
						continue;
					}
					if ( array_intersect( $roles, $user_roles ) ) {
						$accessible_posts[] = (int) $post_id;
					} else {
						$not_accessible_posts[] = (int) $post_id;
					}
				}
			}
			if ( ! is_user_logged_in() ) {
				$not_accessible_posts = array_merge( $not_accessible_posts, papr_get_restricted_posts_id() );
				$not_accessible_posts = array_merge( $not_accessible_posts, $this->papr_get_toggle_private_ids( $query ) );
			}

			$not_accessible_posts = array_diff( array_unique( $not_accessible_posts ), $accessible_posts );

			if ( ! empty( $not_accessible_posts ) ) {
				$this->restricted_posts_to_filter = array_values( $not_accessible_posts );
				add_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ), 10, 2 );
			}
		}

		if ( $query->is_feed() && $query->is_main_query() && ! $query->is_singular() ) {
			$restricted_ids = $this->papr_get_feed_restricted_ids( $query );

			if ( ! empty( $restricted_ids ) ) {
				$this->restricted_posts_to_filter = array_values( array_unique( array_merge( $this->restricted_posts_to_filter, $restricted_ids ) ) );
				add_filter( 'the_posts', array( $this, 'papr_filter_restricted_posts' ), 10, 2 );
			}
		}
	}

	/**
	 * Collect IDs of pages/posts the current visitor must not see in feeds,
	 * covering "Require Login", the global "make all private" toggle, and
	 * role-based restriction.
	 *
	 * @param WP_Query $query The current query.
	 * @return int[] Restricted page/post IDs.
	 */
	private function papr_get_feed_restricted_ids( $query ) {
		$restricted_ids = array();

		if ( ! is_user_logged_in() ) {
			$restricted_ids = array_merge(
				papr_get_restricted_posts_id(),
				$this->papr_get_toggle_private_ids( $query )
			);
		} else {
			$allowed_roles_posts = get_option( 'papr_allowed_roles_for_posts', array() );
			$allowed_roles_pages = get_option( 'papr_allowed_roles_for_pages', array() );
			$current_user        = wp_get_current_user();
			$user_roles          = $current_user->roles;

			foreach ( array( $allowed_roles_posts, $allowed_roles_pages ) as $allowed_roles ) {
				if ( ! is_array( $allowed_roles ) ) {
					continue;
				}
				foreach ( $allowed_roles as $post_id => $roles ) {
					if ( 'mo_page_0' === $post_id || empty( $roles ) || ! is_array( $roles ) ) {
						continue;
					}
					if ( ! array_intersect( $roles, $user_roles ) ) {
						$restricted_ids[] = (int) $post_id;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $restricted_ids ) ) ) );
	}

	/**
	 * Exclude comments on restricted pages/posts from comment feeds
	 * (/comments/feed/, ?feed=comments-rss2, ?withcomments=1, etc.).
	 *
	 * Comment feeds are built directly in WP_Query::get_posts() and never run
	 * through WP_Comment_Query, so `the_comments` filtering does not apply.
	 * Removing the comments also removes the parent post titles/permalinks,
	 * which the feed templates print per comment.
	 *
	 * @param string   $cwhere The WHERE clause of the comments feed query.
	 * @param WP_Query $query  The WP_Query instance.
	 * @return string
	 */
	public function papr_filter_comment_feed_where( $cwhere, $query ) {
		global $wpdb;

		// Same scope as the posts feed in papr_filter_posts(); singular comment
		// feeds are already blocked by papr_initialize_page_restrict().
		if ( ! $query->is_comment_feed() || ! $query->is_main_query() || $query->is_singular() ) {
			return $cwhere;
		}

		$restricted_ids = $this->papr_get_feed_restricted_ids( $query );
		if ( empty( $restricted_ids ) ) {
			return $cwhere;
		}

		return $cwhere . " AND {$wpdb->comments}.comment_post_ID NOT IN (" . implode( ',', $restricted_ids ) . ')';
	}

	/**
	 * Collect IDs that must be hidden from non-logged-in users because the global
	 * "make all private" toggle is enabled and the item has not been individually
	 * exempted. Only enumerates the post type(s) being queried.
	 *
	 * @param WP_Query $query The current query.
	 * @return int[] IDs to exclude from the results.
	 */
	private function papr_get_toggle_private_ids( $query ) {
		$ids        = array();
		$post_types = array_values( array_filter( (array) $query->get( 'post_type' ), 'strlen' ) );
		$all_types  = empty( $post_types ) || in_array( 'any', $post_types, true );

		// Only enumerate published pages/posts when the matching toggle is on.
		if ( 1 == get_option( 'papr_access_for_only_loggedin' ) && ( $all_types || in_array( 'page', $post_types, true ) ) ) {
			foreach ( $this->get_all_published_page_ids( 'page' ) as $page_id ) {
				if ( $this->papr_is_private_for_guests_by_toggle( $page_id, true ) ) {
					$ids[] = (int) $page_id;
				}
			}
		}

		if ( 1 == get_option( 'papr_access_for_only_loggedin_posts' ) && ( $all_types || in_array( 'post', $post_types, true ) ) ) {
			foreach ( $this->get_all_published_page_ids( 'post' ) as $post_id ) {
				if ( $this->papr_is_private_for_guests_by_toggle( $post_id, false ) ) {
					$ids[] = (int) $post_id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Filter out restricted posts after query execution.
	 * This approach is more performant than using post__not_in.
	 *
	 * @param WP_Post[] $posts Array of post objects.
	 * @param WP_Query  $query The query object.
	 * @return WP_Post[] Filtered array of posts.
	 */
	public function papr_filter_restricted_posts( $posts, $query ) {
		$restricted_posts = $this->restricted_posts_to_filter;
		if ( empty( $restricted_posts ) ) {
			return $posts;
		}

		return array_filter(
			$posts,
			function ( $post ) use ( $restricted_posts ) {
				return ! in_array( (int) $post->ID, $restricted_posts, true );
			}
		);
	}
	/**
	 * Filter the comment query to add post-query filtering for restricted posts.
	 *
	 * Applies to REST comment routes and to front-end comment queries such as
	 * the Recent Comments widget and Latest Comments block (get_comments()).
	 * Front-end AJAX comment loaders (admin-ajax.php) are covered too. Admin,
	 * cron and WP-CLI queries are left untouched.
	 *
	 * @param WP_Comment_Query $query Query for the comments.
	 * @return void
	 */
	public function papr_parse_comment_query( $query ) {
		$routes = ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ? strtolower( $GLOBALS['wp']->query_vars['rest_route'] ) : '';
		// admin-ajax.php is is_admin(), but serves front-end comment loaders too. Comment
		// moderators already see every comment in wp-admin, so their AJAX is left alone.
		$is_ajax_viewer = wp_doing_ajax() && ! current_user_can( 'moderate_comments' );
		$is_front_end   = ( ! is_admin() || $is_ajax_viewer ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
		if ( 0 === strpos( $routes, '/wp/v2/comments' ) || $is_front_end ) {
			add_filter( 'the_comments', array( $this, 'papr_filter_restricted_comments' ), 10, 2 );
		}
	}

	/**
	 * Filter out comments belonging to restricted posts/pages after query
	 * execution. Acts as a fallback for comment queries that don't carry a
	 * `post` request param (so papr_restrict_comments_rest_api() couldn't
	 * pre-check them) - e.g. GET /wp/v2/comments with no `post` filter.
	 * This approach is more performant than using post__not_in.
	 *
	 * @param WP_Comment[] $comments Array of comment objects.
	 * @param WP_Comment_Query $query The comment query object.
	 * @return WP_Comment[] Filtered array of comments.
	 */
	public function papr_filter_restricted_comments( $comments, $query ) {
		return array_values(
			array_filter(
				$comments,
				function ( $comment ) {
					$post_id = (int) $comment->comment_post_ID;
					if ( $post_id <= 0 ) {
						return true;
					}
					return $this->papr_is_post_accessible_to_current_user( $post_id, 'page' === get_post_type( $post_id ) );
				}
			)
		);
	}

	/**
	 * Get IDs of all the published posts.
	 *
	 * @param array|string $type Type of the post for the ID is needed.
	 * @return array
	 */
	private function get_all_published_page_ids( $type ) {
		remove_filter( 'pre_get_posts', array( $this, 'papr_filter_posts' ) );
		$args     = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		);
		$query    = new WP_Query( $args );
		$post_ids = $query->posts;
		add_filter( 'pre_get_posts', array( $this, 'papr_filter_posts' ) );
		wp_reset_postdata();
		return $post_ids;
	}

	function papr_menu() {
		add_menu_page( 'Page and Post Restriction', 'Page Restriction', 'administrator', 'page_restriction', 'papr_page_restriction', plugin_dir_url( __FILE__ ) . 'includes/images/miniorange.png' );
		add_submenu_page( 'page_restriction', 'Custom Roles', 'Roles and Capabilities', 'administrator', 'papr_custom_roles_sub_menu', 'papr_custom_roles_sub_menu' );
		add_submenu_page( 'page_restriction', 'Abilities API', 'Abilities API', 'manage_options', 'papr_abilities_api', 'papr_display_abilities_api_page' );
	}
	function papr_add_plugin_settings( $links ) {
		$links = array_merge(
			array(
				'<a href="' . esc_url( admin_url( 'admin.php?page=page_restriction' ) ) . '">' . __( 'Settings', 'page-and-post-restriction' ) . '</a>',
			),
			$links
		);
		return $links;
	}

	function papr_feedback_request() {
		papr_display_feedback_form();
	}

	function papr_deactivate() {
		wp_safe_redirect( 'plugins.php' );
		delete_option( 'papr_admin_email' );
		delete_option( 'papr_admin_customer_key' );
		delete_option( 'papr_host_name' );
		delete_option( 'papr_new_registration' );
		delete_option( 'papr_admin_phone' );
		delete_option( 'papr_admin_password' );
		delete_option( 'papr_admin_customer_key' );
		delete_option( 'papr_admin_api_key' );
		delete_option( 'papr_customer_token' );
		delete_option( 'papr_message' );
		delete_option( 'papr_allowed_roles_for_pages' );
		delete_option( 'papr_restricted_pages' );
		delete_option( 'papr_allowed_roles_for_posts' );
		delete_option( 'papr_restricted_posts' );
		delete_option( 'papr_allowed_redirect_for_pages' );
		delete_option( 'papr_allowed_redirect_for_posts' );
		delete_option( 'papr_login_unrestricted_pages' );
		delete_option( 'papr_default_role_parent' );
		delete_option( 'papr_login_unrestricted_posts' );
		delete_option( 'papr_page_search_value' );
		delete_option( 'papr_post_search_value' );
		delete_option( 'papr_post_type' );
		delete_option( 'papr_default_role_parent_page_toggle' );
		delete_option( 'papr_access_for_only_loggedin' );
		delete_option( 'papr_access_for_only_loggedin_posts' );
		delete_option( 'papr_results_per_page' );
		delete_option( 'papr_post_per_page' );
		delete_option( 'papr_category_per_page' );
		delete_option( 'papr_guest_enabled' );
		delete_option( 'papr_roles_per_page' );
		delete_option( 'papr_allowed_metabox_roles' );
	}

	static function papr_plugin_settings_script( $page ) {
		if ( $page == 'toplevel_page_page_restriction' || $page == 'page-restriction_page_papr_custom_roles_sub_menu' || $page == 'page-restriction_page_papr_abilities_api' ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-ui-autocomplete' );
			wp_enqueue_script( 'papr_admin_settings_phone_script', plugins_url( 'includes/js/phone.js', __FILE__ ), array(), Papr_Plugin_Constants::VERSION, array( 'in_footer' => false ) );
			wp_enqueue_style( 'papr_admin_bootstrap_settings_script', plugins_url( 'includes/js/bootstrap/bootstrap.min.js', __FILE__ ), array(), Papr_Plugin_Constants::VERSION );
			wp_enqueue_style( 'papr_admin_bootstrap_settings_script', plugins_url( 'includes/js/bootstrap/popper.min.js', __FILE__ ), array(), Papr_Plugin_Constants::VERSION );
			wp_enqueue_style( 'papr_admin_settings_phone_style', plugins_url( 'includes/css/phone.min.css', __FILE__ ), array(), Papr_Plugin_Constants::VERSION );
			wp_enqueue_style( 'papr_admin_bootstrap_settings_style', plugins_url( 'includes/css/bootstrap/bootstrap.min.css', __FILE__ ), array(), Papr_Plugin_Constants::VERSION );
			wp_enqueue_style( 'papr_admin_settings_style', plugins_url( 'includes/css/papr_settings_style.min.css', __FILE__ ), array(), Papr_Plugin_Constants::VERSION, 'all' );
			wp_enqueue_script( 'papr_auto_assign_private_script', plugins_url( 'includes/js/papr-role-assigned.js', __FILE__ ), array(), Papr_Plugin_Constants::VERSION, array( 'in_footer' => false )  );
			wp_enqueue_script( 'papr_roles_show_dropdown_script', plugins_url( 'includes/js/papr-role-dropdown.js', __FILE__ ), array(), Papr_Plugin_Constants::VERSION, array( 'in_footer' => false )  );
			wp_enqueue_style( 'papr_roles_show_dropdown_style', plugins_url('includes/css/papr_role_show_dropdown.min.css',__FILE__ ),array(), Papr_Plugin_Constants::VERSION );
		} elseif ( $page == 'edit.php' ) {
			wp_enqueue_script( 'populatequickedit', plugins_url( 'includes/js/page-restriction-quick-edit.js', __FILE__ ), array( 'jquery' ), Papr_Plugin_Constants::VERSION, array( 'in_footer' => false ) );
		} else {
			return;
		}
	}

	function papr_restrict_content( $attr, $content = '' ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return '<p>' . wp_kses_post( do_shortcode( $content ) ) . '</p>';
	}

	function papr_page_add_column( $column_array ) {
		$column_array['Allowed_Roles'] = 'Roles who have view access';
		$column_array['Private']       = 'Page restrcited from non-logged in users';
		return $column_array;
	}

	function papr_post_add_column( $column_array ) {
		$column_array['Allowed_Roles'] = 'Roles who have view access';
		$column_array['Private']       = 'Post restrcited from non-logged in users';
		return $column_array;
	}

	function papr_page_custom_columns( $column, $post_id ) {
		$allowed_roles = get_option( 'papr_allowed_roles_for_pages' );
		$allowed_roles = $allowed_roles != '' ? $allowed_roles : array();

		$allowed_redirect_pages = get_option( 'papr_allowed_redirect_for_pages' );
		$allowed_redirect_pages = $allowed_redirect_pages != '' ? $allowed_redirect_pages : array();

		switch ( $column ) {
			case 'Allowed_Roles':
				if ( isset( $allowed_roles[ $post_id ] ) ) {
					$len = 0;
					if ( is_countable( $allowed_roles[ $post_id ] ) ) {
						$len = count( $allowed_roles[ $post_id ] );
					}
					foreach ( $allowed_roles[ $post_id ] as $keys => $allowed_role ) {
						echo esc_html( $allowed_role );
						if ( $keys != $len - 1 ) {
							echo ';';
						}
					}
				}
				break;
			case 'Private':
				if ( isset( $allowed_redirect_pages[ $post_id ] ) || ! empty( $allowed_roles[ $post_id ] ) ) {
					echo esc_html__( 'Yes', 'page-and-post-restriction' );
				} else {
					echo esc_html__( 'No', 'page-and-post-restriction' );
				}
			default:
				break;
		}
	}

	function papr_post_custom_columns( $column, $post_id ) {
		$allowed_roles = get_option( 'papr_allowed_roles_for_posts' );
		$allowed_roles = $allowed_roles != '' ? $allowed_roles : array();

		$allowed_redirect_post = get_option( 'papr_allowed_redirect_for_posts' );
		$allowed_redirect_post = $allowed_redirect_post != '' ? $allowed_redirect_post : array();

		switch ( $column ) {
			case 'Allowed_Roles':
				if ( isset( $allowed_roles[ $post_id ] ) ) {
					$len = 0;
					if ( is_countable( $allowed_roles[ $post_id ] ) ) {
						$len = count( $allowed_roles[ $post_id ] );
					}
					foreach ( $allowed_roles[ $post_id ] as $keys => $allowed_role ) {
						echo esc_html( $allowed_role );
						if ( $keys != $len - 1 ) {
							echo ';';
						}
					}
				}
				break;
			case 'Private':
				if ( isset( $allowed_redirect_post[ $post_id ] ) || ! empty( $allowed_roles[ $post_id ] ) ) {
					echo esc_html__( 'Yes', 'page-and-post-restriction' );
				} else {
					echo esc_html__( 'No', 'page-and-post-restriction' );
				}
				break;
			default:
				break;
		}
	}

	function papr_display_custom_quick_edit_fields( $column_name, $post_type ) {

		if ( ! papr_current_user_can_manage_restriction() ) {
			return;
		}

		wp_nonce_field( 'papr_quick_edit_nonce', 'papr_quick_edit_option_nonce' );

		if ( $column_name == 'Allowed_Roles' ) {
			?>
			<fieldset class="inline-edit-col-right inline-edit-book">
				<h3>miniOrange Page and Post Restriction Configuration</h3>
			<?php
		}
		?>
			
			<div class="inline-edit-col column-<?php echo esc_attr( $column_name ); ?>">
				<label class="inline-edit-group">
				<?php
				switch ( $column_name ) {
					case 'Private':
						?>
						<label class="title">Private</label><input name="Private" type="checkbox" />
						<?php
						break;

					case 'Allowed_Roles':
						?>
						<label class="title">Allowed Roles</label><input name="Allowed_Roles" type="text"/>
						<?php
						break;
				}
				?>
				</label>
			</div>
		<?php
		if ( $column_name == 'Private' ) {
			?>
			</fieldset>
			<?php
		}
	}

	function papr_add_custom_meta_box( $post_type ) {

		global $pagenow;

		if ( in_array( $pagenow, array( 'post-new.php' ) ) ) {
			if ( $post_type == 'page' && get_option( 'papr_select_all_pages' ) == 'checked' ) {

				$pages_for_loggedin_users = get_option( 'papr_allowed_redirect_for_pages' );
				$pages_for_loggedin_users = $pages_for_loggedin_users != '' ? $pages_for_loggedin_users : array();

				$pages_for_loggedin_users[ get_the_ID() ] = true;
				update_option( 'papr_allowed_redirect_for_pages', $pages_for_loggedin_users );

			} elseif ( get_option( 'papr_select_all_posts' ) == 'checked' ) {

				$pages_for_loggedin_users = get_option( 'papr_allowed_redirect_for_posts' );
				$pages_for_loggedin_users = $pages_for_loggedin_users != '' ? $pages_for_loggedin_users : array();

				$pages_for_loggedin_users[ get_the_ID() ] = true;
				update_option( 'papr_allowed_redirect_for_posts', $pages_for_loggedin_users );
			}
		}

		if ( ! papr_current_user_can_manage_restriction() ) {
			return;
		}

		$type = get_post_type_object( $post_type );
		add_meta_box( 'demo-meta-box', 'Page Restrict Access', array( $this, 'papr_meta_box' ), $post_type, 'side', 'high', null );
	}

	/*If the user is not logged in then it checks if the page or post are retricted to logged in user only or not. */
	function papr_restrict_logged_in_users( $page_post_id ) {
		$restricted_pages = get_option( 'papr_allowed_redirect_for_pages' );
		$restricted_posts = get_option( 'papr_allowed_redirect_for_posts' );

		$default_login_toggle = get_option( 'papr_access_for_only_loggedin' );
		$unrestricted_pages   = get_option( 'papr_login_unrestricted_pages' );

		$default_login_toggle_posts = get_option( 'papr_access_for_only_loggedin_posts' );
		$unrestricted_posts         = get_option( 'papr_login_unrestricted_posts' );

		$restricted_pages = $restricted_pages != '' ? $restricted_pages : array();
		$restricted_posts = $restricted_posts != '' ? $restricted_posts : array();

		$default_login_toggle = $default_login_toggle != '' ? $default_login_toggle : '';
		$unrestricted_pages   = $unrestricted_pages != '' ? $unrestricted_pages : array();

		$default_login_toggle_posts = $default_login_toggle_posts != '' ? $default_login_toggle_posts : '';
		$unrestricted_posts         = $unrestricted_posts != '' ? $unrestricted_posts : array();

		// Settings when global toggle is all and fe pages/posts has unticked checkbox
		if ( is_page() && ( $default_login_toggle == 1 ) && empty( $unrestricted_pages[ $page_post_id ] ) ) {
			$papr_message_text = 'Oops! You are not authorized to access this';
			wp_die( esc_html( $papr_message_text ) );
		}

		if ( is_single() && ( $default_login_toggle_posts == 1 ) && empty( $unrestricted_posts[ $page_post_id ] ) ) {
			$papr_message_text = 'Oops! You are not authorized to access this';
			wp_die( esc_html( $papr_message_text ) );
		}

		if ( ( is_page() && ! empty( $restricted_pages[ $page_post_id ] ) ) || ( is_front_page() && ( ! empty( $restricted_pages[ get_option( 'page_on_front' ) ] ) || $default_login_toggle == 1 ) ) || ( is_single() && ! empty( $restricted_posts[ $page_post_id ] ) ) ) {
			$papr_message_text = 'Oops! You are not authorized to access this';
			wp_die( esc_html( $papr_message_text ) );
		}

		// Role restriction implies login is required, even if "Require Login" is disabled.
		if ( ( is_page() || is_single() ) && papr_has_role_restriction( $page_post_id ) ) {
			$papr_message_text = 'Oops! You are not authorized to access this';
			wp_die( esc_html( $papr_message_text ) );
		}
		if ( is_front_page() && get_option( 'page_on_front' ) && papr_has_role_restriction( get_option( 'page_on_front' ) ) ) {
			$papr_message_text = 'Oops! You are not authorized to access this';
			wp_die( esc_html( $papr_message_text ) );
		}
	}

	/*If user is logged in then this function checks if the user is restricted to access any page or post*/
	function papr_restrict_by_role( $page_post_id ) {
		$allowed_roles_for_posts = get_option( 'papr_allowed_roles_for_posts' );
		$allowed_roles_for_pages = get_option( 'papr_allowed_roles_for_pages' );
		$restricted_pages        = get_option( 'papr_restricted_pages' );
		$restricted_posts        = get_option( 'papr_restricted_posts' );

		$allowed_roles_for_posts = $allowed_roles_for_posts != '' ? $allowed_roles_for_posts : array();
		$allowed_roles_for_pages = $allowed_roles_for_pages != '' ? $allowed_roles_for_pages : array();
		$restricted_pages        = $restricted_pages != '' ? $restricted_pages : array();
		$restricted_posts        = $restricted_posts != '' ? $restricted_posts : array();

		if ( is_page( $page_post_id ) ) {
			$allowed_roles_for_types = $allowed_roles_for_pages;
		} else {
			$allowed_roles_for_types = $allowed_roles_for_posts;
		}

		if ( ! is_front_page() && empty( $allowed_roles_for_pages['mo_page_0'] ) ) {
			if ( is_page( $page_post_id ) ) {
				if ( is_array( $restricted_pages ) ) {
					if ( ! papr_in_array( $page_post_id, $restricted_pages ) ) {
						if ( $page_post_id !== 1 ) {
							return;
						}
					}
				}
			} else {
				if ( is_array( $restricted_posts ) ) {
					if ( ! papr_in_array( $page_post_id, $restricted_posts ) ) {
						return;
					}
				}
			}
		}

		$current_user = wp_get_current_user();
		$user_roles   = $current_user->roles;

		foreach ( $user_roles as $key => $user_role ) {
			if ( is_front_page() && ! empty( $allowed_roles_for_pages['mo_page_0'] ) ) {
				foreach ( $allowed_roles_for_pages['mo_page_0'] as $keys => $allowed_roles_for_page ) {
					if ( strcasecmp( $allowed_roles_for_page, $user_role ) === 0 ) {
						return;
					}
				}
			} elseif ( ! empty( $allowed_roles_for_types[ $page_post_id ] ) ) {
				if ( is_array( $allowed_roles_for_types ) ) {
					if ( ! empty( $allowed_roles_for_types[ $page_post_id ] ) ) {
						foreach ( $allowed_roles_for_types[ $page_post_id ] as $keys => $allowed_roles_for_type ) {
							if ( strcasecmp( $allowed_roles_for_type, $user_role ) === 0 ) {
								return;
							}
						}
					}
				}
			}
		}

		// default access to all users
		if ( is_array( $allowed_roles_for_pages ) || is_array( $allowed_roles_for_posts ) ) {
			if ( is_page() && ! empty( $allowed_roles_for_pages[ $page_post_id ] ) && ( count( $allowed_roles_for_pages[ $page_post_id ] ) == 0 ) ) {
				return;
			} elseif ( ( is_single() && ! empty( $allowed_roles_for_posts[ $page_post_id ] ) ) || ( is_front_page() && ! empty( $allowed_roles_for_pages['mo_page_0'] ) ) || ( is_page() && ! empty( $allowed_roles_for_pages[ $page_post_id ] ) ) ) {
				$papr_message_text = 'Oops! You are not authorized to access this';
				wp_die( esc_html( $papr_message_text ) );
			}
		}
	}

	function papr_initialize_page_restrict() {
		$page_post_id         = get_the_ID() ? get_the_ID() : 0;
		$guest_user_logged_in = false;

		$type = get_post_type( $page_post_id );

		if ( $type != 'page' && $type != 'post' ) {
			return;
		}

		if ( is_feed() ) {
			if ( $this->papr_is_post_restricted_for_current_user( $page_post_id ) ) {
				$this->papr_send_feed_forbidden_json();
			}
			return;
		}

		if ( ! is_user_logged_in() and ! $guest_user_logged_in ) {
			$this->papr_restrict_logged_in_users( $page_post_id );
		} else {
			$this->papr_restrict_by_role( $page_post_id );
		}
	}

	public static function papr_meta_box( $post ) {
		$type = '';
		if ( is_object( $post ) && property_exists( $post, 'post_type' ) ) {
			$type = $post->post_type;
		}
		if ( $type == 'page' || $type == 'post' ) {
			wp_nonce_field( 'papr_meta_box_nonce', 'papr_meta_box_option_nonce' );
		}
		global $wp_roles;
		$wp_name_roles = ( $wp_roles->role_names );
		asort( $wp_name_roles );
		if ( $type == 'page' ) {
			$role = maybe_unserialize( get_option( 'papr_allowed_roles_for_pages' ) );
			$role = $role != '' ? $role : array();

			$default_login_toggle = maybe_unserialize( get_option( 'papr_access_for_only_loggedin' ) );
			$default_login_toggle = $default_login_toggle != '' ? $default_login_toggle : '';
		} elseif ( $type == 'post' ) {
			$role = maybe_unserialize( get_option( 'papr_allowed_roles_for_posts' ) );
			$role = $role != '' ? $role : array();

			$default_login_toggle = maybe_unserialize( get_option( 'papr_access_for_only_loggedin_posts' ) );
			$default_login_toggle = $default_login_toggle != '' ? $default_login_toggle : '';
		} else {
			$role                 = array();
			$default_login_toggle = '';
		}

		$roles = array();
		if ( ! empty( $role[ $post->ID ] ) ) {
			$roles = $role[ $post->ID ];
		}

		$is_page_restrcited_for_loggedin_users = 'false';

		$disabled = '';

		if ( $type == 'page' ) {
			$pages_for_loggedin_users = maybe_unserialize( get_option( 'papr_allowed_redirect_for_pages' ) );
			$pages_for_loggedin_users = $pages_for_loggedin_users != '' ? $pages_for_loggedin_users : array();
		} elseif ( $type == 'post' ) {
			$pages_for_loggedin_users = maybe_unserialize( get_option( 'papr_allowed_redirect_for_posts' ) );
			$pages_for_loggedin_users = $pages_for_loggedin_users != '' ? $pages_for_loggedin_users : array();
		} else {
			$disabled                 = 'disabled';
			$pages_for_loggedin_users = array();
		}

		if ( ! empty( $pages_for_loggedin_users ) ) {
			if ( ! empty( $pages_for_loggedin_users[ $post->ID ] ) && $pages_for_loggedin_users[ $post->ID ] == 'true' ) {
				$is_page_restrcited_for_loggedin_users = 'true';
			}
		}
		?>
	<div class="row">
		<img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) ); ?>includes/images/miniorange-logo.png" alt="miniOrange Page and Post Restriction" width="35px">
		<h4 style="position:absolute;top:-0.6rem;left:4.2rem;">Page and Post Restriction</h4>
	</div>
	
	<p> 
		<?php
		if ( $type != 'page' && $type != 'post' ) {
			?>
				<p><b>
				This feature is available for Custom Post in Premium version of the Page and Post Restriction plugin.
				</b></p>
				<a href="<?php echo esc_url( Papr_Plugin_Links::PREMIUM_PLANS ); ?>" target="_blank">Premium Plans</a>
			<?php
			echo '</br></br>';
		}
			esc_html_e( 'Limit access to Logged in users.', 'page-and-post-restriction' );
		?>
		</p>
		<div class="page-restrict-loggedin-user-div">
			<input type="hidden" name="papr_metabox" value="true">
			<ul class="page-restrict-loggedin-user">
				<?php
				if ( $is_page_restrcited_for_loggedin_users == 'true' ) {
					$require_login = 'checked';
				} else {
					$require_login = '';
				}
				global $pagenow;
				if ( in_array( $pagenow, array( 'post-new.php' ) ) ) {
					if ( $default_login_toggle == 1 ) {
						$require_login = 'checked';
					}
				}
				// Role restriction implies login is required.
				if ( ! empty( $roles ) ) {
					$require_login = 'checked';
				}
				?>
				<input type="checkbox" id="papr_require_login_toggle" name="restrict_page_access_loggedin_user"<?php echo esc_attr( $require_login ); ?> value="true" <?php echo ( esc_attr( $disabled ) ); ?> />
				Require Login
			</ul>
		</div>

		<hr>
		<p>
			<?php esc_html_e( 'Limit access to this post\'s content to users of the selected roles.', 'page-and-post-restriction' ); ?>
		</p>

		<div class="role-list-wrap">

			<ul class="role-list">

				<?php foreach ( $wp_name_roles as $role => $name ) : ?>
					<li>
						<label>
							<input type="checkbox" name="papr_access_role[]" <?php checked( papr_in_array( $role, $roles ) || papr_in_array( $name, $roles ) ); ?> value="<?php echo esc_attr( $role ); ?>" <?php echo esc_attr( $disabled ); ?> />
							<?php echo esc_html( translate_user_role( $name ) ); ?>
						</label>
					</li>
				<?php endforeach; ?>

			</ul>
		</div>
		<script>
			( function() {
				var loginToggle = document.getElementById( 'papr_require_login_toggle' );
				var roleBoxes   = document.querySelectorAll( 'input[name="papr_access_role[]"]' );
				if ( ! loginToggle || ! roleBoxes.length || loginToggle.disabled ) {
					return;
				}
				// Selecting any role forces Require Login on and locks it.
				function papr_sync_require_login() {
					var anyRoleChecked = Array.prototype.some.call( roleBoxes, function( box ) {
						return box.checked;
					} );
					if ( anyRoleChecked ) {
						loginToggle.checked = true;
					}
					loginToggle.dataset.paprLocked = anyRoleChecked ? '1' : '';
					loginToggle.title = anyRoleChecked ? 'Require Login is mandatory when roles are selected.' : '';
				}
				loginToggle.addEventListener( 'click', function( e ) {
					if ( loginToggle.dataset.paprLocked ) {
						e.preventDefault();
					}
				} );
				Array.prototype.forEach.call( roleBoxes, function( box ) {
					box.addEventListener( 'change', papr_sync_require_login );
				} );
				papr_sync_require_login();
			} )();
		</script>
		<?php
	}

	/* Function to save the meta box details during creation/editing */
	static function papr_save_meta_box_info( $post_id, $post, $update ) {
		if ( ( isset( $_POST['papr_meta_box_option_nonce'] ) && ! check_admin_referer( 'papr_meta_box_nonce', 'papr_meta_box_option_nonce' ) ) ||
			 ( isset( $_POST['papr_quick_edit_option_nonce'] ) && ! check_admin_referer( 'papr_quick_edit_nonce', 'papr_quick_edit_option_nonce' ) ) ||
			 ( ! isset( $_POST['papr_meta_box_option_nonce'] ) && ! isset( $_POST['papr_quick_edit_option_nonce'] ) )
			) {
			return;
			}

		if ( ! papr_current_user_can_manage_restriction() ) {
			return;
		}

		$type = get_post_type( $post );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$default_role_parent = get_option( 'papr_default_role_parent' );
		$default_role_parent = $default_role_parent != '' ? $default_role_parent : array();
		if ( $type == 'page' ) {
			$allowed_roles          = maybe_unserialize( get_option( 'papr_allowed_roles_for_pages' ) );
			$restrictedposts        = get_option( 'papr_restricted_pages' );
			$allowed_redirect_pages = get_option( 'papr_allowed_redirect_for_pages' );
			$unrestricted_pages     = get_option( 'papr_login_unrestricted_pages' );

			$allowed_roles          = $allowed_roles != '' ? $allowed_roles : array();
			$restrictedposts        = $restrictedposts != '' ? $restrictedposts : array();
			$allowed_redirect_pages = $allowed_redirect_pages != '' ? $allowed_redirect_pages : array();
			$unrestricted_pages     = $unrestricted_pages != '' ? $unrestricted_pages : array();

		} elseif ( $type == 'post' ) {
			$allowed_roles          = maybe_unserialize( get_option( 'papr_allowed_roles_for_posts' ) );
			$restrictedposts        = get_option( 'papr_restricted_posts' );
			$allowed_redirect_pages = get_option( 'papr_allowed_redirect_for_posts' );
			$unrestricted_pages     = get_option( 'papr_login_unrestricted_posts' );

			$allowed_roles          = $allowed_roles != '' ? $allowed_roles : array();
			$restrictedposts        = $restrictedposts != '' ? $restrictedposts : array();
			$allowed_redirect_pages = $allowed_redirect_pages != '' ? $allowed_redirect_pages : array();
			$unrestricted_pages     = $unrestricted_pages != '' ? $unrestricted_pages : array();
		} else {
			return;
		}

		$has_roles = false;
		if ( isset( $_POST['papr_access_role'] ) ) {
			$has_roles = true;
			array_push( $restrictedposts, $post_id );
			$new_roles =  papr_get_sanitized_post_option('sanitize_text_field','papr_access_role' );
			foreach ( $new_roles as $value ) {
				$value = sanitize_text_field( $value );
			}
			$allowed_roles[ $post_id ] = $new_roles;
		} elseif ( !empty( $_POST['Allowed_Roles'] ) ) {
			array_push( $restrictedposts, $post_id );
			$new_roles                 = sanitize_text_field( wp_unslash( $_POST['Allowed_Roles'] ) );
			$allowed_roles[ $post_id ] = explode( ';', $new_roles );
		} else {
			$restrictedpostsarray = $restrictedposts;
			if ( is_array( $restrictedpostsarray ) ) {
				while ( ( $i = array_search( $post_id, $restrictedpostsarray ) ) !== false ) {
					unset( $restrictedpostsarray[ $i ] );
					unset( $allowed_roles[ $post_id ] );
				}
			}
			$restrictedposts = $restrictedpostsarray;
		}
		// Role restriction implies login is required.
		if ( $has_roles || isset( $_POST['restrict_page_access_loggedin_user'] ) || !empty( $_POST['Private'] ) ) {
			$allowed_redirect_pages[ $post_id ] = true;
			unset( $unrestricted_pages[ $post_id ] );
		} else {
			unset( $allowed_redirect_pages[ $post_id ] );
			$unrestricted_pages[ $post_id ] = true;
		}

		$parent_id = wp_get_post_parent_id( $post_id );
		if ( $type == 'page' && $parent_id ) {
			if ( ! empty( $default_role_parent[ $parent_id ] ) && $default_role_parent[ $parent_id ] == true ) {
				$restrictedposts = get_option( 'papr_restricted_pages' );
				$restrictedposts = $restrictedposts != '' ? $restrictedposts : array();

				if ( papr_in_array( $parent_id, $restrictedposts ) ) {
					array_push( $restrictedposts, $post_id );
					$allowed_roles             = get_option( 'papr_allowed_roles_for_pages' );
					$allowed_roles             = $allowed_roles != '' ? $allowed_roles : array();
					$role_string               = $allowed_roles[ $parent_id ];
					$parent_allowed_roles      = $role_string;
					$allowed_roles[ $post_id ] = $parent_allowed_roles;
					if ( $allowed_roles[ $post_id ] != '' ) {
						if ( ! papr_in_array( $post_id, $restrictedposts ) ) {
							array_push( $restrictedposts, $post_id );
						}
					} else {
						unset( $restrictedposts[ $post_id ] );
					}
				}
				if ( papr_in_array( $parent_id, $allowed_redirect_pages ) ) {
					$allowed_redirect_pages[ $post_id ] = true;
					unset( $unrestricted_pages[ $post_id ] );
				} else {
					unset( $allowed_redirect_pages[ $post_id ] );
					$unrestricted_pages[ $post_id ] = true;
				}
			}
		}

		if ( $type == 'page' ) {
			$default_role_toggle = get_option( 'papr_default_role_parent_page_toggle' );
			$default_role_toggle = $default_role_toggle != '' ? $default_role_toggle : '';

			if ( ! empty( $default_role_parent[ $post_id ] ) || $default_role_toggle == 1 ) {
				if ( $post_id != 0 ) {
					$default_role_parent[ $post_id ] = true;

					$children = get_pages( array( 'child_of' => $post_id ) );

					if ( count( $children ) > 0 ) {

						foreach ( $children as $child ) {
							$child_id = $child->ID;

							$allowed_roles[ $child->ID ] = $allowed_roles[ $post_id ];

							if ( $allowed_roles[ $child->ID ] != '' ) {
								if ( ! papr_in_array( $child->ID, $restrictedposts ) ) {
									array_push( $restrictedposts, $child->ID );
								}
							} else {
								unset( $restrictedposts[ $child->ID ] );
							}

							if ( ! empty( $allowed_redirect_pages[ $post_id ] ) ) {
								if ( $allowed_redirect_pages[ $post_id ] == 1 || $allowed_redirect_pages[ $post_id ] == 'on' || $allowed_redirect_pages[ $post_id ] == 'true' ) {
									$allowed_redirect_pages[ $child->ID ] = true;
									unset( $unrestricted_pages[ $child->ID ] );
								}
							} else {
								unset( $allowed_redirect_pages[ $child->ID ] );
								$unrestricted_pages[ $child->ID ] = true;
							}

							$children_of_children = get_pages( array( 'child_of' => $child->ID ) );

							if ( count( $children_of_children ) > 0 ) {
								$default_role_parent[ $child->ID ] = true;
							}
						}
					}
				}
			}
		}

		if ( $type == 'page' ) {
			update_option( 'papr_default_role_parent', $default_role_parent );
			update_option( 'papr_login_unrestricted_pages', $unrestricted_pages );
			update_option( 'papr_restricted_pages', $restrictedposts );
			update_option( 'papr_allowed_roles_for_pages', $allowed_roles );
			update_option( 'papr_allowed_redirect_for_pages', $allowed_redirect_pages );
		} else {
			update_option( 'papr_restricted_posts', $restrictedposts );
			update_option( 'papr_allowed_roles_for_posts', $allowed_roles );
			update_option( 'papr_allowed_redirect_for_posts', $allowed_redirect_pages );
			update_option( 'papr_login_unrestricted_posts', $unrestricted_pages );
		}
	}
}
new papr_page_and_post_restriction();
?>