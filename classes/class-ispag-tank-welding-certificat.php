<?php
/**
 * Class ISPAG_Tank_Welding_Certificat
 * Génère un certificat de soudure PDF pour les réservoirs ISPAG.
 */
class ISPAG_Tank_Welding_Certificat extends ISPAG_PDF_Generator
{
    protected $y_image_bottom;
    private $wpdb;
    private $table_flange_dimension;
    private $table_conception;
    private $table_article;
    private $table_project_article;
    private $table_connections;
    protected static $instance = null;

    /**
     * Constructeur : Initialise la classe et FPDF.
     */
    public function __construct()
    {
        global $wpdb;
        parent::__construct(); // ⭐ Initialise FPDF
        $this->wpdb = $wpdb;
        $this->table_article = $wpdb->prefix . 'achats_articles';
        $this->table_project_article = $wpdb->prefix . 'achats_details_commande';
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';

        // Charge le domaine de traduction
        // load_plugin_textdomain(
        //     'creation-reservoir',
        //     false,
        //     dirname(plugin_basename(__FILE__)) . '/languages/'
        // );
    }

    /**
     * Initialise les hooks WordPress.
     */
    public static function init()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('wp_ajax_ispag_generate_welding_certificat_pdf', [self::$instance, 'ispag_ajax_generate_welding_certificat']);
        add_filter('ispag_get_welding_certificat_btn', [self::$instance, 'get_welding_certificat_btn'], 10, 3);
    }

    /**
     * Méthode AJAX pour générer le certificat de soudure.
     */
    public function ispag_ajax_generate_welding_certificat()
    {
        // Charge le domaine de traduction (au cas où)
        // load_plugin_textdomain(
        //     'creation-reservoir',
        //     false,
        //     dirname(plugin_basename(__FILE__)) . '/languages/'
        // );

        // Récupère les paramètres
        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);

        if (!$article_id || !$deal_id) {
            wp_send_json_error(['message' => __('Missing article ID or deal ID.', 'creation-reservoir')]);
        }

        // Récupère les données
        $tank_id = apply_filters('ispag_get_related_tank', null, $article_id, $deal_id);
        if (!$tank_id) {
            wp_send_json_error(['message' => __('No tank found for this article.', 'creation-reservoir')]);
        }

        $article = apply_filters('ispag_get_article_by_id', null, $tank_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $svg_path = apply_filters('ispag_get_tank_svg', null, $tank_id, false);
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $tank_id);

        if (!$article || !$project || !$svg_path || !$tank_datas) {
            wp_send_json_error(['message' => __('Missing required data.', 'creation-reservoir')]);
        }

        // Détermine le type de baguette
        $baguette = '';
        if (isset($tank_datas['conception']->Material)) {
            if ($tank_datas['conception']->Material == 1 || $tank_datas['conception']->Material == 3) {
                $baguette = 'SMT-316LSi';
            } elseif ($tank_datas['conception']->Material == 2) {
                $baguette = 'Baguette ST-50';
            }
        }

        $weld_control = [
            __('Base Material', 'creation-reservoir') => $baguette,
            __('Welding Process', 'creation-reservoir') => __('TIG', 'creation-reservoir'),
            __('Controlled Area', 'creation-reservoir') => __('tank welding', 'creation-reservoir'),
            __('Anomalies Detected', 'creation-reservoir') => __('none', 'creation-reservoir'),
            __('Conclusion', 'creation-reservoir') => __('conform', 'creation-reservoir'),
        ];

        // Génère le PDF
        $title = __('Welding certificat', 'creation-reservoir') . ' - ' . ($article->Article ?? '');
        $file_name = sanitize_title($title) . '.pdf';

        // Utilise l'instance existante
        self::$instance->generate_weld_certificat($project, $svg_path, $article, $tank_datas, $weld_control);
        self::$instance->Output('I', $file_name);
        exit;
    }

    /**
     * Génère le certificat de soudure.
     */
    public function generate_weld_certificat($project, $svg_path, $article, $tank_datas, $weld_control)
    {
        $this->title = __('Welding certificat', 'creation-reservoir');
        $this->AddPage();
        $this->SetTitle(mb_convert_encoding($this->title, 'ISO-8859-1', 'UTF-8'));
        $this->SetAutoPageBreak(true, 20);
        $this->addHeader();

        // Ajoute l'en-tête et le contenu
        $this->addModernHeader($project, $article);
        $this->addArticleTitle($article);
        $this->addLayoutBlocks($article, $svg_path, $tank_datas, $weld_control);
    }

    // === Méthodes existantes (conservées) ===
    protected function addModernHeader($project, $article) {
        $this->SetTextColor(65, 76, 82);
        $this->SetFont('Arial', '', 8);
        $this->SetDrawColor(222, 226, 230);
        $this->SetLineWidth(0.5);

        $width = $this->GetPageWidth() / 2;
        $this->SetXY($width - 10, 20);
        $this->Cell($width, 5, mb_convert_encoding($project->ObjetCommande ?? '', 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, mb_convert_encoding($article->Groupe ?? '', 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, mb_convert_encoding(__('Number of tanks', 'creation-reservoir') . ' : ' . ($article->Qty ?? ''), 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, date('d.m.Y'), 0, 1, 'R');
        $this->Ln(5);

        $x1 = 10;
        $y1 = $this->GetY();
        $x2 = $this->GetPageWidth() - $x1;
        $this->Line($x1, $y1, $x2, $y1);
        $this->Ln(5);
    }

    protected function addArticleTitle($article) {
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(0);
        $this->Cell(0, 12, mb_convert_encoding($article->Article ?? '', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);
    }

    protected function addLayoutBlocks($article, $svg_path, $tank_datas, $weld_control) {
        $startY = $this->GetY();

        // Bloc gauche
        $this->SetXY(10, $startY);
        $this->addBlocDimensions($tank_datas);
        $this->addBlocSoudure($weld_control);

        // Bloc droit (image)
        $this->SetXY(130, $startY);
        $this->addCuveImage($svg_path);

        // Bas de page
        $this->add_certificat_bottom($article);
    }

    protected function addBlocDimensions($tank_datas) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Dimensions', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);

        $this->SetFont('Arial', '', 8);
        $dims = [
            __('Diameter', 'creation-reservoir') => isset($tank_datas['dimensions']->Diameter) ? $tank_datas['dimensions']->Diameter . ' mm' : '-',
            __('Volume', 'creation-reservoir') => isset($tank_datas['dimensions']->Volume) ? $tank_datas['dimensions']->Volume . ' L' : '-',
            __('Height', 'creation-reservoir') => isset($tank_datas['dimensions']->Height) ? $tank_datas['dimensions']->Height . ' mm' : '-',
            __('Tipping height', 'creation-reservoir') => isset($tank_datas['dimensions']->TippingHeight) ? $tank_datas['dimensions']->TippingHeight . ' mm' : '-',
            __('Materials', 'creation-reservoir') => isset($tank_datas['conception']->material_text) ? __($tank_datas['conception']->material_text, 'creation-reservoir') : '-',
        ];

        foreach ($dims as $label => $value) {
            $this->Cell(60, 5, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);
            $this->Cell(0, 5, mb_convert_encoding(': ' . $value, 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->Ln(3);
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }

    protected function addCuveImage($svgUrl) {
        $svgPath = $this->get_local_path_from_url($svgUrl);
        if (!file_exists($svgPath)) {
            $this->Cell(0, 10, mb_convert_encoding(__('Error: SVG file not found.', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
            $this->y_image_bottom = $this->GetY();
            return;
        }

        $pngPath = str_replace('.svg', '.png', $svgPath);
        if (file_exists($pngPath)) {
            unlink($pngPath);
        }

        try {
            $this->convert_svg_to_png($svgPath, $pngPath);
            if (file_exists($pngPath)) {
                $this->Image($pngPath, 130, $this->GetY(), 0, 80);
                $this->Ln(65);
            } else {
                $this->Cell(0, 10, mb_convert_encoding(__('Failed to convert SVG to PNG.', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
            }
        } catch (ImagickException $e) {
            $this->Cell(0, 10, mb_convert_encoding(__('Error converting SVG: ', 'creation-reservoir') . $e->getMessage(), 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->y_image_bottom = $this->GetY();
    }

    protected function get_local_path_from_url($url) {
        $site_url = site_url();
        $server_path = ABSPATH;
        return str_replace($site_url, $server_path, $url);
    }

    protected function convert_svg_to_png($svgPath, $pngPath) {
        if (!class_exists('Imagick')) {
            throw new Exception(__('Imagick extension is not installed.', 'creation-reservoir'));
        }

        $imagick = new Imagick();
        $imagick->setBackgroundColor(new ImagickPixel('white'));
        $imagick->readImage($svgPath);
        $imagick->resizeImage(1200, 2400, Imagick::FILTER_LANCZOS, 1);
        $imagick->quantizeImage(256, Imagick::COLORSPACE_RGB, 0, false, false);
        $imagick->setImageDepth(8);
        $imagick->setImageFormat('png');
        $imagick->writeImage($pngPath);
        $imagick->clear();
        $imagick->destroy();
    }

    protected function addBlocSoudure($weld_control) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Inspection Results', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->SetFont('Arial', '', 8);

        foreach ($weld_control as $label => $value) {
            $this->Cell(60, 5, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);
            $this->Cell(0, 5, mb_convert_encoding(': ' . $value, 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->Ln(3);
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }

    protected function add_certificat_bottom($article) {
        $y = max($this->y_image_bottom, $this->GetY());
        $this->SetY($y + 10);
        $this->SetX(10);

        $this->SetTextColor(65, 76, 82);
        $this->SetFont('Arial', '', 8);

        $control_date = date('d.m.Y', strtotime($article->date_livraison ?? 'now'));
        $width_col = $this->GetPageWidth() / 3;

        $this->Cell($width_col, 5, mb_convert_encoding(__('Control Date', 'creation-reservoir') . ': ' . $control_date, 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
        $this->Ln(5);

        $x_sig = $this->GetPageWidth() - 70;
        $y_start_sig = $this->GetY() - 5;
        $this->SetXY($x_sig, $y_start_sig);

        $this->SetFont('Arial', 'B', 9);
        $this->Cell(60, 5, mb_convert_encoding(__('Qualified Inspector', 'creation-reservoir') . ':', 'ISO-8859-1', 'UTF-8'), 0, 2, 'C');

        $this->SetFont('Arial', '', 9);
        $this->Cell(60, 5, mb_convert_encoding('Cyril Barthel', 'ISO-8859-1', 'UTF-8'), 0, 2, 'C');
        $this->Ln(2);

        $signature_url = 'https://app.ispag-asp.ch/wp-content/uploads/2024/05/Signature_Cyril-Barthel.jpg';
        $this->Image($signature_url, $x_sig + 5, $this->GetY(), 50, 0);
        $this->SetY($this->GetY() + 20);

        $this->SetDrawColor(222, 226, 230);
        $this->SetLineWidth('0.5');
        $x1 = 10;
        $y1 = $this->GetY();
        $x2 = $this->GetPageWidth() - $x1;
        $this->Line($x1, $y1, $x2, $y1);
    }

    // === Méthodes utilitaires (RoundedRect, _Arc) ===
    protected function RoundedRect($x, $y, $w, $h, $r = 2, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');
        $MyArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));

        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);

        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);

        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);

        $this->_out($op);
    }

    protected function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k));
    }

    // === Méthodes pour le bouton et le script ===
    public function get_welding_certificat_btn($html, $article, $deal_id) {
        if ($article->Type == 3 && $article->Livre) {
            return '<button id="welding-certificat-pdf"
                        class="ispag-btn ispag-btn-secondary-outlined"
                        style="margin-top: 1rem;"
                        data-article-id="' . intval($article->Id) . '"
                        data-deal-id="' . $deal_id . '">
                            <span class="dashicons dashicons-awards"></span>
                            ' . __('Welding certificat', 'creation-reservoir') . '
                    </button>' . self::getScript();
        }
        return $html;
    }

    private static function getScript() {
        return '<script>
                document.addEventListener(\'click\', function (event) {
                    if (event.target.matches(\'#welding-certificat-pdf\') || event.target.closest(\'#welding-certificat-pdf\')) {
                        const button = event.target.closest(\'#welding-certificat-pdf\');
                        const articleId = button.dataset.articleId;
                        const dealId = button.dataset.dealId;

                        if (articleId) {
                            const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                            url.searchParams.set(\'action\', \'ispag_generate_welding_certificat_pdf\');
                            if (dealId) {
                                url.searchParams.set(\'deal_id\', dealId);
                            }
                            url.searchParams.set(\'article_id\', articleId);

                            window.open(url.toString(), \'_blank\');
                        } else {
                            console.error(\'Article ID non trouvé sur le bouton.\');
                        }
                    }
                });
                </script>';
    }
}