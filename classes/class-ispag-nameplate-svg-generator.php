<?php

class ISPAG_Nameplate_SVG_Generator {

    protected $logo_url = 'https://app.ispag-asp.ch/wp-content/uploads/2025/03/Logo_ISPAG_RGB_F.png';

    public function __construct($logo_url = '') {
        if (!empty($logo_url)) {
            $this->logo_url = $logo_url;
        }
    }

    public function generate_nameplate($project, $article, $tank_datas) {
        
        // --- 1. Préparation des variables ---
        $project_id = $project->NumCommande ?? '0000';
        $article_id_padded = str_pad($article->Id, 5, '0', STR_PAD_LEFT);
        $dims = $tank_datas['dimensions_principales'] ?? [];
        $mat_text = htmlspecialchars($dims['Matiere'] ?? '', ENT_XML1, 'UTF-8'); 
        $material_code = $this->get_material_code($mat_text);
        
        $type_code = "AE"; 
        $serial_number = "{$type_code}-{$project_id}-{$material_code}-{$article_id_padded}";

        // URL du Digital Twin et QR Code embarqué en Base64
        $base_url = home_url('/ispag-digital-product-twin/'); 
        $qr_url_link = add_query_arg('serial', $serial_number, $base_url);
        $qr_image_api = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_url_link);
        
        $qr_data = wp_remote_get($qr_image_api);
        $qr_base64 = '';
        if (!is_wp_error($qr_data)) {
            $qr_base64 = 'data:image/png;base64,' . base64_encode(wp_remote_retrieve_body($qr_data));
        }

        // --- Préparation du Logo en Base64 ---
        $logo_base64 = '';
        if (!empty($this->logo_url)) {
            $logo_data = wp_remote_get($this->logo_url);
            if (!is_wp_error($logo_data)) {
                $logo_body = wp_remote_retrieve_body($logo_data);
                $logo_type = wp_remote_retrieve_header($logo_data, 'content-type');
                $logo_base64 = 'data:' . $logo_type . ';base64,' . base64_encode($logo_body);
            }
        }

        if (ob_get_length()) ob_end_clean();

        header('Content-Type: image/svg+xml; charset=UTF-8');
        // header('Content-Disposition: inline; filename="' . $serial_number . '.svg"');
        header('Content-Disposition: attachment; filename="' . $serial_number . '.svg"');
        // Optionnel : Indiquer la taille du fichier (bonne pratique pour les téléchargements)
        header('Cache-Control: public, must-revalidate, max-age=0');
        header('Pragma: public');

        echo '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n";
        echo '<svg width="120mm" height="80mm" viewBox="0 0 120 80" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">' . "\n";
        
        echo '<style>
                .text { font-family: Arial, sans-serif; fill: black; }
                .bold { font-weight: bold; }
                .small { font-size: 2.2px; }
                .medium { font-size: 3.2px; }
                .title { font-size: 4.2px; line-height: 1.2; }
                .line { stroke: black; stroke-width: 0.3; }
              </style>' . "\n";

        // Cadre extérieur
        echo '<rect x="2" y="2" width="116" height="76" fill="none" stroke="black" stroke-width="0.6" />' . "\n";

        // Logo ISPAG
        if (!empty($logo_base64)) {
            echo '<image xlink:href="' . $logo_base64 . '" x="5" y="5" height="12" width="35" />' . "\n";
        }

        // Adresse En-tête
        echo '<text x="115" y="7" class="text small" text-anchor="end">ISPAG, succursale de ISSA SA</text>' . "\n";
        echo '<text x="115" y="11" class="text small" text-anchor="end">Champ Paccot 19, 1627 Vaulruz</text>' . "\n";
        echo '<line x1="5" y1="20" x2="115" y2="20" class="line" />' . "\n";

        // --- TITRE AVEC RETOUR LIGNE AUTOMATIQUE ---
        $article_name = htmlspecialchars($article->Article ?? 'NOM ARTICLE', ENT_XML1, 'UTF-8');
        echo '<foreignObject x="5" y="22" width="110" height="12">' . "\n";
        echo '  <div xmlns="http://www.w3.org/1999/xhtml" class="text bold title">' . $article_name . '</div>' . "\n";
        echo '</foreignObject>' . "\n";
        
