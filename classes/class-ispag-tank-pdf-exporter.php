<?php
defined('ABSPATH') || exit;

require_once(WP_PLUGIN_DIR . '/ispag-project-manager/libs/fpdf/fpdf.php');

/**
 * Class ISPAG_Tank_PDF_Exporter
 * Génère des PDF pour les réservoirs ISPAG avec vues techniques et cartouche.
 * Logging : Toutes les actions sont loguées dans ispag_tank_pdf_exporter.log.
 */
class ISPAG_Tank_PDF_Exporter extends FPDF
{

    private const LOG_NAME = 'tank_pdf_exporter';

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
    protected $front_view_width = 90;
    protected $front_view_height = 140;
    protected $top_view_width = 70;
    protected $top_view_height = 70;
    protected $views_spacing = 20;

    // Positions calculées dynamiquement
    protected $front_view_x = 20;
    protected $front_view_y;
    protected $top_view_x;
    protected $top_view_y;

    // Propriétés du tableau des piquages
    protected $table_width = 60;
    protected $table_x;
    protected $table_y = 20;

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

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct($page_format = 'A4', $page_orientation = 'L', $page_unit = 'mm')
    {
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        parent::__construct($page_orientation, $page_unit, $page_format);

        $this->page_format = $page_format;
        $this->page_orientation = $page_orientation;
        $this->page_unit = $page_unit;
        $this->calculate_page_dimensions();

        $this->front_view_y = ($this->page_height - $this->front_view_height - $this->cartouche_height - 2 * $this->margin) / 2 + $this->margin;
        $this->top_view_x = $this->front_view_x + $this->front_view_width + $this->views_spacing;
        $this->top_view_y = $this->front_view_y + ($this->front_view_height - $this->top_view_height) / 2;
        $this->table_x = $this->page_width - $this->table_width - $this->margin - 20;

        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_dimension = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';

        // $this->logger->log_user_action(self::LOG_NAME, 'class_constructed', [], $user_id);
    }

