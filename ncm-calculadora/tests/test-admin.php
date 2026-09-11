<?php
/**
 * Pruebas del guardado del panel de configuración.
 *
 * Verifican que un "guardar" desde el admin no altere los números del Excel:
 * el margen se escribe en porcentaje y debe volver a la config como fracción,
 * y los casos A y B deben seguir dando exacto después del round trip.
 *
 * Se ejecutan fuera de WordPress con stubs mínimos:
 * `php tests/test-admin.php`.
 *
 * @package NCM_Calculadora
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'NCM_CALC_VERSION', 'test' );
define( 'NCM_CALC_URL', 'http://example.test/' );

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

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $texto ) {
		return trim( wp_strip_all_tags( (string) $texto ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $texto ) {
		return trim( wp_strip_all_tags( (string) $texto ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $valor ) {
		return abs( (int) $valor );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $texto ) {
		return strip_tags( (string) $texto );
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['ncm_test_transients'] = array();

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $clave, $valor, $ttl = 0 ) {
		$GLOBALS['ncm_test_transients'][ $clave ] = $valor;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $clave ) {
		return isset( $GLOBALS['ncm_test_transients'][ $clave ] ) ? $GLOBALS['ncm_test_transients'][ $clave ] : false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $clave ) {
		unset( $GLOBALS['ncm_test_transients'][ $clave ] );

		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

require_once __DIR__ . '/../includes/class-ncm-data.php';
require_once __DIR__ . '/../includes/class-ncm-calculator.php';
require_once __DIR__ . '/../admin/class-ncm-admin.php';

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

/**
 * Llama a un método privado estático de NCM_Admin.
 *
 * @param string $metodo Nombre del método.
 * @param array  $args   Argumentos.
 * @return mixed
 */
function ncm_privado( $metodo, $args ) {
	$ref = new ReflectionMethod( 'NCM_Admin', $metodo );
	$ref->setAccessible( true );

	return $ref->invokeArgs( null, $args );
}

/**
 * Vacía los avisos acumulados y devuelve los que había.
 *
 * @return array
 */
function ncm_avisos() {
	$ref = new ReflectionProperty( 'NCM_Admin', 'avisos' );
	$ref->setAccessible( true );

	$avisos = $ref->getValue();
	$ref->setValue( null, array() );

	return $avisos;
}

/**
 * ¿Algún aviso contiene este fragmento?
 *
 * @param array  $avisos    Avisos generados.
 * @param string $fragmento Texto a buscar.
 * @return bool
 */
function ncm_aviso_contiene( $avisos, $fragmento ) {
	foreach ( $avisos as $aviso ) {
		if ( false !== strpos( $aviso, $fragmento ) ) {
			return true;
		}
	}

	return false;
}

$semilla = NCM_Data::semilla();

echo "\n== Parámetros: porcentaje en pantalla, fracción en la base ==\n";

$param = ncm_privado(
	'sanitizar_parametros',
	array(
		array(
			'margen_comercial'   => '35',
			'factor_merma_metal' => '1.1',
			'redondeo_precio'    => '10000',
			'moneda'             => 'COP',
			'texto_nota'         => '  Nota de prueba  ',
		),
		$semilla['parametros'],
	)
);

ncm_check( 'margen 35 -> 0.35', 0.35, $param['margen_comercial'] );
ncm_check( 'merma', 1.1, $param['factor_merma_metal'] );
ncm_check( 'redondeo', 10000.0, $param['redondeo_precio'] );
ncm_check( 'moneda', 'COP', $param['moneda'] );
ncm_check( 'nota recortada', 'Nota de prueba', $param['texto_nota'] );

$param_coma = ncm_privado(
	'sanitizar_parametros',
	array(
		array(
			'margen_comercial'   => '35,5',
			'factor_merma_metal' => '1,15',
			'redondeo_precio'    => '10000',
			'moneda'             => 'COP',
			'texto_nota'         => '',
		),
		$semilla['parametros'],
	)
);

