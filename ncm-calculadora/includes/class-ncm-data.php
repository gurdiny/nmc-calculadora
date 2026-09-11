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
				'whatsapp_activo'    => true,
				'whatsapp_numero'    => '',
				'whatsapp_mensaje'   => "Hola, quiero cotizar esta pieza:\n\n{tipo} · {diseno}\nGema: {gema} ({origen})\nTalla: {talla}\nMetal: {metal}\n\nPrecio estimado: {precio}",
				'paleta'             => 'ncm',
				'color_acento'       => '#354C3F',
				'color_acento_texto' => '#FFFFFF',
				'color_tinta'        => '#1D1E1B',
				'color_fondo'        => '#FFFFFF',
				'color_fondo_alt'    => '#F2EEE3',
				'color_linea'        => '#E5DECC',
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
			'whatsapp_activo'    => true,
			'whatsapp_numero'    => '',
			'whatsapp_mensaje'   => '',
			'paleta'             => 'ncm',
			'color_acento'       => '#354C3F',
			'color_acento_texto' => '#FFFFFF',
			'color_tinta'        => '#1D1E1B',
			'color_fondo'        => '#FFFFFF',
			'color_fondo_alt'    => '#F2EEE3',
			'color_linea'        => '#E5DECC',
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

		$param['whatsapp_activo']  = self::a_booleano( $param['whatsapp_activo'] );
		$param['whatsapp_numero']  = self::normalizar_telefono( $param['whatsapp_numero'] );
		$param['whatsapp_mensaje'] = (string) $param['whatsapp_mensaje'];

		$param['paleta'] = in_array( $param['paleta'], self::get_paletas(), true ) ? $param['paleta'] : 'ncm';

		foreach ( array( 'color_acento', 'color_acento_texto', 'color_tinta', 'color_fondo', 'color_fondo_alt', 'color_linea' ) as $clave ) {
			$param[ $clave ] = self::normalizar_color( $param[ $clave ], $defaults_param[ $clave ] );
		}

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

	/** Paletas disponibles para el formulario. */
	public static function get_paletas() {
		return array( 'ncm', 'claro', 'oscuro', 'personalizada' );
	}

	/**
	 * Deja un número de WhatsApp en el formato que espera wa.me.
	 *
	 * Solo dígitos, con indicativo de país y sin `+`, espacios ni guiones. Se
	 * descarta lo que no parezca un número de teléfono internacional.
	 *
	 * @param mixed $valor Número tal como se escribió.
	 * @return string Número limpio, o '' si no es plausible.
	 */
	public static function normalizar_telefono( $valor ) {
		$digitos = preg_replace( '/\D+/', '', (string) $valor );

		if ( '' === $digitos ) {
			return '';
		}

		// Los números internacionales van de 7 a 15 dígitos (E.164).
		$largo = strlen( $digitos );

		return ( $largo >= 7 && $largo <= 15 ) ? $digitos : '';
	}

	/**
	 * Valida un color hexadecimal.
	 *
	 * @param mixed  $valor    Color recibido.
	 * @param string $fallback Valor por defecto si no es válido.
	 * @return string
	 */
	public static function normalizar_color( $valor, $fallback = '#000000' ) {
		$valor = trim( (string) $valor );

		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $valor ) ? strtoupper( $valor ) : $fallback;
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

		/*
		 * Sin autoload: la config ronda los 6 KB y solo hace falta en el panel y
		 * en las páginas que llevan un shortcode de la calculadora. Cargarla en
		 * cada petición del sitio —incluidas las que no la usan— sale más caro
		 * que la consulta extra que cuesta leerla cuando toca.
		 */
		$guardada = update_option( self::OPTION, $normalizada, false );

		self::asegurar_sin_autoload();

		return $guardada;
	}

	/**
	 * Fuerza `autoload = off` en la opción.
	 *
	 * `update_option()` sale antes de tiempo cuando el valor no cambia, así que
	 * el parámetro `$autoload` no basta: en una instalación que ya venía de una
	 * versión anterior la opción seguiría autocargándose hasta que alguien
	 * editara algo. Esto lo corrige en cuanto se guarda o se activa el plugin.
	 *
	 * `wp_set_option_autoload()` existe desde WordPress 6.4; en versiones
	 * anteriores la opción se queda como esté, que es el comportamiento previo.
	 */
	public static function asegurar_sin_autoload() {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::OPTION, false );
		}
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
	 * Devuelve el id de imagen solo si se puede mostrar en público.
	 *
	 * El panel guarda cualquier id de adjunto que se elija en la mediateca, y
	 * ahí caben cosas que no deberían acabar en la página pública: un PDF, un
	 * adjunto de una entrada en borrador o privada, o uno que ya se borró. Esto
	 * se comprueba **al mostrar**, no al guardar, porque es legítimo asignar una
	 * imagen cuya entrada padre todavía no está publicada y publicarla después.
	 *
	 * @param int $id Id del adjunto.
	 * @return int El mismo id si es publicable, 0 si no.
	 */
	public static function imagen_publicable( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return 0;
		}

		// Que exista y sea una imagen de verdad (no un PDF ni un vídeo).
		if ( ! function_exists( 'wp_attachment_is_image' ) || ! wp_attachment_is_image( $id ) ) {
			return 0;
		}

		/*
		 * Para un adjunto, get_post_status() resuelve el 'inherit' al estado de
		 * su entrada padre: un adjunto de un borrador devuelve 'draft', y uno de
		 * una entrada privada, 'private'. Solo pasa 'publish'.
		 */
		if ( 'publish' !== get_post_status( $id ) ) {
			return 0;
		}

		return $id;
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
			if ( $diseno['tipo'] !== $tipo ) {
				continue;
			}

			$imagen = self::imagen_publicable( $diseno['imagen'] );

			if ( $imagen ) {
				return $imagen;
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
