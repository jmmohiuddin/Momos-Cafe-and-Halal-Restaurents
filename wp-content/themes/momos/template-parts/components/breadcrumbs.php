<?php
/**
 * CMP-Breadcrumbs component alias (the frame partial owns the markup).
 *
 * @package momos
 * @var array{items?:list<array{name:string,url?:string}>} $args
 */

defined( 'ABSPATH' ) || exit;

get_template_part( 'template-parts/frame/breadcrumbs', null, $args );
