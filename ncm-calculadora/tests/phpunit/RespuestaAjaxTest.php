<?php
/**
 * El endpoint AJAX real: qué recibe un anónimo y qué recibe alguien con sesión.
 *
 * @package NCM_Calculadora
 */

/**
 * Pruebas del endpoint ncm_calcular sobre WP_Ajax_UnitTestCase.
 */
class RespuestaAjaxTest extends WP_Ajax_UnitTestCase {

	/** Cifras del caso B que un anónimo no debe ver por ningún lado. */
	const PROHIBIDOS = array(
		'12.000.000',
		'2.288.000',
		'14.288.000',
		'5.000.800',
		'19.288.800',
		'650.000',
		'Desglose',
		'Margen',
	);

	/** Claves que solo existen en la respuesta interna. */
	const CLAVES_INTERNAS = array(
		'gema',
		'metal',
		'mano_obra',
		'extras',
		'costo_produccion',
		'margen_comercial',
		'valor_margen',
		'precio_calculado',
		'redondeo_precio',
		'desglose',
		'codigo',
	);

	/** Deja la configuración en los valores del Excel antes de cada prueba. */
	public function set_up() {
		parent::set_up();

		NCM_Data::restaurar_semilla();
		NCM_Data::limpiar_cache();
	}

	/**
	 * Lanza la petición AJAX del caso B y devuelve la respuesta decodificada.
	 *
	 * @return array
	 */
	private function pedir_caso_b() {
		$_POST = array(
			'action' => 'ncm_calcular',
			'nonce'  => wp_create_nonce( 'ncm_calcular' ),
			'tipo'   => 'Anillo',
			'diseno' => 'Solitario',
			'origen' => 'Natural',
			'gema'   => 'Diamante',
			'talla'  => 'Redonda',
			'metal'  => 'Oro blanco',
		);

		try {
			$this->_handleAjax( 'ncm_calcular' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore
			// wp_send_json corta la ejecución; la salida ya está capturada.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore
			$this->fail( 'La petición murió antes de responder: ' . $e->getMessage() );
		}

		return json_decode( $this->_last_response, true );
	}

	/** (a) Sin sesión: llega el número, no llega el desglose. */
	public function test_sin_sesion_recibe_solo_el_precio() {
		wp_set_current_user( 0 );

		$crudo     = null;
		$respuesta = $this->pedir_caso_b();
		$crudo     = $this->_last_response;

		$this->assertTrue( $respuesta['success'], 'El anónimo sí debe poder calcular.' );

		$datos = $respuesta['data'];

		$this->assertSame( 'publico', $datos['modo'] );
		$this->assertEqualsWithDelta( 19290000.0, $datos['precio_final'], 0.000001 );
		$this->assertSame( 'DESDE $19.290.000 COP', $datos['precio_final_formateado'] );

		// Ninguna clave interna.
		foreach ( self::CLAVES_INTERNAS as $clave ) {
			$this->assertArrayNotHasKey( $clave, $datos, "La respuesta pública no debe traer «{$clave}»." );
		}

		// Ni ninguna cifra sensible, tampoco dentro del HTML.
		foreach ( self::PROHIBIDOS as $cifra ) {
			$this->assertStringNotContainsString(
				$cifra,
				$crudo,
				"La respuesta pública filtró «{$cifra}»."
			);
		}

		// El precio sí tiene que estar.
		$this->assertStringContainsString( '19.290.000', $datos['html'] );
	}

	/** (b) Con sesión: llega el desglose completo. */
	public function test_con_sesion_recibe_el_desglose_completo() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$respuesta = $this->pedir_caso_b();

		$this->assertTrue( $respuesta['success'] );

		$datos = $respuesta['data'];

		$this->assertSame( 'interno', $datos['modo'] );
		$this->assertEqualsWithDelta( 19290000.0, $datos['precio_final'], 0.000001 );
		$this->assertSame( 'AN-SOL', $datos['codigo'] );

		foreach ( self::CLAVES_INTERNAS as $clave ) {
			$this->assertArrayHasKey( $clave, $datos, "La respuesta interna debe traer «{$clave}»." );
		}

		$this->assertEqualsWithDelta( 12000000.0, $datos['gema']['subtotal'], 0.000001 );
		$this->assertEqualsWithDelta( 2288000.0, $datos['metal']['subtotal'], 0.000001 );
		$this->assertEqualsWithDelta( 14288000.0, $datos['costo_produccion'], 0.000001 );
		$this->assertEqualsWithDelta( 5000800.0, $datos['valor_margen'], 0.000001 );
		$this->assertCount( 25, $datos['desglose'] );
		$this->assertStringContainsString( 'Desglose detallado', $datos['html'] );
		$this->assertStringContainsString( 'ncm-calc__imprimir', $datos['html'] );
	}

	/** Un nonce inválido corta la petición, con o sin sesión. */
	public function test_nonce_invalido_es_rechazado() {
		wp_set_current_user( 0 );

		$_POST = array(
			'action' => 'ncm_calcular',
			'nonce'  => 'basura',
			'tipo'   => 'Anillo',
			'diseno' => 'Solitario',
			'origen' => 'Natural',
			'gema'   => 'Diamante',
			'talla'  => 'Redonda',
			'metal'  => 'Oro blanco',
		);

		$this->expectException( 'WPAjaxDieStopException' );
		$this->_handleAjax( 'ncm_calcular' );
	}

	/** Una combinación inválida responde REVISAR CONFIGURACIÓN, sin detalles. */
	public function test_combinacion_invalida_no_expone_el_motivo() {
		wp_set_current_user( 0 );

		$_POST = array(
			'action' => 'ncm_calcular',
			'nonce'  => wp_create_nonce( 'ncm_calcular' ),
			'tipo'   => 'Anillo',
			'diseno' => 'Tennis',
			'origen' => 'Natural',
			'gema'   => 'Diamante',
			'talla'  => 'Redonda',
			'metal'  => 'Oro blanco',
		);

		try {
			$this->_handleAjax( 'ncm_calcular' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore
			// Respuesta capturada.
		}

		$respuesta = json_decode( $this->_last_response, true );

		$this->assertFalse( $respuesta['success'] );
		$this->assertSame( 'REVISAR_CONFIGURACION', $respuesta['data']['estado'] );
		$this->assertSame( 'REVISAR CONFIGURACIÓN', $respuesta['data']['mensaje'] );
		$this->assertArrayNotHasKey( 'detalle', $respuesta['data'] );
		$this->assertStringNotContainsString( 'no existe en la matriz', $this->_last_response );
	}

	/** El endpoint queda registrado para las dos rutas. */
	public function test_las_dos_rutas_estan_registradas() {
		$this->assertNotFalse( has_action( 'wp_ajax_ncm_calcular' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_ncm_calcular' ) );
	}
}
