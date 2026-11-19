<?php
// Router for PHP's built-in web server (development/E2E only): serve real files, else WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
// Same deny rules as the shipped config/server/ files (SEC-042, spec §9.3): no version/config disclosure,
// no installer, no logs/backups, no script execution below uploads.
$decoded = rawurldecode( (string) $path );
foreach ( [
	'#/\.(?!well-known/)#',
	'#^/(wp-config\.php|wp-config-sample\.php|readme\.html|license\.txt|xmlrpc\.php)$#i',
	'#(^|/)(debug\.log|error_log|\.user\.ini|php\.ini|composer\.(json|lock))$#i',
	'#\.(sql|bak|old|orig|save|swp|zip|tar|gz|tgz|7z|log|env|ini|sh|yml|yaml|lock)$#i',
	'#^/wp-admin/(install|setup-config|upgrade)\.php$#i',
	'#^/wp-admin/includes/#i',
	'#^/wp-includes/[^/]+\.php$#i',
	'#^/wp-content/uploads/.*\.(php\d?|phtml|phar|pht|pl|py|cgi|sh)$#i',
	'#^/wp-content/(plugins/momos-core|themes/momos)/(vendor|tests|node_modules|bin)/#i',
	'#^/wp-content/plugins/momos-core/(src|config|templates|languages)/#i',
] as $deny ) {
	if ( preg_match( $deny, $decoded ) ) {
		http_response_code( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "403 Forbidden\n";
		return true;
	}
}
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
