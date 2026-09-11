<?php
/**
 * Plugin Name:       Calculadora de Joyas NCM
 * Plugin URI:        https://ncm.com.co/
 * Description:       Calculadora de cotización de joyas de NCM: replica el Excel. Versión pública [ncm_calculadora] (solo el precio) y versión interna [ncm_calculadora_interna] (desglose completo). Panel de configuración propio, sin dependencias.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            NCM
 * Text Domain:       ncm-calculadora
 *
 * @package NCM_Calculadora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NCM_CALC_VERSION', '1.2.0' );
define( 'NCM_CALC_FILE', __FILE__ );
define( 'NCM_CALC_PATH', plugin_dir_path( __FILE__ ) );
define( 'NCM_CALC_URL', plugin_dir_url( __FILE__ ) );

require_once NCM_CALC_PATH . 'includes/class-ncm-data.php';
require_once NCM_CALC_PATH . 'includes/class-ncm-calculator.php';
require_once NCM_CALC_PATH . 'admin/class-ncm-admin.php';
require_once NCM_CALC_PATH . 'public/class-ncm-shortcode.php';

/**
 * Precarga los valores del Excel la primera vez que se activa el plugin.
 *
 * Si ya existe configuración guardada no se toca: una reactivación nunca
 * debe pisar precios que el equipo haya ajustado.
 */
function ncm_calc_activar() {
	$actual = get_option( NCM_Data::OPTION, null );

	if ( ! is_array( $actual ) || empty( $actual ) ) {
		NCM_Data::restaurar_semilla();
	}
}
register_activation_hook( __FILE__, 'ncm_calc_activar' );

/** Arranca el plugin. */
function ncm_calc_init() {
	NCM_Admin::init();
	NCM_Shortcode::init();
}
add_action( 'plugins_loaded', 'ncm_calc_init' );
