<?php
defined('ABSPATH') || exit;

require_once WP_PLUGIN_DIR . '/ispag-project-manager/libs/fpdf/fpdf.php';

class ISPAG_Notice_PDF_Generator extends FPDF
{
    protected static $wpdb;
    protected static $templates_path;
    protected static $logo_url = 'https://app.ispag-asp.ch/wp-content/uploads/2024/06/Logo_ISPAG_CMYK_F_web.png';
    protected static $logo_path;

    // --- CONFIGURATION DES POLICES ---
    protected static $base_font_size = 10; // Taille standard de référence
    protected static $font_size_title;     // Titres principaux (+2)
    protected static $font_size_header;    // En-têtes de tableau / sections (Base)
    protected static $font_size_body;      // Corps de texte (-2)
    protected static $font_size_small;     // Mentions légales / footer (-4)

    public $footer_template; // Template du pied de page (pour Footer())

    public static function init()
    {
        global $wpdb;
        self::$wpdb = $wpdb;
        self::$templates_path = ISPAG_PLUGIN_PATH . 'templates/';
        self::$logo_path = ISPAG_PLUGIN_PATH . 'assets/logo_ispag.png';

        // Calcul dynamique des tailles de polices
        self::$font_size_title  = self::$base_font_size + 2; // 14
        self::$font_size_header = self::$base_font_size;     // 12
        self::$font_size_body   = self::$base_font_size - 2; // 10
        self::$font_size_small  = self::$base_font_size - 4; // 8

        if (!file_exists(self::$logo_path)) {
            self::download_logo();
        }

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('wp_ajax_generate_notice_pdf', [__CLASS__, 'handle_ajax_request']);
    }

    public static function enqueue_scripts()
    {
        $js_path = ISPAG_PLUGIN_PATH . 'assets/js/ispag-notice-pdf.js';

        if (!file_exists($js_path)) {
            error_log("Fichier JS manquant : " . $js_path);
            return;
        }

        wp_register_script(
            'ispag-notice-pdf',
            ISPAG_PLUGIN_URL . 'assets/js/ispag-notice-pdf.js',
            ['jquery'],
            filemtime($js_path),
            true
        );

        wp_add_inline_script(
            'ispag-notice-pdf',
            'var ispagNoticePdf = ' . json_encode([
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ispag_nonce')
            ]) . ';'
        );

        wp_enqueue_script('ispag-notice-pdf');
    }

    protected static function download_logo()
    {
        $assets_dir = dirname(self::$logo_path);
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
        }

