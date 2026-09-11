<?php
/**
 * Botón de WhatsApp y paleta de colores, dentro de WordPress.
 *
 * @package NCM_Calculadora
 */

/**
 * Pruebas del enlace de WhatsApp y del CSS de la paleta.
 */
class WhatsAppTest extends WP_UnitTestCase {

	/** Deja la configuración en los valores del Excel antes de cada prueba. */
	public function set_up() {
		parent::set_up();

		NCM_Data::restaurar_semilla();
		NCM_Data::limpiar_cache();
	}

	/**
	 * Guarda un número de WhatsApp y devuelve el caso B calculado.
	 *
	 * @param string $numero Número a guardar.
	 * @param bool   $activo Si el botón está activo.
	 * @return array
	 */
	private function con_numero( $numero, $activo = true ) {
		$config = NCM_Data::get_config();

		$config['parametros']['whatsapp_numero'] = $numero;
		$config['parametros']['whatsapp_activo'] = $activo;

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		return NCM_Calculator::desde_config()->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );
	}

	/** Sin número configurado no hay botón. */
	public function test_sin_numero_no_hay_boton() {
		$r = NCM_Calculator::desde_config()->calcular( 'Anillo', 'Solitario', 'Natural', 'Diamante', 'Redonda', 'Oro blanco' );

		$this->assertNull( NCM_Shortcode::whatsapp( $r ) );
		$this->assertStringNotContainsString( 'ncm-calc__whatsapp', NCM_Shortcode::respuesta( $r, false )['html'] );
	}

	/** Con número, el enlace apunta a wa.me con el mensaje ya escrito. */
	public function test_el_enlace_lleva_la_cotizacion() {
		$r  = $this->con_numero( '+57 (300) 123-4567' );
		$wa = NCM_Shortcode::whatsapp( $r );

		$this->assertSame( '573001234567', $wa['numero'] );
		$this->assertStringStartsWith( 'https://wa.me/573001234567?text=', $wa['url'] );
		$this->assertStringContainsString( '$19.290.000', $wa['mensaje'] );
		$this->assertStringContainsString( 'Anillo', $wa['mensaje'] );
		$this->assertStringContainsString( 'Solitario', $wa['mensaje'] );
		$this->assertStringContainsString( 'Diamante', $wa['mensaje'] );
		$this->assertStringContainsString( 'Oro blanco', $wa['mensaje'] );
	}

	/**
	 * Los saltos de línea tienen que sobrevivir hasta el HTML.
	 *
	 * esc_url() borra los `%0a`, así que el href se escapa con esc_attr(). Si
	 * alguien lo devuelve a esc_url(), al cliente le llega el mensaje pegado en
	 * un solo párrafo y esta prueba lo avisa.
	 */
	public function test_el_href_conserva_los_saltos_de_linea() {
		$r = $this->con_numero( '573001234567' );

		$this->assertStringContainsString( '%0A', NCM_Shortcode::whatsapp( $r )['url'] );

		foreach ( array( false, true ) as $interno ) {
			$html = NCM_Shortcode::respuesta( $r, $interno )['html'];

			$this->assertMatchesRegularExpression( '/href="https:\/\/wa\.me\/[^"]*%0A/', $html );
		}
	}

	/**
	 * El mensaje va igual para todos, así que no puede llevar costos.
	 *
	 * @dataProvider cifras_prohibidas
	 *
	 * @param string $cifra Cifra que no debe aparecer.
	 */
	public function test_el_mensaje_nunca_lleva_cifras_internas( $cifra ) {
		$r  = $this->con_numero( '573001234567' );
		$wa = NCM_Shortcode::whatsapp( $r );

		$this->assertStringNotContainsString( $cifra, $wa['mensaje'] );
		$this->assertStringNotContainsString( $cifra, rawurldecode( $wa['url'] ) );

		// Ni en la carga completa que recibe un anónimo.
		$this->assertStringNotContainsString( $cifra, wp_json_encode( NCM_Shortcode::respuesta( $r, false ) ) );
	}

	/**
	 * Cifras del caso B que solo debería ver el equipo.
	 *
	 * @return array
	 */
	public function cifras_prohibidas() {
		return array(
			'precio por ct'       => array( '12.000.000' ),
			'costo del metal'     => array( '2.288.000' ),
			'costo de producción' => array( '14.288.000' ),
			'valor del margen'    => array( '5.000.800' ),
			'precio sin redondear' => array( '19.288.800' ),
		);
	}

	/** Un número inválido no genera botón. */
	public function test_un_numero_invalido_no_genera_boton() {
		$this->assertNull( NCM_Shortcode::whatsapp( $this->con_numero( '123' ) ) );
		$this->assertNull( NCM_Shortcode::whatsapp( $this->con_numero( 'no soy un teléfono' ) ) );
	}

	/** Se puede apagar el botón sin borrar el número. */
	public function test_se_puede_desactivar() {
		$r = $this->con_numero( '573001234567', false );

		$this->assertNull( NCM_Shortcode::whatsapp( $r ) );
		$this->assertSame( '573001234567', NCM_Data::get_parametro( 'whatsapp_numero' ) );
	}

	/** El botón sale en los dos modos, con rel seguro y en pestaña nueva. */
	public function test_el_boton_sale_en_los_dos_modos() {
		$r = $this->con_numero( '573001234567' );

		foreach ( array( false, true ) as $interno ) {
			$html = NCM_Shortcode::respuesta( $r, $interno )['html'];

			$this->assertStringContainsString( 'ncm-calc__whatsapp', $html );
			$this->assertStringContainsString( 'https://wa.me/573001234567', $html );
			$this->assertStringContainsString( 'rel="noopener noreferrer nofollow"', $html );
			$this->assertStringContainsString( 'target="_blank"', $html );
		}
	}

	/** La paleta por defecto se engancha a las variables de Elementor. */
	public function test_la_paleta_ncm_sigue_a_elementor() {
		$css = NCM_Shortcode::css_paleta();

		$this->assertStringContainsString( '--e-global-color-primary, #354C3F', $css );
		$this->assertStringContainsString( '--e-global-color-secondary, #1D1E1B', $css );
		$this->assertStringContainsString( '.ncm-calc{', $css );
	}

	/** Las demás paletas son independientes del tema. */
	public function test_las_otras_paletas_son_fijas() {
		foreach ( array( 'claro', 'oscuro' ) as $paleta ) {
			$config                         = NCM_Data::get_config();
			$config['parametros']['paleta'] = $paleta;

			NCM_Data::guardar_config( $config );
			NCM_Data::limpiar_cache();

			$this->assertStringNotContainsString( '--e-global-color', NCM_Shortcode::css_paleta() );
		}
	}

	/** Un color inventado no se cuela en el CSS. */
	public function test_un_color_invalido_no_se_cuela() {
		$config = NCM_Data::get_config();

		$config['parametros']['paleta']       = 'personalizada';
		$config['parametros']['color_acento'] = 'red;}body{display:none}.x{';

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		$css = NCM_Shortcode::css_paleta();

		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertStringContainsString( '#354C3F', $css );
	}

	/** Una paleta personalizada sí usa los colores elegidos. */
	public function test_la_paleta_personalizada_se_aplica() {
		$config = NCM_Data::get_config();

		$config['parametros']['paleta']       = 'personalizada';
		$config['parametros']['color_acento'] = '#123456';

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		$this->assertStringContainsString( '#123456', NCM_Shortcode::css_paleta() );
	}
}