ncm_check( 'coma decimal en margen', 0.355, $param_coma['margen_comercial'] );
ncm_check( 'coma decimal en merma', 1.15, $param_coma['factor_merma_metal'] );

$param_negativo = ncm_privado(
	'sanitizar_parametros',
	array(
		array( 'margen_comercial' => '-10' ),
		$semilla['parametros'],
	)
);

ncm_check( 'margen negativo se corta en 0', 0.0, $param_negativo['margen_comercial'] );

echo "\n== Colecciones: filas vacías fuera, checkbox y decimales ==\n";

$metales = ncm_privado(
	'sanitizar_coleccion',
	array(
		'metales',
		array(
			0      => array(
				'metal'            => ' Oro blanco ',
				'precio_gramo'     => '650000',
				'factor_adicional' => '1',
				'disponible'       => '1',
			),
			1      => array(
				'metal'            => 'Platino',
				'precio_gramo'     => '300.000,5',
				'factor_adicional' => '1,2',
				'disponible'       => '0',
			),
			2      => array(
				'metal'            => '', // Fila vacía: se descarta.
				'precio_gramo'     => '999',
				'factor_adicional' => '1',
				'disponible'       => '1',
			),
			'__i__' => array(
				'metal'            => 'Plantilla',
				'precio_gramo'     => '1',
				'factor_adicional' => '1',
				'disponible'       => '1',
			),
		),
	)
);

ncm_check( 'filas conservadas', 2, count( $metales ) );
ncm_check( 'nombre recortado', 'Oro blanco', $metales[0]['metal'] );
ncm_check( 'disponible marcado', true, $metales[0]['disponible'] );
ncm_check( 'disponible desmarcado', false, $metales[1]['disponible'] );
ncm_check( 'factor con coma decimal', 1.2, $metales[1]['factor_adicional'] );
ncm_check( 'precio con miles y decimal', 300000.5, $metales[1]['precio_gramo'] );

$disenos = ncm_privado(
	'sanitizar_coleccion',
	array(
		'disenos',
		array(
			array(
				'codigo'       => 'AN-SOL',
				'tipo'         => 'Anillo',
				'diseno'       => 'Solitario',
				'peso_metal_g' => '3.2',
				'cant_gemas'   => '1',
				'ct_por_gema'  => '1',
				'mano_obra'    => '',
				'extras'       => '',
			),
			array(
				'codigo' => 'X',
				'tipo'   => 'Anillo',
				'diseno' => '', // Sin diseño: se descarta.
			),
		),
	)
);

ncm_check( 'diseños conservados', 1, count( $disenos ) );
ncm_check( 'peso decimal', 3.2, $disenos[0]['peso_metal_g'] );
ncm_check( 'campos numéricos vacíos -> 0', 0.0, $disenos[0]['mano_obra'] );

echo "\n== Lectura de números escritos a mano ==\n";

$numeros = array(
	array( '650000', 650000.0, 'entero plano' ),
	array( '3.2', 3.2, 'punto decimal' ),
	array( '1,15', 1.15, 'coma decimal' ),
	array( '300.000,5', 300000.5, 'miles con punto, decimal con coma' ),
	array( '300,000.5', 300000.5, 'miles con coma, decimal con punto' ),
	array( '1.234.567', 1234567.0, 'varios puntos son miles' ),
	array( '1,234,567', 1234567.0, 'varias comas son miles' ),
	array( '$ 650.000,00', 650000.0, 'con símbolo de moneda' ),
	array( '', 0.0, 'vacío' ),
	array( 'abc', 0.0, 'texto sin números' ),
	array( '-5', -5.0, 'negativo' ),
);

foreach ( $numeros as $caso ) {
	ncm_check( $caso[2] . ' (' . $caso[0] . ')', $caso[1], ncm_privado( 'a_numero', array( $caso[0] ) ) );
}

echo "\n== Validación: códigos únicos ==\n";

ncm_avisos();

