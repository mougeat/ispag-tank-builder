<?php
defined('ABSPATH') || exit;

/**
 * Textes affichés par le JavaScript de ce paquet (messages d'erreur, confirmations, libellés).
 * Le JavaScript les appelle avec ispagT('Texte anglais') ; ce fichier fournit leur traduction dans la langue du site
 * (fichiers languages/ de ce paquet). Un texte absent de la liste reste tel quel (anglais).
 * Après avoir ajouté un texte dans un fichier JS, ajoutez-le ici puis lancez tools/i18n/build.py (ISPAG Project Manager).
 */
if (!function_exists('ispag_tank_js_strings')) {
    function ispag_tank_js_strings() {
        return [
        'Critical error: The tank ID is missing.' => __('Critical error: The tank ID is missing.', 'creation-reservoir'),
        'Delete this heat exchanger?' => __('Delete this heat exchanger?', 'creation-reservoir'),
        'Error' => __('Error', 'creation-reservoir'),
        'Error lors de la génération du PDF. Vérifiez la console.' => __('Error lors de la génération du PDF. Vérifiez la console.', 'creation-reservoir'),
        'Error while loading the form.' => __('Error while loading the form.', 'creation-reservoir'),
        'Error: ' => __('Error: ', 'creation-reservoir'),
        'Error: Invalid temperature difference' => __('Error: Invalid temperature difference', 'creation-reservoir'),
        'Error: Unable to load the form.' => __('Error: Unable to load the form.', 'creation-reservoir'),
        'Fix the temperature errors before saving.' => __('Fix the temperature errors before saving.', 'creation-reservoir'),
        'Invalid server response' => __('Invalid server response', 'creation-reservoir'),
        'Must be < charge outlet temp.' => __('Must be < charge outlet temp.', 'creation-reservoir'),
        'Must be > hot water outlet temp.' => __('Must be > hot water outlet temp.', 'creation-reservoir'),
        'Must be ≤ charge outlet temp. - 2°C' => __('Must be ≤ charge outlet temp. - 2°C', 'creation-reservoir'),
        'Network error' => __('Network error', 'creation-reservoir'),
        'Network error while saving.' => __('Network error while saving.', 'creation-reservoir'),
        'PHP error: ' => __('PHP error: ', 'creation-reservoir'),
        'Server connection error.' => __('Server connection error.', 'creation-reservoir'),
        "You have unsaved changes. Save them before opening the fittings?\n\nOK = save and continue, Cancel = continue without saving" => __("You have unsaved changes. Save them before opening the fittings?\n\nOK = save and continue, Cancel = continue without saving", 'creation-reservoir'),
        '❌ Error while deleting' => __('❌ Error while deleting', 'creation-reservoir'),
        ];
    }

    /** Dictionnaire {texte anglais → texte traduit} injecté dans la page ; seuls les textes réellement traduits sont envoyés. */
    function ispag_tank_print_js_i18n() {
        $map = array_filter(ispag_tank_js_strings(), function ($translated, $english) { return $translated !== $english; }, ARRAY_FILTER_USE_BOTH);
        echo '<script>window.ISPAG_JS_I18N=Object.assign(window.ISPAG_JS_I18N||{},' . wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ');'
            . 'window.ispagT=function(s){var d=window.ISPAG_JS_I18N||{};return Object.prototype.hasOwnProperty.call(d,s)?d[s]:s};</script>' . "\n";
    }
    add_action('wp_head', 'ispag_tank_print_js_i18n', 1);
    add_action('admin_head', 'ispag_tank_print_js_i18n', 1);
}
