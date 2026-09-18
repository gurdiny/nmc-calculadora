<?php
/**
 * Pruebas de aceptación del motor de cálculo.
 *
 * Se ejecutan fuera de WordPress: se definen los mínimos stubs de WP que
 * necesita NCM_Data y se corre con `php tests/test-calculator.php`.
 *
 * @package NCM_Calculadora
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ncm_test_options'] = array();

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

require_once __DIR__ . '/../includes/class-ncm-data.php';
require_once __DIR__ . '/../includes/class-ncm-calculator.php';

$fallos = 0;
$total  = 0;

/**
 * Comprueba una igualdad y reporta el resultado.
 *
 * @param string $titulo    Nombre de la prueba.
 * @param mixed  $esperado  Valor esperado.
 * @param mixed  $obtenido  Valor obtenido.
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

	$e = is_scalar( $esperado ) ? var_export( $esperado, true ) : wordwrap( var_export( $esperado, true ) );
	$o = is_scalar( $obtenido ) ? var_export( $obtenido, true ) : wordwrap( var_export( $obtenido, true ) );

	echo "  FALLA {$titulo}\n         esperado: {$e}\n         obtenido: {$o}\n";
}

/**
 * Extrae una columna de una lista de arrays (equivalente a wp_list_pluck).
 *
 * @param array  $lista Lista de arrays.
 * @param string $clave Clave a extraer.
 * @return array
 */
function wp_list_pluck_local( $lista, $clave ) {
	$out = array();

	foreach ( $lista as $fila ) {
		if ( isset( $fila[ $clave ] ) ) {
			$out[] = $fila[ $clave ];
		}
	}

	return $out;
}

$calc = new NCM_Calculator( NCM_Data::semilla() );

echo "\n== Caso A: Pulsera / Bangle (Rígida) / Natural / Diamante / Redonda / Oro blanco ==\n";

$a = $calc->calcular(
	array(
		'tipo'   => 'Pulsera',
		'diseno' => 'Bangle (Rígida)',
		'origen' => 'Natural',
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Oro blanco',
	)
);

ncm_check( 'calculable', true, $a['ok'] );
ncm_check( 'código de diseño', 'PU-BAN', $a['codigo'] );
ncm_check( 'componente gema', 0.0, $a['gema']['subtotal'] );
ncm_check( 'componente metal', 10725000.0, $a['metal']['subtotal'] );
ncm_check( 'costo de producción', 10725000.0, $a['costo_produccion'] );
ncm_check( 'precio calculado', 14478750.0, $a['precio_calculado'] );
ncm_check( 'precio final', 14480000.0, $a['precio_final'] );
ncm_check( 'salida formateada', 'DESDE $14.480.000 COP', 'DESDE ' . NCM_Calculator::formato_moneda( $a['precio_final'], $a['moneda'] ) );

echo "\n== Caso B: Anillo / Solitario / Natural / Diamante / Redonda / Oro blanco ==\n";

$b = $calc->calcular(
	array(
		'tipo'   => 'Anillo',
		'diseno' => 'Solitario',
		'origen' => 'Natural',
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Oro blanco',
	)
);

ncm_check( 'calculable', true, $b['ok'] );
ncm_check( 'código de diseño', 'AN-SOL', $b['codigo'] );
ncm_check( 'componente gema', 12000000.0, $b['gema']['subtotal'] );
ncm_check( 'componente metal', 2288000.0, $b['metal']['subtotal'] );
ncm_check( 'costo de producción', 14288000.0, $b['costo_produccion'] );
ncm_check( 'precio calculado', 19288800.0, $b['precio_calculado'] );
ncm_check( 'precio final', 19290000.0, $b['precio_final'] );
ncm_check( 'salida formateada', 'DESDE $19.290.000 COP', 'DESDE ' . NCM_Calculator::formato_moneda( $b['precio_final'], $b['moneda'] ) );

echo "\n== Firma posicional y desglose detallado ==\n";

$pos = $calc->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

ncm_check( 'las 6 selecciones sueltas dan lo mismo', 19290000.0, $pos['precio_final'] );
ncm_check( 'estado OK', 'OK', $pos['estado'] );
ncm_check( 'precio final formateado', 'DESDE $19.290.000 COP', $pos['precio_final_formateado'] );