$dup = ncm_privado(
	'sanitizar_coleccion',
	array(
		'disenos',
		array(
			array( 'codigo' => 'AN-SOL', 'tipo' => 'Anillo', 'diseno' => 'Solitario', 'peso_metal_g' => '3.2' ),
			array( 'codigo' => 'an-sol', 'tipo' => 'Anillo', 'diseno' => 'Trilogía', 'peso_metal_g' => '3' ),
			array( 'codigo' => 'AN-TRI', 'tipo' => 'Anillo', 'diseno' => 'Trilogía', 'peso_metal_g' => '3' ),
		),
	)
);
$avisos = ncm_avisos();

ncm_check( 'código repetido descartado', 2, count( $dup ) );
ncm_check( 'sobrevive el primero', 'AN-SOL', $dup[0]['codigo'] );
ncm_check( 'y la fila sin choque', 'AN-TRI', $dup[1]['codigo'] );
ncm_check( 'avisa del código repetido', true, ncm_aviso_contiene( $avisos, 'el código' ) );

$dup_combo = ncm_privado(
	'sanitizar_coleccion',
	array(
		'disenos',
		array(
			array( 'codigo' => 'AN-SOL', 'tipo' => 'Anillo', 'diseno' => 'Solitario' ),
			array( 'codigo' => 'AN-XXX', 'tipo' => 'Anillo', 'diseno' => 'Solitario' ),
		),
	)
);
$avisos = ncm_avisos();

ncm_check( 'combinación tipo+diseño repetida descartada', 1, count( $dup_combo ) );
ncm_check( 'avisa de la combinación', true, ncm_aviso_contiene( $avisos, 'combinación' ) );

$dup_metal = ncm_privado(
	'sanitizar_coleccion',
	array(
		'metales',
		array(
			array( 'metal' => 'Oro blanco', 'precio_gramo' => '650000', 'factor_adicional' => '1', 'disponible' => '1' ),
			array( 'metal' => 'Oro Blanco', 'precio_gramo' => '700000', 'factor_adicional' => '1', 'disponible' => '1' ),
		),
	)
);
ncm_avisos();

ncm_check( 'metal repetido (sin importar mayúsculas) descartado', 1, count( $dup_metal ) );
ncm_check( 'gana el primero', 650000.0, $dup_metal[0]['precio_gramo'] );

echo "\n== Validación: números no negativos ==\n";

$neg = ncm_privado(
	'sanitizar_coleccion',
	array(
		'metales',
		array(
			array( 'metal' => 'Plata', 'precio_gramo' => '-250000', 'factor_adicional' => '-1', 'disponible' => '1' ),
		),
	)
);
$avisos = ncm_avisos();

ncm_check( 'precio negativo -> 0', 0.0, $neg[0]['precio_gramo'] );
ncm_check( 'factor negativo -> 0', 0.0, $neg[0]['factor_adicional'] );
ncm_check( 'avisa del negativo', true, ncm_aviso_contiene( $avisos, 'negativo' ) );

$param_neg = ncm_privado( 'sanitizar_parametros', array( array( 'margen_comercial' => '-10' ), $semilla['parametros'] ) );
$avisos    = ncm_avisos();

ncm_check( 'margen negativo -> 0', 0.0, $param_neg['margen_comercial'] );
ncm_check( 'avisa del margen negativo', true, ncm_aviso_contiene( $avisos, 'Margen comercial' ) );

echo "\n== Validación: campos requeridos y avisos ==\n";

$sin_codigo = ncm_privado(
	'sanitizar_coleccion',
	array(
		'disenos',
		array(
			array( 'codigo' => '', 'tipo' => 'Anillo', 'diseno' => 'Nuevo' ),
			array( 'codigo' => 'AN-OK', 'tipo' => 'Anillo', 'diseno' => 'Otro' ),
		),
	)
);
$avisos = ncm_avisos();

ncm_check( 'fila sin código descartada', 1, count( $sin_codigo ) );
ncm_check( 'avisa del campo faltante', true, ncm_aviso_contiene( $avisos, 'Código' ) );