        $response = wp_remote_get(self::$logo_url);
        if (!is_wp_error($response) && $response['response']['code'] === 200) {
            file_put_contents(self::$logo_path, $response['body']);
        } else {
            error_log("Impossible de télécharger le logo ISPAG : " . ($response->get_error_message() ?? 'Unknown error'));
        }
    }

    public static function handle_ajax_request()
    {
        try {
            check_ajax_referer('ispag_nonce', 'nonce');

            $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
            $lang = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : 'fr';

            if (!$article_id) {
                wp_send_json_error('ID de l\'article manquant.', 400);
            }

            $result = self::generate_notice_pdf($article_id, $lang);
            wp_send_json_success($result);

        } catch (Exception $e) {
            error_log("Erreur AJAX : " . $e->getMessage());
            wp_send_json_error('Erreur : ' . $e->getMessage(), 500);
        }
    }

    public static function generate_notice_pdf($article_id, $lang = 'fr')
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        $data = self::load_tank_datas($article_id);
        $template = self::load_template($lang);

        // Créer le PDF en format A5
        $pdf = new self('P', 'mm', 'A5');
        $pdf->AliasNbPages(); // Nécessaire pour {nb} dans Footer()
        $pdf->SetAutoPageBreak(true, 20); // Marge inférieure de 20 mm pour le pied de page
        $pdf->footer_template = $template['footer']; // Stocker le template du footer

        $pdf->AddPage();

        self::add_ispag_header($pdf, $template, $data['article'], $data['project'], $data['tank_datas']);
        self::add_content_from_template($pdf, $template, $data['article'], $data['project'], $data['tank_datas']);

        $upload_dir = wp_upload_dir();
        $filename = "Notice_Reservoir_" . self::sanitize_filename($data['article']->Article) . "_{$lang}_" . time() . ".pdf";
        $pdf_path = $upload_dir['path'] . '/' . $filename;
        $pdf_url = $upload_dir['url'] . '/' . $filename;

        $pdf->Output('F', $pdf_path);

        return [
            'pdf_url' => $pdf_url,
            'filename' => $filename
        ];
    }

    /**
     * Méthode Footer() appelée automatiquement par FPDF pour chaque page.
     * Ajoute le pied de page avec le numéro de page (X/X).
     */
    public function Footer()
    {
        $this->SetY(-20);
        $this->SetFont('Arial', 'I', self::$font_size_small);
        $this->SetTextColor(100, 100, 100);

        // Ligne de séparation
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());

        $this->Ln(5);
        $this->Cell(0, 10, utf8_decode(self::replace_placeholders_static($this->footer_template['text'], null, null, null)), 0, 0, 'C');
        $this->Ln(5);
        $this->Cell(0, 10, utf8_decode('Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
    }

    /**
     * Méthode pour remplacer les placeholders (version statique pour Footer).
     */
    protected static function replace_placeholders_static($content, $article, $project, $tank_datas)
    {
        if (is_array($content)) {
            return array_map(function($item) use ($article, $project, $tank_datas) {
                return self::replace_placeholders_static($item, $article, $project, $tank_datas);
            }, $content);
        }

        $placeholders = [
            '{{year}}' => date('Y')
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $content);
    }

    protected static function sanitize_filename($filename) {
        $filename = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename);
        return $filename;
    }

    protected static function load_tank_datas($article_id)
    {
        $tank_designer = new ISPAG_Tank_Designer();
        $tank_datas = $tank_designer->get_tank_data(null, $article_id);

        $fittings_designer = new ISPAG_Tank_Fittings();
        $tank_datas['piquages'] = $fittings_designer->get_all_fittings($article_id, false);

        $welding = new ISPAG_Tank_Welding();
        $tank_datas['weldings'] = $welding->get_all_welding_drilled_plate(null, $article_id, false);

        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, apply_filters('ispag_get_article_deal_id', null, $article_id));

        if (empty($article->Article)) {
            $article->Article = $article->ID ?? $article_id;
        }

        return [
            'article' => $article,
            'project' => $project,
            'tank_datas' => $tank_datas
        ];
    }

    protected static function load_template($lang)
    {
        $file = self::$templates_path . "notice_{$lang}.json";

        if (!file_exists($file)) {
            error_log("Template manquant : " . $file);
            $file = self::$templates_path . "notice_fr.json";
            if (!file_exists($file)) {
                throw new Exception("Template par défaut non trouvé.");
            }
        }

        $template_content = file_get_contents($file);
        $template = json_decode($template_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur JSON dans le template : " . json_last_error_msg());
        }

        return $template;
    }

    protected static function add_ispag_header($pdf, $template, $article, $project, $tank_datas)
    {
        if (file_exists(self::$logo_path)) {
            $pdf->Image(self::$logo_path, 10, 10, 30);
        }

        $pdf->SetFont('Arial', 'B', self::$font_size_title);
        $pdf->SetTextColor(180, 0, 0);
        $pdf->Cell(0, 10, utf8_decode($template['header']['company']), 0, 1, 'R');

        $pdf->SetFont('Arial', '', self::$font_size_body);
        $pdf->SetTextColor(0, 0, 0);
        $slogan = self::replace_placeholders($template['header']['slogan'], $article, $project, $tank_datas);
        $pdf->Cell(0, 10, utf8_decode($slogan), 0, 1, 'R');
        $pdf->Ln(5);

        $pdf->SetFont('Arial', 'I', self::$font_size_small);
        $pdf->Cell(0, 10, utf8_decode($template['header']['date_label'] . ' : ' . date('d.m.Y')), 0, 1, 'R');
        $pdf->Ln(10);
    }

    protected static function add_content_from_template($pdf, $template, $article, $project, $tank_datas)
    {
        foreach ($template['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }

            // Saut de page si demandé
            if (!empty($section['page_break'])) {
                $pdf->AddPage();
            }

            // Titre de la section
            $pdf->SetFont('Arial', 'B', self::$font_size_header);
            $pdf->SetTextColor(180, 0, 0);
            $pdf->Cell(0, 10, utf8_decode($section['title']), 0, 1);

            $pdf->SetFont('Arial', '', self::$font_size_body);
            $pdf->SetTextColor(0, 0, 0);

            // Sauvegarder la position Y actuelle
            $start_y = $pdf->GetY();

            // 1. Afficher le texte SI il existe (que ce soit en string ou dans un champ 'text')
            if (isset($section['content']['text'])) {
                $text = self::replace_placeholders($section['content']['text'], $article, $project, $tank_datas);
                $pdf->MultiCell(0, 7, utf8_decode($text));
                $pdf->Ln(5);
            }
            elseif (is_string($section['content'])) {
                // Cas où content est directement une string (ex: "Généralités")
                $content = self::replace_placeholders($section['content'], $article, $project, $tank_datas);
                $pdf->MultiCell(0, 7, utf8_decode($content));
                $pdf->Ln(5);
            }

            // Afficher l'image si présente dans le JSON
            if (isset($section['image'])) {
                $image_data = $section['image'];
                $image_url = self::replace_placeholders($image_data['url'], $article, $project, $tank_datas);

                // Position par défaut
                $x = null;
                $y = $start_y;

                // Calculer la position X en fonction de la position demandée
                if (isset($image_data['position'])) {
                    switch ($image_data['position']) {
                        case 'left':
                            $x = 10;
                            break;
                        case 'center':
                            $x = ($pdf->GetPageWidth() - ($image_data['width'] ?? 40)) / 2;
                            break;
                        case 'right':
                        default:
                            $x = 110; // Position à droite du texte (90 mm + 20 mm de marge)
                            break;
                    }
                } else {
                    $x = 110; // Position par défaut à droite
                }

                // Afficher l'image
                $image_height = self::add_tank_image(
                    $pdf,
                    $image_url,
                    $x,
                    $y,
                    $image_data['width'] ?? 0,
                    $image_data['height'] ?? 40
                );

                // Repositionner Y après l'image (si l'image est plus haute que le texte)
                $current_y = $pdf->GetY();
                if ($start_y + $image_height > $current_y) {
                    $pdf->SetY($start_y + $image_height);
                }
                $pdf->Ln(5);
            }

            // 2. Afficher le tableau SI il existe (que ce soit un tableau statique ou dynamique)
            if (isset($section['content']['table_headers']) && isset($section['content']['table_rows'])) {
                $table_rows = $section['content']['table_rows'];
                $widths = $section['content']['column_widths'] ?? array_fill(0, count($section['content']['table_headers']), (self::get_page_width($pdf) - 20) / count($section['content']['table_headers']));

                // Remplacer les placeholders comme {{piquages}} ou {{weldings}}
                if (is_string($table_rows)) {
                    if (strpos($table_rows, '{{piquages}}') !== false) {
                        $table_rows = self::format_piquages_table($tank_datas['piquages']);
                    } elseif (strpos($table_rows, '{{weldings}}') !== false) {
                        $table_rows = self::format_weldings_table($tank_datas['weldings']);
                    }
                }

                self::add_table($pdf, $section['content']['table_headers'], $table_rows, $article, $project, $tank_datas, $widths);
            }
        }
    }

    /**
     * Ajoute une image (SVG converti en PNG) dans le PDF.
     *
     * @param FPDF $pdf Instance de FPDF.
     * @param string $svg_url URL ou chemin du fichier SVG.
     * @param float|null $x Position X (null = position actuelle).
     * @param float|null $y Position Y (null = position actuelle).
     * @param float $w Largeur (0 pour largeur automatique).
     * @param float $h Hauteur (par défaut 40 mm).
     * @return float Hauteur de l'image affichée.
     */
    protected static function add_tank_image($pdf, $svg_url, $x = null, $y = null, $w = 0, $h = 40)
    {
        if (empty($svg_url)) {
            return 0;
        }

        // Convertir l'URL en chemin local (si c'est une URL WordPress)
        $svg_path = str_replace(
            [site_url(), WP_CONTENT_URL],
            [ABSPATH, WP_CONTENT_DIR],
            $svg_url
        );

        // Vérifier que le fichier SVG existe
        if (!file_exists($svg_path)) {
            error_log("Fichier SVG introuvable : " . $svg_path);
            return 0;
        }

        // Chemin pour le PNG généré (remplace .svg par .png)
        $png_path = str_replace('.svg', '.png', $svg_path);

        // Convertir SVG en PNG si nécessaire (si le PNG n'existe pas ou est obsolète)
        if (!file_exists($png_path) || filemtime($png_path) < filemtime($svg_path)) {
            self::convert_svg_to_png($svg_path, $png_path);
        }

        // Vérifier que le PNG a bien été généré
        if (!file_exists($png_path)) {
            error_log("Échec de la conversion SVG → PNG : " . $png_path);
            return 0;
        }

        // Position par défaut
        if ($x === null) {
            $x = $pdf->GetX();
        }
        if ($y === null) {
            $y = $pdf->GetY();
        }

        // Ajouter l'image au PDF
        $pdf->Image($png_path, $x, $y, $w, $h);

        return $h; // Retourne la hauteur pour ajuster le positionnement
    }

    /**
     * Convertit un fichier SVG en PNG.
     *
     * @param string $svg_path Chemin vers le fichier SVG.
     * @param string $png_path Chemin vers le fichier PNG de sortie.
     * @return bool Succès ou échec.
     */
    protected static function convert_svg_to_png($svg_path, $png_path)
    {
        // Méthode 1 : Utiliser Imagick (recommandé)
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setBackgroundColor(new ImagickPixel('white')); // Fond blanc
                $imagick->readImage($svg_path);
                $imagick->setImageFormat('png');
                $imagick->writeImage($png_path);
                $imagick->clear();
                $imagick->destroy();
                return true;
            } catch (Exception $e) {
                error_log("Erreur Imagick : " . $e->getMessage());
                return false;
            }
        }
        // Méthode 2 : Alternative avec GD Library (moins fiable pour SVG)
        elseif (function_exists('imagecreatefromstring')) {
            try {
                $svg_content = file_get_contents($svg_path);
                if ($svg_content === false) {
                    error_log("Impossible de lire le fichier SVG : " . $svg_path);
                    return false;
                }

                // GD ne gère pas directement le SVG, donc on utilise une alternative
                // (Cette méthode est moins fiable, préférez Imagick)
                error_log("GD Library ne peut pas convertir directement le SVG. Utilisez Imagick.");
                return false;
            } catch (Exception $e) {
                error_log("Erreur GD Library : " . $e->getMessage());
                return false;
            }
        }
        // Méthode 3 : Utiliser un service externe (ex: API de conversion)
        else {
            error_log("Aucune méthode de conversion SVG → PNG disponible. Installez Imagick.");
            return false;
        }
    }

    protected static function add_table($pdf, $headers, $rows, $article, $project, $tank_datas, $widths = [])
    {
        if (!is_array($headers) || !is_array($rows)) {
            return;
        }

        $pdf->SetFont('Arial', 'B', self::$font_size_header);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0, 0, 0);

        if (empty($widths)) {
            $widths = array_fill(0, count($headers), (self::get_page_width($pdf) - 20) / count($headers));
        }

        // En-têtes : Traduire + convertir en ISO-8859-1
        foreach ($headers as $i => $header) {
            $translated_header = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', __($header, 'creation-reservoir'));
            $pdf->Cell($widths[$i], 10, $translated_header, 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Arial', '', self::$font_size_body);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                // Les cellules sont déjà converties en ISO-8859-1 dans format_piquages_table()
                $cell = self::replace_placeholders($cell, $article, $project, $tank_datas);
                $pdf->Cell($widths[$i], 10, $cell, 1);
            }
            $pdf->Ln();
        }
        $pdf->Ln(5);
    }

    /**
     * Récupère la largeur de la page (A5 = 148 mm)
     */
    protected static function get_page_width($pdf) {
        return $pdf->w; // Largeur de la page en mm
    }

    protected static function replace_placeholders($content, $article, $project, $tank_datas)
    {
        if (is_array($content)) {
            return array_map(function($item) use ($article, $project, $tank_datas) {
                return self::replace_placeholders($item, $article, $project, $tank_datas);
            }, $content);
        }

        $tank_designer = new ISPAG_Tank_Designer();
        $insulation = new ISPAG_Tank_Insulation();

        // Ajoutez le placeholder pour l'URL du SVG
        $svg_url = apply_filters('ispag_get_tank_svg_url', null, $article->Id ?? 0);

        $placeholders = [
            '{{article.Article}}' => $article->Article ?? ($article->ID ?? 'N/A'),
            '{{project.ObjetCommande}}' => $project->ObjetCommande ?? 'N/A',
            '{{tank_datas.conception.TankType}}' => $tank_designer->get_tank_text_data($tank_datas['conception']->TankType ?? '') ?? 'N/A',
            '{{tank_datas.conception.material_text}}' => $tank_datas['conception']->material_text ?? 'N/A',
            '{{tank_datas.conception.Finition}}' => $tank_datas['conception']->Finition ?? 'N/A',
            '{{tank_datas.dimensions.Volume}}' => $tank_datas['dimensions']->Volume ?? 'N/A',
            '{{tank_datas.dimensions.Diameter}}' => $tank_datas['dimensions']->Diameter ?? 'N/A',
            '{{tank_datas.dimensions.Height}}' => $tank_datas['dimensions']->Height ?? 'N/A',
            '{{tank_datas.dimensions.MaxPressure}}' => $tank_datas['dimensions']->MaxPressure ?? 'N/A',
            '{{tank_datas.dimensions.TestPressure}}' => $tank_datas['dimensions']->TestPressure ?? 'N/A',
            '{{tank_datas.dimensions.usingTemperature}}' => $tank_datas['dimensions']->usingTemperature ?? 'N/A',
            '{{tank_datas.insulation.insulationCover}}' => $insulation->get_conception_value($tank_datas['insulation']->insulationCover ?? '') ?? 'N/A',
            '{{tank_svg_url}}' => $svg_url ?? '', // Ajoutez cette ligne pour le placeholder SVG
            '{{year}}' => date('Y')
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $content);
    }

    protected static function format_piquages_table($piquages)
    {
        $rows = [];
        foreach ($piquages as $piquage) {
            if (!is_object($piquage)) {
                continue;
            }

            // Convertir TOUTES les valeurs en ISO-8859-1 avec TRANSLIT
            $rows[] = [
                
                iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', __($piquage->Type ?? '', 'creation-reservoir')),
                iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', __($piquage->Accessories ?? '', 'creation-reservoir')),
                iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) ($piquage->Pouces ?? '')),
                iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) ($piquage->Height ?? ''))
                
            ];
        }
        return $rows;
    }

    protected static function format_weldings_table($weldings)
    {
        $rows = [];
        foreach ($weldings as $welding) {
            if (!is_object($welding)) {
                continue;
            }
            $rows[] = [
                (string) ($welding->Type ?? ''),
                (string) ($welding->Pouces ?? ''),
                (string) ($welding->Height ?? '')
            ];
        }
        return $rows;
    }
}

ISPAG_Notice_PDF_Generator::init();