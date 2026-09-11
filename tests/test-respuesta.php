<?php
/**
 * Pruebas del filtrado de la respuesta AJAX.
 *
 * Lo crítico de la calculadora pública: el servidor decide qué sale. Una
 * petición sin sesión recibe el precio y nada más; una con sesión recibe el
 * desglose completo. Estas pruebas revisan la carga entera —incluido el HTML—
 * en busca de cualquier cifra que no deba salir.
 *
 * Se ejecutan fuera de WordPress: `php tests/test-respuesta.php`.
 *
 * @package NCM_Calculadora
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'NCM_CALC_VERSION', 'test' );
define( 'NCM_CALC_URL', 'http://example.test/' );

$GLOBALS['ncm_test_options'] = array();
$GLOBALS['ncm_test_cap']     = true;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $clave, $fallback = false ) {
		return array_key_exists( $clave, $GLOBALS['ncm_test_options'] )
			? $GLOBALS['ncm_test_options'][ $clave ]
			: $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $clave, $valor ) {
		$GLOBALS['ncm_test_options'][ $clave ] = $valor;

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode() {}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $texto ) {
		return htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $texto ) {
		return htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = trim( (string) $url );

		// Solo esquemas seguros, como hace WordPress.
		if ( '' !== $url && ! preg_match( '#^(https?|mailto|tel):#i', $url ) ) {
			return '';
		}

		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return (bool) $GLOBALS['ncm_test_cap'];
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $formato ) {
		return date( $formato );
	}
}

require_once __DIR__ . '/../includes/class-ncm-data.php';
require_once __DIR__ . '/../includes/class-ncm-calculator.php';
require_once __DIR__ . '/../public/class-ncm-shortcode.php';

$fallos = 0;
$total  = 0;

/**
 * Comprueba una igualdad y reporta el resultado.
 *
 * @param string $titulo   Nombre de la prueba.
 * @param mixed  $esperado Valor esperado.
 * @param mixed  $obtenido Valor obtenido.
 */
function ncm_check( $titulo, $esperado, $obtenido ) {
	global $fallos, $total;

	++$total;

	$ok = is_float( $esperado ) || is_float( $obtenido )
		? abs( (float) $esperado - (float) $obtenido ) < 0.000001
		: $esperado === $obtenido;

	if ( $ok ) {
		echo "  OK    {$titulo}\n";

		return;
	}

	++$fallos;

	echo "  FALLA {$titulo}\n";
	echo '         esperado: ' . var_export( $esperado, true ) . "\n";
	echo '         obtenido: ' . var_export( $obtenido, true ) . "\n";
}

$calc = new NCM_Calculator( NCM_Data::semilla() );

// Caso B: es el que más cifras sensibles produce.
$b = $calc->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

// Cifras que un anónimo no debe ver por ningún lado.
$prohibidos = array(
	'12.000.000' => 'precio de la gema por ct',
	'2.288.000'  => 'costo del metal',
	'14.288.000' => 'costo de producción',
	'5.000.800'  => 'valor del margen',
	'19.288.800' => 'precio antes de redondear',
	'650.000'    => 'precio del metal por gramo',
	'3,52'       => 'gramos con merma',
	'Desglose'   => 'la tabla del desglose',
	'Margen'     => 'la palabra margen',
);

$claves_prohibidas = array(
	'gema', 'metal', 'mano_obra', 'extras', 'costo_produccion',
	'margen_comercial', 'valor_margen', 'precio_calculado', 'desglose',
	'redondeo_precio', 'codigo',
);

echo "\n== (a) Petición SIN sesión: el número y nada más ==\n";

$publica = NCM_Shortcode::respuesta( $b, false );
$json    = wp_json_encode_local( $publica );

ncm_check( 'modo publico', 'publico', $publica['modo'] );
ncm_check( 'recibe el precio final', 19290000.0, $publica['precio_final'] );
ncm_check( 'y el texto DESDE', 'DESDE $19.290.000 COP', $publica['precio_final_formateado'] );
ncm_check( 'el precio aparece en el HTML', true, false !== strpos( $publica['html'], '19.290.000' ) );

