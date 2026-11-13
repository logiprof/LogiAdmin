<?php
/**
 * Plugin Name: LogiAdmin
 * Description: Les outils d'administration LogiProf réunis dans une seule extension : Export Sensei, Shortcodes Sensei, Compteurs, Accueil selon groupe, Médias inutilisés et Menu admin. Reprend les données des anciennes extensions sans rien effacer.
 * Version: 1.0.4
 * Author: Logiprof
 * Text Domain: logiadmin
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOGIADMIN_VERSION', '1.0.4' );
define( 'LOGIADMIN_FILE', __FILE__ );
define( 'LOGIADMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOGIADMIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Menu parent partagé par tous les outils LogiProf.
 * Les anciennes extensions définissaient ces fonctions (protégées par function_exists) :
 * LogiAdmin les fournit désormais et en garde le même identifiant « logiprof-tools »,
 * ce qui conserve les réglages déjà enregistrés par « Menu admin » (ordre, éléments masqués).
 */
if ( ! function_exists( 'logiprof_admin_menu_parent_slug' ) ) {
	function logiprof_admin_menu_parent_slug() {
		return 'logiprof-tools';
	}

	function logiprof_register_admin_menu_parent() {
		LogiAdmin::register_parent_menu();
	}

	function logiprof_render_admin_menu_parent() {
		LogiAdmin::render_dashboard();
	}

	function logiprof_cleanup_admin_menu_parent_submenu() {
		// LogiAdmin garde son tableau de bord comme premier sous-menu.
	}
}

require_once LOGIADMIN_DIR . 'includes/class-logiadmin.php';
require_once LOGIADMIN_DIR . 'includes/class-logiadmin-updater.php';

register_activation_hook( __FILE__, array( 'LogiAdmin', 'activate' ) );
add_action( 'plugins_loaded', array( 'LogiAdmin', 'boot' ), 1 );
LogiAdmin_Updater::init();