// Campos del bloque DESGLOSE DETALLADO del Excel.
ncm_check( 'gema: tipo', 'Diamante', $pos['gema']['tipo_gema'] );
ncm_check( 'gema: origen', 'Natural', $pos['gema']['origen'] );
ncm_check( 'gema: cantidad', 1.0, $pos['gema']['cant_gemas'] );
ncm_check( 'gema: ct por gema', 1.0, $pos['gema']['ct_por_gema'] );
ncm_check( 'gema: ct totales', 1.0, $pos['gema']['ct_total'] );
ncm_check( 'gema: precio por ct', 12000000.0, $pos['gema']['precio_ct'] );
ncm_check( 'gema: subtotal antes del ajuste', 12000000.0, $pos['gema']['subtotal_gemas'] );
ncm_check( 'gema: ajuste de talla', 0.0, $pos['gema']['ajuste_talla'] );
ncm_check( 'gema: componente', 12000000.0, $pos['gema']['subtotal'] );
ncm_check( 'metal: peso base', 3.2, $pos['metal']['peso_base'] );
ncm_check( 'metal: factor de merma', 1.1, $pos['metal']['factor_merma'] );
ncm_check( 'metal: gramos con merma', 3.52, $pos['metal']['gramos_con_merma'] );
ncm_check( 'metal: precio por gramo', 650000.0, $pos['metal']['precio_gramo'] );
ncm_check( 'metal: factor adicional', 1.0, $pos['metal']['factor_adicional'] );
ncm_check( 'metal: costo', 2288000.0, $pos['metal']['subtotal'] );
ncm_check( 'valor del margen', 5000800.0, $pos['valor_margen'] );
ncm_check( 'redondeo aplicado', 10000.0, $pos['redondeo_precio'] );

$desglose  = NCM_Calculator::desglose( $pos );
$etiquetas = wp_list_pluck_local( $desglose, 'etiqueta' );

ncm_check( 'el desglose trae 3 secciones', 3, count( array_filter( $desglose, function ( $f ) { return 'seccion' === $f['tipo']; } ) ) );

foreach ( array(
	'Tipo de gema', 'Origen', 'Cantidad de gemas', 'Ct por gema', 'Ct totales',
	'Precio de la gema por ct', 'Subtotal gemas', 'Ajuste por talla (Redonda)', 'Componente gema',
	'Metal', 'Peso base', 'Factor de merma', 'Gramos con merma', 'Precio del metal por gramo',
	'Factor adicional', 'Costo del metal', 'Mano de obra', 'Extras', 'Costo de producción',
	'Margen comercial (35 %)', 'Precio calculado', 'Precio final',
) as $etiqueta ) {
	ncm_check( 'desglose incluye: ' . $etiqueta, true, in_array( $etiqueta, $etiquetas, true ) );
}

ncm_check( 'desglose de un error va vacío', array(), NCM_Calculator::desglose( array( 'ok' => false ) ) );

echo "\n== Combinaciones no calculables ==\n";

$c = $calc->calcular(
	array(
		'tipo'   => 'Pulsera',
		'diseno' => 'Solitario', // Solitario no existe en Pulsera.
		'origen' => 'Natural',
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Oro blanco',
	)
);

ncm_check( 'no calculable', false, $c['ok'] );
ncm_check( 'texto de error', 'REVISAR CONFIGURACIÓN', $c['error'] );
ncm_check( 'estado REVISAR_CONFIGURACION', 'REVISAR_CONFIGURACION', $c['estado'] );
ncm_check( 'motivo', 'diseno_no_encontrado', $c['error_codigo'] );

$d = $calc->calcular(
	array(
		'tipo'   => 'Anillo',
		'diseno' => 'Solitario',
		'origen' => 'Sintética', // Origen inválido.
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Oro blanco',
	)
);

ncm_check( 'origen inválido no calcula', false, $d['ok'] );
ncm_check( 'motivo origen', 'origen_invalido', $d['error_codigo'] );

$e = $calc->calcular(
	array(
		'tipo'   => 'Anillo',
		'diseno' => 'Solitario',
		'origen' => 'Natural',
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Titanio', // Metal inexistente.
	)
);

ncm_check( 'metal inexistente no calcula', false, $e['ok'] );
ncm_check( 'motivo metal', 'metal_no_encontrado', $e['error_codigo'] );

echo "\n== Combinaciones cruzadas entre tipos (Fase 7) ==\n";

