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

// Autochargement des classes
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-manager.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-exchanger.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-fitting-autosaver.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-nameplate-generator.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-nameplate-svg-generator.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-pdf-exporter.php';
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-notice-pdf-generator.php';

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

//     // Initialiser la classe pour les scripts et actions AJAX
//     ISPAG_Notice_PDF_Generator::init();
});