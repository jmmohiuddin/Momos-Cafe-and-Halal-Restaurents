<?php
/**
 * Customer email layout E01–E06 (CMP-EmailLayout, Doc 08 §7.9; spec §4.6–4.7). 600 px single
 * column, table layout, inline CSS, `lang` + `dir` on the root, real text headings, one bulletproof
 * button ≥ 44 px, text wordmark (no images, no tracking pixels), no UTM (PRV-005).
 * Content order: header → status headline → reference → details → template block → button →
 * branch block → carrier/help line → footer.
 *
 * @var array<string,mixed> $v view
 */

use Momos\Notification\Template\Html;

defined( 'ABSPATH' ) || exit;

$lang             = (string) $v['lang'];
$dir              = (string) $v['dir'];
$align            = 'rtl' === $dir ? 'right' : 'left';
$fonts            = [
	'ja' => '"Hiragino Sans","Yu Gothic","Meiryo",sans-serif',
	'ar' => 'Tahoma,"Segoe UI",sans-serif',
	'ur' => 'Tahoma,"Segoe UI",sans-serif',
	'hi' => '"Nirmala UI",sans-serif',
	'bn' => '"Nirmala UI",sans-serif',
];
$font             = $fonts[ $lang ] ?? 'Arial,Helvetica,sans-serif';
$tones            = [
	'pending'   => [ '#FDF1DC', '#6B4410', '#97651F' ],
	'confirmed' => [ '#E6EFEA', '#1E3D34', '#1E3D34' ],
	'rejected'  => [ '#FBE9E7', '#8F1D14', '#BA1A1A' ],
	'neutral'   => [ '#EFE9E5', '#4A3F3A', '#8C7167' ],
];
[ $bg, $fg, $bd ] = $tones[ $v['tone'] ?? 'neutral' ] ?? $tones['neutral'];
$branch           = (array) $v['branch'];
$p                = 'margin:0 0 12px;font-size:16px;line-height:1.6;color:#221A17;';
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( (string) $v['subject'] ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#FAF3EB;">
<div role="article" aria-roledescription="email" aria-label="<?php echo esc_attr( (string) $v['subject'] ); ?>" lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>" style="font-family:<?php echo esc_attr( $font ); ?>;text-align:<?php echo esc_attr( $align ); ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FAF3EB;">
<tr><td align="center" style="padding:16px 8px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#FFFFFF;border-radius:12px;">
<tr><td dir="<?php echo esc_attr( $dir ); ?>" style="background-color:#1E3D34;color:#FFFFFF;padding:20px 24px;font-size:20px;font-weight:700;line-height:1.3;border-radius:12px 12px 0 0;"><bdi><?php echo esc_html( (string) $v['brand'] ); ?></bdi></td></tr>
<tr><td dir="<?php echo esc_attr( $dir ); ?>" style="padding:24px 24px 0;">
<h1 style="margin:0 0 16px;padding:12px 16px;font-size:22px;line-height:1.4;color:<?php echo esc_attr( $fg ); ?>;background-color:<?php echo esc_attr( $bg ); ?>;border-<?php echo esc_attr( $align ); ?>:4px solid <?php echo esc_attr( $bd ); ?>;"><?php echo esc_html( (string) $v['headline'] ); ?></h1>
<?php if ( ! empty( $v['ref'] ) ) : ?>
<p style="<?php echo esc_attr( $p ); ?>"><?php echo strtr( esc_html( (string) $v['reference_text'] ), [ '{ref}' => '<bdi dir="ltr" style="font-weight:700;letter-spacing:0.05em;">' . esc_html( (string) $v['ref'] ) . '</bdi>' ] ); // phpcs:ignore ?></p>
<?php endif; ?>
<?php if ( ! empty( $v['details_rows'] ) ) : ?>
<table style="width:100%;border-collapse:collapse;margin:0 0 20px;font-size:16px;line-height:1.5;border-top:1px solid #EFE9E5;">
	<?php foreach ( (array) $v['details_rows'] as $row ) : ?>
<tr><th scope="row" style="text-align:<?php echo esc_attr( $align ); ?>;vertical-align:top;padding:8px 12px 8px 0;color:#6E625D;font-weight:400;width:35%;border-bottom:1px solid #EFE9E5;"><?php echo esc_html( (string) $row[0] ); ?></th><td style="padding:8px 0;color:#221A17;font-weight:700;border-bottom:1px solid #EFE9E5;"><bdi><?php echo esc_html( (string) $row[1] ); ?></bdi></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php require __DIR__ . '/_blocks.html.php'; ?>
<?php if ( ! empty( $v['button'] ) ) : ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr><td style="background-color:#C64F0E;border-radius:999px;mso-padding-alt:12px 24px;"><a href="<?php echo esc_url( (string) $v['button']['url'] ); ?>" style="display:inline-block;min-height:44px;line-height:44px;padding:0 24px;color:#FFFFFF;font-size:16px;font-weight:700;text-decoration:none;border-radius:999px;"><?php echo esc_html( (string) $v['button']['label'] ); ?></a></td></tr></table>
<?php endif; ?>
</td></tr>
<?php if ( '' !== (string) $branch['name'] ) : ?>
<tr><td dir="<?php echo esc_attr( $dir ); ?>" style="padding:16px 24px;background-color:#FDF8F3;border-top:1px solid #EFE9E5;">
<h2 style="margin:0 0 8px;font-size:18px;line-height:1.4;color:#221A17;"><bdi><?php echo esc_html( (string) $branch['name'] ); ?></bdi></h2>
	<?php if ( '' !== (string) $branch['address_local'] ) : ?>
<p style="<?php echo esc_attr( $p ); ?>"><bdi><?php echo esc_html( (string) $branch['address_local'] ); ?></bdi></p>
<?php endif; ?>
	<?php if ( '' !== (string) $branch['address_ja'] ) : ?>
		<?php
		if ( 'ja' !== $lang ) :
			?>
			<p style="margin:0 0 4px;font-size:14px;color:#6E625D;"><?php echo esc_html( (string) $v['labels']['address_ja'] ); ?></p><?php endif; ?>
<p style="<?php echo esc_attr( $p ); ?>"><span lang="ja" dir="ltr"><?php echo esc_html( (string) $branch['address_ja'] ); ?></span></p>
<?php endif; ?>
	<?php if ( '' !== (string) $branch['phone'] ) : ?>
<p style="<?php echo esc_attr( $p ); ?>"><?php echo strtr( esc_html( (string) $v['labels']['call'] ), [ '{phone}' => '<a href="' . esc_url( (string) $branch['tel'], [ 'tel' ] ) . '" style="color:#A03B00;"><bdi dir="ltr">' . esc_html( (string) $branch['phone'] ) . '</bdi></a>' ] ); // phpcs:ignore ?></p>
<?php endif; ?>
	<?php if ( '' !== (string) $branch['directions'] ) : ?>
<p style="<?php echo esc_attr( $p ); ?>"><a href="<?php echo esc_url( (string) $branch['directions'] ); ?>" style="color:#A03B00;"><?php echo esc_html( (string) $v['labels']['directions'] ); ?></a></p>
<?php endif; ?>
</td></tr>
<?php endif; ?>
<tr><td dir="<?php echo esc_attr( $dir ); ?>" style="padding:16px 24px 24px;font-size:14px;line-height:1.6;color:#6E625D;border-top:1px solid #EFE9E5;">
<?php if ( ! empty( $v['carrier'] ) ) : ?>
<p style="margin:0 0 12px;"><?php echo Html::fill( (string) $v['labels']['carrier'], [ 'domain' => (string) $v['carrier_domain'] ] ); // phpcs:ignore ?></p>
<?php endif; ?>
<p style="margin:0 0 8px;"><?php echo esc_html( (string) $v['labels']['auto'] ); ?></p>
<p style="margin:0 0 8px;"><a href="<?php echo esc_url( (string) $v['privacy_url'] ); ?>" style="color:#6E625D;"><?php echo esc_html( (string) $v['labels']['privacy'] ); ?></a></p>
<?php if ( '' !== (string) $v['operator'] ) : ?>
<p style="margin:0;"><?php echo Html::fill( (string) $v['labels']['copyright'], [ 'year' => (string) $v['year'], 'operator' => (string) $v['operator'] ] ); // phpcs:ignore ?></p>
<?php endif; ?>
<p style="margin:0;"><bdi><?php echo esc_html( (string) $v['brand'] ); ?></bdi></p>
</td></tr>
</table>
</td></tr>
</table>
</div>
</body>
</html>
