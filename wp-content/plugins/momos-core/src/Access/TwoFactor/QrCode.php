<?php
declare(strict_types=1);

namespace Momos\Access\TwoFactor;

/**
 * Minimal QR Code encoder (ISO/IEC 18004) rendered as inline SVG, so the TOTP enrolment QR is
 * generated locally with no third-party service or library (PRV-024, CSP; Access spec §5.2).
 * Byte mode, error-correction level M, versions 1–20 (≤ 663 bytes — an otpauth URI is ~130–250).
 * Algorithm after Project Nayuki's reference implementation (MIT): Reed–Solomon over GF(2^8)
 * with polynomial 0x11D, standard function patterns and penalty-based mask selection.
 */
final class QrCode {
	private const MAX_VERSION = 20;

	/** ECC level M: codewords per block and number of blocks, index = version (0 unused). */
	private const ECC_PER_BLOCK = [ -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26 ];
	private const NUM_BLOCKS    = [ -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16 ];

	/** @var list<list<bool>> */
	private array $modules = [];
	/** @var list<list<bool>> */
	private array $is_function = [];
	private int $size;

	private function __construct( private int $version ) {
		$this->size        = $version * 4 + 17;
		$row               = array_fill( 0, $this->size, false );
		$this->modules     = array_fill( 0, $this->size, $row );
		$this->is_function = array_fill( 0, $this->size, $row );
	}

	/** Encode bytes; returns the module matrix (true = dark), indexed [y][x]. @return list<list<bool>> */
	public static function matrix( string $data, ?int $force_mask = null ): array {
		$len     = strlen( $data );
		$version = 0;
		for ( $v = 1; $v <= self::MAX_VERSION; $v++ ) {
			$bits_needed = 4 + ( $v < 10 ? 8 : 16 ) + 8 * $len;
			if ( $bits_needed <= self::data_codewords( $v ) * 8 ) {
				$version = $v;
				break;
			}
		}
		if ( 0 === $version ) {
			throw new \InvalidArgumentException( 'Data too long for QR version ' . self::MAX_VERSION );
		}
		$qr = new self( $version );

		// Bit stream: mode indicator, character count, data, terminator, padding.
		$bits = [];
		$push = static function ( int $val, int $n ) use ( &$bits ): void {
			for ( $i = $n - 1; $i >= 0; $i-- ) {
				$bits[] = ( $val >> $i ) & 1;
			}
		};
		$push( 0x4, 4 );
		$push( $len, $version < 10 ? 8 : 16 );
		for ( $i = 0; $i < $len; $i++ ) {
			$push( ord( $data[ $i ] ), 8 );
		}
		$capacity = self::data_codewords( $version ) * 8;
		$push( 0, min( 4, $capacity - count( $bits ) ) );
		$push( 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$push( $pad, 8 );
		}
		$codewords = array_fill( 0, intdiv( count( $bits ), 8 ), 0 );
		foreach ( $bits as $i => $b ) {
			$codewords[ $i >> 3 ] |= $b << ( 7 - ( $i & 7 ) );
		}

		$qr->draw_function_patterns();
		$qr->draw_codewords( $qr->add_ecc_and_interleave( $codewords ) );

		$best_mask = $force_mask ?? -1;
		if ( null === $force_mask ) {
			$min = PHP_INT_MAX;
			for ( $m = 0; $m < 8; $m++ ) {
				$qr->apply_mask( $m );
				$qr->draw_format_bits( $m );
				$p = $qr->penalty();
				if ( $p < $min ) {
					$best_mask = $m;
					$min       = $p;
				}
				$qr->apply_mask( $m ); // Undo (XOR).
			}
		}
		$qr->apply_mask( $best_mask );
		$qr->draw_format_bits( $best_mask );
		return $qr->modules;
	}

