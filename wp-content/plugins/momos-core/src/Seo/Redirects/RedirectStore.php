<?php
declare(strict_types=1);

namespace Momos\Seo\Redirects;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\I18n\Url;

/**
 * Redirect map (F-SEO-03, FR-SEO-008(a), DEC-039; i18n-seo-analytics.md §3.3, §11). Option
 * `momos_redirects` (not autoloaded) = list of
 * `{id, from, to, status: 301|302|410, owner, verified, source: legacy|manual|auto, created_at, updated_at}`.
 *
 * Rules (validated on every write, also on CSV import):
 *  - exact path matching only — no regular expressions; `from` is a site path (optional query), stored
 *    lower-case without trailing slash; `to` is a site path or an absolute URL of this site;
 *  - 410 has no target; 301/302 need one;
 *  - refused: target = source, target that is itself a source (chain) or that leads back (loop),
 *    targets on another host, system paths (`/wp-admin`, `/wp-json`, `wp-login.php`, sitemaps, robots),
 *    personal data in either URL (`email=`, `phone=`, `name=`, `token=`, `t=`, `ref=`, `@`).
 */
final class RedirectStore {
	public const OPTION   = 'momos_redirects';
	public const STATUSES = [ 301, 302, 410 ];
	public const SOURCES  = [ 'legacy', 'manual', 'auto' ];
	public const LOCK     = 'momos_redirects';
	public const MAX      = 5000;

	/** Query keys that can carry personal data (never stored in a redirect rule). */
	private const PII_KEYS = [ 'email', 'mail', 'phone', 'tel', 'name', 'token', 't', 'ref', 'reference' ];

	/** @var list<array<string,mixed>>|null */
	private static ?array $rows = null;

	public static function flush(): void {
		self::$rows = null;
	}

	/** @return list<array<string,mixed>> */
	public static function all(): array {
		if ( null === self::$rows ) {
			$raw        = get_option( self::OPTION, [] );
			self::$rows = is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
		}
		return self::$rows;
	}

