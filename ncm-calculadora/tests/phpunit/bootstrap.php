<?php
/**
 * Arranque de PHPUnit dentro del entorno de pruebas de wp-env.
 *
 * wp-env deja la librería de pruebas de WordPress montada y expone su ruta en
 * WP_TESTS_DIR; aquí solo se engancha el plugin para que se cargue como si
 * estuviera activo.
 *
 * @package NCM_Calculadora
 */

$ncm_polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';

if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $ncm_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $ncm_polyfills );
}

$ncm_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $ncm_tests_dir ) {
	$ncm_tests_dir = '/wordpress-phpunit';
}

$ncm_tests_dir = rtrim( $ncm_tests_dir, '/\\' );

if ( ! file_exists( $ncm_tests_dir . '/includes/functions.php' ) ) {
	echo "No encuentro la librería de pruebas de WordPress en {$ncm_tests_dir}.\n";
	echo "Corre las pruebas con: wp-env run tests-cli --env-cwd=wp-content/plugins/ncm-calculadora vendor/bin/phpunit\n";
	exit( 1 );
}

require_once $ncm_tests_dir . '/includes/functions.php';

/** Carga el plugin antes de que arranque WordPress. */
function ncm_cargar_plugin() {
	require dirname( __DIR__, 2 ) . '/ncm-calculadora.php';
}
tests_add_filter( 'muplugins_loaded', 'ncm_cargar_plugin' );

require $ncm_tests_dir . '/includes/bootstrap.php';
