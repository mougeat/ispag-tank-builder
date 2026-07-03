<?php
defined('ABSPATH') || exit;

require_once(WP_PLUGIN_DIR . '/ispag-project-manager/libs/fpdf/fpdf.php');

class ISPAG_Tank_PDF_Exporter extends FPDF {
    // Propriétés de la page A4 paysage (297x210mm)
    protected $page_format = 'A4';
    protected $page_orientation = 'L';
    protected $page_unit = 'mm';
    protected $page_width;
    protected $page_height;

    // Propriétés du cartouche (en bas à droite)
    protected $cartouche_height = 56;
    protected $cartouche_width = 150;
    protected $margin = 10;

    // Propriétés des vues
    protected $front_view_width = 90;   // Largeur vue de face
    protected $front_view_height = 140; // Hauteur vue de face
    protected $top_view_width = 70;     // Largeur vue de dessus (réduite)
    protected $top_view_height = 70;    // Hauteur vue de dessus (réduite)
    protected $views_spacing = 20;      // Espacement entre les vues

    // Positions calculées dynamiquement
    protected $front_view_x = 20;       // Position X vue de face (20mm de marge)
    protected $front_view_y;           // Position Y vue de face (centrée verticalement)
    protected $top_view_x;             // Position X vue de dessus
    protected $top_view_y;             // Position Y vue de dessus

    // Propriétés du tableau des piquages
    protected $table_width = 60;       // Largeur réduite du tableau
    protected $table_x;                // Position X du tableau (collé à droite)
    protected $table_y = 20;           // Position Y du tableau

    // Propriétés de la base de données
    protected $wpdb;
    protected $table_dimension;
    protected $table_conception;
    protected $table_connections;

    // Données techniques
    protected $fittings = [];
    protected $weldings = [];
    protected $drilled_plate = [];

    protected static $instance;

    public function __construct($page_format = 'A4', $page_orientation = 'L', $page_unit = 'mm') {
        parent::__construct($page_orientation, $page_unit, $page_format);

        $this->page_format = $page_format;
        $this->page_orientation = $page_orientation;
        $this->page_unit = $page_unit;
        $this->calculate_page_dimensions();

        // Calculer les positions dynamiquement
        $this->front_view_y = ($this->page_height - $this->front_view_height - $this->cartouche_height - 2 * $this->margin) / 2 + $this->margin;
        $this->top_view_x = $this->front_view_x + $this->front_view_width + $this->views_spacing;
        $this->top_view_y = $this->front_view_y + ($this->front_view_height - $this->top_view_height) / 2;
        $this->table_x = $this->page_width - $this->table_width - $this->margin;

        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_dimension = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('wp_ajax_ispag_export_pdf', [self::$instance, 'ispag_export_pdf']);
        add_action('wp_ajax_nopriv_ispag_export_pdf', [self::$instance, 'ispag_export_pdf']);
    }

    public function ispag_export_pdf() {
        if (!function_exists('load_plugin_textdomain') || !is_textdomain_loaded('creation-reservoir')) {
            add_action('init', function() {
                load_plugin_textdomain(
                    'creation-reservoir',
                    false,
                    dirname(plugin_basename(__FILE__)) . '/languages/'
                );
            });
        }

        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        if (!$article_id) {
            wp_send_json_error(['message' => __('ID de l\'article manquant.', 'creation-reservoir')]);
            exit;
        }

        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        if (!$article) {
            wp_send_json_error(['message' => __('Article introuvable.', 'creation-reservoir')]);
            exit;
        }

        $article_title = $article->Article ?? 'unnamed_tank';
        $filename = $this->sanitize_filename($article_title) . ".pdf";

        $deal_id = apply_filters('ispag_get_article_deal_id', null, $article_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);

        $this->loadFittings($article_id);
        $this->loadWeldings($article_id);
        $this->loadPlate($article_id);

        $front_view_png = $this->generate_and_convert_svg($article_id, 'front');
        $top_view_png = $this->generate_and_convert_svg($article_id, 'top');

        $this->generate_pdf($article, $project, $tank_datas, $front_view_png, $top_view_png);

        foreach ([$front_view_png, $top_view_png] as $png_path) {
            if ($png_path && file_exists($png_path)) {
                unlink($png_path);
            }
            $svg_path = str_replace('.png', '.svg', $png_path);
            if (file_exists($svg_path)) {
                unlink($svg_path);
            }
        }

        $this->Output('I', $filename);
        exit;
    }

