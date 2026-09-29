<?php
/*
Plugin Name: ISPAG Tank Builder
Description: Plugin de conception de réservoirs pour projets techniques ISPAG.
Version: 1.0
Author: Cyril Barthel
*/

defined('ABSPATH') || exit;

// Définir les constantes
if (!defined('ISPAG_PLUGIN_URL')) {
    define('ISPAG_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('ISPAG_PLUGIN_PATH')) {
    define('ISPAG_PLUGIN_PATH', plugin_dir_path(__FILE__));
}

// Mise à jour depuis une branche GitHub : inactif sauf si wp-config.php définit ISPAG_GITHUB_TOKEN et ISPAG_UPDATE_BRANCH
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-github-updater.php';
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-tank-builder');

// Schéma de base de données : créé à l'activation, et re-vérifié à chaque chargement si la version change
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-builder-installer.php';
register_activation_hook(__FILE__, ['ISPAG_Tank_Builder_Installer', 'install']);
ISPAG_Tank_Builder_Installer::init();

// Autochargement des classes
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-manager.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-exchanger.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-fitting-autosaver.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-nameplate-generator.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-nameplate-svg-generator.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-pdf-exporter.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-notice-pdf-generator.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-valves-manager.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-pricing.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-welding-site-sheet.php';

add_action('init', 'ispag_load_textdomain');
// Initialisation du plugin
add_action('plugins_loaded', function() {
    
    ISPAG_Tank_Manager::init();
    ISPAG_Tank_Designer::init();
    ISPAG_Tank_Description::init();
    ISPAG_Tank_Drawing::init();
    ISPAG_Tank_Fittings::init();
    ISPAG_Tank_SVG_Generator::init();
    ISPAG_Tank_SVG_Top_View_Generator::init();
    ISPAG_Tank_Welding::init();
    ISPAG_Tank_Welding_Certificat::init();
    ISPAG_Tank_Insulation::init();
    ISPAG_Tank_Insulation_Auto_Saver::init();
    ISPAG_Tank_Welding_Auto_Saver::init();
    ISPAG_Existing_Tanks_table::init();
    ISPAG_Tank_Exchanger::init();
    ISPAG_Tank3D_Renderer::init();
    ISPAG_Tank_DXF_Exporter::init();
    ISPAG_Tank_PDF_Exporter::init();

    new ISPAG_Nameplate_Generator();
    new ISPAG_Nameplate_SVG_Generator();
    ISPAG_Tank_Welding_Site_Sheet::init();
    new ISPAG_Valves_Manager();

    

//     // Initialiser la classe pour les scripts et actions AJAX
//     ISPAG_Notice_PDF_Generator::init();
});