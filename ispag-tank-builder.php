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

// Mise à jour depuis une branche GitHub (jeton + branche : wp-config.php ou Outils → Updates ISPAG ; « main » par défaut)
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-github-updater.php';
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-tank-builder');

// Schéma de base de données : créé à l'activation, et re-vérifié à chaque chargement si la version change
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-builder-installer.php';
register_activation_hook(__FILE__, ['ISPAG_Tank_Builder_Installer', 'install']);
ISPAG_Tank_Builder_Installer::init();

// ISPAG_Logger : le vrai (classes/class-ispag-logger.php d'ISPAG Project Manager, s'il est présent) passe en premier ;
// sinon classe de secours. Enregistré dès le chargement : l'activation du plugin utilise déjà le logger.
spl_autoload_register(function ($class) {
    if ($class !== 'ISPAG_Logger') return;
    $real = defined('ISPAG_PROJECT_MANAGER_DIR') ? ISPAG_PROJECT_MANAGER_DIR . 'classes/class-ispag-logger.php' : '';
    if ($real && is_readable($real)) { require_once $real; return; }
    require_once ISPAG_PLUGIN_PATH . 'install/fallback-logger.php';
});


// Pages nécessaires (créées à l'activation ou via Outils → Pages ISPAG ; jamais automatiquement)
require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-page-installer.php';
ISPAG_Page_Installer::register('ISPAG Tank Builder', require ISPAG_PLUGIN_PATH . 'install/pages.php');
register_activation_hook(__FILE__, function () { ISPAG_Page_Installer::on_activation('ISPAG Tank Builder'); });

// Chemin d'ISPAG Project Manager, quel que soit le nom de son dossier (un ZIP GitHub donne « ispag-project-manager-<branche> »)
if (!function_exists('ispag_project_manager_dir')) {
    function ispag_project_manager_dir() {
        return defined('ISPAG_PROJECT_MANAGER_DIR') ? ISPAG_PROJECT_MANAGER_DIR : WP_PLUGIN_DIR . '/ispag-project-manager/';
    }
}

// Dépendance : ISPAG Project Manager (classes PDF, FPDF/FPDI, articles). Les classes du plugin en héritent dès leur chargement :
// sans lui, c'était une erreur fatale à l'activation. On les charge donc à plugins_loaded (tous les plugins sont alors chargés,
// quel que soit l'ordre des dossiers) et, si le project-manager manque, on affiche un avis au lieu de planter.
add_action('plugins_loaded', function () {
    if (!defined('ISPAG_PROJECT_MANAGER_DIR')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>ISPAG Tank Builder</strong> requires the <strong>ISPAG Project Manager</strong> plugin to be active. '
               . 'Activez-le d\'abord.</p></div>';
        });
        return;
    }
    require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-rules.php';
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
    define('ISPAG_TANK_BUILDER_CLASSES_LOADED', true);
}, 5);

add_action('init', function () { if (function_exists('ispag_load_textdomain')) ispag_load_textdomain(); });
// Initialisation du plugin
add_action('plugins_loaded', function() {
    if (!defined('ISPAG_TANK_BUILDER_CLASSES_LOADED')) return; // dépendance absente (voir l'avis ci-dessus)

    ISPAG_Tank_Rules::init();
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