    protected function generate_and_convert_svg($article_id, $view_type = 'front') {
        if ($view_type === 'front') {
            $svg_generator = new ISPAG_Tank_SVG_Generator();
            $svg_content = $svg_generator->design_tank_svg(null, $article_id, false);
        } else {
            $svg_generator = new ISPAG_Tank_SVG_Top_View_Generator(
                $this->get_tank_diameter($article_id),
                160,
                $this->fittings,
                $this->get_tank_height($article_id)
            );
            $svg_content = $svg_generator->render_svg();
        }

        $temp_dir = plugin_dir_path(__FILE__) . 'temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $svg_path = $temp_dir . 'temp_svg_' . $article_id . '_' . $view_type . '.svg';
        $png_path = $temp_dir . 'temp_png_' . $article_id . '_' . $view_type . '.png';

        file_put_contents($svg_path, $svg_content);

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setBackgroundColor(new ImagickPixel('transparent'));
                $imagick->readImage($svg_path);
                $imagick->setImageDepth(8);
                $imagick->setImageFormat('png');
                $imagick->writeImage($png_path);
                $imagick->clear();
                $imagick->destroy();
                return $png_path;
            } catch (Exception $e) {
                error_log("Erreur Imagick: " . $e->getMessage());
            }
        }

        $cmd = "rsvg-convert -w 800 -h 800 -d 96 -p 96 -f png -o " . escapeshellarg($png_path) . " " . escapeshellarg($svg_path);
        exec($cmd, $output, $return_var);
        if ($return_var !== 0) {
            error_log("Erreur rsvg-convert: " . implode("\n", $output));
            return false;
        }

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick($png_path);
                if ($imagick->getImageDepth() > 8) {
                    $imagick->setImageDepth(8);
                    $imagick->writeImage($png_path);
                }
                $imagick->clear();
                $imagick->destroy();
            } catch (Exception $e) {
                error_log("Erreur de correction: " . $e->getMessage());
            }
        }

        return $png_path;
    }

    protected function get_tank_diameter($article_id) {
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        return $tank_datas['dimensions']->Diameter ?? 1000;
    }

    protected function get_tank_height($article_id) {
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        return $tank_datas['dimensions']->Height ?? 2000;
    }

    public function generate_pdf($article, $project, $tank_datas, $front_view_png = false, $top_view_png = false) {
        $this->AddPage($this->page_orientation);
        $this->draw_page_frame();

        // Vue de face à gauche
        if ($front_view_png && file_exists($front_view_png)) {
            $this->render_view($front_view_png, $this->front_view_x, $this->front_view_y,
                             $this->front_view_width, $this->front_view_height);
        }

        // Vue de dessus au centre
        if ($top_view_png && file_exists($top_view_png)) {
            $this->render_view($top_view_png, $this->top_view_x, $this->top_view_y,
                             $this->top_view_width, $this->top_view_height);
        }

        // Tableau des piquages à droite
        $this->render_fittings_table($tank_datas, $article, $project);

        // Cartouche en bas à droite
        $this->render_cartouche_pdf([
            'article' => $article,
            'project' => $project,
            'tank_datas' => $tank_datas,
            'appr' => 'Wihan',
            'drawn_by' => get_the_author_meta('display_name', $article->created_by_id) ?? 'Cyril Barthel',
        ]);
    }

    protected function render_view($png_path, $x, $y, $max_width, $max_height) {
        list($img_width, $img_height) = getimagesize($png_path);

        $ratio = $max_width / $img_width;
        $new_height = $img_height * $ratio;

        if ($new_height > $max_height) {
            $ratio = $max_height / $img_height;
            $new_width = $img_width * $ratio;
        } else {
            $new_width = $max_width;
        }

        $this->Image($png_path, $x, $y, $new_width, $new_height);
    }

    protected function draw_page_frame() {
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->Rect(
            $this->margin,
            $this->margin,
            $this->page_width - 2 * $this->margin,
            $this->page_height - 2 * $this->margin
        );
    }

    protected function render_fittings_table($tank_datas, $article, $project) {
        $table_x = $this->table_x;
        $table_y = $this->table_y;

        // Titre
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->SetXY($table_x, $table_y);
        $this->Cell(0, 6, iconv('UTF-8', 'ISO-8859-1', __('Fittings List', 'creation-reservoir')), 0, 1, 'L');

        // Largeurs des colonnes (réduites pour 60mm de large)
        $col_widths = [
            'id' => 12,
            'description' => 30,
            'diameter' => 10,
            'height' => 8,
        ];

        // En-tête
        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(230, 230, 230);

        $this->SetXY($table_x, $table_y + 8);
        $this->Cell($col_widths['id'], 5, iconv('UTF-8', 'ISO-8859-1', __('ID', 'creation-reservoir')), 1, 0, 'C', true);
        $this->Cell($col_widths['description'], 5, iconv('UTF-8', 'ISO-8859-1', __('Desc', 'creation-reservoir')), 1, 0, 'C', true);
        $this->Cell($col_widths['diameter'], 5, iconv('UTF-8', 'ISO-8859-1', __('Diam', 'creation-reservoir')), 1, 0, 'C', true);
        $this->Cell($col_widths['height'], 5, iconv('UTF-8', 'ISO-8859-1', __('H', 'creation-reservoir')), 1, 1, 'C', true);

        // Lignes
        $this->SetFont('Arial', '', 6);
        $this->SetFillColor(255, 255, 255);

        $y_position = $table_y + 13;
        $row_height = 4;

        error_log('LISTE DES FITTINGS DANS PDF : ' . print_r($this->fittings, true));

        $nozzleData = [];
        if (!empty($this->fittings)) {
            foreach ($this->fittings as $index => $fitting) {
                $nozzleData[] = [
                    'id' => ($index + 1) . '',
                    'desc' => $this->get_fitting_description($fitting),
                    'diameter' => (isset($fitting->Diametre_Nominal) ? $fitting->Diametre_Nominal : 'N/A'),
                    'height' => (isset($fitting->Elevation_mm) ? $fitting->Elevation_mm : 'N/A') . 'mm',
                ];
            }
        }

        if (empty($nozzleData)) {
            $this->SetXY($table_x, $y_position);
            $this->Cell($this->table_width, $row_height, iconv('UTF-8', 'ISO-8859-1', __('No fittings', 'creation-reservoir')), 1, 1, 'C');
        } else {
            foreach ($nozzleData as $item) {
                $this->SetXY($table_x, $y_position);

                $this->Cell($col_widths['id'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['id']), 1, 0, 'C');
                $desc = strlen($item['desc']) > 18 ? substr($item['desc'], 0, 15) . '...' : $item['desc'];
                $this->Cell($col_widths['description'], $row_height, iconv('UTF-8', 'ISO-8859-1', $desc), 1, 0, 'L');
                $this->Cell($col_widths['diameter'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['diameter']), 1, 0, 'C');
                $this->Cell($col_widths['height'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['height']), 1, 1, 'C');

                $y_position += $row_height;

                if ($y_position > $this->page_height - $this->cartouche_height - 15) {
                    break;
                }
            }
        }
    }

    protected function get_fitting_description($fitting) {
        if (!$fitting) return 'N/A';
        $parts = [];
        if (isset($fitting->Diametre_Nominal) && !empty($fitting->Diametre_Nominal)) $parts[] = $fitting->Diametre_Nominal;
        if (isset($fitting->Accessories_label) && !empty($fitting->Accessories_label)) $parts[] = $fitting->Accessories_label;
        if (isset($fitting->Type_raccord_label) && !empty($fitting->Type_raccord_label)) $parts[] = $fitting->Type_raccord_label;
        else {
            $type_label = $this->get_fitting_type_label($fitting->Type_raccord ?? 0);
            if ($type_label !== 'Unknown') $parts[] = $type_label;
        }
        if (isset($fitting->Bride_Int_mm) && !empty($fitting->Bride_Int_mm)) $parts[] = 'Ø' . $fitting->Bride_Int_mm . 'mm';
        return implode(' - ', $parts);
    }

    protected function get_fitting_type_label($type) {
        $types = [
            12 => __('Bend Pipe', 'creation-reservoir'),
            24 => __('Flange', 'creation-reservoir'),
        ];
        return $types[$type] ?? __('Unknown', 'creation-reservoir');
    }

    protected function loadFittings($article_id) {
        if (class_exists('ISPAG_Tank_Fittings')) {
            $this->fittings = (new ISPAG_Tank_Fittings())->get_all_fittings($article_id);
        }
    }

    protected function loadWeldings($article_id) {
        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM {$this->table_connections} c LEFT JOIN {$this->table_dimension} d ON c.TankId = d.Id WHERE d.customerTankId = %d AND c.Type = 23",
            $article_id
        );
        $this->weldings = $this->wpdb->get_results($sql);
    }

    protected function loadPlate($article_id) {
        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM {$this->table_connections} c LEFT JOIN {$this->table_dimension} d ON c.TankId = d.Id WHERE d.customerTankId = %d AND c.Type = 22",
            $article_id
        );
        $this->drilled_plate = $this->wpdb->get_results($sql);
    }

    protected function render_cartouche_pdf($content = []) {
        $x = $this->page_width - $this->cartouche_width - $this->margin;
        $y = $this->page_height - $this->cartouche_height - $this->margin;

        $this->SetFillColor(240, 240, 240);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->Rect($x, $y, $this->cartouche_width, $this->cartouche_height, 'DF');

        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);

        $this->SetXY($x + 5, $y + 5);
        $this->Cell($this->cartouche_width - 10, 5, iconv('UTF-8', 'ISO-8859-1', __('Designation', 'creation-reservoir') . " : " . ($content['article']->Article ?? 'N/A')), 0, 1);

        $this->SetX($x + 5);
        $this->Cell($this->cartouche_width - 10, 5, iconv('UTF-8', 'ISO-8859-1', __('Article No.', 'creation-reservoir') . " : " . ($content['article']->Id ?? 'N/A')), 0, 1);

        $this->SetX($x + 5);
        $this->Cell($this->cartouche_width - 10, 5, iconv('UTF-8', 'ISO-8859-1', __('Order No.', 'creation-reservoir') . " : " . ($content['project']->NumCommande ?? 'N/A')), 0, 1);

        $this->SetX($x + 5);
        $this->Cell($this->cartouche_width - 10, 5, iconv('UTF-8', 'ISO-8859-1', __('Project', 'creation-reservoir') . " : " . ($content['project']->ObjetCommande ?? 'N/A')), 0, 1);

        $this->SetDrawColor(200, 200, 200);
        $this->Line($x, $y + 25, $x + $this->cartouche_width, $y + 25);

        $this->SetFont('Arial', '', 8);
        $left_x = $x + 5;
        $right_x = $x + $this->cartouche_width - 55;

        $material = isset($content['tank_datas']['conception']->material_text) ?
            __($content['tank_datas']['conception']->material_text, 'creation-reservoir') : __('Stainless Steel V4a (AISI316L)', 'creation-reservoir');
        $this->SetXY($left_x, $y + 30);
        $this->Cell(120, 5, iconv('UTF-8', 'ISO-8859-1', __('Materials', 'creation-reservoir') . " : " . $material), 0, 1);

        $volume = isset($content['tank_datas']['dimensions']->Volume) ?
            $content['tank_datas']['dimensions']->Volume . ' ' . __('L', 'creation-reservoir') : 'N/A';
        $this->SetX($left_x);
        $this->Cell(120, 5, iconv('UTF-8', 'ISO-8859-1', __('Volume', 'creation-reservoir') . " : " . $volume), 0, 1);

        $pressure = isset($content['tank_datas']['dimensions']->MaxPressure) ?
            number_format($content['tank_datas']['dimensions']->MaxPressure, 2) . ' ' . __('bar', 'creation-reservoir') : 'N/A';
        $this->SetX($left_x);
        $this->Cell(120, 5, iconv('UTF-8', 'ISO-8859-1', __('Service Pressure', 'creation-reservoir') . " : " . $pressure), 0, 1);

        $this->SetXY($right_x, $y + 30);
        $this->Cell(55, 5, iconv('UTF-8', 'ISO-8859-1', __('Approved by', 'creation-reservoir') . " : " . ($content['appr'] ?? 'Wihan')), 0, 1);

        $this->SetX($right_x);
        $this->Cell(55, 5, iconv('UTF-8', 'ISO-8859-1', __('Drawn by', 'creation-reservoir') . " : " . ($content['drawn_by'] ?? 'Cyril Barthel')), 0, 1);

        $this->SetX($right_x);
        $this->Cell(55, 5, iconv('UTF-8', 'ISO-8859-1', __('Date', 'creation-reservoir') . " : " . date('d.m.Y')), 0, 1);
    }

    protected function calculate_page_dimensions() {
        $sizes = [
            'A0' => ['width' => 841, 'height' => 1189],
            'A1' => ['width' => 594, 'height' => 841],
            'A2' => ['width' => 420, 'height' => 594],
            'A3' => ['width' => 297, 'height' => 420],
            'A4' => ['width' => 210, 'height' => 297],
        ];

        if (isset($sizes[$this->page_format])) {
            $w = $sizes[$this->page_format]['width'];
            $h = $sizes[$this->page_format]['height'];
            $this->page_width = ($this->page_orientation === 'L') ? $h : $w;
            $this->page_height = ($this->page_orientation === 'L') ? $w : $h;
        } else {
            $this->page_width = ($this->page_orientation === 'L') ? 297 : 210;
            $this->page_height = ($this->page_orientation === 'L') ? 210 : 297;
        }
    }

    protected function sanitize_filename($title) {
        $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
        $title = strtolower($title);
        return trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-');
    }
}