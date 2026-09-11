<?php
/**
 * El motor de cálculo, dentro de un WordPress real.
 *
 * @package NCM_Calculadora
 */

/**
 * Casos de aceptación del Excel y recorrido de la matriz.
 */
class CalculadoraTest extends WP_UnitTestCase {

	/** Deja la configuración en los valores del Excel antes de cada prueba. */
	public function set_up() {
		parent::set_up();

		NCM_Data::restaurar_semilla();
		NCM_Data::limpiar_cache();
	}

	/**
	 * Atajo para calcular con la config guardada.
	 *
	 * @param string $tipo   Tipo de joya.
	 * @param string $diseno Diseño.
	 * @param string $origen Origen de la gema.
	 * @param string $gema   Tipo de gema.
	 * @param string $talla  Talla.
	 * @param string $metal  Metal.
	 * @return array
	 */
	private function calcular( $tipo, $diseno, $origen, $gema, $talla, $metal ) {
		return NCM_Calculator::desde_config()->calcular( $tipo, $diseno, $origen, $gema, $talla, $metal );
	}

	/**
	 * Caso A del documento de fases.
	 *
	 * Pulsera / Bangle (Rígida) / Natural / Diamante / Redonda / Oro blanco.
	 */
	public function test_caso_a_pulsera_bangle_rigida() {
		$r = $this->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

		$this->assertTrue( $r['ok'], 'La combinación debería ser calculable.' );
		$this->assertSame( 'PU-BAN', $r['codigo'] );

		// Gema: 12.000.000 x 0 x 0 + 0 = 0
		$this->assertEqualsWithDelta( 0.0, $r['gema']['subtotal'], 0.000001 );

		// Metal: 15 x 1,1 x 650.000 x 1 = 10.725.000
		$this->assertEqualsWithDelta( 10725000.0, $r['metal']['subtotal'], 0.000001 );

		$this->assertEqualsWithDelta( 10725000.0, $r['costo_produccion'], 0.000001 );
		$this->assertEqualsWithDelta( 14478750.0, $r['precio_calculado'], 0.000001 );
		$this->assertEqualsWithDelta( 14480000.0, $r['precio_final'], 0.000001 );
		$this->assertSame( 'DESDE $14.480.000 COP', $r['precio_final_formateado'] );
	}

	/**
	 * Caso B del documento de fases.
	 *
	 * Anillo / Solitario / Natural / Diamante / Redonda / Oro blanco.
	 */
	public function test_caso_b_anillo_solitario() {
		$r = $this->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

		$this->assertTrue( $r['ok'], 'La combinación debería ser calculable.' );
		$this->assertSame( 'AN-SOL', $r['codigo'] );

		// Gema: 12.000.000 x 1 x 1 + 0 = 12.000.000
		$this->assertEqualsWithDelta( 12000000.0, $r['gema']['subtotal'], 0.000001 );

		// Metal: 3,2 x 1,1 x 650.000 x 1 = 2.288.000
		$this->assertEqualsWithDelta( 2288000.0, $r['metal']['subtotal'], 0.000001 );

		$this->assertEqualsWithDelta( 14288000.0, $r['costo_produccion'], 0.000001 );
		$this->assertEqualsWithDelta( 19288800.0, $r['precio_calculado'], 0.000001 );
		$this->assertEqualsWithDelta( 19290000.0, $r['precio_final'], 0.000001 );
		$this->assertSame( 'DESDE $19.290.000 COP', $r['precio_final_formateado'] );
	}

	/** La semilla del Excel queda guardada en wp_options al activar. */
	public function test_la_semilla_esta_en_wp_options() {
		$config = get_option( 'ncm_calc_config' );

		$this->assertIsArray( $config );
		$this->assertCount( 15, $config['disenos'] );
		$this->assertCount( 4, $config['gemas'] );
		$this->assertCount( 5, $config['tallas'] );
		$this->assertCount( 5, $config['metales'] );
		$this->assertEqualsWithDelta( 0.35, $config['parametros']['margen_comercial'], 0.000001 );
		$this->assertEqualsWithDelta( 1.1, $config['parametros']['factor_merma_metal'], 0.000001 );
		$this->assertEqualsWithDelta( 10000.0, $config['parametros']['redondeo_precio'], 0.000001 );
		$this->assertSame( 'COP', $config['parametros']['moneda'] );
	}

	/** Las tildes y la ñ de las llaves de búsqueda sobreviven a la base de datos. */
	public function test_los_acentos_sobreviven_a_wp_options() {
		$this->assertNotNull( NCM_Data::find_diseno( 'Pulsera', 'Bangle (Rígida)' ) );
		$this->assertNotNull( NCM_Data::find_diseno( 'Anillo', 'Trilogía' ) );
		$this->assertNotNull( NCM_Data::find_diseno( 'Aretes', 'Topos en Línea' ) );
		$this->assertNotNull( NCM_Data::find_gema( 'Rubí' ) );
	}