	/** Inline SVG (4-module quiet zone), crisp at any size. */
	public static function svg( string $data, int $pixel_size = 220, string $title = '' ): string {
		$m    = self::matrix( $data );
		$n    = count( $m );
		$dim  = $n + 8;
		$path = '';
		foreach ( $m as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $x + 4 ) . ',' . ( $y + 4 ) . 'h1v1h-1z';
				}
			}
		}
		$t = '' !== $title ? '<title>' . esc_html( $title ) . '</title>' : '';
		return '<svg xmlns="http://www.w3.org/2000/svg" role="img" width="' . $pixel_size . '" height="' . $pixel_size . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">' . $t
			. '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
	}

	// ----------------------------------------------------------------- capacity

	private static function raw_data_modules( int $ver ): int {
		$result = ( 16 * $ver + 128 ) * $ver + 64;
		if ( $ver >= 2 ) {
			$num_align = intdiv( $ver, 7 ) + 2;
			$result   -= ( 25 * $num_align - 10 ) * $num_align - 55;
			if ( $ver >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	private static function data_codewords( int $ver ): int {
		return intdiv( self::raw_data_modules( $ver ), 8 ) - self::ECC_PER_BLOCK[ $ver ] * self::NUM_BLOCKS[ $ver ];
	}

	// ----------------------------------------------------------------- function patterns

	private function set_function( int $x, int $y, bool $dark ): void {
		$this->modules[ $y ][ $x ]     = $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns(): void {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}
		$this->draw_finder( 3, 3 );
		$this->draw_finder( $this->size - 4, 3 );
		$this->draw_finder( 3, $this->size - 4 );
		$pos = $this->alignment_positions();
		$n   = count( $pos );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < $n; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $n - 1 === $j ) || ( $n - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_function( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format_bits( 0 );
		$this->draw_version();
	}

	private function draw_finder( int $x, int $y ): void {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$dist = max( abs( $dx ), abs( $dy ) );
				$xx   = $x + $dx;
				$yy   = $y + $dy;
				if ( $xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size ) {
					$this->set_function( $xx, $yy, 2 !== $dist && 4 !== $dist );
				}
			}
		}
	}

	/** @return list<int> */
	private function alignment_positions(): array {
		if ( 1 === $this->version ) {
			return [];
		}
		$num  = intdiv( $this->version, 7 ) + 2;
		$step = (int) ( ceil( ( $this->version * 4 + 4 ) / ( $num * 2 - 2 ) ) * 2 );
		$out  = [ 6 ];
		for ( $pos = $this->size - 7; count( $out ) < $num; $pos -= $step ) {
			array_splice( $out, 1, 0, [ $pos ] );
		}
		return $out;
	}

	private function draw_format_bits( int $mask ): void {
		$data = ( 0 << 3 ) | $mask; // ECC level M = 0b00.
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = static fn( int $i ): bool => 1 === ( ( $bits >> $i ) & 1 );
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}
		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $this->size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $this->size - 15 + $i, $bit( $i ) );
		}
		$this->set_function( 8, $this->size - 8, true );
	}

	private function draw_version(): void {
		if ( $this->version < 7 ) {
			return;
		}
		$rem = $this->version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		$bits = ( $this->version << 12 ) | $rem;
		for ( $i = 0; $i < 18; $i++ ) {
			$dark = 1 === ( ( $bits >> $i ) & 1 );
			$a    = $this->size - 11 + $i % 3;
			$b    = intdiv( $i, 3 );
			$this->set_function( $a, $b, $dark );
			$this->set_function( $b, $a, $dark );
		}
	}

	// ----------------------------------------------------------------- data

	/** @param list<int> $data @return list<int> */
	private function add_ecc_and_interleave( array $data ): array {
		$num_blocks = self::NUM_BLOCKS[ $this->version ];
		$ecc_len    = self::ECC_PER_BLOCK[ $this->version ];
		$raw        = intdiv( self::raw_data_modules( $this->version ), 8 );
		$num_short  = $num_blocks - $raw % $num_blocks;
		$short_len  = intdiv( $raw, $num_blocks );
		$divisor    = self::rs_divisor( $ecc_len );
		$blocks     = [];
		$k          = 0;
		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$len = $short_len - $ecc_len + ( $i < $num_short ? 0 : 1 );
			$dat = array_slice( $data, $k, $len );
			$k  += $len;
			$ecc = self::rs_remainder( $dat, $divisor );
			if ( $i < $num_short ) {
				$dat[] = 0;
			}
			$blocks[] = array_merge( $dat, $ecc );
		}
		$out = [];
		$bl  = count( $blocks[0] );
		for ( $i = 0; $i < $bl; $i++ ) {
			foreach ( $blocks as $j => $block ) {
				if ( $i !== $short_len - $ecc_len || $j >= $num_short ) {
					$out[] = $block[ $i ];
				}
			}
		}
		return $out;
	}

	/** @return list<int> */
	private static function rs_divisor( int $degree ): array {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	/** @param list<int> $data @param list<int> $divisor @return list<int> */
	private static function rs_remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor   = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	private static function gf_mul( int $x, int $y ): int {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	/** @param list<int> $data */
	private function draw_codewords( array $data ): void {
		$i     = 0;
		$total = count( $data ) * 8;
		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $this->size - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				$invert = match ( $mask ) {
					0 => 0 === ( $x + $y ) % 2,
					1 => 0 === $y % 2,
					2 => 0 === $x % 3,
					3 => 0 === ( $x + $y ) % 3,
					4 => 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2,
					5 => 0 === ( $x * $y % 2 + $x * $y % 3 ),
					6 => 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2,
					default => 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2,
				};
				if ( $invert && ! $this->is_function[ $y ][ $x ] ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	// ----------------------------------------------------------------- penalty

	private function penalty(): int {
		$result = 0;
		$n      = $this->size;
		for ( $pass = 0; $pass < 2; $pass++ ) {
			for ( $a = 0; $a < $n; $a++ ) {
				$run_color = false;
				$run_len   = 0;
				$history   = [ 0, 0, 0, 0, 0, 0, 0 ];
				for ( $b = 0; $b < $n; $b++ ) {
					$c = 0 === $pass ? $this->modules[ $a ][ $b ] : $this->modules[ $b ][ $a ];
					if ( $c === $run_color ) {
						++$run_len;
						if ( 5 === $run_len ) {
							$result += 3;
						} elseif ( $run_len > 5 ) {
							++$result;
						}
					} else {
						$this->history_add( $run_len, $history );
						if ( ! $run_color ) {
							$result += $this->finder_count( $history ) * 40;
						}
						$run_color = $c;
						$run_len   = 1;
					}
				}
				if ( $run_color ) {
					$this->history_add( $run_len, $history );
					$run_len = 0;
				}
				$run_len += $n;
				$this->history_add( $run_len, $history );
				$result += $this->finder_count( $history ) * 40;
			}
		}
		$dark = 0;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				$c = $this->modules[ $y ][ $x ];
				if ( $c ) {
					++$dark;
				}
				if ( $y < $n - 1 && $x < $n - 1 && $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$result += 3;
				}
			}
		}
		$total   = $n * $n;
		$k       = (int) ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1;
		$result += $k * 10;
		return $result;
	}

	/** @param list<int> $history */
	private function history_add( int $run_len, array &$history ): void {
		if ( 0 === $history[0] ) {
			$run_len += $this->size;
		}
		array_pop( $history );
		array_unshift( $history, $run_len );
	}

	/** @param list<int> $h */
	private function finder_count( array $h ): int {
		$n    = $h[1];
		$core = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;
		return ( $core && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0 ) + ( $core && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0 );
	}
}
