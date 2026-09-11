<?php
/**
 * Desinstalación del plugin.
 *
 * WordPress ejecuta este archivo cuando se **borra** el plugin desde el
 * escritorio (no al desactivarlo). Se aprovecha para no dejar huérfana en la
 * base de datos la configuración, que contiene precios y el margen comercial.
 *
 * Desactivar el plugin no borra nada: los datos solo se van si se elimina.
 *
 * @package NCM_Calculadora
 */

// Sin esta constante, el archivo no lo está llamando WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Clave de la opción. Se repite aquí porque NCM_Data no está cargada. */
const NCM_CALC_OPCION = 'ncm_calc_config';

if ( is_multisite() ) {
	$ncm_sitios = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ncm_sitios as $ncm_sitio ) {
		switch_to_blog( $ncm_sitio );
		delete_option( NCM_CALC_OPCION );
		delete_transient( 'ncm_avisos_' . get_current_user_id() );
		restore_current_blog();
	}

	unset( $ncm_sitios, $ncm_sitio );
} else {
	delete_option( NCM_CALC_OPCION );
	delete_transient( 'ncm_avisos_' . get_current_user_id() );
}

/*
 * Las imágenes asignadas a las opciones NO se borran: son adjuntos de la
 * mediateca, pueden estar en uso en otro sitio de la web y su gestión es de
 * WordPress, no de este plugin.
 */