	/** Los 15 diseños calculan sin errores ni divisiones por cero. */
	public function test_los_quince_disenos_calculan() {
		$disenos = NCM_Data::get_disenos();

		$this->assertCount( 15, $disenos );

		foreach ( $disenos as $diseno ) {
			$r = $this->calcular( $diseno['tipo'], $diseno['diseno'], 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

			$this->assertTrue( $r['ok'], "El diseño {$diseno['codigo']} debería calcular." );
			$this->assertSame( $diseno['codigo'], $r['codigo'] );
			$this->assertIsFloat( $r['precio_final'] );
			$this->assertTrue( is_finite( $r['precio_final'] ), "{$diseno['codigo']} dio un precio no finito." );
			$this->assertGreaterThan( 0, $r['precio_final'], "{$diseno['codigo']} dio un precio de cero." );
		}
	}

	/**
	 * Un diseño que existe, pero bajo otro tipo de joya, no es calculable.
	 *
	 * @dataProvider combinaciones_invalidas
	 *
	 * @param string $tipo   Tipo de joya.
	 * @param string $diseno Diseño.
	 */
	public function test_combinacion_invalida_pide_revisar_configuracion( $tipo, $diseno ) {
		$r = $this->calcular( $tipo, $diseno, 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'REVISAR_CONFIGURACION', $r['estado'] );
		$this->assertSame( 'REVISAR CONFIGURACIÓN', $r['error'] );
		$this->assertArrayNotHasKey( 'precio_final', $r );
	}

	/**
	 * Combinaciones que no existen en la matriz.
	 *
	 * @return array
	 */
	public function combinaciones_invalidas() {
		return array(
			'Anillo + Tennis'          => array( 'Anillo', 'Tennis' ),
			'Anillo + Halo'            => array( 'Anillo', 'Halo' ),
			'Pulsera + Eternity'       => array( 'Pulsera', 'Eternity' ),
			'Aretes + Esclava'         => array( 'Aretes', 'Esclava' ),
			'Dije + Topos Solitario'   => array( 'Dije', 'Topos Solitario' ),
			'Tipo inexistente'         => array( 'Tobillera', 'Solitario' ),
		);
	}

	/** Editar un precio desde la config cambia el resultado, sin tocar código. */
	public function test_editar_un_precio_cambia_el_resultado() {
		$antes = $this->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

		$this->assertEqualsWithDelta( 14480000.0, $antes['precio_final'], 0.000001 );

		$config = NCM_Data::get_config();

		foreach ( $config['metales'] as $i => $metal ) {
			if ( 'Oro blanco' === $metal['metal'] ) {
				$config['metales'][ $i ]['precio_gramo'] = 700000;
			}
		}

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		// 15 x 1,1 x 700.000 x 1,35 = 15.592.500 -> 15.600.000
		$despues = $this->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

		$this->assertEqualsWithDelta( 15600000.0, $despues['precio_final'], 0.000001 );

		// Y los demás metales siguen igual.
		$plata = $this->calcular( 'Pulsera', 'Bangle (Rígida)', 'Natural', 'Diamante', 'Redonda', 'Plata' );

		$this->assertEqualsWithDelta( 250000.0, $plata['metal']['precio_gramo'], 0.000001 );
	}

	/** El precio "desde" del catálogo es la configuración más barata posible. */
	public function test_precio_desde_del_catalogo() {
		$desde = NCM_Calculator::desde_config()->precio_desde();

		// Dije Solitario: 150.000 (diamante de laboratorio) + 3 x 1,1 x 250.000
		// (plata) = 975.000 -> x1,35 = 1.316.250 -> 1.320.000.
		$this->assertSame( 'DI-SOL', $desde['codigo'] );
		$this->assertEqualsWithDelta( 1320000.0, $desde['precio_final'], 0.000001 );
		$this->assertSame( 'DESDE $1.320.000 COP', $desde['formateado'] );

		// Ninguna combinación de la matriz puede salir por debajo.
		foreach ( NCM_Data::get_disenos() as $diseno ) {
			foreach ( NCM_Data::get_gemas() as $gema ) {
				foreach ( array( 'Natural', 'Laboratorio' ) as $origen ) {
					$r = $this->calcular( $diseno['tipo'], $diseno['diseno'], $origen, $gema['tipo_gema'], 'Redonda', 'Plata' );

					$this->assertGreaterThanOrEqual(
						$desde['precio_final'],
						$r['precio_final'],
						"{$diseno['codigo']} con {$gema['tipo_gema']} {$origen} quedó por debajo del precio desde."
					);
				}
			}
		}
	}
}
