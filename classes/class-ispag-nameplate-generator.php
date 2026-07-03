<?php

class ISPAG_Nameplate_Generator extends ISPAG_PDF_Generator {

    public function __construct() {
        parent::__construct('L', 'mm', [120, 80]);
    }

    public function generate_nameplate($project, $article, $tank_datas) {

        // --- 1. Préparation des variables ---
        $project_id = $project->NumCommande ?? '0000';
        $article_id_padded = str_pad($article->Id, 5, '0', STR_PAD_LEFT);
        $dims = $tank_datas['dimensions_principales'] ?? [];
        $mat_text = $dims['Matiere'] ?? ''; 
        $material_code = $this->get_material_code($mat_text);
        
        $type_code = "AE"; 
        $serial_number = "{$type_code}-{$project_id}-{$material_code}-{$article_id_padded}";

        // --- 2. URL du Digital Product Twin ---
        // Remplace 'https://ispag.ch' par ton domaine réel si nécessaire
        $base_url = home_url('/ispag-digital-product-twin/'); 
        $qr_url_link = add_query_arg('serial', $serial_number, $base_url);

        // Génération de l'image du QR Code via l'API
        $qr_image_api = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_url_link);

        // --- 3. Configuration PDF ---
        $this->SetTitle(iconv('UTF-8', 'windows-1252', $serial_number)); 
        $this->SetAuthor('ispag-crm');
        $this->SetMargins(5, 5, 5);
        $this->AddPage();
        $this->SetAutoPageBreak(false);
 
        // --- 4. Dessin du cadre ---
        $this->SetDrawColor(0); 
        $this->SetLineWidth(0.6);
        $this->Rect(2, 2, 116, 76); 

        // --- 5. En-tête (Logo + Adresse) ---
        if (!empty($this->logo_url)) {
            $this->Image($this->logo_url, 5, 5, 30);
        }

        $this->SetFont('Arial', '', 7);
        $this->SetXY(65, 5);
        $address = "ISPAG, succursale de ISSA SA\nChamp Paccot 19\n1627 Vaulruz";
        $this->MultiCell(50, 3.5, iconv('UTF-8', 'windows-1252', $address), 0, 'R');
        $this->Line(5, 18, 115, 18);

        // --- 6. Titre (Article) ---
        $this->SetXY(5, 20);
        $this->SetFont('Arial', 'B', 10);
        $article_name = $article->Article ?? 'NOM ARTICLE MANQUANT';
        $this->MultiCell(110, 5, iconv('UTF-8', 'windows-1252', $article_name), 0, 'L');
        $this->Line(5, 32, 115, 32);

        // --- 7. Données Techniques (Bilingue) ---
        $this->SetFont('Arial', '', 8);

        // Récupération des données des échangeurs via ton filtre existant
        $coils = apply_filters('ispag_get_heat_exchanger_datas', [], $article->Id);
        $total_surface = 0;
        $exchanger_details = "";

        if (!empty($coils)) {
            foreach ($coils as $coil) {
                $total_surface += floatval($coil['coilSurface'] ?? 0);
            }
        }

        $ps = $dims['Pression_Max_bar'] ?? 0;
        $pt = $dims['Pression_Test_bar'] ?? (number_format($ps * 1.25, 2));

        $specs = [
            __('Material', 'creation-reservoir') . ' (MAT)'       => $mat_text,
            __('Volume', 'creation-reservoir') . ' (V)'           => ($dims['Volume_L'] ?? '0') . ' L',
            __('Exch. Surface', 'creation-reservoir')             => ($total_surface > 0) ? $total_surface . ' m²' : '---',
            __('Design Pressure', 'creation-reservoir') . ' (PS)' => $ps . ' bar',
            __('Test Pressure', 'creation-reservoir') . ' (PT)'   => $pt . ' bar',
            __('Max. Temp.', 'creation-reservoir') . ' (TS)'      => ($dims['Temperature_Max'] ?? '0') . ' C'
        ];

        $y_row = 35;
        foreach ($specs as $label => $value) {
            $this->SetXY(5, $y_row);
            $this->SetFont('Arial', 'B', 7);
            $this->Cell(35, 5, iconv('UTF-8', 'windows-1252', $label . ' :'), 0);
            $this->SetFont('Arial', '', 8);
            $this->Cell(40, 5, iconv('UTF-8', 'windows-1252', $value), 0);
            $y_row += 5.5;
        }

        // --- 8. Année et Numéro de Série ---
        $this->SetXY(75, 35);
        $this->SetFont('Arial', 'B', 8);
        $year_raw = $article->date_livraison ?? '';
        $year = !empty($year_raw) ? date('Y', strtotime($year_raw)) : date('Y');
        
        $this->Cell(40, 5, __('YEAR', 'creation-reservoir') . ' : ' . $year, 0, 1);
        $this->SetX(75);
        $this->Cell(40, 5, __('SERIAL', 'creation-reservoir') . ' : ' . $serial_number, 0, 1);

        // --- 9. QR Code (Digital Twin) ---
        $this->Image($qr_image_api, 88, 46, 22, 22, 'PNG');
        
        // Petit texte explicatif bilingue sous le QR
        $this->SetXY(88, 68);
        $this->SetFont('Arial', 'B', 5.5);
        $this->Cell(22, 2.5, '[ DIGITAL TWIN ]', 0, 1, 'C');
        $this->SetX(88);
        $this->SetFont('Arial', 'I', 5);
        $this->Cell(22, 2, 'SCAN FOR DOCS', 0, 0, 'C');

        // --- 10. Mention Légale Bas de Page ---
        $this->SetXY(5, 73);
        $this->SetFont('Arial', 'I', 5.5);
        $legal_en = "Built according to Pressure Equipment Directive 2014/68/EU (art. 4 para. 3)";
        $legal_fr = "Construit selon la directive 2014/68/UE (art. 4 al. 3)";
        $this->Cell(110, 3, iconv('UTF-8', 'windows-1252', "$legal_en / $legal_fr"), 0, 0, 'L');

        // --- 11. Sortie avec header pour le nom du fichier ---
        $filename = $serial_number . ".pdf";
        if (!headers_sent()) {
            header('Content-Disposition: inline; filename="' . $filename . '"');
        }
        $this->Output('I', $filename);
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
    
    function Footer() {}
}