foreach ( $claves_prohibidas as $clave ) {
	ncm_check( "no trae la clave «{$clave}»", false, array_key_exists( $clave, $publica ) );
}

foreach ( $prohibidos as $cifra => $que ) {
	ncm_check( "la carga completa no filtra {$que}", false, false !== strpos( $json, $cifra ) );
}

ncm_check(
	'las claves son exactamente las públicas',
	array( 'entrada', 'estado', 'html', 'modo', 'moneda', 'precio_final', 'precio_final_formateado', 'texto_nota', 'whatsapp' ),
	ncm_claves_ordenadas( $publica )
);

echo "\n== WhatsApp: el mensaje tampoco puede llevar cifras internas ==\n";

// Sin número configurado, no hay enlace.
ncm_check( 'sin número no hay enlace', null, NCM_Shortcode::whatsapp( $b ) );

$con_wa = NCM_Data::semilla_cruda();
$con_wa['parametros']['whatsapp_numero'] = '+57 300 123 45 67';
NCM_Data::guardar_config( $con_wa );
NCM_Data::limpiar_cache();

$wa = NCM_Shortcode::whatsapp( $b );

ncm_check( 'número normalizado a E.164', '573001234567', $wa['numero'] );
ncm_check( 'la url apunta a wa.me', true, 0 === strpos( $wa['url'], 'https://wa.me/573001234567?text=' ) );
ncm_check( 'el mensaje lleva el precio', true, false !== strpos( $wa['mensaje'], '$19.290.000' ) );
ncm_check( 'y la selección del visitante', true, false !== strpos( $wa['mensaje'], 'Diamante' ) );

foreach ( $prohibidos as $cifra => $que ) {
	ncm_check( "el mensaje no filtra {$que}", false, false !== strpos( $wa['mensaje'], $cifra ) );
	ncm_check( "ni la url codificada ({$que})", false, false !== strpos( rawurldecode( $wa['url'] ), $cifra ) );
}

// Y el enlace viaja en la respuesta pública, con la misma garantía.
$publica_wa = NCM_Shortcode::respuesta( $b, false );
$json_wa    = wp_json_encode_local( $publica_wa );

ncm_check( 'la respuesta pública trae el enlace', true, is_array( $publica_wa['whatsapp'] ) );

foreach ( $prohibidos as $cifra => $que ) {
	ncm_check( "la carga con WhatsApp no filtra {$que}", false, false !== strpos( $json_wa, $cifra ) );
}

// El botón sale en el HTML de los dos modos.
ncm_check( 'botón en el HTML público', true, false !== strpos( $publica_wa['html'], 'ncm-calc__whatsapp' ) );
ncm_check( 'botón en el HTML interno', true, false !== strpos( NCM_Shortcode::respuesta( $b, true )['html'], 'ncm-calc__whatsapp' ) );

// Desactivado, no aparece.
$sin_wa = NCM_Data::semilla_cruda();
$sin_wa['parametros']['whatsapp_numero'] = '573001234567';
$sin_wa['parametros']['whatsapp_activo'] = false;
NCM_Data::guardar_config( $sin_wa );
NCM_Data::limpiar_cache();

ncm_check( 'desactivado no genera enlace', null, NCM_Shortcode::whatsapp( $b ) );
ncm_check( 'ni botón en el HTML', false, false !== strpos( NCM_Shortcode::respuesta( $b, false )['html'], 'ncm-calc__whatsapp' ) );

// Una plantilla con un marcador inventado se deja tal cual, no revienta.
$raro = NCM_Data::semilla_cruda();
$raro['parametros']['whatsapp_numero']  = '573001234567';
$raro['parametros']['whatsapp_mensaje'] = 'Precio {precio} y {inventado} y {costo_produccion}';
NCM_Data::guardar_config( $raro );
NCM_Data::limpiar_cache();

