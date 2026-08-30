<?php

class ISPAG_Tank_Description {
    protected static $instance = null;

    public function __construct() {
        
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_get_tank_title', [self::$instance, 'generate_tank_title'], 10, 2);
        add_filter('ispag_get_tank_description', [self::$instance, 'generate_tank_description'], 10, 3);
        // add_filter('ispag_get_last_drawing_url', [self::$instance, 'get_last_tank_plan_for_article'], 10, 2);
        add_filter('ispag_get_drawing_approval', [self::$instance, 'get_drawing_approval'], 10, 2);
        add_filter('ispag_get_if_last_drawing_or_modif', [self::$instance, 'get_if_last_drawing_or_modif'], 10, 2);
        // add_filter('ispag_get_tank_description', function($title, $id) {
        //     return "Test OK pour article $id";
        // }, 10, 2);
    }

    public function generate_tank_title($title, $article_id, $target_locale = null) {

        // 1. Forcer la locale AVANT toute opération
        if (!empty($target_locale)) {
            $previous_locale = switch_to_locale($target_locale);
        }

        // error_log('[DEBUG] Locale dans tank title: ' . (function_exists('pll_current_language') ? pll_current_language() : get_locale()));

        $tank_designer = new ISPAG_Tank_Designer();
        $datas = $tank_designer->get_tank_data(null, $article_id);
        
        $conception = isset($datas['conception']) ? $datas['conception'] : null;
        $dimensions = isset($datas['dimensions']) ? $datas['dimensions'] : null;

        // Si les données sont absentes, on retourne le titre original par défaut
        if (!$conception || !$dimensions) {
            return $title; 
        }

        return sprintf(
            '%s %s %s %s, %s %s',
            __($tank_designer->get_tank_text_data($conception->TankType ?? ''), 'creation-reservoir'),
            ($dimensions->Volume ?? '0') . ' ' . __('liters', 'creation-reservoir'),
            __('on', 'creation-reservoir'),
            __($tank_designer->get_tank_text_data($conception->Support ?? ''), 'creation-reservoir'),
            __('in', 'creation-reservoir'),
            __($tank_designer->get_tank_text_data($conception->Material ?? ''), 'creation-reservoir')
        );
    }