$con_vacia = ncm_privado(
	'sanitizar_coleccion',
	array(
		'metales',
		array(
			array( 'metal' => 'Plata', 'precio_gramo' => '250000', 'factor_adicional' => '1', 'disponible' => '1' ),
			array( 'metal' => '', 'precio_gramo' => '', 'factor_adicional' => '', 'disponible' => '1' ),
		),
	)
);
$avisos = ncm_avisos();

ncm_check( 'fila del todo vacía se ignora', 1, count( $con_vacia ) );
ncm_check( 'y no genera ruido', 0, count( $avisos ) );

$vacia_total = ncm_privado( 'sanitizar_coleccion', array( 'gemas', array() ) );
$avisos      = ncm_avisos();

ncm_check( 'colección sin filas válidas -> null', null, $vacia_total );
ncm_check( 'avisa que conserva lo anterior', true, ncm_aviso_contiene( $avisos, 'conservó' ) );

echo "\n== Round trip completo: guardar cada pestaña sin cambios ==\n";

NCM_Data::restaurar_semilla();
NCM_Data::limpiar_cache();

$config = NCM_Data::get_config();

// Se simula reenviar cada pestaña tal como la pinta el panel.
$config['parametros'] = ncm_privado(
	'sanitizar_parametros',
	array(
		array(
			'margen_comercial'   => (string) ( $config['parametros']['margen_comercial'] * 100 ),
			'factor_merma_metal' => (string) $config['parametros']['factor_merma_metal'],
			'redondeo_precio'    => (string) $config['parametros']['redondeo_precio'],
			'moneda'             => $config['parametros']['moneda'],
			'texto_nota'         => $config['parametros']['texto_nota'],
		),
		$config['parametros'],
	)
);

foreach ( array( 'disenos', 'gemas', 'tallas', 'metales' ) as $coleccion ) {
	$config[ $coleccion ] = ncm_privado( 'sanitizar_coleccion', array( $coleccion, $config[ $coleccion ] ) );
}

NCM_Data::guardar_config( $config );
NCM_Data::limpiar_cache();

ncm_check( 'margen sigue en fracción', 0.35, NCM_Data::get_parametro( 'margen_comercial' ) );
ncm_check( 'siguen los 15 diseños', 15, count( NCM_Data::get_disenos() ) );
ncm_check( 'siguen las 4 gemas', 4, count( NCM_Data::get_gemas() ) );
ncm_check( 'siguen las 5 tallas', 5, count( NCM_Data::get_tallas() ) );
ncm_check( 'siguen los 5 metales', 5, count( NCM_Data::get_metales() ) );
ncm_check( 'nota intacta', $semilla['parametros']['texto_nota'], NCM_Data::get_parametro( 'texto_nota' ) );

$calc = NCM_Calculator::desde_config();

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

ncm_check( 'caso A tras el round trip', 14480000.0, $a['precio_final'] );
ncm_check( 'caso B tras el round trip', 19290000.0, $b['precio_final'] );

echo "\n== Editar un precio en el panel cambia el resultado (Fase 7) ==\n";

NCM_Data::restaurar_semilla();
NCM_Data::limpiar_cache();

$antes = NCM_Calculator::desde_config()->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

ncm_check( 'antes de editar', 14480000.0, $antes['precio_final'] );

// Se reenvía la pestaña de metales con el Oro blanco a 700.000/g.
$config  = NCM_Data::get_config();
$metales = $config['metales'];

foreach ( $metales as $i => $fila ) {
	if ( 'Oro blanco' === $fila['metal'] ) {
		$metales[ $i ]['precio_gramo'] = '700000';
	}
}

$config['metales'] = ncm_privado( 'sanitizar_coleccion', array( 'metales', $metales ) );
NCM_Data::guardar_config( $config );
NCM_Data::limpiar_cache();
ncm_avisos();

$despues = NCM_Calculator::desde_config()->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );
$plata   = NCM_Calculator::desde_config()->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Plata' );

// 15 x 1.1 x 700000 x 1.35 = 15.592.500 -> 15.600.000
ncm_check( 'después de editar', 15600000.0, $despues['precio_final'] );
ncm_check( 'el precio nuevo llega al desglose', 700000.0, $despues['metal']['precio_gramo'] );
ncm_check( 'los demás metales no se tocaron', 250000.0, $plata['metal']['precio_gramo'] );