$wa_raro = NCM_Shortcode::whatsapp( $b );

ncm_check( 'marcador válido resuelto', true, false !== strpos( $wa_raro['mensaje'], '$19.290.000' ) );
ncm_check( 'marcador inventado intacto', true, false !== strpos( $wa_raro['mensaje'], '{inventado}' ) );
ncm_check( 'no existe marcador para el costo', true, false !== strpos( $wa_raro['mensaje'], '{costo_produccion}' ) );

NCM_Data::restaurar_semilla();
NCM_Data::limpiar_cache();

echo "\n== Paleta de colores ==\n";

ncm_check( 'la paleta por defecto es la de NCM', true, false !== strpos( NCM_Shortcode::css_paleta(), '--e-global-color-primary' ) );

$oscura = NCM_Data::semilla_cruda();
$oscura['parametros']['paleta'] = 'oscuro';
NCM_Data::guardar_config( $oscura );
NCM_Data::limpiar_cache();

ncm_check( 'la paleta oscura no depende de Elementor', false, false !== strpos( NCM_Shortcode::css_paleta(), '--e-global-color' ) );
ncm_check( 'y usa su fondo oscuro', true, false !== strpos( NCM_Shortcode::css_paleta(), '#1D1E1B' ) );

$sucia = NCM_Data::semilla_cruda();
$sucia['parametros']['paleta']       = 'personalizada';
$sucia['parametros']['color_acento'] = 'red; } body { display:none } .x{';
NCM_Data::guardar_config( $sucia );
NCM_Data::limpiar_cache();

$css = NCM_Shortcode::css_paleta();

ncm_check( 'un color inválido no se cuela en el CSS', false, false !== strpos( $css, 'display:none' ) );
ncm_check( 'cae al valor por defecto', true, false !== strpos( $css, '#354C3F' ) );

$paleta_falsa = NCM_Data::semilla_cruda();
$paleta_falsa['parametros']['paleta'] = '../../etc/passwd';
NCM_Data::guardar_config( $paleta_falsa );
NCM_Data::limpiar_cache();

ncm_check( 'una paleta inexistente cae en ncm', 'ncm', NCM_Data::get_parametro( 'paleta' ) );

NCM_Data::restaurar_semilla();
NCM_Data::limpiar_cache();

echo "\n== (b) Petición CON sesión: desglose completo ==\n";

$interna = NCM_Shortcode::respuesta( $b, true );

ncm_check( 'modo interno', 'interno', $interna['modo'] );
ncm_check( 'mismo precio final', 19290000.0, $interna['precio_final'] );
ncm_check( 'código de diseño', 'AN-SOL', $interna['codigo'] );
ncm_check( 'componente gema', 12000000.0, $interna['gema']['subtotal'] );
ncm_check( 'costo del metal', 2288000.0, $interna['metal']['subtotal'] );
ncm_check( 'costo de producción', 14288000.0, $interna['costo_produccion'] );
ncm_check( 'margen', 0.35, $interna['margen_comercial'] );
ncm_check( 'valor del margen', 5000800.0, $interna['valor_margen'] );
ncm_check( 'desglose con filas', 25, count( $interna['desglose'] ) );
ncm_check( 'el HTML trae la tabla del desglose', true, false !== strpos( $interna['html'], 'Desglose detallado' ) );
ncm_check( 'y el botón de imprimir', true, false !== strpos( $interna['html'], 'ncm-calc__imprimir' ) );
ncm_check( 'y el membrete de la cotización', true, false !== strpos( $interna['html'], 'ncm-res__membrete' ) );

foreach ( $claves_prohibidas as $clave ) {
	ncm_check( "sí trae la clave «{$clave}»", true, array_key_exists( $clave, $interna ) );
}

echo "\n== Los casos A y B no cambian ==\n";

$a = $calc->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