        echo '<line x1="5" y1="34" x2="115" y2="34" class="line" />' . "\n";

        // Récupération surface échangeur
        $coils_datas = apply_filters('ispag_get_heat_exchanger_datas', [], $article->Id);
        $total_surface = 0;
        if (!empty($coils_datas)) {
            foreach ($coils_datas as $coil) { $total_surface += floatval($coil['coilSurface'] ?? 0); }
        }
        $surface_text = ($total_surface > 0) ? (number_format($total_surface, 2) . ' m²') : '---';

        $ps = $dims['Pression_Max_bar'] ?? 0;
        $pt = $dims['Pression_Test_bar'] ?? (number_format($ps * 1.25, 2));
        
        $specs = [
            __('Material', 'creation-reservoir') . ' (MAT)'         => $mat_text,
            __('Volume', 'creation-reservoir') . ' (V)'             => ($dims['Volume_L'] ?? '0') . ' L',
            __('Exch. surface', 'creation-reservoir')               => $surface_text,
            __('Design pressure', 'creation-reservoir') . ' (PS)'   => $ps . ' bar',
            __('Test pressure', 'creation-reservoir') . ' (PT)'     => $pt . ' bar',
            __('Max. temp.', 'creation-reservoir') . ' (TS)'        => ($dims['Temperature_Max'] ?? '0') . ' C'
        ];

        // Rendu des specs - Valeurs décalées à x=48 pour un alignement plus serré
        $y = 41;
        foreach ($specs as $label => $val) {
            echo '<text x="5" y="'.$y.'" class="text medium bold">'.htmlspecialchars($label, ENT_XML1, 'UTF-8').' :</text>' . "\n";
            echo '<text x="48" y="'.$y.'" class="text medium">'.htmlspecialchars($val, ENT_XML1, 'UTF-8').'</text>' . "\n";
            $y += 5.5;
        }

        // Bloc Année / Série - Maintenu à x=65
        $year_raw = $article->date_livraison ?? '';
        $year = !empty($year_raw) ? date('Y', strtotime($year_raw)) : date('Y');
        
        echo '<text x="65" y="41" class="text medium bold">' . __('YEAR', 'creation-reservoir') . ' : '.$year.'</text>' . "\n";
        echo '<text x="65" y="47" class="text medium bold">' . __('SERIAL', 'creation-reservoir') . ' : '.$serial_number.'</text>' . "\n";

        // QR Code
        if (!empty($qr_base64)) {
            echo '<image xlink:href="'.$qr_base64.'" x="88" y="48" height="22" width="22" />' . "\n";
        }
        echo '<text x="99" y="72" class="text small bold" text-anchor="middle">[ ' . __('Scan for docs', 'creation-reservoir') . ' ]</text>' . "\n";

        // Mentions légales raccourcies pour tenir dans le cadre
        $legal_en = "Built acc. to Pressure Equip. Directive 2014/68/EU (art. 4 para. 3)";
        $legal_fr = "Construit sel. la directive 2014/68/UE (art. 4 al. 3)";
        echo '<text x="3" y="76.5" class="text small" style="font-style:italic;">' . htmlspecialchars("$legal_en / $legal_fr", ENT_XML1, 'UTF-8') . '</text>' . "\n";

        echo '</svg>';
        exit;
    }

    protected function get_material_code($text) {
        $text = strtoupper($text);
        if (strpos($text, 'AISI316L') !== false || strpos($text, '1.4571') !== false) return 'V4';
        if (strpos($text, 'AISI304L') !== false || strpos($text, '1.4301') !== false) return 'V2';
        if (strpos($text, 'S235JR') !== false || strpos($text, 'ACIER') !== false) return 'AC';
        if (strpos($text, 'EMAILLE') !== false || strpos($text, 'ÉMAILLÉ') !== false) return 'EM';
        return 'XX';
    }
}