    public static function init()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null)
        {
            self::$instance = new self();
            // $logger->log_user_action(self::LOG_NAME, 'instance_initialized', [], $user_id);
        }

        add_action('wp_ajax_ispag_export_pdf', [self::$instance, 'ispag_export_pdf']);
        add_action('wp_ajax_nopriv_ispag_export_pdf', [self::$instance, 'ispag_export_pdf']);
        // $logger->log_user_action(self::LOG_NAME, 'hooks_registered', [], $user_id);
    }

    /**
     * Export PDF du réservoir
     */
    public function ispag_export_pdf()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ispag_export_pdf_start', [], $user_id);

        // if (!function_exists('load_plugin_textdomain') || !is_textdomain_loaded('creation-reservoir'))
        // {
        //     add_action('init', function() use ($user_id)
        //     {
        //         load_plugin_textdomain(
        //             'creation-reservoir',
        //             false,
        //             dirname(plugin_basename(__FILE__)) . '/languages/'
        //         );
        //         $this->logger->log_user_action(self::LOG_NAME, 'textdomain_loaded', [], $user_id);
        //     });
        // }

        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        if (!$article_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing article_id', $user_id);
            wp_send_json_error(['message' => __('ID de l\'article manquant.', 'creation-reservoir')]);
            exit;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'article_id_received', ['article_id' => $article_id], $user_id);

        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        if (!$article)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Article not found', $user_id);
            wp_send_json_error(['message' => __('Article not found', 'creation-reservoir')]);
            exit;
        }

        $this->logger->log_db_change(self::LOG_NAME, 'articles', 'FETCH_ARTICLE', ['article_id' => $article_id], $user_id);

        $article_title = $article->Article ?? 'unnamed_tank';
        $filename = $this->sanitize_filename($article_title) . ".pdf";

        $deal_id = apply_filters('ispag_get_article_deal_id', null, $article_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);

        $this->logger->log_db_change(self::LOG_NAME, 'projects', 'FETCH_PROJECT', ['deal_id' => $deal_id], $user_id);
        $this->logger->log_db_change(self::LOG_NAME, 'tank_datas', 'FETCH_TANK_DATAS', ['article_id' => $article_id], $user_id);

        $this->loadFittings($article_id);
        $this->loadWeldings($article_id);
        $this->loadPlate($article_id);

        $this->logger->log_user_action(self::LOG_NAME, 'technical_data_loaded', ['fittings_count' => count($this->fittings), 'weldings_count' => count($this->weldings), 'plates_count' => count($this->drilled_plate)], $user_id);

        $front_view_png = $this->generate_and_convert_svg($article_id, 'front');
        $top_view_png = $this->generate_and_convert_svg($article_id, 'top');

        $this->logger->log_user_action(self::LOG_NAME, 'svg_generated_and_converted', ['front_view' => $front_view_png, 'top_view' => $top_view_png], $user_id);

        $this->generate_pdf($article, $project, $tank_datas, $front_view_png, $top_view_png);

        foreach ([$front_view_png, $top_view_png] as $png_path)
        {
            if ($png_path && file_exists($png_path))
            {
                unlink($png_path);
                $this->logger->log_user_action(self::LOG_NAME, 'temp_png_deleted', ['path' => $png_path], $user_id);
            }
            $svg_path = str_replace('.png', '.svg', $png_path);
            if (file_exists($svg_path))
            {
                unlink($svg_path);
                $this->logger->log_user_action(self::LOG_NAME, 'temp_svg_deleted', ['path' => $svg_path], $user_id);
            }
        }

        if (ob_get_length())
        {
            ob_end_clean();
            $this->logger->log_user_action(self::LOG_NAME, 'output_buffer_cleaned', [], $user_id);
        }

        $this->Output('D', $filename);
        $this->logger->log_user_action(self::LOG_NAME, 'pdf_output_complete', ['filename' => $filename], $user_id);
        exit;
    }

    protected function generate_and_convert_svg($article_id, $view_type = 'front')
    {
        $user_id = get_current_user_id();

        if ($view_type === 'front')
        {
            $this->logger->log_user_action(self::LOG_NAME, 'generating_front_view_svg', ['article_id' => $article_id], $user_id);
            $svg_generator = new ISPAG_Tank_SVG_Generator();
            $svg_content = $svg_generator->design_tank_svg(null, $article_id, true);
        }
        else
        {
            $this->logger->log_user_action(self::LOG_NAME, 'generating_top_view_svg', ['article_id' => $article_id], $user_id);
            $svg_generator = new ISPAG_Tank_SVG_Top_View_Generator(
                $this->get_tank_diameter($article_id),
                160,
                $this->fittings,
                $this->get_tank_height($article_id)
            );
            $svg_content = $svg_generator->render_svg();
        }

        $temp_dir = plugin_dir_path(__FILE__) . 'temp/';
        if (!file_exists($temp_dir))
        {
            wp_mkdir_p($temp_dir);
            $this->logger->log_user_action(self::LOG_NAME, 'temp_dir_created', ['dir' => $temp_dir], $user_id);
        }

        $svg_path = $temp_dir . 'temp_svg_' . $article_id . '_' . $view_type . '.svg';
        $png_path = $temp_dir . 'temp_png_' . $article_id . '_' . $view_type . '.png';

        file_put_contents($svg_path, $svg_content);
        $this->logger->log_user_action(self::LOG_NAME, 'svg_saved_to_temp', ['svg_path' => $svg_path], $user_id);

        if (extension_loaded('imagick'))
        {
            try
            {
                $imagick = new Imagick();
                $imagick->setBackgroundColor(new ImagickPixel('transparent'));
                $imagick->readImage($svg_path);
                $imagick->setImageDepth(8);
                $imagick->setImageFormat('png');
                $imagick->writeImage($png_path);
                $imagick->clear();
                $imagick->destroy();
                $this->logger->log_user_action(self::LOG_NAME, 'png_converted_with_imagick', ['png_path' => $png_path], $user_id);
                return $png_path;
            }
            catch (Exception $e)
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Imagick conversion failed - ' . $e->getMessage(), $user_id);
            }
        }

        $cmd = "rsvg-convert -w 800 -h 800 -d 96 -p 96 -f png -o " . escapeshellarg($png_path) . " " . escapeshellarg($svg_path);
        exec($cmd, $output, $return_var);

        if ($return_var !== 0)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: rsvg-convert failed - ' . implode("\n", $output), $user_id);
            return false;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'png_converted_with_rsvg', ['png_path' => $png_path], $user_id);

        if (extension_loaded('imagick'))
        {
            try
            {
                $imagick = new Imagick($png_path);
                if ($imagick->getImageDepth() > 8)
                {
                    $imagick->setImageDepth(8);
                    $imagick->writeImage($png_path);
                    $this->logger->log_user_action(self::LOG_NAME, 'png_depth_corrected', ['png_path' => $png_path], $user_id);
                }
                $imagick->clear();
                $imagick->destroy();
            }
            catch (Exception $e)
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Depth correction failed - ' . $e->getMessage(), $user_id);
            }
        }

        return $png_path;
    }

    protected function get_tank_diameter($article_id)
    {
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        $diameter = $tank_datas['dimensions']->Diameter ?? 1000;
        $this->logger->log_db_change(self::LOG_NAME, 'tank_dimensions', 'FETCH_DIAMETER', ['article_id' => $article_id, 'diameter' => $diameter], get_current_user_id());
        return $diameter;
    }

    protected function get_tank_height($article_id)
    {
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        $height = $tank_datas['dimensions']->Height ?? 2000;
        $this->logger->log_db_change(self::LOG_NAME, 'tank_dimensions', 'FETCH_HEIGHT', ['article_id' => $article_id, 'height' => $height], get_current_user_id());
        return $height;
    }

    public function generate_pdf($article, $project, $tank_datas, $front_view_png = false, $top_view_png = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'generate_pdf_start', ['article_id' => $article->Id], $user_id);

        $this->AddPage($this->page_orientation);
        $this->draw_page_frame();

        if ($front_view_png && file_exists($front_view_png))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'rendering_front_view', [], $user_id);
            $this->render_view($front_view_png, $this->front_view_x, $this->front_view_y,
                             $this->front_view_width, $this->front_view_height);
        }

        if ($top_view_png && file_exists($top_view_png))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'rendering_top_view', [], $user_id);
            $this->render_view($top_view_png, $this->top_view_x, $this->top_view_y,
                             $this->top_view_width, $this->top_view_height);
        }

        $this->render_fittings_table($tank_datas, $article, $project);
        $this->logger->log_user_action(self::LOG_NAME, 'fittings_table_rendered', [], $user_id);

        $this->render_cartouche_pdf([
            'article' => $article,
            'project' => $project,
            'tank_datas' => $tank_datas,
            'appr' => 'Claudio Tonelli',
            'drawn_by' => get_the_author_meta('display_name', $article->created_by_id) ?? 'Cyril Barthel',
        ]);
        $this->logger->log_user_action(self::LOG_NAME, 'cartouche_rendered', [], $user_id);
    }

    protected function render_view($png_path, $x, $y, $max_width, $max_height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_view_start', ['png_path' => $png_path], $user_id);

        list($img_width, $img_height) = getimagesize($png_path);

        $ratio = $max_width / $img_width;
        $new_height = $img_height * $ratio;

        if ($new_height > $max_height)
        {
            $ratio = $max_height / $img_height;
            $new_width = $img_width * $ratio;
        }
        else
        {
            $new_width = $max_width;
        }

        $this->Image($png_path, $x, $y, $new_width, $new_height);
        $this->logger->log_user_action(self::LOG_NAME, 'view_rendered', ['x' => $x, 'y' => $y, 'width' => $new_width, 'height' => $new_height], $user_id);
    }

    protected function draw_page_frame()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'draw_page_frame_start', [], $user_id);

        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->Rect(
            $this->margin,
            $this->margin,
            $this->page_width - 2 * $this->margin,
            $this->page_height - 2 * $this->margin
        );
    }
    protected function render_fittings_table($tank_datas, $article, $project)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_fittings_table_start', [], $user_id);

        $table_x = $this->table_x;
        $table_y = $this->table_y;

        // --- LOG DÉTAILLÉ DES FITTINGS REÇUS ---
        if (!empty($this->fittings))
        {
            $fittings_details = [];
            foreach ($this->fittings as $index => $fitting)
            {
                $fittings_details[] = [
                    'index' => $index,
                    'Type' => $fitting->Type ?? 'N/A',
                    'Type_raccord' => $fitting->Type_raccord ?? 'N/A',
                    'Type_raccord_label' => $fitting->Type_raccord_label ?? 'N/A',
                    'Diametre_Nominal' => $fitting->Pouces ?? 'N/A',
                    'Elevation_mm' => $fitting->Height ?? 'N/A',
                    'Angle_deg' => $fitting->Angle ?? 'N/A',
                    'Accessories_label' => $fitting->Accessories_label ?? 'N/A',
                    'Bride_Int_mm' => $fitting->Bride_Int_mm ?? 'N/A',
                    'Value' => $fitting->Value ?? 'N/A',
                    // Ajoutez ici d'autres propriétés si nécessaire
                ];
            }
            $this->logger->log_user_action(self::LOG_NAME, 'fittings_received', ['raw fittings' => $this->fittings, 'fittings' => $fittings_details], $user_id);
        }
        else
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_fittings_received', [], $user_id);
        }
        // --- FIN DU LOG DÉTAILLÉ ---

        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->SetXY($table_x, $table_y);
        $this->Cell(0, 6, iconv('UTF-8', 'ISO-8859-1', __('Fittings List', 'creation-reservoir')), 0, 1, 'L');

        $col_widths = [
            'id'            => 12,
            'description'   => 50,
            // 'diameter'      => 10,
            'height'        => 8,
            'angle'         => 8,
        ];

        $this->SetFont('Arial', 'B', 7);
        $this->SetFillColor(230, 230, 230);

        $this->SetXY($table_x, $table_y + 8);
        $this->Cell($col_widths['id'], 5, iconv('UTF-8', 'ISO-8859-1', __('pos', 'creation-reservoir')), 1, 0, 'C', true);
        $this->Cell($col_widths['description'], 5, iconv('UTF-8', 'ISO-8859-1', __('Desc', 'creation-reservoir')), 1, 0, 'C', true);
        // $this->Cell($col_widths['diameter'], 5, iconv('UTF-8', 'ISO-8859-1', __('Diam.', 'creation-reservoir')), 1, 0, 'C', true);
        $this->Cell($col_widths['height'], 5, iconv('UTF-8', 'ISO-8859-1', __('H', 'creation-reservoir')), 1, 0, 'C', true);
        $this->Cell($col_widths['angle'], 5, iconv('UTF-8', 'ISO-8859-1', __('W', 'creation-reservoir')), 1, 1, 'C', true);

        $this->SetFont('Arial', '', 6);
        $this->SetFillColor(255, 255, 255);

        $y_position = $table_y + 13;
        $row_height = 4;

        $nozzleData = [];
        if (!empty($this->fittings))
        {
            foreach ($this->fittings as $index => $fitting)
            {
                $nozzleData[] = [
                    'id' => ($index + 1) . '',
                    'desc' => $this->get_fitting_description($fitting),
                    // 'diameter' => (isset($fitting->Pouces) ? $fitting->Pouces : 'N/A'),
                    'height' => (isset($fitting->Height) ? $fitting->Height : 'N/A') . 'mm',
                    'angle' => (isset($fitting->Angle) ? $fitting->Angle : '0') . '°',
                ];
            }
        }

        if (empty($nozzleData))
        {
            $this->SetXY($table_x, $y_position);
            $this->Cell($this->table_width, $row_height, iconv('UTF-8', 'ISO-8859-1', __('No fittings', 'creation-reservoir')), 1, 1, 'C');
            $this->logger->log_user_action(self::LOG_NAME, 'no_fittings_to_render', [], $user_id);
        }
        else
        {
            // --- LOG DES DONNÉES DES FITTINGS APRES TRAITEMENT ---
            $this->logger->log_user_action(self::LOG_NAME, 'fittings_processed_for_table', ['nozzleData' => $nozzleData], $user_id);

            foreach ($nozzleData as $item)
            {
                $this->SetXY($table_x, $y_position);

                $this->Cell($col_widths['id'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['id']), 1, 0, 'C');
                $desc = strlen($item['desc']) > 35 ? substr($item['desc'], 0, 35) . '...' : $item['desc'];
                $this->Cell($col_widths['description'], $row_height, iconv('UTF-8', 'ISO-8859-1', $desc), 1, 0, 'L');
                // $this->Cell($col_widths['diameter'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['diameter']), 1, 0, 'C');
                $this->Cell($col_widths['height'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['height']), 1, 0, 'C');
                $this->Cell($col_widths['angle'], $row_height, iconv('UTF-8', 'ISO-8859-1', $item['angle']), 1, 1, 'C');

                $y_position += $row_height;

                if ($y_position > $this->page_height - $this->cartouche_height - 15)
                {
                    $this->logger->log_user_action(self::LOG_NAME, 'fittings_table_overflow', ['y_position' => $y_position], $user_id);
                    break;
                }
            }
        }
    }

    protected function get_fitting_description($fitting)
    {
        $user_id = get_current_user_id();

        if (!$fitting)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Fitting is null', $user_id);
            return 'N/A';
        }

        $parts = [];
        if (isset($fitting->Pouces) && !empty($fitting->Pouces))
        {
            $parts[] = $fitting->Pouces;
            $this->logger->log_user_action(self::LOG_NAME, 'fitting_part_added', ['part' => 'Diametre_Nominal', 'value' => $fitting->Pouces], $user_id);
        }
        if (isset($fitting->Accessories_label) && !empty($fitting->Accessories_label))
        {
            $parts[] = $fitting->Accessories_label;
            $this->logger->log_user_action(self::LOG_NAME, 'fitting_part_added', ['part' => 'Accessories_label', 'value' => $fitting->Accessories_label], $user_id);
        }
        if (isset($fitting->madeFor) && !empty($fitting->madeFor))
        {
            $parts[] = $fitting->madeFor;
            $this->logger->log_user_action(self::LOG_NAME, 'fitting_part_added', ['part' => 'Made for', 'value' => $fitting->madeFor], $user_id);
        }
        // else
        // {
        //     $type_label = $this->get_fitting_type_label($fitting->Type_raccord ?? 0);
        //     if ($type_label !== 'Unknown')
        //     {
        //         $parts[] = $type_label;
        //         $this->logger->log_user_action(self::LOG_NAME, 'fitting_type_label_resolved', ['type' => $fitting->Type_raccord, 'label' => $type_label], $user_id);
        //     }
        // }
        // if (isset($fitting->Bride_Int_mm) && !empty($fitting->Bride_Int_mm))
        // {
        //     $parts[] = 'Ø' . $fitting->Bride_Int_mm . 'mm';
        //     $this->logger->log_user_action(self::LOG_NAME, 'fitting_part_added', ['part' => 'Bride_Int_mm', 'value' => $fitting->Bride_Int_mm], $user_id);
        // }

        $description = implode(' - ', $parts);
        $this->logger->log_user_action(self::LOG_NAME, 'fitting_description_generated', ['description' => $description], $user_id);
        return $description;
    }

    protected function get_fitting_type_label($type)
    {
        $types = [
            12 => __('Bend Pipe', 'creation-reservoir'),
            24 => __('Flange', 'creation-reservoir'),
        ];
        $label = $types[$type] ?? __('Unknown', 'creation-reservoir');
        $this->logger->log_user_action(self::LOG_NAME, 'fitting_type_label_resolved', ['type' => $type, 'label' => $label], get_current_user_id());
        return $label;
    }

    protected function loadFittings($article_id)
    {
        $user_id = get_current_user_id();
        if (class_exists('ISPAG_Tank_Fittings'))
        {
            $fittings = (new ISPAG_Tank_Fittings())->get_all_fittings($article_id);
            $welding = (new ISPAG_Tank_Welding())->get_all_welding_drilled_plate(null, $article_id);

            // Fusion des deux tableaux
            $this->fittings = array_merge($fittings, $welding);
            $this->logger->log_db_change(self::LOG_NAME, 'tank_fittings', 'FETCH_ALL', ['article_id' => $article_id, 'count' => count($this->fittings)], $user_id);
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: ISPAG_Tank_Fittings class not found', $user_id);
        }
    }

    protected function loadWeldings($article_id)
    {
        $user_id = get_current_user_id();
        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM {$this->table_connections} c LEFT JOIN {$this->table_dimension} d ON c.TankId = d.Id WHERE d.customerTankId = %d AND c.Type = 23",
            $article_id
        );
        $this->weldings = $this->wpdb->get_results($sql);
        $this->logger->log_db_change(self::LOG_NAME, $this->table_connections, 'FETCH_WELDINGS', ['article_id' => $article_id, 'count' => count($this->weldings)], $user_id);
    }

    protected function loadPlate($article_id)
    {
        $user_id = get_current_user_id();
        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM {$this->table_connections} c LEFT JOIN {$this->table_dimension} d ON c.TankId = d.Id WHERE d.customerTankId = %d AND c.Type = 22",
            $article_id
        );
        $this->drilled_plate = $this->wpdb->get_results($sql);
        $this->logger->log_db_change(self::LOG_NAME, $this->table_connections, 'FETCH_PLATES', ['article_id' => $article_id, 'count' => count($this->drilled_plate)], $user_id);
    }

    protected function render_cartouche_pdf($content = [])
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_cartouche_pdf_start', [], $user_id);

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
        $this->Cell($this->cartouche_width - 10, 5, iconv('UTF-8', 'ISO-8859-1', __('Article Nr.', 'creation-reservoir') . " : " . ($content['article']->Id ?? 'N/A')), 0, 1);

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
        $this->Cell(120, 5, iconv('UTF-8', 'ISO-8859-1', __('Design pressure', 'creation-reservoir') . " : " . $pressure), 0, 1);

        $this->SetXY($right_x, $y + 30);
        $this->Cell(55, 5, iconv('UTF-8', 'ISO-8859-1', __('Approved by', 'creation-reservoir') . " : " . ($content['appr'] ?? 'Wihan')), 0, 1);

        $this->SetX($right_x);
        $this->Cell(55, 5, iconv('UTF-8', 'ISO-8859-1', __('Drawn by', 'creation-reservoir') . " : " . ($content['drawn_by'] ?? 'Cyril Barthel')), 0, 1);

        $this->SetX($right_x);
        $this->Cell(55, 5, iconv('UTF-8', 'ISO-8859-1', __('Date', 'creation-reservoir') . " : " . date('d.m.Y')), 0, 1);

        $this->logger->log_user_action(self::LOG_NAME, 'cartouche_rendered', [], $user_id);
    }

    protected function calculate_page_dimensions()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'calculate_page_dimensions_start', [], $user_id);

        $sizes = [
            'A0' => ['width' => 841, 'height' => 1189],
            'A1' => ['width' => 594, 'height' => 841],
            'A2' => ['width' => 420, 'height' => 594],
            'A3' => ['width' => 297, 'height' => 420],
            'A4' => ['width' => 210, 'height' => 297],
        ];

        if (isset($sizes[$this->page_format]))
        {
            $w = $sizes[$this->page_format]['width'];
            $h = $sizes[$this->page_format]['height'];
            $this->page_width = ($this->page_orientation === 'L') ? $h : $w;
            $this->page_height = ($this->page_orientation === 'L') ? $w : $h;
            $this->logger->log_user_action(self::LOG_NAME, 'page_dimensions_calculated', ['width' => $this->page_width, 'height' => $this->page_height], $user_id);
        }
        else
        {
            $this->page_width = ($this->page_orientation === 'L') ? 297 : 210;
            $this->page_height = ($this->page_orientation === 'L') ? 210 : 297;
            $this->logger->log_user_action(self::LOG_NAME, 'default_page_dimensions_used', ['width' => $this->page_width, 'height' => $this->page_height], $user_id);
        }
    }

    protected function sanitize_filename($title)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'sanitize_filename_start', ['original' => $title], $user_id);

        $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
        $title = strtolower($title);
        $sanitized = trim(preg_replace('/[^a-z0-9]+/', '-', $title), '-');

        $this->logger->log_user_action(self::LOG_NAME, 'filename_sanitized', ['original' => $title, 'sanitized' => $sanitized], $user_id);
        return $sanitized;
    }
}