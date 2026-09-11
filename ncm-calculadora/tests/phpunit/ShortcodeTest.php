<?php
/**
 * Los dos shortcodes: qué pinta cada uno y a quién.
 *
 * @package NCM_Calculadora
 */

/**
 * Pruebas de render de [ncm_calculadora] y [ncm_calculadora_interna].
 */
class ShortcodeTest extends WP_UnitTestCase {

	/** Deja la configuración en los valores del Excel antes de cada prueba. */
	public function set_up() {
		parent::set_up();

		NCM_Data::restaurar_semilla();
		NCM_Data::limpiar_cache();
	}

	/** Ambos shortcodes están registrados. */
	public function test_los_shortcodes_existen() {
		$this->assertTrue( shortcode_exists( 'ncm_calculadora' ) );
		$this->assertTrue( shortcode_exists( 'ncm_calculadora_interna' ) );
	}

	/** La pública se pinta sin sesión, con los 6 pasos. */
	public function test_la_publica_funciona_sin_sesion() {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[ncm_calculadora]' );

		$this->assertSame( 6, substr_count( $html, 'data-paso="' ) );
		$this->assertStringContainsString( 'data-ncm-calc="publico"', $html );

		foreach ( array( 'tipo', 'diseno', 'origen', 'gema', 'talla', 'metal' ) as $campo ) {
			$this->assertStringContainsString( 'data-paso="' . $campo . '"', $html );
		}
	}

	/** Cada opción del catálogo es una tarjeta con su radio. */
	public function test_las_opciones_son_tarjetas_con_radio() {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[ncm_calculadora]' );

		// 4 tipos + 15 diseños + 2 orígenes + 4 gemas + 5 tallas + 5 metales.
		$this->assertSame( 35, substr_count( $html, 'class="ncm-opcion__radio"' ) );

		// Los 15 diseños llevan su tipo para la cascada.
		$this->assertSame( 15, substr_count( $html, 'data-tipo="' ) );

		// Y los nombres del catálogo están en el HTML, sin JavaScript.
		foreach ( array( 'Bangle (Rígida)', 'Topos en Línea', 'Rubí', 'Marquesa', 'Platino' ) as $nombre ) {
			$this->assertStringContainsString( esc_attr( $nombre ), $html );
		}

		$this->assertStringNotContainsString( '<select', $html );
	}

	/** Sin imagen cargada, la tarjeta cae en un monograma. */
	public function test_sin_imagen_hay_monograma() {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[ncm_calculadora]' );

		$this->assertStringContainsString( 'ncm-opcion__monograma', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/** Con imagen cargada, la tarjeta la usa. */
	public function test_con_imagen_la_tarjeta_la_muestra() {
		wp_set_current_user( 0 );

		$adjunto = self::factory()->attachment->create_object(
			array(
				'file'           => 'anillo.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$config = NCM_Data::get_config();

		foreach ( $config['gemas'] as $i => $gema ) {
			if ( 'Diamante' === $gema['tipo_gema'] ) {
				$config['gemas'][ $i ]['imagen'] = $adjunto;
			}
		}

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		$this->assertSame( $adjunto, NCM_Data::find_gema( 'Diamante' )['imagen'] );

		$html = do_shortcode( '[ncm_calculadora]' );

		$this->assertStringContainsString( 'ncm-opcion__img', $html );
		$this->assertStringContainsString( 'anillo', $html );
	}

	/** La imagen de un tipo sale del primer diseño de ese tipo que tenga una. */
	public function test_la_imagen_del_tipo_sale_de_sus_disenos() {
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file'           => 'pulsera.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertSame( 0, NCM_Data::get_imagen_tipo( 'Pulsera' ) );

		$config = NCM_Data::get_config();

		foreach ( $config['disenos'] as $i => $diseno ) {
			if ( 'PU-ESC' === $diseno['codigo'] ) {
				$config['disenos'][ $i ]['imagen'] = $adjunto;
			}
		}

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		$this->assertSame( $adjunto, NCM_Data::get_imagen_tipo( 'Pulsera' ) );
		$this->assertSame( 0, NCM_Data::get_imagen_tipo( 'Anillo' ) );
	}

	/** La pública trae contenido indexable antes de cualquier interacción. */
	public function test_la_publica_trae_contenido_para_seo() {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[ncm_calculadora]' );

		$this->assertStringContainsString( 'ncm-calc__texto', $html );
		$this->assertStringContainsString( 'ncm-calc__desde-monto', $html );
		$this->assertStringContainsString( '1.320.000', $html );

		// Y ese contenido inicial no filtra costos.
		$this->assertStringNotContainsString( 'Desglose detallado', $html );
		$this->assertStringNotContainsString( 'Costo de producción', $html );
		$this->assertStringNotContainsString( '10.725.000', $html );
	}

	/** La interna no le pinta nada a un anónimo. */
	public function test_la_interna_se_bloquea_sin_sesion() {
		wp_set_current_user( 0 );

		$html = do_shortcode( '[ncm_calculadora_interna]' );

		$this->assertSame( 0, substr_count( $html, 'data-paso="' ) );
		$this->assertSame( 0, substr_count( $html, 'ncm-opcion__radio' ) );
		$this->assertStringContainsString( 'uso interno', $html );
	}

	/** La interna sí se pinta con sesión. */
	public function test_la_interna_se_pinta_con_sesion() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$html = do_shortcode( '[ncm_calculadora_interna]' );

		$this->assertSame( 6, substr_count( $html, 'data-paso="' ) );
		$this->assertStringContainsString( 'data-ncm-calc="interno"', $html );
	}

	/** Dos instancias en la misma página no repiten ids. */
	public function test_dos_instancias_no_repiten_ids() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$html = do_shortcode( '[ncm_calculadora][ncm_calculadora_interna]' );

		preg_match_all( '/id="(ncm-calc-\d+)"/', $html, $coincidencias );

		$this->assertCount( 2, $coincidencias[1] );
		$this->assertSame( $coincidencias[1], array_unique( $coincidencias[1] ) );

		// Y los grupos de radios tampoco pueden compartir nombre entre instancias.
		preg_match_all( '/name="(ncm-calc-\d+-tipo)"/', $html, $nombres );

		$this->assertCount( 2, array_unique( $nombres[1] ) );
	}
}
