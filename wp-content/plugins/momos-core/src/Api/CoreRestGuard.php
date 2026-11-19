<?php
declare(strict_types=1);

namespace Momos\Api;

/**
 * Core REST hardening (TRD §4.2, SEC-023, SEC-041):
 *  - `/wp/v2/momo_*` routes are GET-only for everyone: any other method → 405 method_not_allowed, so
 *    field guards of the editors cannot be bypassed through core REST.
 *  - `/wp/v2/users*` is not available to the public (401) and needs `list_users` for logged-in users,
 *    except `/users/me` and the editor's author lookup (`who=authors`) for users who can edit posts.
 *  - Application Passwords are disabled in Phase 1.
 * Route patterns are case-insensitive because WP_REST_Server matches routes with `@^…$@i`
 * (`/wp/v2/USERS` reaches the users controller; QA-021, TC-SEC-003).
 */
final class CoreRestGuard {
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', [ self::class, 'pre_dispatch' ], 5, 3 );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function pre_dispatch( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( null !== $result ) {
			return $result;
		}
		$route  = untrailingslashit( $request->get_route() );
		$method = strtoupper( $request->get_method() );
		if ( preg_match( '#^/wp/v2/momo_[a-z_]+(/|$)#i', $route . '/' ) && ! in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
			return new \WP_Error( 'method_not_allowed', __( 'This resource is read-only.', 'momos-core' ), [ 'status' => 405 ] );
		}
		if ( preg_match( '#^/wp/v2/users(/|$)#i', $route . '/' ) ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'Please sign in.', 'momos-core' ), [ 'status' => 401 ] );
			}
			$is_me   = (bool) preg_match( '#^/wp/v2/users/me$#i', $route );
			$authors = 'GET' === $method && 'authors' === $request->get_param( 'who' ) && current_user_can( 'edit_posts' );
			if ( ! $is_me && ! $authors && ! current_user_can( 'list_users' ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to do this.', 'momos-core' ), [ 'status' => 403 ] );
			}
		}
		return $result;
	}
}
