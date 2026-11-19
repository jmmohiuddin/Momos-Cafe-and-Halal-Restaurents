<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * Language prefix routing without a multilingual plugin (IMPL-002).
 *
 * The language prefix is detected and stripped before WordPress matches rewrite rules, then the
 * original REQUEST_URI is restored so canonical redirects and links work with prefixes. Links to
 * posts, pages and terms produced on the front end carry the current language prefix.
 */
final class Router {
	private static ?string $original_uri   = null;
	private static bool $disabled_language = false;

	public static function register(): void {
		// Detect early enough for REST and front end; keep cheap.
		add_action( 'plugins_loaded', [ self::class, 'detect' ], 0 );
		add_filter( 'do_parse_request', [ self::class, 'strip_for_parsing' ], 1 );
		add_action( 'parse_request', [ self::class, 'restore_uri' ], 0 );
		add_action( 'template_redirect', [ self::class, 'guard_disabled_language' ], 0 );

		foreach ( [ 'post_link', 'page_link', 'post_type_link', 'term_link', 'post_type_archive_link' ] as $hook ) {
			add_filter( $hook, [ self::class, 'filter_link' ], 50 );
		}
		add_filter( 'redirect_canonical', [ self::class, 'redirect_canonical' ], 10, 2 );
		add_filter( 'determine_locale', [ self::class, 'determine_locale' ], 50 );
		add_filter( 'language_attributes', [ self::class, 'language_attributes' ], 50 );
		add_action( 'wp_loaded', [ self::class, 'apply_direction' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'rest_language' ], 1, 3 );
	}

	public static function original_uri(): string {
		if ( null !== self::$original_uri ) {
			return self::$original_uri;
		}
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	}

	public static function is_front_request(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || wp_doing_cron() ) {
			return false;
		}
		$uri = self::original_uri();
		return ! str_contains( $uri, '/wp-json/' ) && ! str_contains( $uri, 'wp-login.php' ) && ! str_contains( $uri, '/wp-admin' );
	}

	public static function detect(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::$original_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( is_admin() ) {
			return;
		}
		$path = (string) wp_parse_url( self::$original_uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( Url::raw_home(), PHP_URL_PATH );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$lang = Url::lang_of_path( '' === $path ? '/' : $path );
		Locale::set( $lang );
		if ( Lang::SOURCE !== $lang && ! Lang::is_enabled( $lang ) ) {
			self::$disabled_language = true;
		}
	}

	/** @param bool $do */
	public static function strip_for_parsing( $do ) {
		$uri  = self::original_uri();
		$base = (string) wp_parse_url( Url::raw_home(), PHP_URL_PATH );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$rel  = ( '' !== $base && str_starts_with( $path, $base ) ) ? substr( $path, strlen( $base ) ) : $path;
		$lang = Url::lang_of_path( '' === $rel ? '/' : $rel );
		if ( Lang::SOURCE !== $lang ) {
			$prefix = '/' . Lang::ALL[ $lang ]['prefix'];
			// "/en" without trailing slash → "/en/".
			if ( $rel === $prefix ) {
				wp_safe_redirect( Url::to( '/', $lang ), 301 );
				exit;
			}
			$_SERVER['REQUEST_URI'] = $base . substr( $rel, strlen( $prefix ) ) . ( str_contains( $uri, '?' ) ? '?' . explode( '?', $uri, 2 )[1] : '' );
			// PHP's built-in server (and some CGI setups) also expose the path in PATH_INFO, which WP::parse_request prefers.
			foreach ( [ 'PATH_INFO', 'ORIG_PATH_INFO' ] as $k ) {
				if ( isset( $_SERVER[ $k ] ) && str_starts_with( (string) $_SERVER[ $k ], $prefix . '/' ) ) {
					$_SERVER[ $k ] = substr( (string) $_SERVER[ $k ], strlen( $prefix ) );
				} elseif ( isset( $_SERVER[ $k ] ) && (string) $_SERVER[ $k ] === $prefix ) {
					$_SERVER[ $k ] = '/';
				}
			}
		}
		return $do;
	}

	public static function restore_uri(): void {
		if ( null !== self::$original_uri ) {
			$_SERVER['REQUEST_URI'] = self::$original_uri;
		}
	}

	public static function guard_disabled_language(): void {
		if ( self::$disabled_language && ! current_user_can( 'momo_preview_languages' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function filter_link( string $url ): string {
		if ( ! self::is_front_request() && ! defined( 'MOMOS_FORCE_LINK_LANG' ) ) {
			return $url;
		}
		return Url::localize( $url, Locale::current() );
	}

	/** @param string|false $redirect */
	public static function redirect_canonical( $redirect, string $requested ) {
		if ( false === $redirect ) {
			return false;
		}
		// Never let core drop or change the language prefix.
		$req_lang = Url::lang_of_path( (string) wp_parse_url( $requested, PHP_URL_PATH ) );
		return Url::localize( (string) $redirect, $req_lang );
	}

	public static function determine_locale( string $locale ): string {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $locale;
		}
		if ( self::is_front_request() ) {
			return Lang::locale( Locale::current() );
		}
		return $locale;
	}

	public static function language_attributes( string $output ): string {
		if ( is_admin() ) {
			return $output;
		}
		$lang = Locale::current();
		return sprintf( 'lang="%s" dir="%s"', esc_attr( Lang::ALL[ $lang ]['html'] ), Lang::is_rtl( $lang ) ? 'rtl' : 'ltr' );
	}

	public static function apply_direction(): void {
		global $wp_locale;
		if ( self::is_front_request() && $wp_locale instanceof \WP_Locale ) {
			$wp_locale->text_direction = Lang::is_rtl( Locale::current() ) ? 'rtl' : 'ltr';
		}
	}

	/**
	 * REST: `lang` query parameter selects the language for messages and translated fields.
	 *
	 * @param mixed $result
	 */
	public static function rest_language( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		$lang = $request->get_param( 'lang' );
		if ( is_string( $lang ) && Lang::is_valid( $lang ) ) {
			Locale::set( $lang );
		}
		return $result;
	}
}
