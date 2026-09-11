<?php
/**
 * Plugin Name:       Calculadora de Joyas NCM
 * Plugin URI:        https://ncm.com.co/
 * Description:       Calculadora de cotización de joyas de NCM: replica el Excel. Versión pública [ncm_calculadora] (solo el precio) y versión interna [ncm_calculadora_interna] (desglose completo). Panel de configuración propio, sin dependencias.
 * Version:           1.3.3
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            NCM
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ncm-calculadora
 *
 * @package NCM_Calculadora
 */

/*
Calculadora de Joyas NCM es software libre: puedes redistribuirlo y/o
modificarlo bajo los términos de la Licencia Pública General de GNU publicada
por la Free Software Foundation, en su versión 2 o (a tu elección) cualquier
versión posterior.

Se distribuye con la esperanza de que sea útil, pero SIN NINGUNA GARANTÍA; ni
siquiera la garantía implícita de COMERCIABILIDAD o IDONEIDAD PARA UN PROPÓSITO
PARTICULAR. Consulta la Licencia Pública General de GNU para más detalles:
https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NCM_CALC_VERSION', '1.3.3' );
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

		return;
	}

	// Instalación que viene de una versión anterior: la opción podría seguir
	// autocargándose en cada petición. Se corrige sin tocar su contenido.
	NCM_Data::asegurar_sin_autoload();
}
register_activation_hook( __FILE__, 'ncm_calc_activar' );

/** Arranca el plugin. */
function ncm_calc_init() {
	NCM_Admin::init();
	NCM_Shortcode::init();
}
add_action( 'plugins_loaded', 'ncm_calc_init' );
