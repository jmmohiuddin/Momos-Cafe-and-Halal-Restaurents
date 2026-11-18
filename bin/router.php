<?php
// Router for PHP's built-in web server (development/E2E only): serve real files, else WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . '/../.wp' . $path;
if ( $path !== '/' && is_file( $file ) && ! str_ends_with( $file, '.php' ) ) {
	return false;
}
if ( is_file( $file ) && str_ends_with( $file, '.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = $path;
	require $file;
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../.wp/index.php';
