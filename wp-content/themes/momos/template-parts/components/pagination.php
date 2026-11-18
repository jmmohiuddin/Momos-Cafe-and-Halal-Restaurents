<?php
/**
 * Pagination (news list, 10/page): `<nav aria-label>`; current page `aria-current="page"`; prev/next
 * with mirrored chevrons; first/last pages + window of ±1 with ellipsis.
 *
 * @package momos
 * @var array{current:int,total:int,url:callable|string} $args url: sprintf pattern with %d or callable(int):string
 */

defined( 'ABSPATH' ) || exit;

$momos_cur   = max( 1, (int) $args['current'] );
$momos_total = max( 1, (int) $args['total'] );
if ( $momos_total < 2 ) {
	return;
}
$momos_url = static function ( int $n ) use ( $args ): string {
	return is_callable( $args['url'] ) ? (string) call_user_func( $args['url'], $n ) : sprintf( (string) $args['url'], $n );
};
$momos_pages = array_unique( array_filter( [ 1, $momos_cur - 1, $momos_cur, $momos_cur + 1, $momos_total ], static fn( $n ) => $n >= 1 && $n <= $momos_total ) );
sort( $momos_pages );
?>
<nav class="momo-pagination" aria-label="<?php echo esc_attr( momos_s( 'a11y.pagination' ) ); ?>">
	<ul>
		<li><?php if ( $momos_cur > 1 ) : ?><a href="<?php echo esc_url( $momos_url( $momos_cur - 1 ) ); ?>" rel="prev" aria-label="<?php echo esc_attr( momos_s( 'a11y.prev' ) ); ?>"><?php momos_the_icon( 'chevron-start' ); ?></a><?php else : ?><span class="is-disabled" aria-hidden="true"><?php momos_the_icon( 'chevron-start' ); ?></span><?php endif; ?></li>
		<?php
		$momos_prev = 0;
		foreach ( $momos_pages as $momos_p ) :
			if ( $momos_p - $momos_prev > 1 ) :
				?>
				<li><span aria-hidden="true">…</span></li>
			<?php endif; ?>
			<li>
				<?php if ( $momos_p === $momos_cur ) : ?>
					<a href="<?php echo esc_url( $momos_url( $momos_p ) ); ?>" aria-current="page" aria-label="<?php echo esc_attr( momos_s( 'a11y.page_n', [ 'n' => $momos_p ] ) ); ?>"><bdi dir="ltr"><?php echo (int) $momos_p; ?></bdi></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $momos_url( $momos_p ) ); ?>" aria-label="<?php echo esc_attr( momos_s( 'a11y.page_n', [ 'n' => $momos_p ] ) ); ?>"><bdi dir="ltr"><?php echo (int) $momos_p; ?></bdi></a>
				<?php endif; ?>
			</li>
			<?php
			$momos_prev = $momos_p;
		endforeach;
		?>
		<li><?php if ( $momos_cur < $momos_total ) : ?><a href="<?php echo esc_url( $momos_url( $momos_cur + 1 ) ); ?>" rel="next" aria-label="<?php echo esc_attr( momos_s( 'a11y.next' ) ); ?>"><?php momos_the_icon( 'chevron-end' ); ?></a><?php else : ?><span class="is-disabled" aria-hidden="true"><?php momos_the_icon( 'chevron-end' ); ?></span><?php endif; ?></li>
	</ul>
</nav>