    public function generate_tank_description($title, $article_id, $is_purchase, $target_locale = null) {
        
        // Charger le domaine de traduction pour Polylang
        if (function_exists('pll_load_textdomain')) {
            pll_load_textdomain('creation-reservoir');
        }

        // 1. Forcer la locale AVANT toute opération
        if (!empty($target_locale)) {
            $previous_locale = switch_to_locale($target_locale);
        }

        // error_log('[DEBUG] Locale dans tank description: ' . (function_exists('pll_current_language') ? pll_current_language() : get_locale()));
        
        $tank_designer = new ISPAG_Tank_Designer();
        $fittings_designer = new ISPAG_Tank_Fittings();
        $ispag_welding = new ISPAG_Tank_Welding();
        $datas = $tank_designer->get_tank_data(null, $article_id);
        
        $conception = $datas['conception'] ?? null;
        $dimensions = $datas['dimensions'] ?? null;

        // Sécurité critique : si ce n'est pas un réservoir, on arrête ici
        if (!$conception || !$dimensions) {
            // error_log("ISPAG Debug: Article $article_id n'est pas un réservoir ou données manquantes.");
            return $title; // On renvoie au moins le titre
        }
        

        if (!$conception || !$dimensions) {
            return 'ERREUR';
        }
        
        $lines = [];

        // On passe le titre déjà généré ou on le génère ici
        $lines[] = $this->generate_tank_title($title, $article_id, $target_locale);

        // Utilisation de l'opérateur de coalescence ?? pour éviter les Warnings
        $lines[] = __('Uninsulated diameter', 'creation-reservoir') . ' : ' . number_format($dimensions->Diameter ?? 0, 0, ',', ' ') . ' mm';
        $lines[] = __('Total height', 'creation-reservoir') . ' : ' . number_format($dimensions->Height ?? 0, 0, ',', ' ') . ' mm';
        $lines[] = __('Tipping height', 'creation-reservoir') . ' : ' . number_format($dimensions->TippingHeight ?? 0, 0, ',', ' ') . ' mm';
        $lines[] = __('Ground clearance', 'creation-reservoir') . ' : ' . number_format($dimensions->GroundClearance ?? 0, 0, ',', ' ') . ' mm';
        
        if(!empty($conception->Finition)) {
            $lines[] = $conception->Finition;
        }
        
        $lines[] = __('Design pressure', 'creation-reservoir') . ' : ' . number_format($dimensions->MaxPressure ?? 0, 2, ',', ' ') . ' bar';
        $lines[] = __('Test pressure', 'creation-reservoir') . ' : ' . number_format($dimensions->TestPressure ?? 0, 2, ',', ' ') . ' bar';
        $lines[] = __('Temperature', 'creation-reservoir') . ' : ' . number_format($dimensions->usingTemperature ?? 0, 0, ',', ' ') . ' °C';
        
        ($text = apply_filters('ispag_get_welding_text', null, $article_id, true)) && $lines[] = $text; 
        ($text = apply_filters('ispag_get_tank_drilled_plate_description', null, $article_id)) && $lines[] = $text;

        foreach (apply_filters('ispag_get_heat_exchanger_titles_array', [], $article_id) as $key => $value) {
            $lines[] = $value;
        }
 
        $lines[] = __('Fittings', 'creation-reservoir') . ' : ';
        $lines[] = $fittings_designer->get_tank_connections_description(null, $article_id);
        $lines[] = __('Connection layout freely configurable', 'creation-reservoir');
        $lines[] = apply_filters('ispag_get_insulation_for_tank_description', null, $article_id, $is_purchase);
        $lines[] = !empty($conception->openComment) ? ' ' :  null;
        $lines[] = !empty($conception->openComment) ? $conception->openComment :  null;

        if(!empty($target_locale)){
            restore_previous_locale();
        }

        // error_log('[DEBUG] tank description in : ' . (function_exists('pll_current_language') ? pll_current_language() : get_locale()) . ' - ' . print_r($lines, true));

        return implode("\n", $lines);
    }

    public function get_drawing_approval($title, $article_id){
        if (!is_numeric($article_id)) return false;

        global $wpdb;
        $sql = $wpdb->prepare("
            SELECT DrawingApproved 
            FROM {$wpdb->prefix}achats_details_commande
            WHERE Id = %d
            LIMIT 1
        ", $article_id);

        $result = $wpdb->get_var($sql);



        return intval($result) === 1;
    }

    

    public function get_if_last_drawing_or_modif($title, $article_id) {
        global $wpdb;

        if (empty($article_id) || !is_numeric($article_id)) {
            return null;
        }

        $allowed_types = ['product_drawing', 'drawingApproval', 'drawingModification', 'sketch'];
        $placeholders = implode(',', array_fill(0, count($allowed_types), '%s'));

        $sql = "
            SELECT dt.label, dt.badge_class, dt.slug
            FROM {$wpdb->prefix}achats_historique h
            LEFT JOIN {$wpdb->prefix}achats_doc_types dt ON dt.slug = h.ClassCss
            WHERE h.Historique = %d
            AND h.ClassCss IN ($placeholders)
            ORDER BY h.dateReadable	DESC
            LIMIT 1
        ";

        $query_args = array_merge([$article_id], $allowed_types);
        $prepared_sql = $wpdb->prepare($sql, ...$query_args);

        if ($prepared_sql === false) {
            return null;
        }

        return $wpdb->get_row($prepared_sql, ARRAY_A); // Retourne ['label' => ..., 'badge_class' => ...]
    }


    

    


}