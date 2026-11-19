<?php
/**
 * Staff email layout E07–E11 (compact, ja/en, one primary button — spec §5.3). Minimal PII by
 * construction: templates only pass the DEC-017 fields.
 *
 * @var array<string,mixed> $v view
 */

defined( 'ABSPATH' ) || exit;

$lang     = (string) $v['lang'];
$font     = 'ja' === $lang ? '"Hiragino Sans","Yu Gothic","Meiryo",sans-serif' : 'Arial,Helvetica,sans-serif';
$v['dir'] = 'ltr';
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( (string) $v['subject'] ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#F6F7F7;">
<div role="article" aria-roledescription="email" aria-label="<?php echo esc_attr( (string) $v['subject'] ); ?>" lang="<?php echo esc_attr( $lang ); ?>" style="font-family:<?php echo esc_attr( $font ); ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:12px 8px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#FFFFFF;border:1px solid #DCDCDE;">
<tr><td style="background-color:#1E3D34;color:#FFFFFF;padding:12px 20px;font-size:14px;font-weight:700;"><bdi><?php echo esc_html( (string) $v['brand'] ); ?></bdi>
<?php
if ( '' !== (string) ( $v['branch']['code'] ?? '' ) ) :
	?>
	· <bdi><?php echo esc_html( (string) $v['branch']['code'] ); ?></bdi><?php endif; ?></td></tr>
<tr><td style="padding:20px;">
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.4;color:#1D2327;"><?php echo esc_html( (string) $v['headline'] ); ?></h1>
<?php require __DIR__ . '/_blocks.html.php'; ?>
<?php if ( ! empty( $v['button'] ) ) : ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 8px;"><tr><td style="background-color:#2271B1;border-radius:4px;"><a href="<?php echo esc_url( (string) $v['button']['url'] ); ?>" style="display:inline-block;min-height:44px;line-height:44px;padding:0 20px;color:#FFFFFF;font-size:15px;font-weight:700;text-decoration:none;"><?php echo esc_html( (string) $v['button']['label'] ); ?></a></td></tr></table>
<?php endif; ?>
</td></tr>
<tr><td style="padding:12px 20px;font-size:12px;line-height:1.5;color:#646970;border-top:1px solid #DCDCDE;"><?php echo esc_html( \Momos\Notification\Template\Renderer::t( 'staff.email.footer' ) ); ?></td></tr>
</table>
</td></tr></table>
</div>
</body>
</html>
