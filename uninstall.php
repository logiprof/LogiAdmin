<?php
/**
 * Désinstallation de LogiAdmin.
 *
 * Volontairement prudente : seules les deux petites options propres à LogiAdmin sont retirées.
 * Les données des outils (historique et réglages d'export, réglages du menu,
 * URL de secours, profils autorisés des cours, etc.) sont conservées, pour pouvoir réinstaller
 * LogiAdmin ou les anciennes extensions sans rien perdre.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'logiadmin_version' );
delete_option( 'logiadmin_migration' );
