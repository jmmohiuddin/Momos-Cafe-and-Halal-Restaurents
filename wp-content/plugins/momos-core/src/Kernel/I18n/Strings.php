<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

use Momos\Kernel\Clock\Clock;

/**
 * Key-based UI string catalog for customer-facing text and emails (Doc 07 §6 key register).
 *
 * Defaults: languages/strings/{lang}/{module}.php (array key => string). Reviewed overrides edited by
 * translators in SCR-A11 are stored in option `momos_strings_overrides` [lang][key].
 * Placeholders use {name}. A key missing in a target language falls back to English and is
 * reported in the translation gaps view (UI chrome only; content never falls back — DEC-027).
 *
 * Catalog precedence (content-strings.md §2.1): files are read in sorted order. A key must be defined
 * by exactly one module file; when two files define it anyway, the owning module (OWNERS, longest
 * prefix) wins, otherwise the later file in sorted order wins. Every such collision is reported by
 * duplicates() and fails the PHPUnit catalog test.
 *
 * Review status per key and language (content-strings.md §2.1 [Prop], i18n-seo-analytics.md §6.2):
 * option `momos_strings_status` [lang][key] => {status, source, by, at, hash, src, translated_by}.
 * Catalog seeds start `draft`; `approved` stamps the hash of the reviewed value (`hash`) and of the
 * reference text (`src`). A later change of the value turns the key back to `needs_review`; a change of
 * the reference text marks the approval outdated. Read with status() / is_approved(); filter
 * `momos/strings/status` (string $status, string $key, string $lang).
 */
final class Strings {
	public const STATUS_OPTION = 'momos_strings_status';
	public const STATUSES      = [ 'draft', 'needs_review', 'approved' ];
	public const SOURCES       = [ 'seed', 'human', 'mt' ];

	/**
	 * Owning module of shared key prefixes (duplicate resolution only). Longest prefix wins.
	 *
	 * @var array<string,string>
	 */
	public const OWNERS = [
		'res.'                             => 'reservation',
		'status.'                          => 'reservation',
		'decline.'                         => 'reservation',
		'cancel.'                          => 'notification',
		'email.'                           => 'notification',
		'staff.err.'                       => 'access',
		'staff.warn.customer_not_notified' => 'notification',
		'staff.warn.needs_action_closure'  => 'reservation',
		'price.'                           => 'menu',
		'media.'                           => 'menu',
		'cta.directions'                   => 'branch',
		'nav.'                             => 'presentation',
		'ftr.'                             => 'presentation',
		'bc.'                              => 'presentation',
		'lang.'                            => 'presentation',
		'skip.'                            => 'presentation',
		'404.'                             => 'presentation',
		'admin.legal.'                     => 'admin-content',
		'admin.translation.'               => 'admin-content',
		'admin.media.'                     => 'admin-content',
	];

	/** Prefixes of staff/admin-only keys (ja/en admin, DEC-037) — not customer-facing. */
	public const ADMIN_PREFIXES = [ 'staff.', 'admin.' ];

	/**
	 * Launch-critical key prefixes (Doc 06 §4.6 LCCS, content-strings.md §2.2). Language enable still
	 * requires every customer-facing key approved (content-strings §3.2); LCCS marks priority in A11.
	 */
	public const LCCS_PREFIXES = [
		'nav.', 'bc.', 'cta.', 'ftr.', 'skip.', 'lang.', 'price.', '404.', 'media.', 'common.', 'a11y.',
		'branch.', 'closure_reason.', 'facility.', 'availability_reason.', 'period.', 'menu.', 'spice.', 'diet.',
		'res.', 'status.', 'decline.', 'cancel.', 'notice.', 'consent.', 'legal.', 'email.',
		'halal.', 'alcohol.', 'allergen.', 'ingredients.', 'last_verified', 'home.', 'maintenance.', 'anl.',
	];

	/** Review-class prefixes: reviewer must differ from the translator (i18n-seo-analytics §6.2). */
	public const REVIEW_CLASS_PREFIXES = [ 'res.', 'status.', 'decline.', 'cancel.', 'email.', 'legal.', 'notice.', 'consent.', 'halal.', 'alcohol.', 'allergen.', 'ingredients.', 'last_verified' ];

