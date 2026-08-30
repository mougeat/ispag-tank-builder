<?php
/**
 * Classe ISPAG_Nameplate_Generator
 *
 * Génère une plaque signalétique (nameplate) au format PDF pour un réservoir.
 * Héritage : ISPAG_PDF_Generator (pour la gestion de base du PDF).
 * Logging : Toutes les actions sont loguées dans ispag_nameplate_generator.log.
 */
class ISPAG_Nameplate_Generator extends ISPAG_PDF_Generator {

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    /**
     * Constructeur.
     */
    public function __construct() {
        parent::__construct('L', 'mm', [120, 80]);
        $this->logger = ISPAG_Logger::get_instance();

        $this->logo_url = WP_CONTENT_DIR . '/uploads/2025/03/Logo_ISPAG_RGB_F.png';
    }

    /**
     * Génère la plaque signalétique pour un réservoir.
     *
     * @param object $project Données du projet.
     * @param object $article Données de l'article.
     * @param array $tank_datas Données techniques du réservoir.
     */
    public function generate_nameplate($project, $article, $tank_datas) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(
            'nameplate_generator',
            'generate_start',
            [
                'project_id' => $project->NumCommande ?? '0000',
                'article_id' => $article->Id ?? '0',
                'article_name' => $article->Article ?? 'N/A'
            ],
            $user_id
        );

        // --- 1. Préparation des variables ---
        $project_id = $project->NumCommande ?? '0000';
        $article_id_padded = str_pad($article->Id, 5, '0', STR_PAD_LEFT);
        $dims = $tank_datas['dimensions_principales'] ?? [];
        $mat_text = $dims['Matiere'] ?? '';
        $this->logger->log_db_change(
            'nameplate_generator',
            'tank_dimensions',
            'RESOLVE_MATERIAL',
            ['raw_material' => $mat_text],
            $user_id
        );

        $material_code = $this->get_material_code($mat_text);
        $this->logger->log_db_change(
            'nameplate_generator',
            'material_code',
            'RESOLVE',
            ['material_text' => $mat_text, 'resolved_code' => $material_code],
            $user_id
        );

        $type_code = implode('', array_map(function($mot) {
            return mb_strtoupper(mb_substr($mot, 0, 1));
        }, explode(' ', trim($tank_datas['dimensions_principales']['Type']))));

        $serial_number = "{$type_code}-{$project_id}-{$material_code}-{$article_id_padded}";
        $this->logger->log_user_action(
            'nameplate_generator',
            'serial_number_generated',
            ['serial' => $serial_number],
            $user_id
        );

