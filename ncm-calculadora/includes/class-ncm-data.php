<?php
/**
 * Acceso a la configuración de la calculadora.
 *
 * Toda la configuración vive en una sola opción de WP (`ncm_calc_config`).
 * Esta clase es la única que sabe cómo está guardada: el resto del plugin
 * lee por nombre a través de los getters y buscadores de aquí.
 *
 * @package NCM_Calculadora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCM_Data {

	/** Clave de la opción en wp_options. */
	const OPTION = 'ncm_calc_config';

	/** Caché en memoria de la config ya normalizada. */
	private static $cache = null;

	/* ---------------------------------------------------------------------
	 * Datos semilla (valores exactos del Excel)
	 * ------------------------------------------------------------------ */

	/**
	 * Semilla en formato de filas (tal cual el documento de fases).
	 *
	 * @return array
	 */
	public static function semilla_cruda() {
		return array(
			'parametros' => array(
				'margen_comercial'   => 0.35,
				'factor_merma_metal' => 1.1,
				'redondeo_precio'    => 10000,
				'moneda'             => 'COP',
				'texto_publico'      => 'Calcula el precio estimado de tu joya a la medida: elige el tipo de pieza, el diseño, la gema, su origen, la talla y el metal, y obtén al instante un valor de referencia. Cada pieza de NCM se fabrica a mano y bajo pedido en Colombia, con gemas naturales o de laboratorio y metales certificados.',
				'texto_nota'         => 'Valor estimado para la configuración seleccionada. El precio final puede variar según talla, dimensiones, características específicas de la gema, origen de la gema y personalizaciones adicionales.',
			),

			// codigo, tipo, diseno, peso_metal_g, cant_gemas, ct_por_gema, mano_obra, extras
			'disenos'    => array(
				array( 'AN-SOL', 'Anillo', 'Solitario', 3.2, 1, 1, 0, 0 ),
				array( 'AN-TRI', 'Anillo', 'Trilogía', 3, 3, 0.5, 0, 0 ),
				array( 'AN-ETE', 'Anillo', 'Eternity', 4, 15, 0.05, 0, 0 ),
				array( 'AN-COC', 'Anillo', 'Cocktail', 3.5, 1, 1, 0, 500000 ),
				array( 'AR-TSO', 'Aretes', 'Topos Solitario', 4, 2, 1, 0, 0 ),
				array( 'AR-TCO', 'Aretes', 'Topos Cocktail', 4, 2, 1, 0, 500000 ),
				array( 'AR-ETE', 'Aretes', 'Arete Eternity', 5, 10, 0.3, 0, 0 ),
				array( 'AR-LIN', 'Aretes', 'Topos en Línea', 5, 6, 0.2, 0, 0 ),
				array( 'PU-TEN', 'Pulsera', 'Tennis', 16, 30, 0.5, 0, 0 ),
				array( 'PU-ESC', 'Pulsera', 'Esclava', 18, 0, 0, 0, 0 ),
				array( 'PU-BAN', 'Pulsera', 'Bangle (Rígida)', 15, 0, 0, 0, 0 ),
				array( 'DI-HAL', 'Dije', 'Halo', 3.7, 10, 0.2, 0, 0 ),
				array( 'DI-SOL', 'Dije', 'Solitario', 3, 1, 1, 0, 0 ),
				array( 'DI-CRU', 'Dije', 'Cruz', 4, 5, 0.5, 0, 0 ),
				array( 'DI-INI', 'Dije', 'Iniciales', 4, 1, 0.05, 0, 0 ),
			),

			// tipo_gema, precio_natural, precio_laboratorio (por 1 ct)
			'gemas'      => array(
				array( 'Rubí', 700000, 160000 ),
				array( 'Zafiro', 400000, 160000 ),
				array( 'Esmeralda', 1000000, 268000 ),
				array( 'Diamante', 12000000, 150000 ),
			),

			// talla, ajuste (COP), disponible
			'tallas'     => array(
				array( 'Redonda', 0, true ),
				array( 'Cuadrada', 0, true ),
				array( 'Gota', 0, true ),
				array( 'Esmeralda', 0, true ),
				array( 'Marquesa', 0, true ),
			),

			// metal, precio_gramo, factor_adicional, disponible
			'metales'    => array(
				array( 'Oro amarillo', 650000, 1, true ),
				array( 'Oro blanco', 650000, 1, true ),
				array( 'Oro rosado', 650000, 1, true ),
				array( 'Plata', 250000, 1, true ),
				array( 'Platino', 300000, 1, true ),
			),

			'origenes'   => array( 'Natural', 'Laboratorio' ),
		);
	}

	/**
	 * Semilla ya convertida a arrays asociativos (formato de trabajo).
	 *
	 * @return array
	 */
	public static function semilla() {
		return self::normalizar( self::semilla_cruda() );
	}

	/* ---------------------------------------------------------------------
	 * Normalización: filas indexadas -> arrays asociativos
	 * ------------------------------------------------------------------ */

	/** Claves de cada colección, en el orden de las filas de la semilla. */
	private static function claves( $coleccion ) {
		$mapa = array(
			'disenos' => array( 'codigo', 'tipo', 'diseno', 'peso_metal_g', 'cant_gemas', 'ct_por_gema', 'mano_obra', 'extras', 'imagen' ),
			'gemas'   => array( 'tipo_gema', 'precio_natural', 'precio_laboratorio', 'imagen' ),
			'tallas'  => array( 'talla', 'ajuste', 'disponible', 'imagen' ),
			'metales' => array( 'metal', 'precio_gramo', 'factor_adicional', 'disponible', 'imagen' ),
		);

		return isset( $mapa[ $coleccion ] ) ? $mapa[ $coleccion ] : array();
	}

	/** Columnas numéricas por colección (se castean a float). */
	private static function columnas_numericas( $coleccion ) {
		$mapa = array(
			'disenos' => array( 'peso_metal_g', 'cant_gemas', 'ct_por_gema', 'mano_obra', 'extras' ),
			'gemas'   => array( 'precio_natural', 'precio_laboratorio' ),
			'tallas'  => array( 'ajuste' ),
			'metales' => array( 'precio_gramo', 'factor_adicional' ),
		);

		return isset( $mapa[ $coleccion ] ) ? $mapa[ $coleccion ] : array();
	}

	/**
	 * Deja la config en el formato de trabajo: parámetros completos y cada
	 * colección como lista de arrays asociativos.
	 *
	 * @param array $config Config cruda o ya normalizada.
	 * @return array
	 */
	public static function normalizar( $config ) {
		$defaults_param = array(
			'margen_comercial'   => 0.35,
			'factor_merma_metal' => 1.1,
			'redondeo_precio'    => 10000,
			'moneda'             => 'COP',
			'texto_publico'      => '',
			'texto_nota'         => '',
		);

		$out = array();

		$param = isset( $config['parametros'] ) && is_array( $config['parametros'] ) ? $config['parametros'] : array();
		$param = array_merge( $defaults_param, $param );

		$param['margen_comercial']   = (float) $param['margen_comercial'];
		$param['factor_merma_metal'] = (float) $param['factor_merma_metal'];
		$param['redondeo_precio']    = (float) $param['redondeo_precio'];
		$param['moneda']             = (string) $param['moneda'];
		$param['texto_publico']      = (string) $param['texto_publico'];
		$param['texto_nota']         = (string) $param['texto_nota'];

		$out['parametros'] = $param;

		foreach ( array( 'disenos', 'gemas', 'tallas', 'metales' ) as $coleccion ) {
			$claves    = self::claves( $coleccion );
			$numericas = self::columnas_numericas( $coleccion );
			$filas     = isset( $config[ $coleccion ] ) && is_array( $config[ $coleccion ] ) ? $config[ $coleccion ] : array();
			$out[ $coleccion ] = array();

			foreach ( $filas as $fila ) {
				if ( ! is_array( $fila ) ) {
					continue;
				}

				$asoc = array();

				foreach ( $claves as $i => $clave ) {
					if ( array_key_exists( $clave, $fila ) ) {
						$asoc[ $clave ] = $fila[ $clave ];
					} elseif ( array_key_exists( $i, $fila ) ) {
						$asoc[ $clave ] = $fila[ $i ];
					} else {
						$asoc[ $clave ] = '';
					}
				}

				foreach ( $numericas as $clave ) {
					$asoc[ $clave ] = (float) $asoc[ $clave ];
				}

				if ( array_key_exists( 'disponible', $asoc ) ) {
					$asoc['disponible'] = self::a_booleano( $asoc['disponible'] );
				}

				// La imagen es un id de adjunto de la mediateca; 0 = sin imagen.
				if ( array_key_exists( 'imagen', $asoc ) ) {
					$asoc['imagen'] = max( 0, (int) $asoc['imagen'] );
				}

				foreach ( $claves as $clave ) {
					if ( is_string( $asoc[ $clave ] ) ) {
						$asoc[ $clave ] = trim( $asoc[ $clave ] );
					}
				}


				$out[ $coleccion ][] = $asoc;
			}
		}

		$origenes = isset( $config['origenes'] ) && is_array( $config['origenes'] ) && $config['origenes']
			? array_values( $config['origenes'] )
			: array( 'Natural', 'Laboratorio' );

		$out['origenes'] = $origenes;

		return $out;
	}

	/** Interpreta "0", "", "no", false... como falso. */
	public static function a_booleano( $valor ) {
		if ( is_bool( $valor ) ) {
			return $valor;
		}

		if ( is_numeric( $valor ) ) {
			return (float) $valor != 0.0; // phpcs:ignore
		}

		$valor = strtolower( trim( (string) $valor ) );

		return ! in_array( $valor, array( '', '0', 'no', 'false', 'off' ), true );
	}

	/* ---------------------------------------------------------------------
	 * Lectura / escritura de la config
	 * ------------------------------------------------------------------ */

	/**
	 * Config actual (guardada o semilla si aún no hay nada).
	 *
	 * @return array
	 */
	public static function get_config() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$guardada = get_option( self::OPTION, null );

		if ( ! is_array( $guardada ) || empty( $guardada ) ) {
			$guardada = self::semilla_cruda();
		}

		self::$cache = self::normalizar( $guardada );

		return self::$cache;
	}

	/**
	 * Guarda la config (normalizándola antes) y limpia la caché.
	 *
	 * @param array $config Config a guardar.
	 * @return bool
	 */
	public static function guardar_config( $config ) {
		$normalizada = self::normalizar( $config );
		self::$cache = $normalizada;

		return update_option( self::OPTION, $normalizada );
	}

	/** Restablece la config a los valores del Excel. */
	public static function restaurar_semilla() {
		return self::guardar_config( self::semilla_cruda() );
	}

	/** Limpia la caché en memoria (útil en tests y tras guardar). */
	public static function limpiar_cache() {
		self::$cache = null;
	}

	/* ---------------------------------------------------------------------
	 * Getters
	 * ------------------------------------------------------------------ */

	public static function get_parametros() {
		$config = self::get_config();

		return $config['parametros'];
	}

	/**
	 * Un parámetro suelto.
	 *
	 * @param string $clave    Nombre del parámetro.
	 * @param mixed  $fallback Valor si no existe.
	 * @return mixed
	 */
	public static function get_parametro( $clave, $fallback = null ) {
		$param = self::get_parametros();

		return array_key_exists( $clave, $param ) ? $param[ $clave ] : $fallback;
	}

	public static function get_disenos() {
		$config = self::get_config();

		return $config['disenos'];
	}

	public static function get_gemas() {
		$config = self::get_config();

		return $config['gemas'];
	}

	public static function get_tallas() {
		$config = self::get_config();

		return $config['tallas'];
	}

	public static function get_metales() {
		$config = self::get_config();

		return $config['metales'];
	}

	public static function get_origenes() {
		$config = self::get_config();

		return $config['origenes'];
	}

	/** Tipos de joya únicos, en el orden en que aparecen en la matriz. */
	public static function get_tipos() {
		$tipos = array();

		foreach ( self::get_disenos() as $diseno ) {
			if ( '' !== $diseno['tipo'] && ! in_array( $diseno['tipo'], $tipos, true ) ) {
				$tipos[] = $diseno['tipo'];
			}
		}

		return $tipos;
	}

	/**
	 * Imagen que representa a un tipo de joya.
	 *
	 * Los tipos no son una matriz propia: salen de la columna `tipo` de los
	 * diseños. Se usa la imagen del primer diseño de ese tipo que tenga una.
	 *
	 * @param string $tipo Tipo de joya.
	 * @return int Id del adjunto, o 0 si ninguno tiene imagen.
	 */
	public static function get_imagen_tipo( $tipo ) {
		foreach ( self::get_disenos() as $diseno ) {
			if ( $diseno['tipo'] === $tipo && ! empty( $diseno['imagen'] ) ) {
				return (int) $diseno['imagen'];
			}
		}

		return 0;
	}

	/**
	 * Diseños de un tipo de joya.
	 *
	 * @param string $tipo Tipo de joya.
	 * @return array
	 */
	public static function get_disenos_por_tipo( $tipo ) {
		$out = array();

		foreach ( self::get_disenos() as $diseno ) {
			if ( $diseno['tipo'] === $tipo ) {
				$out[] = $diseno;
			}
		}

		return $out;
	}

	/** Solo las tallas marcadas como disponibles. */
	public static function get_tallas_disponibles() {
		return array_values(
			array_filter(
				self::get_tallas(),
				function ( $talla ) {
					return ! empty( $talla['disponible'] );
				}
			)
		);
	}

	/** Solo los metales marcados como disponibles. */
	public static function get_metales_disponibles() {
		return array_values(
			array_filter(
				self::get_metales(),
				function ( $metal ) {
					return ! empty( $metal['disponible'] );
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Buscadores
	 * ------------------------------------------------------------------ */

	/**
	 * Busca un diseño por tipo + nombre de diseño.
	 *
	 * @param string $tipo   Tipo de joya.
	 * @param string $diseno Nombre del diseño.
	 * @return array|null
	 */
	public static function find_diseno( $tipo, $diseno ) {
		foreach ( self::get_disenos() as $fila ) {
			if ( $fila['tipo'] === $tipo && $fila['diseno'] === $diseno ) {
				return $fila;
			}
		}

		return null;
	}

	/**
	 * Busca un diseño por su código.
	 *
	 * @param string $codigo Código del diseño (ej. PU-BAN).
	 * @return array|null
	 */
	public static function find_diseno_por_codigo( $codigo ) {
		foreach ( self::get_disenos() as $fila ) {
			if ( $fila['codigo'] === $codigo ) {
				return $fila;
			}
		}

		return null;
	}

	/**
	 * Busca una gema por nombre.
	 *
	 * @param string $tipo_gema Nombre de la gema.
	 * @return array|null
	 */
	public static function find_gema( $tipo_gema ) {
		foreach ( self::get_gemas() as $fila ) {
			if ( $fila['tipo_gema'] === $tipo_gema ) {
				return $fila;
			}
		}

		return null;
	}

	/**
	 * Busca una talla por nombre.
	 *
	 * @param string $talla Nombre de la talla.
	 * @return array|null
	 */
	public static function find_talla( $talla ) {
		foreach ( self::get_tallas() as $fila ) {
			if ( $fila['talla'] === $talla ) {
				return $fila;
			}
		}

		return null;
	}

	/**
	 * Busca un metal por nombre.
	 *
	 * @param string $metal Nombre del metal.
	 * @return array|null
	 */
	public static function find_metal( $metal ) {
		foreach ( self::get_metales() as $fila ) {
			if ( $fila['metal'] === $metal ) {
				return $fila;
			}
		}

		return null;
	}

	/**
	 * Precio por 1 ct de una gema según su origen.
	 *
	 * @param string $tipo_gema Nombre de la gema.
	 * @param string $origen    'Natural' o 'Laboratorio'.
	 * @return float|null Null si la gema o el origen no existen.
	 */
	public static function get_precio_gema( $tipo_gema, $origen ) {
		$gema = self::find_gema( $tipo_gema );

		if ( null === $gema ) {
			return null;
		}

		if ( ! in_array( $origen, self::get_origenes(), true ) ) {
			return null;
		}

		return 'Laboratorio' === $origen
			? (float) $gema['precio_laboratorio']
			: (float) $gema['precio_natural'];
	}

	/**
	 * Catálogos que necesita el front para pintar los selectores.
	 *
	 * @return array
	 */
	public static function get_catalogos() {
		$disenos_por_tipo = array();

		foreach ( self::get_disenos() as $fila ) {
			$disenos_por_tipo[ $fila['tipo'] ][] = $fila['diseno'];
		}

		$gemas = array();

		foreach ( self::get_gemas() as $fila ) {
			$gemas[] = $fila['tipo_gema'];
		}

		$tallas = array();

		foreach ( self::get_tallas_disponibles() as $fila ) {
			$tallas[] = $fila['talla'];
		}

		$metales = array();

		foreach ( self::get_metales_disponibles() as $fila ) {
			$metales[] = $fila['metal'];
		}

		return array(
			'tipos'            => self::get_tipos(),
			'disenos_por_tipo' => $disenos_por_tipo,
			'origenes'         => self::get_origenes(),
			'gemas'            => $gemas,
			'tallas'           => $tallas,
			'metales'          => $metales,
		);
	}
}