	/** @var array<string, array<string,string>> */
	private static array $catalog = [];

	/** @var array<string, array<string,string>> lang => key => module file (basename without .php) */
	private static array $origin = [];

	/** @var array<string, array<string, list<string>>> lang => key => modules defining it (only when > 1) */
	private static array $duplicates = [];

	/** @var array<string, array<string,string>> lang => key => value as shipped in the module files */
	private static array $files = [];

	/** @var array<string, array<string,string>>|null */
	private static ?array $overrides = null;

	/** @var array<string, array<string, array<string,mixed>>>|null */
	private static ?array $status = null;

	/** @param array<string, string|int|float> $args */
	public static function t( string $key, array $args = [], ?string $lang = null ): string {
		$lang  = $lang ?? Locale::current();
		$value = self::raw( $key, $lang );
		if ( null === $value && 'en' !== $lang ) {
			$value = self::raw( $key, 'en' );
		}
		if ( null === $value ) {
			$value = $key;
		}
		if ( $args ) {
			$replace = [];
			foreach ( $args as $k => $v ) {
				$replace[ '{' . $k . '}' ] = (string) $v;
			}
			$value = strtr( $value, $replace );
		}
		return $value;
	}

	public static function raw( string $key, string $lang ): ?string {
		$over = self::overrides()[ $lang ][ $key ] ?? null;
		if ( null !== $over && '' !== $over ) {
			return $over;
		}
		$cat = self::catalog( $lang );
		return $cat[ $key ] ?? null;
	}

	public static function has( string $key, string $lang ): bool {
		return null !== self::raw( $key, $lang );
	}

	/** @return array<string,string> */
	public static function catalog( string $lang ): array {
		if ( ! isset( self::$catalog[ $lang ] ) ) {
			self::load( $lang );
			/** Allow theme/modules to add keys. */
			self::$catalog[ $lang ] = (array) apply_filters( 'momos/strings/catalog', self::$files[ $lang ], $lang );
		}
		return self::$catalog[ $lang ];
	}

	/** Read the module files of a language with explicit precedence and duplicate detection. */
	private static function load( string $lang ): void {
		$data   = [];
		$origin = [];
		$dups   = [];
		$files  = Lang::is_valid( $lang ) ? (array) glob( MOMOS_CORE_DIR . '/languages/strings/' . $lang . '/*.php' ) : [];
		sort( $files, SORT_STRING );
		foreach ( $files as $file ) {
			$module = basename( (string) $file, '.php' );
			$part   = include $file;
			if ( ! is_array( $part ) ) {
				continue;
			}
			foreach ( $part as $key => $value ) {
				$key = (string) $key;
				if ( ! is_string( $value ) ) {
					continue;
				}
				if ( isset( $origin[ $key ] ) ) {
					$dups[ $key ] = array_values( array_unique( array_merge( $dups[ $key ] ?? [ $origin[ $key ] ], [ $module ] ) ) );
					$owner        = self::owner( $key );
					if ( null !== $owner && $origin[ $key ] === $owner ) {
						continue; // The owning module's value stays.
					}
				}
				$data[ $key ]   = $value;
				$origin[ $key ] = $module;
			}
		}
		self::$files[ $lang ]      = $data;
		self::$origin[ $lang ]     = $origin;
		self::$duplicates[ $lang ] = $dups;
	}

	/** Owning module of a key per OWNERS (longest matching prefix), or null. */
	public static function owner( string $key ): ?string {
		$best = null;
		$len  = 0;
		foreach ( self::OWNERS as $prefix => $module ) {
			if ( str_starts_with( $key, $prefix ) && strlen( $prefix ) > $len ) {
				$best = $module;
				$len  = strlen( $prefix );
			}
		}
		return $best;
	}

	/** Module file (group) that defines a key in a language, or '' (filter-added / unknown). */
	public static function group_of( string $key, string $lang = 'en' ): string {
		self::catalog( $lang );
		return self::$origin[ $lang ][ $key ] ?? ( self::$origin['en'][ $key ] ?? '' );
	}