// Un cambio de parámetro también se refleja.
$config = NCM_Data::get_config();
$config['parametros'] = ncm_privado(
	'sanitizar_parametros',
	array(
		array(
			'margen_comercial'   => '50',
			'factor_merma_metal' => '1.1',
			'redondeo_precio'    => '10000',
			'moneda'             => 'COP',
			'texto_nota'         => '',
		),
		$config['parametros'],
	)
);
NCM_Data::guardar_config( $config );
NCM_Data::limpiar_cache();
ncm_avisos();

// 15 x 1.1 x 700000 x 1.5 = 17.325.000 -> 17.330.000
ncm_check( 'margen al 50 % cambia el precio', 17330000.0, NCM_Calculator::desde_config()->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' )['precio_final'] );

NCM_Data::restaurar_semilla();
NCM_Data::limpiar_cache();

ncm_check( 'restaurar deja el caso A exacto otra vez', 14480000.0, NCM_Calculator::desde_config()->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' )['precio_final'] );

echo "\n== Imagen de cada fila (mediateca) ==\n";

$con_imagen = ncm_privado(
	'sanitizar_coleccion',
	array(
		'gemas',
		array(
			array( 'tipo_gema' => 'Rubí', 'precio_natural' => '700000', 'precio_laboratorio' => '160000', 'imagen' => '42' ),
			array( 'tipo_gema' => 'Zafiro', 'precio_natural' => '400000', 'precio_laboratorio' => '160000', 'imagen' => '' ),
			array( 'tipo_gema' => 'Esmeralda', 'precio_natural' => '1000000', 'precio_laboratorio' => '268000', 'imagen' => '-7' ),
		),
	)
);
ncm_avisos();

ncm_check( 'id de adjunto guardado', 42, $con_imagen[0]['imagen'] );
ncm_check( 'sin imagen -> 0', 0, $con_imagen[1]['imagen'] );
ncm_check( 'id negativo -> absoluto', 7, $con_imagen[2]['imagen'] );

// Una fila con solo imagen sigue siendo una fila vacía: no ensucia el catálogo.
$solo_imagen = ncm_privado(
	'sanitizar_coleccion',
	array(
		'metales',
		array(
			array( 'metal' => 'Plata', 'precio_gramo' => '250000', 'factor_adicional' => '1', 'disponible' => '1', 'imagen' => '3' ),
			array( 'metal' => '', 'precio_gramo' => '', 'factor_adicional' => '', 'imagen' => '9' ),
		),
	)
);
$avisos = ncm_avisos();

ncm_check( 'fila con solo imagen se ignora', 1, count( $solo_imagen ) );
ncm_check( 'y sin aviso de ruido', 0, count( $avisos ) );

echo "\n== Catálogos para el front ==\n";

$cat = NCM_Data::get_catalogos();

ncm_check( 'tipos', array( 'Anillo', 'Aretes', 'Pulsera', 'Dije' ), $cat['tipos'] );
ncm_check( 'diseños de Pulsera', array( 'Tennis', 'Esclava', 'Bangle (Rígida)' ), $cat['disenos_por_tipo']['Pulsera'] );
ncm_check( 'gemas', array( 'Rubí', 'Zafiro', 'Esmeralda', 'Diamante' ), $cat['gemas'] );
ncm_check( 'orígenes', array( 'Natural', 'Laboratorio' ), $cat['origenes'] );
ncm_check( 'tallas disponibles', 5, count( $cat['tallas'] ) );
ncm_check( 'metales disponibles', 5, count( $cat['metales'] ) );

echo "\n----------------------------------------\n";

if ( $fallos > 0 ) {
	echo "RESULTADO: {$fallos} de {$total} pruebas fallaron.\n\n";
	exit( 1 );
}

echo "RESULTADO: {$total} pruebas, todas OK.\n\n";
exit( 0 );