	/** @return array<string,mixed>|null */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $r ) {
			if ( (string) $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null rule matching a normalised request key */
	public static function match( string $path, string $query = '' ): ?array {
		$with_query = '' !== $query ? self::key( $path . '?' . $query ) : '';
		$plain      = self::key( $path );
		$hit        = null;
		foreach ( self::all() as $r ) {
			if ( '' !== $with_query && $r['from'] === $with_query ) {
				return $r;
			}
			if ( null === $hit && $r['from'] === $plain ) {
				$hit = $r;
			}
		}
		return $hit;
	}

	/**
	 * Normalised comparison key of a source: path only (host dropped), lower-case, no trailing slash
	 * (except "/"), query kept as given (lower-cased) when present.
	 */
	public static function key( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$path = '/' . ltrim( rawurldecode( (string) ( $parts['path'] ?? '/' ) ), '/' );
		$path = strtolower( $path );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . strtolower( (string) $parts['query'] ) : '';
		return $path . $query;
	}

	/** Absolute target URL (relative site paths are made absolute; '' for 410). */
	public static function target_url( array $rule ): string {
		$to = (string) $rule['to'];
		if ( '' === $to ) {
			return '';
		}
		return str_starts_with( $to, '/' ) ? Url::raw_home() . $to : $to;
	}

	/**
	 * Validate a rule against the stored map (excluding $id when editing).
	 *
	 * @param array{from?:mixed,to?:mixed,status?:mixed,owner?:mixed,verified?:mixed,source?:mixed} $in
	 * @return array{rule:array<string,mixed>,errors:array<string,string>}
	 */
	public static function validate( array $in, string $id = '', ?array $rows = null ): array {
		$rows   = $rows ?? self::all();
		$errors = [];
		$status = (int) ( $in['status'] ?? 301 );
		$from   = self::key( sanitize_text_field( (string) ( $in['from'] ?? '' ) ) );
		$to_raw = trim( sanitize_text_field( (string) ( $in['to'] ?? '' ) ) );
		$owner  = mb_substr( sanitize_text_field( (string) ( $in['owner'] ?? '' ) ), 0, 100 );
		$source = isset( $in['source'] ) && in_array( $in['source'], self::SOURCES, true ) ? (string) $in['source'] : 'manual';

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$errors['status'] = __( 'Choose 301, 302 or 410.', 'momos-core' );
		}
		$raw_from = trim( (string) ( $in['from'] ?? '' ) );
		if ( '' === $raw_from || '' === $from || ( preg_match( '#^https?://#i', $raw_from ) && ! self::same_host( $raw_from ) ) ) {
			$errors['from'] = __( 'Enter the old path of this site, e.g. /old-page/.', 'momos-core' );
		} elseif ( '/' === $from ) {
			$errors['from'] = __( 'The home page cannot be redirected.', 'momos-core' );
		} elseif ( self::is_system( $from ) ) {
			$errors['from'] = __( 'System URLs cannot be redirected.', 'momos-core' );
		} elseif ( self::has_pii( $raw_from ) ) {
			$errors['from'] = __( 'Personal data is not allowed in redirect URLs.', 'momos-core' );
		} elseif ( mb_strlen( $from ) > 500 ) {
			$errors['from'] = __( 'The path is too long.', 'momos-core' );
		}

		$to = '';
		if ( 410 === $status ) {
			$to = '';
		} elseif ( '' === $to_raw ) {
			$errors['to'] = __( 'Enter the new URL.', 'momos-core' );
		} elseif ( preg_match( '#^https?://#i', $to_raw ) ) {
			if ( ! self::same_host( $to_raw ) ) {
				$errors['to'] = __( 'The target must be a page of this site.', 'momos-core' );
			} else {
				$to = esc_url_raw( $to_raw );
			}
		} elseif ( str_starts_with( $to_raw, '/' ) && ! str_starts_with( $to_raw, '//' ) ) {
			$to = esc_url_raw( Url::raw_home() . $to_raw );
			$to = substr( $to, strlen( Url::raw_home() ) );
		} else {
			$errors['to'] = __( 'The target must start with / or be a full URL of this site.', 'momos-core' );
		}
		if ( '' !== $to && ! isset( $errors['to'] ) ) {
			$to_key = self::key( $to );
			if ( self::has_pii( $to ) ) {
				$errors['to'] = __( 'Personal data is not allowed in redirect URLs.', 'momos-core' );
			} elseif ( $to_key === $from || self::key( (string) wp_parse_url( $to, PHP_URL_PATH ) ) === self::key( (string) wp_parse_url( $from, PHP_URL_PATH ) ) ) {
				$errors['to'] = __( 'The target is the same as the source.', 'momos-core' );
			} elseif ( self::is_system( $to_key ) ) {
				$errors['to'] = __( 'System URLs cannot be a target.', 'momos-core' );
			}
		}
		if ( ! $errors ) {
			$others = array_values( array_filter( $rows, static fn( $r ) => (string) $r['id'] !== $id ) );
			foreach ( $others as $r ) {
				if ( $r['from'] === $from ) {
					$errors['from'] = __( 'A redirect for this path already exists.', 'momos-core' );
					break;
				}
			}
			if ( ! isset( $errors['from'] ) && '' !== $to ) {
				$to_key = self::key( $to );
				foreach ( $others as $r ) {
					if ( $r['from'] === $to_key || $r['from'] === self::key( (string) wp_parse_url( $to, PHP_URL_PATH ) ) ) {
						$errors['to'] = __( 'The target is itself redirected (chain). Point to the final URL instead.', 'momos-core' );
						break;
					}
					if ( '' !== (string) $r['to'] && self::key( (string) $r['to'] ) === $from ) {
						// An existing rule points at this source: the new rule would extend a chain or close a loop.
						$errors['from'] = self::key( (string) $r['from'] ) === $to_key
							? __( 'This redirect would create a loop.', 'momos-core' )
							: __( 'Another redirect points to this path (chain). Update that redirect instead.', 'momos-core' );
						break;
					}
				}
			}
		}
		$now  = Clock::mysql_utc();
		$rule = [
			'id'         => '' !== $id ? $id : substr( md5( $from . '|' . wp_generate_uuid4() ), 0, 12 ),
			'from'       => $from,
			'to'         => $to,
			'status'     => $status,
			'owner'      => $owner,
			'verified'   => ! empty( $in['verified'] ),
			'source'     => $source,
			'created_at' => $now,
			'updated_at' => $now,
		];
		return [ 'rule' => $rule, 'errors' => $errors ];
	}

	/**
	 * Create or update a rule (validated, under a named lock). Returns errors or the stored rule.
	 *
	 * @param array<string,mixed> $in
	 * @return array{rule:?array<string,mixed>,errors:array<string,string>,before:?array<string,mixed>}
	 */
	public static function save( array $in, string $id = '' ): array {
		if ( ! NamedLock::acquire( self::LOCK, 5 ) ) {
			return [ 'rule' => null, 'errors' => [ 'form' => __( 'Another change is being saved. Please try again.', 'momos-core' ) ], 'before' => null ];
		}
		try {
			self::flush();
			$rows   = self::all();
			$before = '' !== $id ? self::get( $id ) : null;
			if ( '' !== $id && null === $before ) {
				return [ 'rule' => null, 'errors' => [ 'form' => __( 'This redirect no longer exists.', 'momos-core' ) ], 'before' => null ];
			}
			if ( '' === $id && count( $rows ) >= self::MAX ) {
				return [ 'rule' => null, 'errors' => [ 'form' => __( 'The redirect list is full.', 'momos-core' ) ], 'before' => null ];
			}
			$v = self::validate( $in, $id, $rows );
			if ( $v['errors'] ) {
				return [ 'rule' => null, 'errors' => $v['errors'], 'before' => $before ];
			}
			$rule = $v['rule'];
			if ( null !== $before ) {
				$rule['created_at'] = $before['created_at'];
				$rule['source']     = $before['source'];
				$rows               = array_map( static fn( $r ) => (string) $r['id'] === $id ? $rule : $r, $rows );
			} else {
				$rows[] = $rule;
			}
			update_option( self::OPTION, array_values( $rows ), false );
			self::flush();
			return [ 'rule' => $rule, 'errors' => [], 'before' => $before ];
		} finally {
			NamedLock::release( self::LOCK );
		}
	}

	/** @return array<string,mixed>|null deleted rule */
	public static function delete( string $id ): ?array {
		if ( ! NamedLock::acquire( self::LOCK, 5 ) ) {
			return null;
		}
		try {
			self::flush();
			$rule = self::get( $id );
			if ( null === $rule ) {
				return null;
			}
			update_option( self::OPTION, array_values( array_filter( self::all(), static fn( $r ) => (string) $r['id'] !== $id ) ), false );
			self::flush();
			return $rule;
		} finally {
			NamedLock::release( self::LOCK );
		}
	}

	/** Mark rules verified/unverified after HEAD checks. @param array<string,bool> $results id => ok */
	public static function set_verified( array $results ): void {
		if ( ! NamedLock::acquire( self::LOCK, 5 ) ) {
			return;
		}
		try {
			self::flush();
			$rows = array_map(
				static function ( $r ) use ( $results ) {
					if ( array_key_exists( (string) $r['id'], $results ) ) {
						$r['verified'] = (bool) $results[ (string) $r['id'] ];
					}
					return $r;
				},
				self::all()
			);
			update_option( self::OPTION, $rows, false );
			self::flush();
		} finally {
			NamedLock::release( self::LOCK );
		}
	}

	/** Audit payload of a rule (paths only — never personal data, validated above). */
	public static function audit_view( ?array $r ): array {
		return null === $r ? [] : [ 'from' => $r['from'], 'to' => $r['to'], 'status' => $r['status'], 'source' => $r['source'] ];
	}

	public static function same_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( Url::raw_home(), PHP_URL_HOST ) );
		return '' !== $host && ( $host === $home || 'www.' . $host === $home || $host === 'www.' . $home );
	}

	public static function is_system( string $key ): bool {
		$path = (string) strtok( $key, '?' );
		foreach ( [ '/wp-admin', '/wp-json', '/wp-login.php', '/wp-content', '/wp-includes', '/xmlrpc.php', '/sitemap.xml', '/sitemaps/', '/robots.txt' ] as $sys ) {
			if ( $path === rtrim( $sys, '/' ) || str_starts_with( $path, rtrim( $sys, '/' ) . '/' ) || str_starts_with( $path, $sys ) ) {
				return true;
			}
		}
		return false;
	}

	public static function has_pii( string $url ): bool {
		if ( str_contains( rawurldecode( $url ), '@' ) ) {
			return true;
		}
		$q = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $q ) {
			return false;
		}
		parse_str( $q, $args );
		foreach ( array_keys( $args ) as $k ) {
			if ( in_array( strtolower( (string) $k ), self::PII_KEYS, true ) ) {
				return true;
			}
		}
		return false;
	}
}
