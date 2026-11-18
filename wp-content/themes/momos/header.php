<?php
/**
 * Customer page frame — top (ui-design-system.md §8.1): skip link → suggestion bar → notices → sticky
 * header → <main>. `<html lang dir>` comes from the momos-core Router (FR-I18N-001).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FAF3EB">
<meta name="format-detection" content="telephone=no">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();
get_template_part( 'template-parts/frame/skip-link' );
get_template_part( 'template-parts/frame/lang-suggestion' );
get_template_part( 'template-parts/frame/site-notices' );
get_template_part( 'template-parts/frame/header' );
?>
<main id="main" tabindex="-1">
