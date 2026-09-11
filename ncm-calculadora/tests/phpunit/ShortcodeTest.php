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

	/**
	 * Asigna una imagen a la gema Diamante y devuelve el HTML público.
	 *
	 * @param int $adjunto Id del adjunto.
	 * @return string
	 */
	private function html_con_imagen_en_diamante( $adjunto ) {
		$config = NCM_Data::get_config();

		foreach ( $config['gemas'] as $i => $gema ) {
			if ( 'Diamante' === $gema['tipo_gema'] ) {
				$config['gemas'][ $i ]['imagen'] = $adjunto;
			}
		}

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		wp_set_current_user( 0 );

		return do_shortcode( '[ncm_calculadora]' );
	}

	/** Un adjunto de una entrada en borrador no llega a la página pública. */
	public function test_una_imagen_de_un_borrador_no_se_publica() {
		$borrador = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$adjunto  = self::factory()->attachment->create_object(
			array(
				'file'           => 'privada.jpg',
				'post_parent'    => $borrador,
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertSame( 0, NCM_Data::imagen_publicable( $adjunto ) );

		$html = $this->html_con_imagen_en_diamante( $adjunto );

		$this->assertStringNotContainsString( 'privada', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'ncm-opcion__monograma', $html );
	}

	/** Un adjunto de una entrada privada tampoco. */
	public function test_una_imagen_de_una_entrada_privada_no_se_publica() {
		$privada = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file'           => 'reservada.jpg',
				'post_parent'    => $privada,
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertSame( 0, NCM_Data::imagen_publicable( $adjunto ) );
		$this->assertStringNotContainsString( 'reservada', $this->html_con_imagen_en_diamante( $adjunto ) );
	}

	/** Un adjunto que no es imagen se ignora. */
	public function test_un_adjunto_que_no_es_imagen_se_ignora() {
		$pdf = self::factory()->attachment->create_object(
			array(
				'file'           => 'tarifas.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertSame( 0, NCM_Data::imagen_publicable( $pdf ) );
		$this->assertStringNotContainsString( 'tarifas', $this->html_con_imagen_en_diamante( $pdf ) );
	}

	/** Un id que ya no existe se ignora sin romper nada. */
	public function test_un_adjunto_borrado_se_ignora() {
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file'           => 'borrada.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		wp_delete_attachment( $adjunto, true );

		$this->assertSame( 0, NCM_Data::imagen_publicable( $adjunto ) );
		$this->assertSame( 0, NCM_Data::imagen_publicable( 999999 ) );
		$this->assertSame( 0, NCM_Data::imagen_publicable( 0 ) );
		$this->assertSame( 0, NCM_Data::imagen_publicable( -5 ) );

		$this->assertStringNotContainsString( '<img', $this->html_con_imagen_en_diamante( $adjunto ) );
	}

	/** Una imagen sin entrada padre sí es publicable. */
	public function test_una_imagen_sin_padre_si_se_publica() {
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file'           => 'suelta.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertSame( $adjunto, NCM_Data::imagen_publicable( $adjunto ) );
		$this->assertStringContainsString( 'suelta', $this->html_con_imagen_en_diamante( $adjunto ) );
	}

	/** La imagen del tipo también respeta la validación. */
	public function test_la_imagen_del_tipo_ignora_adjuntos_no_publicables() {
		$borrador = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$oculta   = self::factory()->attachment->create_object(
			array(
				'file'           => 'oculta.jpg',
				'post_parent'    => $borrador,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$buena    = self::factory()->attachment->create_object(
			array(
				'file'           => 'buena.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$config = NCM_Data::get_config();

		foreach ( $config['disenos'] as $i => $diseno ) {
			if ( 'AN-SOL' === $diseno['codigo'] ) {
				$config['disenos'][ $i ]['imagen'] = $oculta;
			}

			if ( 'AN-TRI' === $diseno['codigo'] ) {
				$config['disenos'][ $i ]['imagen'] = $buena;
			}
		}

		NCM_Data::guardar_config( $config );
		NCM_Data::limpiar_cache();

		// Se salta la del borrador y usa la siguiente que sí es publicable.
		$this->assertSame( $buena, NCM_Data::get_imagen_tipo( 'Anillo' ) );
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

	/** Un suscriptor tiene sesión, pero no ve el formulario interno. */
	public function test_la_interna_se_bloquea_para_un_suscriptor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$html = do_shortcode( '[ncm_calculadora_interna]' );

		$this->assertSame( 0, substr_count( $html, 'data-paso="' ) );
		$this->assertStringContainsString( 'uso interno', $html );
	}

	/** La pública sí funciona para un suscriptor, como para cualquiera. */
	public function test_la_publica_funciona_para_un_suscriptor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$html = do_shortcode( '[ncm_calculadora]' );

		$this->assertSame( 6, substr_count( $html, 'data-paso="' ) );
	}

	/** La capacidad interna es la del equipo, no la de cualquier registrado. */
	public function test_la_capacidad_interna_no_es_read() {
		$this->assertSame( 'edit_posts', NCM_Shortcode::CAP );

		$suscriptor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$autor      = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertFalse( user_can( $suscriptor, NCM_Shortcode::CAP ) );
		$this->assertTrue( user_can( $autor, NCM_Shortcode::CAP ) );
	}

	/** La interna sí se pinta con sesión del equipo. */
	public function test_la_interna_se_pinta_con_sesion() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

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