	/**
	 * Keys defined by more than one module file.
	 *
	 * @return array<string, array<string, list<string>>> lang => key => modules
	 */
	public static function duplicates( ?string $lang = null ): array {
		$out = [];
		foreach ( null === $lang ? Lang::codes() : [ $lang ] as $l ) {
			self::catalog( $l );
			if ( ! empty( self::$duplicates[ $l ] ) ) {
				$out[ $l ] = self::$duplicates[ $l ];
			}
		}
		return $out;
	}

	/** @return array<string, array<string,string>> */
	public static function overrides(): array {
		if ( null === self::$overrides ) {
			$o               = get_option( 'momos_strings_overrides', [] );
			self::$overrides = is_array( $o ) ? $o : [];
		}
		return self::$overrides;
	}

	public static function save_override( string $lang, string $key, string $value ): void {
		$o                  = self::overrides();
		$o[ $lang ][ $key ] = sanitize_textarea_field( $value );
		if ( '' === $o[ $lang ][ $key ] ) {
			unset( $o[ $lang ][ $key ] );
		}
		update_option( 'momos_strings_overrides', $o, true );
		self::$overrides = $o;
	}

	/** Value shipped in the module files (before overrides), or null. */
	public static function file_value( string $key, string $lang ): ?string {
		self::catalog( $lang );
		return self::$files[ $lang ][ $key ] ?? null;
	}

