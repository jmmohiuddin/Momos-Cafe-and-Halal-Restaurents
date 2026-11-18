<?php
// Router for PHP's built-in web server (development/E2E only): serve real files, else WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . '/../.wp' . $path;
// Directory requests (e.g. /wp-admin/) → their index.php, like Apache/nginx DirectoryIndex.
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	if ( ! str_ends_with( $path, '/' ) ) {
		header( 'Location: ' . $path . '/' . ( isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '' ), true, 301 );
		return;
	}
	if ( '/' !== $path ) {
		$path .= 'index.php';
		$file  = rtrim( $file, '/' ) . '/index.php';
	}
}
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
