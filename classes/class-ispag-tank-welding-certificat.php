<?php
/**
 * Class ISPAG_Tank_Welding_Certificat
 *
 * Certificat de soudure PDF d'un réservoir ISPAG (A4 portrait), sur le modèle de la fiche technique.
 *
 * Mise en page :
 *   1. bandeau : logo, titre du document, date
 *   2. titre du réservoir, sous-titre (projet · groupe · quantité)
 *   3. quatre indicateurs clés (volume, diamètre, hauteur, pression de service)
 *   4. deux colonnes : dimensions et résultats du contrôle | dessin du réservoir
 *   5. conclusion, date du contrôle et signature du contrôleur
 *   6. pied de page : coordonnées de la société, pagination
 */
require_once __DIR__ . '/class-ispag-tank-pdf-generator.php';

class ISPAG_Tank_Welding_Certificat extends ISPAG_Tank_TechSheet_Generator
{
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
        $this->AliasNbPages('{nb}');
        $this->SetTitle($this->cleanStr($this->title . ' - ' . ($article->Article ?? '')), true);
        $this->SetAutoPageBreak(false);
        $this->AddPage();

        // Le sous-titre (projet | groupe) indique aussi le nombre de réservoirs
        $heading = clone $article;
        if (!empty($article->Qty)) {
            $heading->Groupe = trim(($article->Groupe ?? '') . '   |   ' . __('Number of tanks', 'creation-reservoir') . ' : ' . $article->Qty, ' |');
        }

        $this->drawBand($project, $article);
        $y = $this->drawTitle($project, $heading);
        $y = $this->drawKpis($tank_datas, $y);

        $right_end = $this->drawDrawingColumn($svg_path, $y);
        $left_end  = $this->drawControlColumn($tank_datas, $weld_control, $y);

        $this->drawSignature($article, max($left_end, $right_end));
    }

    // ------------------------------------------------------------------ Bandeau et titre

    protected function docTitle()
    {
        return __('Welding certificat', 'creation-reservoir');
    }

    // ------------------------------------------------------------------ Colonne de gauche

    protected function drawControlColumn($tank_datas, $weld_control, $y)
    {
        $x = self::MARGIN;
        $w = self::LEFT_W;
        $d = $tank_datas['dimensions'] ?? null;
        $c = $tank_datas['conception'] ?? null;

        // Dimensions
        $y = $this->sectionTitle($x, $y, $w, __('Dimensions', 'creation-reservoir'));
        $y = $this->keyValueRows($x, $y, $w, [
            __('Diameter', 'creation-reservoir')       => !empty($d->Diameter) ? $d->Diameter . ' mm' : null,
            __('Volume', 'creation-reservoir')         => !empty($d->Volume) ? $d->Volume . ' L' : null,
            __('Height', 'creation-reservoir')         => !empty($d->Height) ? $d->Height . ' mm' : null,
            __('Tipping height', 'creation-reservoir') => !empty($d->TippingHeight) ? $d->TippingHeight . ' mm' : null,
            __('Materials', 'creation-reservoir')      => !empty($c->material_text) ? __($c->material_text, 'creation-reservoir') : null,
        ]);

        // Résultats du contrôle
        $y = $this->sectionTitle($x, $y + 3, $w, __('Inspection Results', 'creation-reservoir'));
        $y = $this->keyValueRows($x, $y, $w, $weld_control);

        return $y;
    }

    // ------------------------------------------------------------------ Colonne de droite

    protected function drawDrawingColumn($svg_path, $y)
    {
        $x = $this->right_x;
        $w = $this->right_w;

        $y = $this->sectionTitle($x, $y, $w, __('Drawing', 'creation-reservoir'));
        $box_h = 112;
        $this->roundedBox($x, $y, $w, $box_h, self::WHITE, self::LINE);
        $this->placeDrawing($svg_path, $x + 3, $y + 3, $w - 6, $box_h - 6);
        return $y + $box_h + 6;
    }

    // ------------------------------------------------------------------ Date et signature

    protected function drawSignature($article, $y)
    {
        $y = $this->ensure($y + 6, 46);
        $control_date = date('d.m.Y', strtotime($article->date_livraison ?? 'now'));

        $this->roundedBox(self::MARGIN, $y, 186, 40, self::PANEL);

        // Date du contrôle
        $this->SetXY(self::MARGIN + 6, $y + 6);
        $this->SetFont('Arial', '', 7.5);
        $this->color('text', self::MUTED);
        $this->Cell(80, 4, $this->t(mb_strtoupper(__('Control Date', 'creation-reservoir'))), 0, 2, 'L');
        $this->SetFont('Arial', 'B', 12);
        $this->color('text', self::INK);
        $this->Cell(80, 8, $this->t($control_date), 0, 0, 'L');

        // Contrôleur et signature
        $sx = self::MARGIN + 186 - 76;
        $this->SetXY($sx, $y + 6);
        $this->SetFont('Arial', '', 7.5);
        $this->color('text', self::MUTED);
        $this->Cell(70, 4, $this->t(mb_strtoupper(__('Controller', 'creation-reservoir'))), 0, 2, 'C');
        $this->SetFont('Arial', 'B', 10);
        $this->color('text', self::INK);
        $this->Cell(70, 6, $this->t('Cyril Barthel'), 0, 0, 'C');

        $signature_url = 'https://app.ispag-asp.ch/wp-content/uploads/2024/05/Signature_Cyril-Barthel.jpg';
        try {
            $this->Image($signature_url, $sx + 10, $y + 17, 50, 0);
        } catch (Exception $e) {
            // signature indisponible : le cadre reste vierge pour une signature manuscrite
        }
        return $y + 40;
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
                    </button>';
        }
        return $html;
    }

}