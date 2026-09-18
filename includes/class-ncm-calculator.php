<?php
/**
 * Motor de cálculo de la calculadora de joyas.
 *
 * Cálculo puro: no imprime HTML, no llama a funciones de WordPress y no
 * conoce ningún número del Excel. Recibe la configuración ya normalizada
 * (ver NCM_Data::normalizar) y trabaja solo con ella, así que se puede
 * ejecutar y testear fuera de WordPress.
 *
 * @package NCM_Calculadora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCM_Calculator {

	/** Texto de salida cuando la combinación no es calculable. */
	const ERROR_CONFIG = 'REVISAR CONFIGURACIÓN';

	/** Estado de un cálculo resuelto. */
	const ESTADO_OK = 'OK';

	/** Estado de una combinación que no se puede calcular. */
	const ESTADO_REVISAR = 'REVISAR_CONFIGURACION';

	/** Configuración normalizada. */
	private $config;

	/**
	 * @param array $config Configuración normalizada (NCM_Data::normalizar).
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Instancia el motor con la configuración guardada en WordPress.
	 *
	 * @return NCM_Calculator
	 */
	public static function desde_config() {
		return new self( NCM_Data::get_config() );
	}

	/**
	 * Calcula el precio de una configuración de joya.
	 *
	 * Admite las seis selecciones sueltas, en el orden del formulario, o un
	 * único array asociativo con las claves tipo, diseno, origen, gema, talla
	 * y metal:
	 *
	 *     $calc->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );
	 *     $calc->calcular( array( 'tipo' => 'Anillo', 'diseno' => 'Solitario', ... ) );
	 *
	 * @param array|string $tipo_joya Tipo de joya, o array con las 6 claves.
	 * @param string       $diseno    Nombre del diseño.
	 * @param string       $origen    'Natural' o 'Laboratorio'.
	 * @param string       $tipo_gema Nombre de la gema.
	 * @param string       $talla     Nombre de la talla.
	 * @param string       $metal     Nombre del metal.
	 * @return array Resultado con el desglose completo. Si no es calculable,
	 *               'ok' => false y 'estado' => REVISAR_CONFIGURACION.
	 */
	public function calcular( $tipo_joya, $diseno = '', $origen = '', $tipo_gema = '', $talla = '', $metal = '' ) {
		$seleccion = is_array( $tipo_joya )
			? $tipo_joya
			: array(
				'tipo'   => $tipo_joya,
				'diseno' => $diseno,
				'origen' => $origen,
				'gema'   => $tipo_gema,
				'talla'  => $talla,
				'metal'  => $metal,
			);

		$entrada = array(
			'tipo'   => $this->texto( $seleccion, 'tipo' ),
			'diseno' => $this->texto( $seleccion, 'diseno' ),
			'origen' => $this->texto( $seleccion, 'origen' ),
			'gema'   => $this->texto( $seleccion, 'gema' ),
			'talla'  => $this->texto( $seleccion, 'talla' ),
			'metal'  => $this->texto( $seleccion, 'metal' ),
		);

		$parametros = $this->config['parametros'];

		// 1. Tipo + diseño -> código de diseño.
		$fila_diseno = $this->buscar( 'disenos', array( 'tipo' => $entrada['tipo'], 'diseno' => $entrada['diseno'] ) );

		if ( null === $fila_diseno ) {
			return $this->error( $entrada, 'diseno_no_encontrado', 'La combinación de tipo de joya y diseño no existe en la matriz.' );
		}

		/*
		 * Una pieza sin gemas (cant_gemas = 0) no tiene origen, ni tipo de gema,
		 * ni talla que valgan: la esclava y el bangle son metal y nada más. No
		 * se piden y no se cobran.
		 *
		 * Ojo con la talla en particular: su ajuste se suma dentro del
		 * componente gema, así que sin esta condición una pieza de cero gemas
		 * pagaba por tallar una piedra que no existe en cuanto alguien
		 * configurara un ajuste distinto de cero desde el panel.
		 */
		$lleva_gemas = (float) $fila_diseno['cant_gemas'] > 0;

		$gema  = null;
		$talla = null;

		if ( $lleva_gemas ) {
			$gema = $this->buscar( 'gemas', array( 'tipo_gema' => $entrada['gema'] ) );

			if ( null === $gema ) {
				return $this->error(
					$entrada,
					'gema_no_encontrada',
					'' === $entrada['gema']
						? 'Falta el tipo de gema, y este diseño sí lleva.'
						: 'El tipo de gema no existe en la matriz.',
					$fila_diseno['codigo']
				);
			}

			if ( ! in_array( $entrada['origen'], $this->config['origenes'], true ) ) {
				return $this->error(
					$entrada,
					'origen_invalido',
					'' === $entrada['origen']
						? 'Falta el origen de la gema, y este diseño sí lleva.'
						: 'El origen de la gema no es válido.',
					$fila_diseno['codigo']
				);
			}

			$talla = $this->buscar( 'tallas', array( 'talla' => $entrada['talla'] ) );

			if ( null === $talla ) {
				return $this->error(
					$entrada,
					'talla_no_encontrada',
					'' === $entrada['talla']
						? 'Falta la talla, y este diseño sí lleva gemas.'
						: 'La talla no existe en la matriz.',
					$fila_diseno['codigo']
				);
			}

			if ( empty( $talla['disponible'] ) ) {
				return $this->error( $entrada, 'talla_no_disponible', 'La talla seleccionada no está disponible.', $fila_diseno['codigo'] );
			}
		} else {
			// Lo que venga en esos tres campos se descarta: no aplica a la pieza.
			$entrada['origen'] = '';
			$entrada['gema']   = '';
			$entrada['talla']  = '';
		}

		$metal = $this->buscar( 'metales', array( 'metal' => $entrada['metal'] ) );

		if ( null === $metal ) {
			return $this->error( $entrada, 'metal_no_encontrado', 'El metal no existe en la matriz.', $fila_diseno['codigo'] );
		}

		if ( empty( $metal['disponible'] ) ) {
			return $this->error( $entrada, 'metal_no_disponible', 'El metal seleccionado no está disponible.', $fila_diseno['codigo'] );
		}

		// 2. Datos del diseño.
		$peso_base   = (float) $fila_diseno['peso_metal_g'];
		$cant_gemas  = (float) $fila_diseno['cant_gemas'];
		$ct_por_gema = (float) $fila_diseno['ct_por_gema'];
		$mano_obra   = (float) $fila_diseno['mano_obra'];
		$extras      = (float) $fila_diseno['extras'];

		// 3. Componente gema. Sin gemas no hay nada que sumar, ni el ajuste de talla.
		$precio_ct      = 0.0;
		$ajuste_talla   = 0.0;
		$ct_total       = 0.0;
		$subtotal_gemas = 0.0;

		if ( $lleva_gemas ) {
			$precio_ct      = 'Laboratorio' === $entrada['origen']
				? (float) $gema['precio_laboratorio']
				: (float) $gema['precio_natural'];
			$ajuste_talla   = (float) $talla['ajuste'];
			$ct_total       = $cant_gemas * $ct_por_gema;
			$subtotal_gemas = $precio_ct * $cant_gemas * $ct_por_gema;
		}

		$comp_gema = $subtotal_gemas + $ajuste_talla;

		// 4. Componente metal.
		$factor_merma      = (float) $parametros['factor_merma_metal'];
		$precio_gramo      = (float) $metal['precio_gramo'];
		$factor_adicional  = (float) $metal['factor_adicional'];
		$gramos_con_merma  = $peso_base * $factor_merma;
		$comp_metal        = $peso_base * $factor_merma * $precio_gramo * $factor_adicional;

		// 5. Costo de producción.
		$costo = $comp_gema + $comp_metal + $mano_obra + $extras;

		// 6. Precio con margen comercial.
		$margen           = (float) $parametros['margen_comercial'];
		$precio_calculado = $costo * ( 1 + $margen );

		// 7. Redondeo hacia arriba.
		$redondeo     = (float) $parametros['redondeo_precio'];
		$precio_final = self::redondear_arriba( $precio_calculado, $redondeo );

		$formateado = 'DESDE ' . self::formato_moneda( $precio_final, $parametros['moneda'] );

		return array(
			'ok'      => true,
			'estado'  => self::ESTADO_OK,
			'error'   => '',
			'codigo'  => $fila_diseno['codigo'],
			'entrada' => $entrada,

			/*
			 * Si la pieza lleva gemas. Lo decide el catálogo, no quien pregunta,
			 * y de aquí salen tanto los pasos que se piden en el formulario como
			 * las filas que se pintan en el resultado y en el mensaje de
			 * WhatsApp: sin gemas no se menciona ni origen, ni gema, ni talla.
			 */
			'lleva_gemas' => $lleva_gemas,

			// Bloque de gema, en el orden del desglose del Excel.
			'gema'    => array(
				'tipo_gema'      => $entrada['gema'],
				'origen'         => $entrada['origen'],
				'cant_gemas'     => $cant_gemas,
				'ct_por_gema'    => $ct_por_gema,
				'ct_total'       => $ct_total,
				'precio_ct'      => $precio_ct,
				'subtotal_gemas' => $subtotal_gemas,
				'talla'          => $entrada['talla'],
				'ajuste_talla'   => $ajuste_talla,
				'subtotal'       => $comp_gema,
			),

			// Bloque de metal.
			'metal'   => array(
				'metal'             => $entrada['metal'],
				'peso_base'         => $peso_base,
				'factor_merma'      => $factor_merma,
				'gramos_con_merma'  => $gramos_con_merma,
				'precio_gramo'      => $precio_gramo,
				'factor_adicional'  => $factor_adicional,
				'subtotal'          => $comp_metal,
			),

			'mano_obra'                => $mano_obra,
			'extras'                   => $extras,
			'costo_produccion'         => $costo,
			'margen_comercial'         => $margen,
			'valor_margen'             => $precio_calculado - $costo,
			'precio_calculado'         => $precio_calculado,
			'redondeo_precio'          => $redondeo,
			'precio_final'             => $precio_final,
			'precio_final_formateado'  => $formateado,
			'moneda'                   => $parametros['moneda'],
			'texto_nota'               => $parametros['texto_nota'],
		);
	}

	/**
	 * Precio "desde" del catálogo completo.
	 *
	 * Es el más barato que puede salir de la matriz: para cada diseño se toma
	 * la gema más barata (entre todas las gemas y ambos orígenes), la talla
	 * disponible con menor ajuste y el metal disponible más barato, y se
	 * devuelve el menor de todos. Sirve para mostrar un precio de referencia en
	 * la página pública sin que el visitante tenga que elegir nada.
	 *
	 * Los componentes son independientes y no decrecen, así que basta con
	 * minimizar cada uno por separado en vez de recorrer todas las
	 * combinaciones.
	 *
	 * @return array|null Null si falta catálogo para calcular algo.
	 */
	public function precio_desde() {
		$parametros = $this->config['parametros'];

		if ( empty( $this->config['disenos'] ) ) {
			return null;
		}

		// Gema más barata, mirando ambos orígenes.
		$precio_ct = null;

		foreach ( $this->config['gemas'] as $gema ) {
			foreach ( array( 'precio_natural', 'precio_laboratorio' ) as $columna ) {
				$valor = (float) $gema[ $columna ];

				if ( null === $precio_ct || $valor < $precio_ct ) {
					$precio_ct = $valor;
				}
			}
		}

		// Talla disponible con el menor ajuste.
		$ajuste = null;

		foreach ( $this->config['tallas'] as $talla ) {
			if ( empty( $talla['disponible'] ) ) {
				continue;
			}

			$valor = (float) $talla['ajuste'];

			if ( null === $ajuste || $valor < $ajuste ) {
				$ajuste = $valor;
			}
		}

		// Metal disponible más barato por gramo, ya con su factor adicional.
		$costo_gramo = null;

		foreach ( $this->config['metales'] as $metal ) {
			if ( empty( $metal['disponible'] ) ) {
				continue;
			}

			$valor = (float) $metal['precio_gramo'] * (float) $metal['factor_adicional'];

			if ( null === $costo_gramo || $valor < $costo_gramo ) {
				$costo_gramo = $valor;
			}
		}

		if ( null === $precio_ct || null === $ajuste || null === $costo_gramo ) {
			return null;
		}

		$merma    = (float) $parametros['factor_merma_metal'];
		$margen   = (float) $parametros['margen_comercial'];
		$redondeo = (float) $parametros['redondeo_precio'];
		$mejor    = null;

		foreach ( $this->config['disenos'] as $diseno ) {
			$costo = ( $precio_ct * (float) $diseno['cant_gemas'] * (float) $diseno['ct_por_gema'] )
				+ $ajuste
				+ ( (float) $diseno['peso_metal_g'] * $merma * $costo_gramo )
				+ (float) $diseno['mano_obra']
				+ (float) $diseno['extras'];

			$precio = self::redondear_arriba( $costo * ( 1 + $margen ), $redondeo );

			if ( null === $mejor || $precio < $mejor['precio_final'] ) {
				$mejor = array(
					'precio_final' => $precio,
					'codigo'       => $diseno['codigo'],
					'tipo'         => $diseno['tipo'],
					'diseno'       => $diseno['diseno'],
				);
			}
		}

		if ( null === $mejor ) {
			return null;
		}

		$mejor['moneda']     = $parametros['moneda'];
		$mejor['formateado'] = 'DESDE ' . self::formato_moneda( $mejor['precio_final'], $parametros['moneda'] );

		return $mejor;
	}

	/**
	 * Desglose detallado en filas listas para mostrar.
	 *
	 * Reproduce el bloque "DESGLOSE DETALLADO" del Excel en orden. Devuelve
	 * texto ya formateado, nunca HTML, para que lo pueda pintar tanto el
	 * shortcode como cualquier consumidor del JSON.
	 *
	 * Cada fila trae: `tipo` (seccion | dato | total | gran_total), `etiqueta`,
	 * `valor` y, cuando aplica, `detalle` con la fórmula.
	 *
	 * @param array $r Resultado de calcular().
	 * @return array
	 */
	public static function desglose( array $r ) {
		if ( empty( $r['ok'] ) ) {
			return array();
		}

		$moneda = isset( $r['moneda'] ) ? $r['moneda'] : '';
		$g      = $r['gema'];
		$m      = $r['metal'];

		// Sin gemas no hay sección de gema: sería una columna de ceros y una
		// «Ajuste por talla ()» sin talla.
		$filas_gema = empty( $r['lleva_gemas'] ) ? array() : array(
			array( 'tipo' => 'seccion', 'etiqueta' => 'Gema' ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Tipo de gema', 'valor' => $g['tipo_gema'] ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Origen', 'valor' => $g['origen'] ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Cantidad de gemas', 'valor' => self::formato_numero( $g['cant_gemas'] ) ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Ct por gema', 'valor' => self::formato_numero( $g['ct_por_gema'], 4 ) ),
			array(
				'tipo'     => 'dato',
				'etiqueta' => 'Ct totales',
				'valor'    => self::formato_numero( $g['ct_total'], 4 ),
				'detalle'  => sprintf( '%s × %s', self::formato_numero( $g['cant_gemas'] ), self::formato_numero( $g['ct_por_gema'], 4 ) ),
			),
			array( 'tipo' => 'dato', 'etiqueta' => 'Precio de la gema por ct', 'valor' => self::formato_moneda( $g['precio_ct'] ) ),
			array(
				'tipo'     => 'dato',
				'etiqueta' => 'Subtotal gemas',
				'valor'    => self::formato_moneda( $g['subtotal_gemas'] ),
				'detalle'  => sprintf(
					'%s × %s × %s ct',
					self::formato_moneda( $g['precio_ct'] ),
					self::formato_numero( $g['cant_gemas'] ),
					self::formato_numero( $g['ct_por_gema'], 4 )
				),
			),
			array(
				'tipo'     => 'dato',
				'etiqueta' => sprintf( 'Ajuste por talla (%s)', $g['talla'] ),
				'valor'    => self::formato_moneda( $g['ajuste_talla'] ),
			),
			array( 'tipo' => 'total', 'etiqueta' => 'Componente gema', 'valor' => self::formato_moneda( $g['subtotal'] ) ),
		);

		$filas = array(
			array( 'tipo' => 'seccion', 'etiqueta' => 'Metal' ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Metal', 'valor' => $m['metal'] ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Peso base', 'valor' => self::formato_numero( $m['peso_base'], 4 ) . ' g' ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Factor de merma', 'valor' => self::formato_numero( $m['factor_merma'], 4 ) ),
			array(
				'tipo'     => 'dato',
				'etiqueta' => 'Gramos con merma',
				'valor'    => self::formato_numero( $m['gramos_con_merma'], 4 ) . ' g',
				'detalle'  => sprintf( '%s g × %s', self::formato_numero( $m['peso_base'], 4 ), self::formato_numero( $m['factor_merma'], 4 ) ),
			),
			array( 'tipo' => 'dato', 'etiqueta' => 'Precio del metal por gramo', 'valor' => self::formato_moneda( $m['precio_gramo'] ) ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Factor adicional', 'valor' => self::formato_numero( $m['factor_adicional'], 4 ) ),
			array(
				'tipo'     => 'total',
				'etiqueta' => 'Costo del metal',
				'valor'    => self::formato_moneda( $m['subtotal'] ),
				'detalle'  => sprintf(
					'%s g × %s /g × %s',
					self::formato_numero( $m['gramos_con_merma'], 4 ),
					self::formato_moneda( $m['precio_gramo'] ),
					self::formato_numero( $m['factor_adicional'], 4 )
				),
			),

			array( 'tipo' => 'seccion', 'etiqueta' => 'Totales' ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Mano de obra', 'valor' => self::formato_moneda( $r['mano_obra'] ) ),
			array( 'tipo' => 'dato', 'etiqueta' => 'Extras', 'valor' => self::formato_moneda( $r['extras'] ) ),
			array( 'tipo' => 'total', 'etiqueta' => 'Costo de producción', 'valor' => self::formato_moneda( $r['costo_produccion'] ) ),
			array(
				'tipo'     => 'dato',
				'etiqueta' => sprintf( 'Margen comercial (%s %%)', self::formato_numero( $r['margen_comercial'] * 100, 2 ) ),
				'valor'    => self::formato_moneda( $r['valor_margen'] ),
			),
			array( 'tipo' => 'dato', 'etiqueta' => 'Precio calculado', 'valor' => self::formato_moneda( $r['precio_calculado'] ) ),
			array(
				'tipo'     => 'gran_total',
				'etiqueta' => 'Precio final',
				'valor'    => self::formato_moneda( $r['precio_final'], $moneda ),
				'detalle'  => sprintf( 'redondeado hacia arriba a %s', self::formato_moneda( $r['redondeo_precio'] ) ),
			),
		);

		return array_merge( $filas_gema, $filas );
	}

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */

	/**
	 * Redondea hacia arriba al múltiplo indicado.
	 *
	 * El `round()` previo a `ceil()` absorbe el ruido de coma flotante: sin él
	 * un precio que ya es múltiplo exacto podría saltar al siguiente escalón.
	 *
	 * @param float $valor   Valor a redondear.
	 * @param float $multiplo Múltiplo de redondeo.
	 * @return float
	 */
	public static function redondear_arriba( $valor, $multiplo ) {
		$multiplo = (float) $multiplo;

		if ( $multiplo <= 0 ) {
			return (float) $valor;
		}

		return ceil( round( $valor / $multiplo, 6 ) ) * $multiplo;
	}

	/**
	 * Formatea un monto como $#,##0 (separador de miles con punto).
	 *
	 * @param float  $valor  Monto.
	 * @param string $moneda Sigla de moneda; se agrega al final si no está vacía.
	 * @return string
	 */
	public static function formato_moneda( $valor, $moneda = '' ) {
		$texto = '$' . number_format( (float) $valor, 0, ',', '.' );

		return '' !== $moneda ? $texto . ' ' . $moneda : $texto;
	}

	/**
	 * Formatea un número con hasta N decimales, sin ceros sobrantes.
	 *
	 * @param float $valor    Número.
	 * @param int   $decimales Máximo de decimales.
	 * @return string
	 */
	public static function formato_numero( $valor, $decimales = 2 ) {
		$texto = number_format( (float) $valor, $decimales, ',', '.' );

		if ( false !== strpos( $texto, ',' ) ) {
			$texto = rtrim( rtrim( $texto, '0' ), ',' );
		}

		return $texto;
	}

	/* ---------------------------------------------------------------------
	 * Internos
	 * ------------------------------------------------------------------ */

	/**
	 * Busca la primera fila de una colección que cumpla todos los criterios.
	 *
	 * @param string $coleccion Nombre de la colección en la config.
	 * @param array  $criterios Pares clave => valor a comparar.
	 * @return array|null
	 */
	private function buscar( $coleccion, array $criterios ) {
		if ( empty( $this->config[ $coleccion ] ) || ! is_array( $this->config[ $coleccion ] ) ) {
			return null;
		}

		foreach ( $this->config[ $coleccion ] as $fila ) {
			$coincide = true;

			foreach ( $criterios as $clave => $valor ) {
				if ( ! isset( $fila[ $clave ] ) || (string) $fila[ $clave ] !== (string) $valor ) {
					$coincide = false;
					break;
				}
			}

			if ( $coincide ) {
				return $fila;
			}
		}

		return null;
	}

	/**
	 * Lee una clave de la selección como texto limpio.
	 *
	 * @param array  $seleccion Selección de entrada.
	 * @param string $clave     Clave a leer.
	 * @return string
	 */
	private function texto( array $seleccion, $clave ) {
		return isset( $seleccion[ $clave ] ) ? trim( (string) $seleccion[ $clave ] ) : '';
	}

	/**
	 * Arma el resultado de error (combinación no calculable).
	 *
	 * @param array  $entrada Selección recibida.
	 * @param string $codigo  Código interno del motivo.
	 * @param string $detalle Explicación para el admin.
	 * @param string $codigo_diseno Código de diseño si ya se resolvió.
	 * @return array
	 */
	private function error( array $entrada, $codigo, $detalle, $codigo_diseno = '' ) {
		return array(
			'ok'            => false,
			'estado'        => self::ESTADO_REVISAR,
			'error'         => self::ERROR_CONFIG,
			'error_codigo'  => $codigo,
			'error_detalle' => $detalle,
			'codigo'        => $codigo_diseno,
			'entrada'       => $entrada,
		);
	}
}