	/** Keys present in the English catalog but missing in $lang. @return list<string> */
	public static function missing_keys( string $lang ): array {
		$out = [];
		foreach ( array_keys( self::catalog( 'en' ) ) as $key ) {
			if ( null === self::raw( $key, $lang ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** All known keys (union of the en and ja catalogs), sorted. @return list<string> */
	public static function keys(): array {
		$keys = array_unique( array_merge( array_keys( self::catalog( 'en' ) ), array_keys( self::catalog( Lang::SOURCE ) ) ) );
		sort( $keys, SORT_STRING );
		return array_values( array_map( 'strval', $keys ) );
	}

	public static function is_customer_key( string $key ): bool {
		foreach ( self::ADMIN_PREFIXES as $p ) {
			if ( str_starts_with( $key, $p ) ) {
				return false;
			}
		}
		return true;
	}

	public static function is_lccs_key( string $key ): bool {
		if ( ! self::is_customer_key( $key ) ) {
			return false;
		}
		foreach ( self::LCCS_PREFIXES as $p ) {
			if ( str_starts_with( $key, $p ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_review_class( string $key ): bool {
		foreach ( self::REVIEW_CLASS_PREFIXES as $p ) {
			if ( str_starts_with( $key, $p ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- placeholders

	/** Placeholder names used in a value, sorted and unique. @return list<string> */
	public static function placeholders( string $value ): array {
		preg_match_all( '/\{([a-z0-9_]+)\}/i', $value, $m );
		$names = array_values( array_unique( $m[1] ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Reference text a translation is checked against: the English file/override value; for English the
	 * Japanese value. Null when the key is unknown.
	 */
	public static function reference( string $key, string $lang ): ?string {
		if ( 'en' === $lang ) {
			return self::raw( $key, Lang::SOURCE ) ?? self::raw( $key, 'en' );
		}
		return self::raw( $key, 'en' ) ?? self::raw( $key, Lang::SOURCE );
	}

	/**
	 * Placeholder-set check (content-strings.md §2.1): a translation must contain exactly the placeholders
	 * of the EN source. Returns [missing, extra]; both empty = valid.
	 *
	 * @return array{0:list<string>,1:list<string>}
	 */
	public static function placeholder_diff( string $key, string $value, string $lang ): array {
		$ref = (string) ( self::file_value( $key, 'en' ) ?? self::reference( $key, $lang ) ?? '' );
		$src = self::placeholders( $ref );
		$got = self::placeholders( $value );
		return [ array_values( array_diff( $src, $got ) ), array_values( array_diff( $got, $src ) ) ];
	}

	// ---------------------------------------------------------------- review status

	/** @return array<string, array<string, array<string,mixed>>> */
	public static function status_records(): array {
		if ( null === self::$status ) {
			$s            = get_option( self::STATUS_OPTION, [] );
			self::$status = is_array( $s ) ? $s : [];
		}
		return self::$status;
	}

	/** @return array<string,mixed>|null stored review record of a key in a language */
	public static function record( string $key, string $lang ): ?array {
		$r = self::status_records()[ $lang ][ $key ] ?? null;
		return is_array( $r ) ? $r : null;
	}

	public static function value_hash( ?string $value ): string {
		return substr( hash( 'sha256', (string) $value ), 0, 16 );
	}

	/**
	 * Review status of a key in a language: missing | draft | needs_review | approved.
	 * Seeds without a record are `draft`; an approval whose reviewed value changed reads `needs_review`.
	 */
	public static function status( string $key, string $lang ): string {
		$value = self::raw( $key, $lang );
		if ( null === $value ) {
			$status = 'missing';
		} else {
			$rec    = self::record( $key, $lang );
			$status = in_array( $rec['status'] ?? '', self::STATUSES, true ) ? (string) $rec['status'] : 'draft';
			if ( 'approved' === $status && ( $rec['hash'] ?? '' ) !== self::value_hash( $value ) ) {
				$status = 'needs_review';
			}
		}
		return (string) apply_filters( 'momos/strings/status', $status, $key, $lang );
	}

	public static function is_approved( string $key, string $lang ): bool {
		return 'approved' === self::status( $key, $lang );
	}

	/** Approved, but the reference text changed after approval. */
	public static function is_outdated( string $key, string $lang ): bool {
		if ( Lang::SOURCE === $lang || 'approved' !== self::status( $key, $lang ) ) {
			return false;
		}
		$src = (string) ( self::record( $key, $lang )['src'] ?? '' );
		return '' !== $src && $src !== self::value_hash( self::reference( $key, $lang ) );
	}

	/**
	 * Customer-facing keys of a language that are not approved (or approved but outdated), optionally
	 * limited to a key prefix.
	 *
	 * @return list<string>
	 */
	public static function unapproved_keys( string $lang, string $prefix = '' ): array {
		$out = [];
		foreach ( self::keys() as $key ) {
			if ( ! self::is_customer_key( $key ) || ( '' !== $prefix && ! str_starts_with( $key, $prefix ) ) ) {
				continue;
			}
			if ( ! self::is_approved( $key, $lang ) || self::is_outdated( $key, $lang ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Set the review status of keys in one language (one option write). Approval stamps the value and
	 * reference hashes and the reviewer. `translated_by` keeps the last user who changed the value.
	 *
	 * @param list<string> $keys
	 */
	public static function set_status( string $lang, array $keys, string $status, int $user_id = 0, ?string $source = null, bool $value_changed = false ): void {
		if ( ! Lang::is_valid( $lang ) || ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid string status target' );
		}
		$all = self::status_records();
		$now = Clock::utc()->format( 'Y-m-d\TH:i:s\Z' );
		foreach ( $keys as $key ) {
			$key   = (string) $key;
			$value = self::raw( $key, $lang );
			if ( null === $value ) {
				continue;
			}
			$rec           = is_array( $all[ $lang ][ $key ] ?? null ) ? $all[ $lang ][ $key ] : [];
			$rec['status'] = $status;
			$rec['source'] = in_array( $source, self::SOURCES, true ) ? $source : (string) ( $rec['source'] ?? 'seed' );
			$rec['by']     = $user_id;
			$rec['at']     = $now;
			if ( $value_changed ) {
				$rec['translated_by'] = $user_id;
			}
			if ( 'approved' === $status ) {
				$rec['hash'] = self::value_hash( $value );
				$rec['src']  = self::value_hash( self::reference( $key, $lang ) );
			} else {
				unset( $rec['hash'], $rec['src'] );
			}
			$all[ $lang ][ $key ] = $rec;
		}
		update_option( self::STATUS_OPTION, $all, false );
		self::$status = $all;
		do_action( 'momos/strings/status_changed', $lang, array_values( array_map( 'strval', $keys ) ), $status, $user_id );
	}

	public static function flush(): void {
		self::$catalog    = [];
		self::$origin     = [];
		self::$duplicates = [];
		self::$files      = [];
		self::$overrides  = null;
		self::$status     = null;
	}
}