// Diseños que existen, pero bajo otro tipo de joya.
$cruces = array(
	array( 'Anillo', 'Tennis' ),
	array( 'Anillo', 'Halo' ),
	array( 'Pulsera', 'Eternity' ),
	array( 'Dije', 'Topos Solitario' ),
	array( 'Aretes', 'Esclava' ),
);

foreach ( $cruces as $cruce ) {
	$x = $calc->calcular( $cruce[0], $cruce[1], 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

	ncm_check(
		$cruce[0] . ' + ' . $cruce[1] . ' -> REVISAR CONFIGURACIÓN',
		'REVISAR_CONFIGURACION',
		$x['estado']
	);
}

echo "\n== Metal no disponible ==\n";

$config_sin_platino = NCM_Data::semilla();

foreach ( $config_sin_platino['metales'] as $i => $fila ) {
	if ( 'Platino' === $fila['metal'] ) {
		$config_sin_platino['metales'][ $i ]['disponible'] = false;
	}
}

$calc_sin = new NCM_Calculator( $config_sin_platino );
$f        = $calc_sin->calcular(
	array(
		'tipo'   => 'Anillo',
		'diseno' => 'Solitario',
		'origen' => 'Natural',
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Platino',
	)
);

ncm_check( 'metal deshabilitado no calcula', false, $f['ok'] );
ncm_check( 'motivo disponibilidad', 'metal_no_disponible', $f['error_codigo'] );

echo "\n== Origen laboratorio y extras ==\n";

$g = $calc->calcular(
	array(
		'tipo'   => 'Anillo',
		'diseno' => 'Cocktail',
		'origen' => 'Laboratorio',
		'gema'   => 'Esmeralda',
		'talla'  => 'Cuadrada',
		'metal'  => 'Plata',
	)
);

// Gema: 268.000 x 1 x 1 + 0 = 268.000
// Metal: 3.5 x 1.1 x 250.000 x 1 = 962.500
// Costo: 268.000 + 962.500 + 0 + 500.000 = 1.730.500
// Precio: 1.730.500 x 1.35 = 2.336.175 -> 2.340.000
ncm_check( 'AN-COC gema laboratorio', 268000.0, $g['gema']['subtotal'] );
ncm_check( 'AN-COC metal plata', 962500.0, $g['metal']['subtotal'] );
ncm_check( 'AN-COC extras', 500000.0, $g['extras'] );
ncm_check( 'AN-COC costo', 1730500.0, $g['costo_produccion'] );
ncm_check( 'AN-COC precio final', 2340000.0, $g['precio_final'] );

echo "\n== Redondeo hacia arriba ==\n";

ncm_check( 'múltiplo exacto no salta', 14480000.0, NCM_Calculator::redondear_arriba( 14480000.0, 10000 ) );
ncm_check( 'un peso por encima sube', 14490000.0, NCM_Calculator::redondear_arriba( 14480001.0, 10000 ) );
ncm_check( 'cero se queda en cero', 0.0, NCM_Calculator::redondear_arriba( 0.0, 10000 ) );
ncm_check( 'redondeo 0 devuelve el valor', 1234.5, NCM_Calculator::redondear_arriba( 1234.5, 0 ) );

echo "\n== Los 15 diseños son calculables ==\n";

$semilla   = NCM_Data::semilla();
$no_calcul = array();

foreach ( $semilla['disenos'] as $diseno ) {
	$r = $calc->calcular(
		array(
			'tipo'   => $diseno['tipo'],
			'diseno' => $diseno['diseno'],
			'origen' => 'Natural',
			'gema'   => 'Diamante',
			'talla'  => 'Redonda',
			'metal'  => 'Oro blanco',
		)
	);

	if ( empty( $r['ok'] ) || $r['codigo'] !== $diseno['codigo'] || $r['precio_final'] <= 0 ) {
		$no_calcul[] = $diseno['codigo'];
	}
}

ncm_check( '15 diseños en la semilla', 15, count( $semilla['disenos'] ) );
ncm_check( 'todos calculan', array(), $no_calcul );

echo "\n== Sin divisiones por cero ni avisos de PHP (Fase 7) ==\n";

// Config con los parámetros en cero: no debe reventar ni emitir avisos.
$config_cero = NCM_Data::semilla();
$config_cero['parametros']['factor_merma_metal'] = 0.0;
$config_cero['parametros']['redondeo_precio']    = 0.0;
$config_cero['parametros']['margen_comercial']   = 0.0;

$errores_php = array();

set_error_handler(
	function ( $nivel, $mensaje ) use ( &$errores_php ) {
		$errores_php[] = $mensaje;

		return true;
	}
);

$calc_cero = new NCM_Calculator( $config_cero );
$cero      = $calc_cero->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

// Y todos los diseños con una gema de precio 0.
$config_gema_cero = NCM_Data::semilla();

foreach ( $config_gema_cero['gemas'] as $i => $g ) {
	$config_gema_cero['gemas'][ $i ]['precio_natural'] = 0.0;
}

$calc_gema_cero = new NCM_Calculator( $config_gema_cero );
$fallos_cero    = array();

foreach ( $config_gema_cero['disenos'] as $d ) {
	$x = $calc_gema_cero->calcular( $d['tipo'], $d['diseno'], 'Natural', 'Diamante', 'Redonda', 'Plata' );

	if ( empty( $x['ok'] ) || ! is_finite( $x['precio_final'] ) ) {
		$fallos_cero[] = $d['codigo'];
	}
}

restore_error_handler();

ncm_check( 'merma 0 -> metal en 0', 0.0, $cero['metal']['subtotal'] );
ncm_check( 'redondeo 0 -> no divide por cero', 12000000.0, $cero['precio_final'] );
ncm_check( 'margen 0 -> precio = costo', 12000000.0, $cero['precio_calculado'] );
ncm_check( 'los 15 diseños con gema en 0', array(), $fallos_cero );
ncm_check( 'sin avisos ni errores de PHP', array(), $errores_php );

echo "\n== El AJAX atiende a anónimos, pero filtrado (Fase 7) ==\n";

$fuente_publica = file_get_contents( __DIR__ . '/../public/class-ncm-shortcode.php' );

ncm_check( 'atiende peticiones anónimas', true, false !== strpos( $fuente_publica, 'wp_ajax_nopriv_' ) );
ncm_check( 'el handler verifica el nonce', true, false !== strpos( $fuente_publica, 'check_ajax_referer' ) );
ncm_check( 'y el permiso sale de la sesión', true, false !== strpos( $fuente_publica, 'is_user_logged_in' ) );
ncm_check( 'el shortcode interno exige sesión', true, false !== strpos( $fuente_publica, 'render_interna' ) );

// El detalle del filtrado vive en tests/test-respuesta.php.

echo "\n== NCM_Data sobre la semilla ==\n";

ncm_check( 'tipos de joya', array( 'Anillo', 'Aretes', 'Pulsera', 'Dije' ), NCM_Data::get_tipos() );
ncm_check( 'diseños de Anillo', 4, count( NCM_Data::get_disenos_por_tipo( 'Anillo' ) ) );
ncm_check( 'precio Rubí natural', 700000.0, NCM_Data::get_precio_gema( 'Rubí', 'Natural' ) );
ncm_check( 'precio Rubí laboratorio', 160000.0, NCM_Data::get_precio_gema( 'Rubí', 'Laboratorio' ) );
ncm_check( 'gema inexistente', null, NCM_Data::get_precio_gema( 'Ópalo', 'Natural' ) );
ncm_check( 'metales disponibles', 5, count( NCM_Data::get_metales_disponibles() ) );
ncm_check( 'find_diseno por código', 'Bangle (Rígida)', NCM_Data::find_diseno_por_codigo( 'PU-BAN' )['diseno'] );

echo "\n== Guardar y releer config ==\n";

$modificada = NCM_Data::semilla_cruda();
$modificada['parametros']['margen_comercial'] = 0.5;
NCM_Data::guardar_config( $modificada );
NCM_Data::limpiar_cache();

ncm_check( 'margen persistido', 0.5, NCM_Data::get_parametro( 'margen_comercial' ) );

NCM_Data::restaurar_semilla();
NCM_Data::limpiar_cache();

ncm_check( 'semilla restaurada', 0.35, NCM_Data::get_parametro( 'margen_comercial' ) );

$calc_wp = NCM_Calculator::desde_config();
$h       = $calc_wp->calcular(
	array(
		'tipo'   => 'Pulsera',
		'diseno' => 'Bangle (Rígida)',
		'origen' => 'Natural',
		'gema'   => 'Diamante',
		'talla'  => 'Redonda',
		'metal'  => 'Oro blanco',
	)
);

ncm_check( 'caso A desde la config guardada', 14480000.0, $h['precio_final'] );

echo "\n== Piezas sin gemas: no se piden, no se cobran ==\n";

/*
 * cant_gemas = 0 significa que la pieza es solo metal. Origen, gema y talla no
 * aplican: ni se exigen ni pueden mover el precio. Ojo con la talla, que es la
 * trampa: su ajuste se suma dentro del componente gema, así que sin esta regla
 * una esclava pagaba por tallar una piedra que no existe.
 */
$sin_gemas = $calc->calcular(
	array( 'tipo' => 'Pulsera', 'diseno' => 'Esclava', 'origen' => '', 'gema' => '', 'talla' => '', 'metal' => 'Oro blanco' )
);

ncm_check( 'la esclava calcula sin gema ni talla', true, $sin_gemas['ok'] );
ncm_check( 'y se marca como pieza sin gemas', false, $sin_gemas['lleva_gemas'] );
ncm_check( 'el componente gema es cero', 0.0, $sin_gemas['gema']['subtotal'] );
ncm_check( 'el desglose no trae sección de gema', false, in_array( 'Gema', array_column( NCM_Calculator::desglose( $sin_gemas ), 'etiqueta' ), true ) );

// Mandar gema y talla no cambia nada: el motor las descarta.
$con_ruido = $calc->calcular(
	array( 'tipo' => 'Pulsera', 'diseno' => 'Esclava', 'origen' => 'Natural', 'gema' => 'Diamante', 'talla' => 'Marquesa', 'metal' => 'Oro blanco' )
);

ncm_check( 'mandar gema no altera el precio', $sin_gemas['precio_final'], $con_ruido['precio_final'] );
ncm_check( 'y la entrada queda limpia', '', $con_ruido['entrada']['gema'] );
ncm_check( 'la talla también', '', $con_ruido['entrada']['talla'] );

// El ajuste de talla, que era el bug de verdad.
$config_ajuste = NCM_Data::semilla();

foreach ( $config_ajuste['tallas'] as $i => $fila ) {
	if ( 'Marquesa' === $fila['talla'] ) {
		$config_ajuste['tallas'][ $i ]['ajuste'] = 300000;
	}
}

$calc_ajuste = new NCM_Calculator( $config_ajuste );

ncm_check(
	'una talla con ajuste no encarece una pieza sin gemas',
	$calc_ajuste->calcular( array( 'tipo' => 'Pulsera', 'diseno' => 'Bangle (Rígida)', 'origen' => '', 'gema' => '', 'talla' => '', 'metal' => 'Oro blanco' ) )['precio_final'],
	$calc_ajuste->calcular( array( 'tipo' => 'Pulsera', 'diseno' => 'Bangle (Rígida)', 'origen' => 'Natural', 'gema' => 'Diamante', 'talla' => 'Marquesa', 'metal' => 'Oro blanco' ) )['precio_final']
);

// Pero en una que sí lleva, el ajuste tiene que seguir aplicándose.
$tennis_redonda  = $calc_ajuste->calcular( array( 'tipo' => 'Pulsera', 'diseno' => 'Tennis', 'origen' => 'Natural', 'gema' => 'Zafiro', 'talla' => 'Redonda', 'metal' => 'Oro blanco' ) );
$tennis_marquesa = $calc_ajuste->calcular( array( 'tipo' => 'Pulsera', 'diseno' => 'Tennis', 'origen' => 'Natural', 'gema' => 'Zafiro', 'talla' => 'Marquesa', 'metal' => 'Oro blanco' ) );

ncm_check( 'y en una que sí lleva, sigue aplicándose', true, $tennis_marquesa['precio_final'] > $tennis_redonda['precio_final'] );

// Al revés: si el diseño lleva gemas, no se puede omitir la gema.
$falta = $calc->calcular(
	array( 'tipo' => 'Anillo', 'diseno' => 'Solitario', 'origen' => '', 'gema' => '', 'talla' => '', 'metal' => 'Oro blanco' )
);

ncm_check( 'un diseño con gemas sigue exigiéndolas', false, $falta['ok'] );
ncm_check( 'y dice por qué', 'gema_no_encontrada', $falta['error_codigo'] );

echo "\n----------------------------------------\n";

if ( $fallos > 0 ) {
	echo "RESULTADO: {$fallos} de {$total} pruebas fallaron.\n\n";
	exit( 1 );
}

echo "RESULTADO: {$total} pruebas, todas OK.\n\n";
exit( 0 );