ncm_check( 'caso A público', 'DESDE $14.480.000 COP', NCM_Shortcode::respuesta( $a, false )['precio_final_formateado'] );
ncm_check( 'caso A interno', 'DESDE $14.480.000 COP', NCM_Shortcode::respuesta( $a, true )['precio_final_formateado'] );
ncm_check( 'caso B público', 'DESDE $19.290.000 COP', $publica['precio_final_formateado'] );
ncm_check( 'caso B interno', 'DESDE $19.290.000 COP', $interna['precio_final_formateado'] );

echo "\n== Errores: el motivo técnico tampoco sale ==\n";

$mal = $calc->calcular( 'Pulsera', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

$err_publico = NCM_Shortcode::respuesta_error( $mal, false );
$err_interno = NCM_Shortcode::respuesta_error( $mal, true );

ncm_check( 'público ve REVISAR CONFIGURACIÓN', 'REVISAR CONFIGURACIÓN', $err_publico['mensaje'] );
ncm_check( 'público no ve el detalle', false, array_key_exists( 'detalle', $err_publico ) );
ncm_check( 'y tampoco en el HTML', false, false !== strpos( $err_publico['html'], 'no existe en la matriz' ) );
ncm_check( 'interno sí ve el detalle', true, array_key_exists( 'detalle', $err_interno ) );

echo "\n== Todas las combinaciones de la matriz, en modo público ==\n";

$filtraciones = array();

foreach ( NCM_Data::semilla()['disenos'] as $diseno ) {
	foreach ( array( 'Natural', 'Laboratorio' ) as $origen ) {
		$r = $calc->calcular( $diseno['tipo'], $diseno['diseno'], $origen, 'Diamante', 'Redonda', 'Oro blanco' );
		$p = NCM_Shortcode::respuesta( $r, false );

		foreach ( $claves_prohibidas as $clave ) {
			if ( array_key_exists( $clave, $p ) ) {
				$filtraciones[] = $diseno['codigo'] . '/' . $origen . ': ' . $clave;
			}
		}

		// El costo de producción no puede aparecer nunca en el HTML público.
		$costo = NCM_Calculator::formato_moneda( $r['costo_produccion'] );

		if ( $r['costo_produccion'] > 0 && false !== strpos( $p['html'], $costo ) ) {
			$filtraciones[] = $diseno['codigo'] . '/' . $origen . ': costo en el HTML';
		}
	}
}

ncm_check( '30 combinaciones sin filtraciones', array(), $filtraciones );

echo "\n== El handler AJAX decide en el servidor ==\n";

$fuente = file_get_contents( __DIR__ . '/../public/class-ncm-shortcode.php' );

ncm_check( 'registra wp_ajax', true, false !== strpos( $fuente, "'wp_ajax_' . self::ACCION" ) );
ncm_check( 'registra wp_ajax_nopriv', true, false !== strpos( $fuente, "'wp_ajax_nopriv_' . self::ACCION" ) );
ncm_check( 'verifica el nonce siempre', true, false !== strpos( $fuente, 'check_ajax_referer( self::ACCION' ) );
ncm_check( 'el permiso sale de is_user_logged_in', true, false !== strpos( $fuente, '$interno = is_user_logged_in()' ) );

echo "\n----------------------------------------\n";

if ( $fallos > 0 ) {
	echo "RESULTADO: {$fallos} de {$total} pruebas fallaron.\n\n";
	exit( 1 );
}

echo "RESULTADO: {$total} pruebas, todas OK.\n\n";
exit( 0 );

/**
 * Codifica a JSON sin depender de WordPress.
 *
 * @param mixed $valor Valor a codificar.
 * @return string
 */
function wp_json_encode_local( $valor ) {
	return json_encode( $valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Claves de un array, ordenadas.
 *
 * @param array $array Array a inspeccionar.
 * @return array
 */
function ncm_claves_ordenadas( $array ) {
	$claves = array_keys( $array );
	sort( $claves );

	return $claves;
}