        // --- 2. URL du Digital Product Twin ---
        $base_url = home_url('/ispag-digital-product-twin/');
        $qr_url_link = add_query_arg('serial', $serial_number, $base_url);
        $qr_image_api = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_url_link);
        $this->logger->log_user_action(
            'nameplate_generator',
            'qr_code_generated',
            ['url' => $qr_url_link],
            $user_id
        );

        // --- 3. Configuration PDF ---
        $this->SetTitle(iconv('UTF-8', 'windows-1252', $serial_number));
        $this->SetAuthor('ispag-crm');
        $this->SetMargins(5, 5, 5);
        $this->AddPage();
        $this->SetAutoPageBreak(false);
        $this->logger->log_user_action(
            'nameplate_generator',
            'pdf_initialized',
            ['page_size' => [120, 80]],
            $user_id
        );

        // --- 4. Dessin du cadre ---
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.6);
        $this->Rect(2, 2, 116, 76);
        $this->logger->log_user_action(
            'nameplate_generator',
            'draw_frame',
            ['dimensions' => [116, 76]],
            $user_id
        );

        // --- 5. En-tête (Logo + Adresse) ---
        if (file_exists($this->logo_url)) {
            $this->Image($this->logo_url, 5, 5, 30);
            $this->logger->log_user_action(
                'nameplate_generator',
                'logo_added',
                ['logo_path' => $this->logo_url],
                $user_id
            );
        } else {
            $this->logger->log_error(
                'nameplate_generator',
                'logo_not_found',
                ['expected_path' => $this->logo_url],
                $user_id
            );
        }

        $this->SetFont('Arial', '', 9);
        $this->SetXY(65, 5);
        $address = "ISPAG, succursale de ISSA SA\nChamp Paccot 19\n1627 Vaulruz";
        $this->MultiCell(50, 3.5, iconv('UTF-8', 'windows-1252', $address), 0, 'R');
        $this->Line(5, 18, 115, 18);
        $this->logger->log_user_action(
            'nameplate_generator',
            'header_drawn',
            ['address' => $address],
            $user_id
        );

        // --- 6. Titre (Article) ---
        $this->SetXY(5, 20);
        $this->SetFont('Arial', 'B', 12);

        // Suppression de la virgule et de ce qui suit dans le titre
        $article_name = $article->Article ?? 'NOM ARTICLE MANQUANT';
        $article_name = preg_replace('/,\s*.*/', '', $article_name);

        $this->MultiCell(110, 5, iconv('UTF-8', 'windows-1252', $article_name), 0, 'C');
        $this->Line(5, 32, 115, 32);
        $this->logger->log_user_action(
            'nameplate_generator',
            'title_drawn',
            ['article_name' => $article_name],
            $user_id
        );

        // --- 7. Données Techniques (Bilingue) ---
        $this->SetFont('Arial', '', 10);

        // Récupération des données des échangeurs
        $coils = apply_filters('ispag_get_heat_exchanger_datas', [], $article->Id);
        $total_surface = 0;
        $exchanger_details = "";

        if (!empty($coils)) {
            foreach ($coils as $coil) {
                $total_surface += floatval($coil['coilSurface'] ?? 0);
            }
            $this->logger->log_db_change(
                'nameplate_generator',
                'heat_exchanger_datas',
                'CALCULATE_SURFACE',
                ['total_surface' => $total_surface, 'coils_count' => count($coils)],
                $user_id
            );
        }

        $ps = $dims['Pression_Max_bar'] ?? 0;
        $pt = $dims['Pression_Test_bar'] ?? (number_format($ps * 1.25, 2));
        $this->logger->log_db_change(
            'nameplate_generator',
            'pressure_datas',
            'RESOLVE',
            ['design_pressure' => $ps, 'test_pressure' => $pt],
            $user_id
        );

        $specs = [
            __('Material', 'creation-reservoir') . ' (MAT)'       => $mat_text,
            __('Volume', 'creation-reservoir') . ' (V)'           => ($dims['Volume_L'] ?? '0') . ' L',
            __('Exch. Surface', 'creation-reservoir')             => ($total_surface > 0) ? $total_surface . ' m²' : '---',
            __('Design pressure', 'creation-reservoir') . ' (PS)' => $ps . ' bar',
            __('Test pressure', 'creation-reservoir') . ' (PT)'   => $pt . ' bar',
            __('Max. Temp.', 'creation-reservoir') . ' (TS)'      => ($dims['Temperature_Max'] ?? '0') . ' C'
        ];
        $this->logger->log_user_action(
            'nameplate_generator',
            'specs_prepared',
            ['specs' => array_keys($specs)],
            $user_id
        );

        $y_row = 35;
        foreach ($specs as $label => $value) {
            $this->SetXY(5, $y_row);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(50, 5, iconv('UTF-8', 'windows-1252', $label . ' :'), 0, 0, 'L'); // Label à gauche (largeur 45)

            $this->SetFont('Arial', '', 10);
            $this->Cell(15, 5, iconv('UTF-8', 'windows-1252', $value), 0, 0, 'R'); // Valeur à droite (largeur 55)
            $y_row += 5.5;
        }

        // --- 8. Année et Numéro de Série ---
        $this->SetXY(75, 35);
        // Police réduite de -1 (10 → 9)
        $this->SetFont('Arial', 'B', 8);
        $year_raw = $article->date_livraison ?? '';
        $year = !empty($year_raw) ? date('Y', strtotime($year_raw)) : date('Y');
        $this->Cell(40, 5, __('YEAR', 'creation-reservoir') . ' : ' . $year, 0, 1);
        $this->SetX(75);
        $this->Cell(40, 5, __('SERIAL', 'creation-reservoir') . ' : ' . $serial_number, 0, 1);
        $this->logger->log_user_action(
            'nameplate_generator',
            'year_and_serial_drawn',
            ['year' => $year, 'serial' => $serial_number],
            $user_id
        );

        // --- 9. QR Code (Digital Twin) ---
        $this->Image($qr_image_api, 88, 46, 22, 22, 'PNG');
        $this->SetXY(88, 68);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(22, 2.5, '[ DIGITAL TWIN ]', 0, 1, 'C');
        $this->SetX(88);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(22, 2, 'SCAN FOR DOCS', 0, 0, 'C');
        $this->logger->log_user_action(
            'nameplate_generator',
            'qr_code_drawn',
            ['qr_url' => $qr_image_api],
            $user_id
        );

        // --- 10. Mention Légale Bas de Page ---
        $this->SetXY(5, 73);
        // Retour à la police initiale (5.5)
        $this->SetFont('Arial', 'I', 5.5);
        $legal_en = "Built according to Pressure Equipment Directive 2014/68/EU (art. 4 para. 3)";
        $legal_fr = "Construit selon la directive 2014/68/UE (art. 4 al. 3)";
        $this->Cell(110, 3, iconv('UTF-8', 'windows-1252', "$legal_en / $legal_fr"), 0, 0, 'L');
        $this->logger->log_user_action(
            'nameplate_generator',
            'legal_notice_drawn',
            ['legal_text' => "$legal_en / $legal_fr"],
            $user_id
        );

        // --- 11. Sortie avec header pour le nom du fichier ---
        $filename = $serial_number . ".pdf";
        if (!headers_sent()) {
            header('Content-Disposition: inline; filename="' . $filename . '"');
        }
        $this->Output('I', $filename);
        $this->logger->log_user_action(
            'nameplate_generator',
            'pdf_output',
            ['filename' => $filename],
            $user_id
        );
        exit;
    }

    /**
     * Résout le code matériau à partir du texte.
     *
     * @param string $text Texte du matériau (ex: "AISI316L").
     * @return string Code matériau (ex: "V4").
     */
    protected function get_material_code($text) {
        $text = strtoupper($text);
        $material_code = 'XX'; // Valeur par défaut

        if (strpos($text, 'AISI316L') !== false || strpos($text, '1.4571') !== false) {
            $material_code = 'V4';
        } elseif (strpos($text, 'AISI304L') !== false || strpos($text, '1.4301') !== false) {
            $material_code = 'V2';
        } elseif (strpos($text, 'S235JR') !== false || strpos($text, 'ACIER') !== false) {
            $material_code = 'AC';
        } elseif (strpos($text, 'EMAILLE') !== false || strpos($text, 'ÉMAILLÉ') !== false) {
            $material_code = 'EM';
        }

        $user_id = get_current_user_id();
        $this->logger->log_db_change(
            'nameplate_generator',
            'material_code',
            'RESOLVE_MANUAL',
            ['input_text' => $text, 'output_code' => $material_code],
            $user_id
        );

        return $material_code;
    }

    /**
     * Pied de page vide (obligatoire pour FPDF).
     */
    function Footer() {}
}