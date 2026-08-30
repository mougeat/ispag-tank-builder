<?php
/**
 * Class ISPAG_Tank_SVG_Generator
 * Génère des représentations SVG des cuves ISPAG avec leurs piquages, soudures et tôles perforées.
 * Logging : Toutes les actions sont loguées dans ispag_tank_svg_generator.log.
 */
class ISPAG_Tank_SVG_Generator
{
    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    protected $wpdb;
    protected static $instance = null;
    protected $table_dimension;
    protected $table_flange_dimension;
    protected $table_conception;
    protected $table_connections;
    protected $tank_data;
    protected $is_sketch = false;
    protected $fittings = [];
    protected $tank_cotations = [];
    protected $bent_tubes = [];

    protected $margin_left;  // marge gauche en mm
    protected $margin_right; // marge droite en mm

    public function __construct($margin_left = 200, $margin_right = 1000)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->table_dimension = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';

        $this->margin_left = $margin_left;
        $this->margin_right = $margin_right;

        $this->logger->log_user_action('tank_svg_generator', 'class_constructed', [
            'margin_left' => $margin_left,
            'margin_right' => $margin_right
        ], $user_id);
    }

    // Ajout d'une nouvelle méthode pour charger les piquages depuis la BDD
    protected function loadFittings($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'loadFittings_start', ['article_id' => $article_id], $user_id);

        $tank_fittings = new ISPAG_Tank_Fittings();
        $this->fittings = $tank_fittings->get_all_fittings($article_id);

        $this->logger->log_user_action('tank_svg_generator', 'fittings_loaded', ['article_id' => $article_id, 'fittings_count' => count($this->fittings)], $user_id);
    }

    public static function init()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null)
        {
            self::$instance = new self();
            $logger->log_user_action('tank_svg_generator', 'instance_initialized', [], $user_id);
        }

        add_filter('ispag_get_tank_svg', [self::$instance, 'get_tank_svg'], 10, 3);
        add_filter('ispag_design_tank_svg', [self::$instance, 'design_tank_svg'], 10, 3);
        add_filter('ispag_get_tank_png', [self::$instance, 'get_tank_png'], 10, 3);

        $logger->log_user_action('tank_svg_generator', 'filters_registered', [], $user_id);
    }

    public function design_tank_svg($html, $article_id, $with_cotation = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'design_tank_svg_start', ['article_id' => $article_id, 'with_cotation' => $with_cotation], $user_id);

        $this->load_data($article_id);
        $this->logger->log_user_action('tank_svg_generator', 'tank_data_loaded', ['article_id' => $article_id], $user_id);

        $this->loadFittings($article_id);
        $this->logger->log_user_action('tank_svg_generator', 'fittings_loaded', ['article_id' => $article_id], $user_id);

        $this->loadWeldings($article_id);
        $this->logger->log_user_action('tank_svg_generator', 'weldings_loaded', ['article_id' => $article_id], $user_id);

        $this->loadPlate($article_id);
        $this->logger->log_user_action('tank_svg_generator', 'plates_loaded', ['article_id' => $article_id], $user_id);

        $this->loadBentTubes($article_id);
        $this->logger->log_user_action('tank_svg_generator', 'bent_tubes_loaded', ['article_id' => $article_id, 'count' => count($this->bent_tubes)], $user_id);

        $svg = $this->render_svg($with_cotation);
        $this->logger->log_user_action('tank_svg_generator', 'design_tank_svg_complete', ['svg_length' => strlen($svg)], $user_id);

        return $svg ?: $html;
    }

    public function get_tank_svg($html, $article_id, $with_cotation = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'get_tank_svg_start', ['article_id' => $article_id, 'with_cotation' => $with_cotation], $user_id);

        $svg_content = $this->design_tank_svg(null, $article_id, $with_cotation);
        $this->logger->log_user_action('tank_svg_generator', 'svg_content_generated', ['article_id' => $article_id, 'content_length' => strlen($svg_content)], $user_id);

        $svg_url = $this->save_svg_file($svg_content, $article_id);
        $this->logger->log_user_action('tank_svg_generator', 'get_tank_svg_complete', ['svg_url' => $svg_url], $user_id);

        return $svg_url;
    }

    public function get_tank_png($html, $article_id, $with_cotation = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'get_tank_png_start', ['article_id' => $article_id, 'with_cotation' => $with_cotation], $user_id);

        $svg_content = $this->design_tank_svg(null, $article_id, $with_cotation);
        $this->logger->log_user_action('tank_svg_generator', 'svg_content_generated', ['article_id' => $article_id, 'content_length' => strlen($svg_content)], $user_id);

        $svg_url = $this->save_svg_file($svg_content, $article_id);
        $this->logger->log_user_action('tank_svg_generator', 'svg_file_saved', ['svg_url' => $svg_url], $user_id);

        $png_url = $this->convert_svg_to_png($article_id);
        $this->logger->log_user_action('tank_svg_generator', 'get_tank_png_complete', ['png_url' => $png_url], $user_id);

        return $png_url;
    }

    protected function save_svg_file($svg_content, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'save_svg_file_start', ['article_id' => $article_id], $user_id);

        // Utiliser le dossier uploads de WordPress
        $upload_dir = wp_upload_dir();
        $svg_dir = trailingslashit($upload_dir['basedir']) . 'ispag-svg/';

        $this->logger->log_user_action('tank_svg_generator', 'upload_dir_retrieved', ['basedir' => $upload_dir['basedir'], 'baseurl' => $upload_dir['baseurl']], $user_id);

        // Créer le dossier s'il n'existe pas
        if (!file_exists($svg_dir))
        {
            wp_mkdir_p($svg_dir);
            $this->logger->log_user_action('tank_svg_generator', 'svg_directory_created', ['svg_dir' => $svg_dir], $user_id);
        }

        $svg_path = $svg_dir . "cuves_{$article_id}.svg";
        $this->logger->log_user_action('tank_svg_generator', 'svg_path_prepared', ['svg_path' => $svg_path], $user_id);

        $bytes_written = file_put_contents($svg_path, $svg_content);
        $this->logger->log_user_action('tank_svg_generator', 'svg_file_written', ['bytes_written' => $bytes_written], $user_id);

        // Retourner l'URL publique
        $svg_url = trailingslashit($upload_dir['baseurl']) . 'ispag-svg/cuves_' . $article_id . '.svg';
        $this->logger->log_user_action('tank_svg_generator', 'save_svg_file_complete', ['svg_url' => $svg_url], $user_id);

        return $svg_url;
    }

    public function load_data($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'load_data_start', ['article_id' => $article_id], $user_id);

        $designer = new ISPAG_Tank_Designer();
        $this->tank_data = $designer->get_tank_data(null, $article_id);

        $this->logger->log_user_action('tank_svg_generator', 'tank_data_loaded', ['article_id' => $article_id], $user_id);
    }

    protected function loadBentTubes($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'loadBentTubes_start', ['article_id' => $article_id], $user_id);

        $conn_table = $this->table_connections;
        $dim_table = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 14",
            $article_id
        );

        $this->logger->log_db_change('tank_svg_generator', $conn_table, 'FETCH_BENT_TUBES', ['article_id' => $article_id, 'sql' => $sql], $user_id);

        $this->bent_tubes = $this->wpdb->get_results($sql);
        $this->logger->log_user_action('tank_svg_generator', 'bent_tubes_loaded', ['article_id' => $article_id, 'count' => count($this->bent_tubes)], $user_id);
    }

    protected function designTankBodyPath()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'designTankBodyPath_start', [], $user_id);

        $d = $this->tank_data['dimensions'];
        if (!$d)
        {
            $this->logger->log('tank_svg_generator', 'ERROR: No dimensions data found', $user_id);
            return '';
        }

        $this->logger->log_user_action('tank_svg_generator', 'dimensions_data_retrieved', ['diameter' => $d->Diameter, 'height' => $d->Height], $user_id);

        $jsonString = file_get_contents(__DIR__ . '/../assets/json/tank_data.json');
        $data = json_decode($jsonString, true);

        if (!$d || !$data)
        {
            $this->logger->log('tank_svg_generator', 'ERROR: Failed to load tank data or JSON file', $user_id);
            return '';
        }

        $this->logger->log_user_action('tank_svg_generator', 'tank_data_json_loaded', [], $user_id);

        $d->arrayBottomHeight = $data['arrayBottomHeight'][$this->tank_data['conception']->Material] ?? [];
        $this->logger->log_user_action('tank_svg_generator', 'arrayBottomHeight_set', ['material' => $this->tank_data['conception']->Material], $user_id);

        $diam = intval($d->Diameter);
        $height = intval($d->Height);
        $ground_clearance = intval($d->GroundClearance);
        $bottom_height = intval($d->arrayBottomHeight[$d->Diameter] ?? 0);
        $body_height = $height - $ground_clearance - 2 * $bottom_height;
        $y = $body_height + $bottom_height;

        $this->logger->log_user_action('tank_svg_generator', 'tank_geometry_calculated', [
            'diam' => $diam,
            'height' => $height,
            'ground_clearance' => $ground_clearance,
            'bottom_height' => $bottom_height,
            'body_height' => $body_height,
            'y' => $y
        ], $user_id);

        $x_mesure_start = $diam / 3;
        $x_mesure_end = $diam + 50;

        $css = 'stroke: #000; stroke-width: 1;';
        $return = '';

        if ($this->tank_data['conception']->Support == 10)
        {
            $feet_svg = $this->designFeet($diam, $y, $height);
            $return .= $feet_svg;
            $this->logger->log_user_action('tank_svg_generator', 'feet_design_added', [], $user_id);
        }

        $return .= '<path d="M 0 ' . $bottom_height . ' A ' . ($diam / 2) . ' ' . $bottom_height . ' 0 0 1 ' . $diam . ' ' . $bottom_height . '" style="' . $css . '" fill="url(#topEllipse)"/>';
        $this->logger->log_user_action('tank_svg_generator', 'bottom_ellipse_added', [], $user_id);

        $return .= '<rect id="designMantel" width="' . $diam . '" height="' . $body_height . '" x="0" y="' . $bottom_height . '" fill="url(#cylinderBody)" style="' . $css . '"/>';
        $this->logger->log_user_action('tank_svg_generator', 'cylinder_body_added', [], $user_id);

        $return .= '<path d="M 0 ' . $y . ' A ' . ($diam / 2) . ' ' . $bottom_height . ' 0 0 0 ' . $diam . ' ' . $y . '" style="' . $css . '" fill="url(#topEllipse)"/>';
        $this->logger->log_user_action('tank_svg_generator', 'top_ellipse_added', [], $user_id);

        if ($this->tank_data['conception']->Support == 11)
        {
            $virole_svg = $this->designVirole($diam, $height, $ground_clearance, $bottom_height, $body_height);
            $return .= $virole_svg;
            $this->logger->log_user_action('tank_svg_generator', 'virole_design_added', [], $user_id);
        }

        $ground_line_svg = $this->designGroundLine($diam, $y, $ground_clearance);
        $return .= $ground_line_svg;
        $this->logger->log_user_action('tank_svg_generator', 'ground_line_added', [], $user_id);

        $this->tank_cotations[] = (object)['Height' => $height];
        $this->tank_cotations[] = (object)['Height' => $height - $bottom_height];
        $this->tank_cotations[] = (object)['Height' => $height - $y];
        $this->tank_cotations[] = (object)['Height' => $ground_clearance];
        $this->logger->log_user_action('tank_svg_generator', 'cotations_added', ['count' => count($this->tank_cotations)], $user_id);

        $this->logger->log_user_action('tank_svg_generator', 'designTankBodyPath_complete', [], $user_id);
        return $return;
    }

    protected function render_bent_tube_svg($tube, $diam, $height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_bent_tube_svg_start', ['tube' => $tube], $user_id);

        $css = 'stroke: #555; stroke-width: 2; fill: none;';

        // Coordonnées de départ et d'arrivée
        $startX = $tube->StartX ?? 0;
        $startY = $height - ($tube->StartY ?? 0); // Inverser Y pour SVG
        $endX = $tube->EndX ?? $diam;
        $endY = $height - ($tube->EndY ?? 0);

        $this->logger->log_user_action('tank_svg_generator', 'bent_tube_coordinates_set', [
            'startX' => $startX,
            'startY' => $startY,
            'endX' => $endX,
            'endY' => $endY
        ], $user_id);

        // Rayon du coude
        $radius = $tube->Radius ?? 50;
        $this->logger->log_user_action('tank_svg_generator', 'bent_tube_radius_set', ['radius' => $radius], $user_id);

        // Dessiner une ligne droite jusqu'au début du coude
        $svg = "<line x1='{$startX}' y1='{$startY}' x2='{$startX}' y2='" . ($startY - $radius) . "' style='{$css}' />";
        $this->logger->log_user_action('tank_svg_generator', 'bent_tube_straight_line_added', [], $user_id);

        // Dessiner un arc de cercle pour le coude (90 degrés)
        $arcX = $startX + $radius;
        $arcY = $startY - $radius;
        $svg .= "<path d='M {$startX} " . ($startY - $radius) . " A {$radius} {$radius} 0 0 1 {$arcX} {$arcY}' style='{$css}' />";
        $this->logger->log_user_action('tank_svg_generator', 'bent_tube_arc_added', [], $user_id);

        // Dessiner une ligne droite après le coude
        $svg .= "<line x1='{$arcX}' y1='{$arcY}' x2='{$endX}' y2='{$arcY}' style='{$css}' />";
        $this->logger->log_user_action('tank_svg_generator', 'bent_tube_end_line_added', [], $user_id);

        $this->logger->log_user_action('tank_svg_generator', 'render_bent_tube_svg_complete', [], $user_id);
        return $svg;
    }

    protected function designRightTextLabel($x = null, $y = 0, $label = '')
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'designRightTextLabel_start', ['x' => $x, 'y' => $y, 'label' => $label], $user_id);

        $css = 'fill: #ccc; stroke: #000;';

        $d = $this->tank_data['dimensions'];
        if (!$d)
        {
            $this->logger->log('tank_svg_generator', 'ERROR: No dimensions data for text label', $user_id);
            return '';
        }

        $default_x = $this->margin_left + $d->Diameter;

        $x_start = !empty($x) ? $x : $default_x;
        $x_end = $this->margin_left + $d->Diameter + 100;
        $return = '';

        $return .= '<line x1="' . $x_start .'" y1="' . $y . '" x2="' . $x_end . '" y2="' . $y . '" style="' . $css . '" />';
        $this->logger->log_user_action('tank_svg_generator', 'text_label_line_added', [], $user_id);

        $return .= '<text x="' . $x_end - 100 . '" y="' . $y-50 . '" font-size="100" fill="#000" dominant-baseline="middle">' . $label . '</text>';
        $this->logger->log_user_action('tank_svg_generator', 'text_label_added', [], $user_id);

        $this->logger->log_user_action('tank_svg_generator', 'designRightTextLabel_complete', [], $user_id);
        return $return;
    }

    protected function designFeet($diam, $bottom_y_end, $tank_height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'designFeet_start', ['diam' => $diam, 'bottom_y_end' => $bottom_y_end, 'tank_height' => $tank_height], $user_id);

        $css = 'stroke: #000;';
        $feet_width = 30;
        $positions = [
            0,
            ($diam - $feet_width) / 2,
            $diam - $feet_width
        ];

        $this->logger->log_user_action('tank_svg_generator', 'feet_positions_calculated', ['positions' => $positions], $user_id);

        $feet_height = $tank_height - $bottom_y_end;
        $this->logger->log_user_action('tank_svg_generator', 'feet_height_calculated', ['feet_height' => $feet_height], $user_id);

        $feet_svg = '';
        foreach ($positions as $x)
        {
            $feet_svg .= '<rect x="' . $x . '" y="' . ($bottom_y_end) . '" width="' . $feet_width . '" height="' . $feet_height . '" style="' . $css . '" fill="url(#cylinderBody)"/>';
            $this->logger->log_user_action('tank_svg_generator', 'foot_added', ['x' => $x], $user_id);
        }

        $this->logger->log_user_action('tank_svg_generator', 'designFeet_complete', [], $user_id);
        return $feet_svg;
    }

    protected function designVirole($diam, $tank_height, $ground_clearance, $bottom_height, $body_height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'designVirole_start', [
            'diam' => $diam,
            'tank_height' => $tank_height,
            'ground_clearance' => $ground_clearance,
            'bottom_height' => $bottom_height,
            'body_height' => $body_height
        ], $user_id);

        // Sécurité : Si le diamètre est nul ou négatif, on ne dessine rien
        if ($diam <= 0)
        {
            $this->logger->log('tank_svg_generator', 'ERROR: Invalid diameter for virole', $user_id, ['diam' => $diam]);
            return '';
        }

        $css = 'stroke: #000; fill-opacity: 0.8;';
        $virole_width = $diam - 200;

        $this->logger->log_user_action('tank_svg_generator', 'virole_width_calculated', ['virole_width' => $virole_width], $user_id);

        // Si la virole est plus large que le diamètre (cas diam < 200), on ajuste
        if ($virole_width <= 0)
        {
            $virole_width = $diam * 0.8;
            $this->logger->log_user_action('tank_svg_generator', 'virole_width_adjusted', ['new_virole_width' => $virole_width], $user_id);
        }

        $rx = ($diam / 2);
        $x_offset = ($virole_width / 2);
        $x_position = $rx - $x_offset;
        $x = ($diam - $virole_width) / 2;

        $this->logger->log_user_action('tank_svg_generator', 'virole_geometry_calculated', [
            'rx' => $rx,
            'x_offset' => $x_offset,
            'x_position' => $x_position,
            'x' => $x
        ], $user_id);

        $y = $tank_height - $ground_clearance;
        $y_position = (sqrt($bottom_height * $bottom_height * (1 - (($x_position - $rx) * ($x_position - $rx)) / ($rx * $rx)))) + $body_height + $bottom_height;
        $virole_height = $tank_height - $y_position;

        $this->logger->log_user_action('tank_svg_generator', 'virole_position_calculated', [
            'y' => $y,
            'y_position' => $y_position,
            'virole_height' => $virole_height
        ], $user_id);

        $svg = '<rect x="' . $x . '" y="' . $y_position . '" width="' . $virole_width . '" height="' . $virole_height . '" style="' . $css . '" fill="url(#cylinderBody)"/>';
        $this->logger->log_user_action('tank_svg_generator', 'virole_rectangle_added', [], $user_id);

        $this->logger->log_user_action('tank_svg_generator', 'designVirole_complete', [], $user_id);
        return $svg;
    }

    protected function designGroundLine($diam, $bottom_y_end, $ground_clearance)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'designGroundLine_start', ['diam' => $diam, 'bottom_y_end' => $bottom_y_end, 'ground_clearance' => $ground_clearance], $user_id);

        $y_sol = $bottom_y_end;
        $this->logger->log_user_action('tank_svg_generator', 'ground_line_y_calculated', ['y_sol' => $y_sol], $user_id);

        $css = 'stroke: #7a7a52 ; stroke-width: 5;';
        $svg = '<line x1="-50" x2="' . ($diam + 50) . '" y1="' . $y_sol . '" y2="' . $y_sol . '" style="' . $css . '" />';
        $this->logger->log_user_action('tank_svg_generator', 'designGroundLine_complete', [], $user_id);

        return $svg;
    }

    /****************************** Début de création des piquages *************************************************************************/
    protected function render_threaded_fitting_svg($fitting, $diameter = 1000, $insulation = 160, $tank_height = 2000, $bottom_height = 0)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_threaded_fitting_svg_start', [
            'fitting' => $fitting,
            'diameter' => $diameter,
            'insulation' => $insulation,
            'tank_height' => $tank_height,
            'bottom_height' => $bottom_height
        ], $user_id);

        $css = 'stroke: black; stroke-width: 1px;';
        $dn = $fitting->InternalDiamter ?? 50;
        $r = $dn / 2;
        $svg = '';

        $angle_deg = intval($fitting->Angle);
        $fitting_height = $fitting->Height ?? 1000;
        $base_cx = $diameter / 2;

        $this->logger->log_user_action('tank_svg_generator', 'fitting_parameters_set', [
            'angle_deg' => $angle_deg,
            'fitting_height' => $fitting_height,
            'base_cx' => $base_cx,
            'dn' => $dn,
            'r' => $r
        ], $user_id);

        // Position Y de départ pour les raccords latéraux (référence si incliné)
        $cy_lateral = $tank_height - $fitting_height;
        $this->logger->log_user_action('tank_svg_generator', 'lateral_cy_calculated', ['cy_lateral' => $cy_lateral], $user_id);

        // =====================================================================
        // 👉 DÉTECTION DU RACCORD VERTICAL (Plus haut que la cuve)
        // =====================================================================
        $is_vertical_top = false;
        if ($fitting_height > $tank_height)
        {
            $angle_deg = 90; // Force l'angle pour un dessin vertical
            $is_vertical_top = true;
            $this->logger->log_user_action('tank_svg_generator', 'vertical_top_fitting_detected', ['original_angle' => $fitting->Angle, 'new_angle' => $angle_deg], $user_id);
        }

        $angle_rad = deg2rad($angle_deg);
        $this->logger->log_user_action('tank_svg_generator', 'angle_converted_to_radians', ['angle_deg' => $angle_deg, 'angle_rad' => $angle_rad], $user_id);

        // =====================================================================
        // 👉 TRAITEMENT DU CAS VERTICAL (Sur le dôme supérieur)
        // =====================================================================
        if ($is_vertical_top)
        {
            $this->logger->log_user_action('tank_svg_generator', 'rendering_vertical_fitting', [], $user_id);

            // 🔑 CORRECTION CLÉ : Le raccord démarre au sommet du corps de la cuve (y = $bottom_height)
            $cy = 0;
            $cx = $base_cx; // Centré horizontalement

            $this->logger->log_user_action('tank_svg_generator', 'vertical_fitting_position_set', ['cx' => $cx, 'cy' => $cy], $user_id);

            $cy_end = $cy - $insulation; // Extrémité vers le haut (Y SVG diminue)
            $cx2 = $cx; // Raccord vertical

            $this->logger->log_user_action('tank_svg_generator', 'vertical_fitting_end_position_set', ['cx2' => $cx2, 'cy_end' => $cy_end], $user_id);

            $width = 2 * $r;
            $height = $insulation;
            $x = $cx - $r;
            $y = $cy_end; // Le haut du rectangle (début du dessin)

            $this->logger->log_user_action('tank_svg_generator', 'vertical_fitting_dimensions_set', [
                'width' => $width,
                'height' => $height,
                'x' => $x,
                'y' => $y
            ], $user_id);

            // 1. Dessin des accessoires (plaque, etc.)
            $accessories_svg = $this->render_fitting_with_accessories($fitting, $diameter, $tank_height);
            $svg .= $accessories_svg;
            $this->logger->log_user_action('tank_svg_generator', 'accessories_rendered', [], $user_id);

            // 2. Dessin du tube (rectangle vertical)
            $svg .= "<rect x='{$x}' y='{$y}' width='{$width}' height='{$height}' style='{$css}' fill='url(#fittingsBody)' />";
            $this->logger->log_user_action('tank_svg_generator', 'vertical_tube_added', [], $user_id);

            // 3. Dessin des ellipses (ouverture en haut et en bas)
            $rx_vert = $r;
            $ry_vert = $r;

            // Ellipse du bas (sur le toit de la cuve, position $cy)
            // $svg .= "<ellipse cx='{$cx}' cy='{$cy}' rx='{$rx_vert}' ry='{$ry_vert}' style='{$css}' fill='url(#fittingsBody)' />";

            // Ellipse du haut (extrémité du raccord, position $cy_end)
            $svg .= "<ellipse cx='{$cx}' cy='{$cy_end}' rx='{$rx_vert}' ry='{$ry_vert}' style='{$css}' fill='url(#fittingsBody)' />";
            $this->logger->log_user_action('tank_svg_generator', 'top_ellipse_added', [], $user_id);

            // 4. Flange (sur l'ellipse supérieure)
            $flange_svg = $this->render_flange_svg($fitting, $cx, $cy_end, $angle_deg, $insulation);
            $svg .= $flange_svg;
            $this->logger->log_user_action('tank_svg_generator', 'flange_added', [], $user_id);

            $this->logger->log_user_action('tank_svg_generator', 'vertical_fitting_complete', [], $user_id);
            return $svg;
        }

        // =====================================================================
        // CAS STANDARD (Raccord latéral/incliné)
        // =====================================================================
        $this->logger->log_user_action('tank_svg_generator', 'rendering_standard_fitting', [], $user_id);

        $cy = $cy_lateral; // On reprend la position Y calculée pour les raccords latéraux
        $this->logger->log_user_action('tank_svg_generator', 'using_lateral_cy', ['cy' => $cy], $user_id);

        // Récupération de la logique de projection pour l'angle réel
        $tiltAdjustedDiameter = abs(($r) * cos((($angle_deg / 90) * M_PI) / 2));
        $tiltAdjustedLength = $insulation * cos(((($angle_deg - 90) / -90) * M_PI) / 2);
        $cx = $base_cx * (1 + cos($angle_rad - M_PI_2));
        $cx2 = $cx + (($angle_deg > 90 && $angle_deg <= 270) ? -abs($tiltAdjustedLength) : abs($tiltAdjustedLength));

        $this->logger->log_user_action('tank_svg_generator', 'fitting_projection_calculated', [
            'tiltAdjustedDiameter' => $tiltAdjustedDiameter,
            'tiltAdjustedLength' => $tiltAdjustedLength,
            'cx' => $cx,
            'cx2' => $cx2
        ], $user_id);

        $y1 = $cy - $r;
        $height = 2 * $r;
        $width = abs($cx2 - $cx);
        $x = min($cx, $cx2);

        $this->logger->log_user_action('tank_svg_generator', 'fitting_rectangle_dimensions_calculated', [
            'y1' => $y1,
            'height' => $height,
            'width' => $width,
            'x' => $x
        ], $user_id);

        // fittings classiques
        $accessories_svg = $this->render_fitting_with_accessories($fitting, $diameter, $tank_height);
        $svg .= $accessories_svg;
        $this->logger->log_user_action('tank_svg_generator', 'accessories_rendered', [], $user_id);

        if ($angle_deg > 180 && $angle_deg < 360)
        {
            // tube orienté gauche
            $svg .= "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<rect x='{$x}' y='{$y1}' width='{$width}' height='{$height}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<ellipse cx='{$cx}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
            $this->logger->log_user_action('tank_svg_generator', 'left_oriented_fitting_added', [], $user_id);
        }
        else
        {
            // tube orienté droite
            $svg .= "<ellipse cx='{$cx}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<rect x='{$x}' y='{$y1}' width='{$width}' height='{$height}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
            $this->logger->log_user_action('tank_svg_generator', 'right_oriented_fitting_added', [], $user_id);
        }

        // Flange
        $flange_svg = $this->render_flange_svg($fitting, $cx, $cy, $angle_deg, $insulation);
        $svg .= $flange_svg;
        $this->logger->log_user_action('tank_svg_generator', 'flange_added', [], $user_id);

        $this->logger->log_user_action('tank_svg_generator', 'render_threaded_fitting_svg_complete', [], $user_id);
        return $svg;
    }

    protected function render_flange_svg($fitting, $cx, $cy, $angle_deg, $insulation)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_flange_svg_start', [
            'fitting' => $fitting,
            'cx' => $cx,
            'cy' => $cy,
            'angle_deg' => $angle_deg,
            'insulation' => $insulation
        ], $user_id);

        if (empty($fitting->NbDrilling) || $fitting->NbDrilling <= 0)
        {
            $this->logger->log_user_action('tank_svg_generator', 'no_drilling_skipping_flange', [], $user_id);
            return '';
        }

        $css_flange = 'stroke: black; stroke-width: 1px;';
        $css_hole = 'fill: #fff; stroke: black; stroke-width: 0.5px;';
        $css_thickness = 'fill: #777; stroke: black; stroke-width: 1px;';

        $this->logger->log_user_action('tank_svg_generator', 'flange_css_styles_set', [], $user_id);

        $int_diam = $fitting->InternalDiamter ?? 50;
        $r_int = $int_diam / 2;
        $ext_diam = $fitting->ExternalDiameter ?? $int_diam + 20;
        $r_ext = $ext_diam / 2;
        $hole_diam = $fitting->Drilling ?? 5;
        $nb_holes = intval($fitting->NbDrilling);

        $this->logger->log_user_action('tank_svg_generator', 'flange_dimensions_set', [
            'int_diam' => $int_diam,
            'r_int' => $r_int,
            'ext_diam' => $ext_diam,
            'r_ext' => $r_ext,
            'hole_diam' => $hole_diam,
            'nb_holes' => $nb_holes
        ], $user_id);

        $tilt_factor = abs(cos(($angle_deg / 90) * M_PI / 2));
        $rx_int = $r_int * $tilt_factor;
        $rx_ext = $r_ext * $tilt_factor;
        $ry_int = $r_int;
        $ry_ext = $r_ext;

        $this->logger->log_user_action('tank_svg_generator', 'flange_tilt_factors_calculated', [
            'tilt_factor' => $tilt_factor,
            'rx_int' => $rx_int,
            'rx_ext' => $rx_ext,
            'ry_int' => $ry_int,
            'ry_ext' => $ry_ext
        ], $user_id);

        $tiltAdjustedLength = $insulation * cos((($angle_deg - 90) / -90) * M_PI / 2);
        $is_left_side = ($angle_deg > 180 && $angle_deg < 360);

        $this->logger->log_user_action('tank_svg_generator', 'flange_position_parameters_calculated', [
            'tiltAdjustedLength' => $tiltAdjustedLength,
            'is_left_side' => $is_left_side
        ], $user_id);

        // $cx2 est la position de la bride (le bord extérieur du raccord)
        if ($angle_deg != 90)
        {
            $cx2 = $cx + ($is_left_side ? -abs($tiltAdjustedLength) : abs($tiltAdjustedLength));
            $this->logger->log_user_action('tank_svg_generator', 'flange_cx2_calculated', ['cx2' => $cx2], $user_id);
        }
        else
        {
            $cx2 = $cx;
            $this->logger->log_user_action('tank_svg_generator', 'using_cx_for_vertical_flange', [], $user_id);
        }

        $flange_ellipses = "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$rx_ext}' ry='{$ry_ext}' style='{$css_flange}' fill='url(#cylinderBody)'/>";
        $flange_ellipses .= "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$rx_int}' ry='{$ry_int}' fill='#ccc' stroke='none' />";
        $this->logger->log_user_action('tank_svg_generator', 'flange_ellipses_added', [], $user_id);

        $thickness = $fitting->Thickness ?? ($ext_diam - $int_diam) / 2;
        $thickness_proj = $thickness * abs(sin(($angle_deg / 90) * M_PI / 2));

        $this->logger->log_user_action('tank_svg_generator', 'flange_thickness_calculated', [
            'thickness' => $thickness,
            'thickness_proj' => $thickness_proj
        ], $user_id);

        $rect_x = ($angle_deg > 90 && $angle_deg <= 270) ? $cx2 - $thickness_proj : $cx2;
        $rect_y = $cy - $ry_ext;
        $rect_width = $thickness_proj;
        $rect_height = 2 * $ry_ext;

        $this->logger->log_user_action('tank_svg_generator', 'flange_rectangle_parameters_calculated', [
            'rect_x' => $rect_x,
            'rect_y' => $rect_y,
            'rect_width' => $rect_width,
            'rect_height' => $rect_height
        ], $user_id);

        $flange_rect = '';
        if ($angle_deg == 90 || $angle_deg == 270)
        {
            // Le rectangle d'épaisseur n'est visible que pour un angle de 90 (vertical) ou 270 (horizontal vu de côté)
            $thickness_vert = ($angle_deg == 90) ? $thickness : $thickness_proj;
            $rect_x_vert = $cx2;
            $rect_y_vert = $cy - $ry_ext;

            $this->logger->log_user_action('tank_svg_generator', 'vertical_flange_rectangle_parameters_set', [
                'thickness_vert' => $thickness_vert,
                'rect_x_vert' => $rect_x_vert,
                'rect_y_vert' => $rect_y_vert
            ], $user_id);

            // Pour le cas vertical (90°), l'épaisseur est vue de côté et n'est pas projetée.
            if ($angle_deg == 90)
            {
                $flange_rect = "<rect x='" . ($rect_x_vert + $thickness) . "' y='{$rect_y_vert}' width='{$thickness}' height='{$rect_height}' style='{$css_thickness}' />";
                $this->logger->log_user_action('tank_svg_generator', 'vertical_flange_rectangle_added', [], $user_id);
            }
        }

        $flange_holes = '';
        // Les trous sont visibles si le raccord n'est pas de côté (90° ou 270°)
        if (!($angle_deg > 90 && $angle_deg < 270))
        {
            $this->logger->log_user_action('tank_svg_generator', 'adding_flange_holes', [], $user_id);

            $hole_rx = ($hole_diam / 2) * $tilt_factor;
            $hole_ry = $hole_diam / 2;
            $hole_circle_rx = ($rx_ext + $rx_int) / 2;
            $hole_circle_ry = ($ry_ext + $ry_int) / 2;

            $this->logger->log_user_action('tank_svg_generator', 'hole_dimensions_calculated', [
                'hole_rx' => $hole_rx,
                'hole_ry' => $hole_ry,
                'hole_circle_rx' => $hole_circle_rx,
                'hole_circle_ry' => $hole_circle_ry
            ], $user_id);

            for ($i = 0; $i < $nb_holes; $i++)
            {
                $angle = 2 * M_PI * $i / $nb_holes - M_PI / 2;
                $hx = $cx2 + $hole_circle_rx * cos($angle);
                $hy = $cy + $hole_circle_ry * sin($angle);

                $this->logger->log_user_action('tank_svg_generator', 'hole_position_calculated', [
                    'i' => $i,
                    'angle' => $angle,
                    'hx' => $hx,
                    'hy' => $hy
                ], $user_id);

                // Pour le raccord vertical (90°), l'ellipse est un cercle.
                if ($angle_deg == 90)
                {
                    $flange_holes .= "<circle cx='{$hx}' cy='{$hy}' r='" . ($hole_diam / 2) . "' style='{$css_hole}' />";
                    $this->logger->log_user_action('tank_svg_generator', 'circular_hole_added', [], $user_id);
                }
                else
                {
                    $flange_holes .= "<ellipse cx='{$hx}' cy='{$hy}' rx='{$hole_rx}' ry='{$hole_ry}' style='{$css_hole}' />";
                    $this->logger->log_user_action('tank_svg_generator', 'elliptical_hole_added', [], $user_id);
                }
            }
        }

        // Si angle entre 90° et 270°, bride derrière → retour avant tube
        if ($angle_deg > 90 && $angle_deg < 270)
        {
            $this->logger->log_user_action('tank_svg_generator', 'flange_behind_tube', [], $user_id);
            return $flange_ellipses . $flange_rect;
        }

        // Sinon, bride devant → retour après tube
        $this->logger->log_user_action('tank_svg_generator', 'flange_in_front_of_tube', [], $user_id);
        return $flange_rect . $flange_ellipses . $flange_holes;
    }

    /****************************** Gestion des accessoires ******************************************************************************/
    protected function render_fitting_with_accessories($fitting, $diameter, $tank_height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_fitting_with_accessories_start', [
            'fitting' => $fitting,
            'diameter' => $diameter,
            'tank_height' => $tank_height
        ], $user_id);

        $svg = '';

        // Vérifie s’il y a des accessoires
        if (!empty($fitting->id_accessories))
        {
            $this->logger->log_user_action('tank_svg_generator', 'fitting_has_accessories', ['id_accessories' => $fitting->id_accessories], $user_id);

            if ($fitting->id_accessories == 16)
            {
                $plate_svg = $this->render_plate_svg($fitting, $diameter, $tank_height);
                $svg .= $plate_svg;
                $this->logger->log_user_action('tank_svg_generator', 'plate_accessory_rendered', [], $user_id);
            }
        }
        else
        {
            $this->logger->log_user_action('tank_svg_generator', 'no_accessories_for_fitting', [], $user_id);
        }

        $this->logger->log_user_action('tank_svg_generator', 'render_fitting_with_accessories_complete', [], $user_id);
        return $svg;
    }

    protected function render_plate_svg($fitting, $diameter, $tank_height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_plate_svg_start', [
            'fitting' => $fitting,
            'diameter' => $diameter,
            'tank_height' => $tank_height
        ], $user_id);

        $insulation = 160;
        $plate_height = 10;

        $r = $fitting->InternalDiamter / 2;
        $fitting_height = $fitting->Height ?? 1000;
        $angle_deg = intval($fitting->Angle);
        $angle_rad = deg2rad($angle_deg);
        $base_cx = $diameter / 2;

        $this->logger->log_user_action('tank_svg_generator', 'plate_parameters_set', [
            'insulation' => $insulation,
            'plate_height' => $plate_height,
            'r' => $r,
            'fitting_height' => $fitting_height,
            'angle_deg' => $angle_deg,
            'base_cx' => $base_cx
        ], $user_id);

        $plate_length = 200;
        $tiltAdjustedPlateLength = $plate_length * cos(((($angle_deg - 90) / -90) * M_PI) / 2);

        $this->logger->log_user_action('tank_svg_generator', 'plate_length_calculated', [
            'plate_length' => $plate_length,
            'tiltAdjustedPlateLength' => $tiltAdjustedPlateLength
        ], $user_id);

        // Calcul des positions comme dans render_threaded_fitting_svg
        $cx = $base_cx * (1 + cos($angle_rad - M_PI_2));
        $tiltAdjustedLength = $insulation * cos(((($angle_deg - 90) / -90) * M_PI) / 2);
        $cx2 = $cx + (($angle_deg > 90 && $angle_deg <= 270) ? -abs($tiltAdjustedLength) : abs($tiltAdjustedLength));
        $cy = $tank_height - $fitting_height - ($r);

        $this->logger->log_user_action('tank_svg_generator', 'plate_position_calculated', [
            'cx' => $cx,
            'cx2' => $cx2,
            'cy' => $cy,
            'tiltAdjustedLength' => $tiltAdjustedLength
        ], $user_id);

        $x_rect1 = ($angle_deg > 90 && $angle_deg <= 270) ? ($cx2 + $tiltAdjustedPlateLength) : ($cx - $tiltAdjustedPlateLength);
        $y_rect1 = $cy - ($plate_height);

        $this->logger->log_user_action('tank_svg_generator', 'plate_rectangle_position_calculated', [
            'x_rect1' => $x_rect1,
            'y_rect1' => $y_rect1
        ], $user_id);

        $svg = "<rect x='{$x_rect1}' y='{$y_rect1}' width='{$tiltAdjustedPlateLength}' height='{$plate_height}' fill='grey' opacity='0.7' />";
        $svg .= "<rect x='{$x_rect1}' y='{$y_rect1}' width='{$plate_height}' height='" . ($r*2) . "' fill='grey' opacity='0.5' />";

        $this->logger->log_user_action('tank_svg_generator', 'plate_rectangles_added', [], $user_id);
        $this->logger->log_user_action('tank_svg_generator', 'render_plate_svg_complete', [], $user_id);

        return $svg;
    }

    /****************************** Gestion de la soudure ******************************************************************************/
    protected $weldings = [];

    protected function loadWeldings($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'loadWeldings_start', ['article_id' => $article_id], $user_id);

        $conn_table = $this->table_connections;
        $dim_table = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 23",
            $article_id
        );

        $this->logger->log_db_change('tank_svg_generator', $conn_table, 'FETCH_WELDINGS', ['article_id' => $article_id, 'sql' => $sql], $user_id);

        $this->weldings = $this->wpdb->get_results($sql);
        $this->logger->log_user_action('tank_svg_generator', 'weldings_loaded', ['article_id' => $article_id, 'count' => count($this->weldings)], $user_id);
    }

    protected function render_weldings_svg($diameter, $height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_weldings_svg_start', ['diameter' => $diameter, 'height' => $height], $user_id);

        if (empty($this->weldings))
        {
            $this->logger->log_user_action('tank_svg_generator', 'no_weldings_to_render', [], $user_id);
            return '';
        }

        $css = 'stroke: red; stroke-width: 3; stroke-dasharray: 50,50;';
        $svg = '';

        $this->logger->log_user_action('tank_svg_generator', 'welding_css_style_set', [], $user_id);

        foreach ($this->weldings as $weld)
        {
            $this->logger->log_user_action('tank_svg_generator', 'processing_welding', ['weld' => $weld], $user_id);

            // On récupère la hauteur depuis la BDD
            $y = $height - floatval($weld->Height); // en SVG, l’origine est en haut, on inverse la hauteur

            $this->logger->log_user_action('tank_svg_generator', 'welding_y_position_calculated', ['y' => $y], $user_id);

            // On dessine une ligne horizontale rouge sur toute la largeur de la cuve
            $svg .= '<line x1="0" y1="' . $y . '" x2="' . $diameter . '" y2="' . $y . '" style="' . $css . '" />';
            $this->logger->log_user_action('tank_svg_generator', 'welding_line_added', [], $user_id);
        }

        $this->logger->log_user_action('tank_svg_generator', 'render_weldings_svg_complete', [], $user_id);
        return $svg;
    }

    /****************************** Gestion des tôles perforées ******************************************************************************/
    protected $drilled_plate = [];

    protected function loadPlate($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'loadPlate_start', ['article_id' => $article_id], $user_id);

        $conn_table = $this->table_connections;
        $dim_table = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 22",
            $article_id
        );

        $this->logger->log_db_change('tank_svg_generator', $conn_table, 'FETCH_DRILLED_PLATES', ['article_id' => $article_id, 'sql' => $sql], $user_id);

        $this->drilled_plate = $this->wpdb->get_results($sql);
        $this->logger->log_user_action('tank_svg_generator', 'drilled_plates_loaded', ['article_id' => $article_id, 'count' => count($this->drilled_plate)], $user_id);
    }

    protected function render_drilled_plate_svg($diameter, $height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_drilled_plate_svg_start', ['diameter' => $diameter, 'height' => $height], $user_id);

        if (empty($this->drilled_plate))
        {
            $this->logger->log_user_action('tank_svg_generator', 'no_drilled_plates_to_render', [], $user_id);
            return '';
        }

        $css = 'stroke: green; stroke-width: 5; stroke-dasharray: 70,30;';
        $svg = '';

        $this->logger->log_user_action('tank_svg_generator', 'drilled_plate_css_style_set', [], $user_id);

        foreach ($this->drilled_plate as $plate)
        {
            $this->logger->log_user_action('tank_svg_generator', 'processing_drilled_plate', ['plate' => $plate], $user_id);

            // On récupère la hauteur depuis la BDD
            $y = $height - floatval($plate->Height); // en SVG, l’origine est en haut, on inverse la hauteur

            $this->logger->log_user_action('tank_svg_generator', 'drilled_plate_y_position_calculated', ['y' => $y], $user_id);

            // On dessine une ligne horizontale verte sur toute la largeur de la cuve
            $svg .= '<line x1="0" y1="' . $y . '" x2="' . $diameter . '" y2="' . $y . '" style="' . $css . '" />';
            $this->logger->log_user_action('tank_svg_generator', 'drilled_plate_line_added', [], $user_id);
        }

        $this->logger->log_user_action('tank_svg_generator', 'render_drilled_plate_svg_complete', [], $user_id);
        return $svg;
    }

    /****************************** Creation de dégradé ******************************************************************************/
    protected function defs($is_sketch = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'defs_start', ['is_sketch' => $is_sketch], $user_id);

        if (!$is_sketch)
        {
            $defs = '
            <defs>
                <linearGradient id="cylinderBody" x1="0" x2="1" y1="0" y2="0">
                    <stop offset="0%" stop-color="#bbb" />
                    <stop offset="50%" stop-color="#eee" />
                    <stop offset="100%" stop-color="#999" />
                </linearGradient>

                <linearGradient id="fittingsBody" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="#bbb" />
                    <stop offset="50%" stop-color="#eee" />
                    <stop offset="100%" stop-color="#999" />
                </linearGradient>

                <radialGradient id="topEllipse" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#fff" />
                    <stop offset="100%" stop-color="#ccc" />
                </radialGradient>

                <radialGradient id="bottomEllipse" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#444" stop-opacity="0.3" />
                    <stop offset="100%" stop-color="#000" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="highlight" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="white" stop-opacity="0.6"/>
                    <stop offset="100%" stop-color="white" stop-opacity="0"/>
                </linearGradient>
            </defs>';

            $this->logger->log_user_action('tank_svg_generator', 'defs_generated_normal_mode', [], $user_id);
            return $defs;
        }
        else
        {
            $defs = '
            <defs>
                <linearGradient id="cylinderBody" x1="0" x2="1" y1="0" y2="0">
                    <stop offset="0%" stop-color="#fff" />
                    <stop offset="50%" stop-color="#fff" />
                    <stop offset="100%" stop-color="#fff" />
                </linearGradient>

                <linearGradient id="fittingsBody" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="#fff" />
                    <stop offset="50%" stop-color="#fff" />
                    <stop offset="100%" stop-color="#fff" />
                </linearGradient>

                <radialGradient id="topEllipse" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#fff" />
                    <stop offset="100%" stop-color="#fff" />
                </radialGradient>

                <radialGradient id="bottomEllipse" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#fff" stop-opacity="0.3" />
                    <stop offset="100%" stop-color="#fff" stop-opacity="0" />
                </radialGradient>

                <linearGradient id="highlight" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="white" stop-opacity="0.6"/>
                    <stop offset="100%" stop-color="white" stop-opacity="0"/>
                </linearGradient>
            </defs>';

            $this->logger->log_user_action('tank_svg_generator', 'defs_generated_sketch_mode', [], $user_id);
            return $defs;
        }
    }

    /******************************DESSIN DU SVG DE LA CUVE ******************************************************************************/
    public function render_svg($with_cotation = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_svg_start', ['with_cotation' => $with_cotation], $user_id);

        $insulation = 160;
        if (!$this->tank_data || !isset($this->tank_data['dimensions']))
        {
            $this->logger->log('tank_svg_generator', 'ERROR: No tank data or dimensions found', $user_id);
            return '';
        }

        $this->logger->log_user_action('tank_svg_generator', 'tank_data_validated', [], $user_id);

        $d = $this->tank_data['dimensions'];
        $jsonString = file_get_contents(__DIR__ . '/../assets/json/tank_data.json');
        $data = json_decode($jsonString, true);

        if (!$d || !$data)
        {
            $this->logger->log('tank_svg_generator', 'ERROR: Failed to load tank data or JSON file', $user_id);
            return '';
        }

        $this->logger->log_user_action('tank_svg_generator', 'tank_data_json_loaded', [], $user_id);

        $d->arrayBottomHeight = $data['arrayBottomHeight'][$this->tank_data['conception']->Material] ?? [];

        $diam = empty($diam) ? $d->Diameter : $diam;
        $height = empty($height) ? $d->Height : $height;

        $this->logger->log_user_action('tank_svg_generator', 'tank_dimensions_set', ['diam' => $diam, 'height' => $height], $user_id);

        // 🔑 NOUVEAU : Récupération de la hauteur du dôme (bottom_height)
        $bottom_height = intval($d->arrayBottomHeight[$d->Diameter] ?? 0);
        $this->logger->log_user_action('tank_svg_generator', 'bottom_height_calculated', ['bottom_height' => $bottom_height], $user_id);

        $dome = round($diam * 0.2);
        $clearance = empty($ground_clearance) ? $d->GroundClearance : $ground_clearance;

        $svg_width = $diam + $this->margin_left + $this->margin_right;
        $svg_height = $dome * 2 + $height + $clearance + 50;

        $this->logger->log_user_action('tank_svg_generator', 'svg_dimensions_calculated', [
            'dome' => $dome,
            'clearance' => $clearance,
            'svg_width' => $svg_width,
            'svg_height' => $svg_height
        ], $user_id);

        ob_start();
        ?>
        <svg viewBox="0 0 <?= $svg_width ?> <?= $svg_height ?>"
            class="responsive-svg"
            xmlns="http://www.w3.org/2000/svg">
            <?= $this->defs(); ?>
            <g transform="translate(<?= $this->margin_left ?>,<?= $dome ?>)">
                <?= $this->designTankBodyPath(); ?>
                <?php
                $this->logger->log_user_action('tank_svg_generator', 'rendering_fittings_start', ['fittings_count' => count($this->fittings)], $user_id);

                foreach ($this->fittings as $fitting)
                {
                    $this->logger->log_user_action('tank_svg_generator', 'rendering_fitting', ['fitting' => $fitting], $user_id);
                    // 🔑 NOUVEAU : On passe $bottom_height à la fonction de rendu
                    echo $this->render_threaded_fitting_svg($fitting, $diam, $insulation, $height, $bottom_height);
                }

                $this->logger->log_user_action('tank_svg_generator', 'rendering_fittings_complete', [], $user_id);

                // ✅ Ajout des tubes coudés
                if (!empty($this->bent_tubes))
                {
                    $this->logger->log_user_action('tank_svg_generator', 'rendering_bent_tubes_start', ['bent_tubes_count' => count($this->bent_tubes)], $user_id);

                    foreach ($this->bent_tubes as $tube)
                    {
                        $this->logger->log_user_action('tank_svg_generator', 'rendering_bent_tube', ['tube' => $tube], $user_id);
                        echo $this->render_bent_tube_svg($tube, $diam, $height);
                    }

                    $this->logger->log_user_action('tank_svg_generator', 'rendering_bent_tubes_complete', [], $user_id);
                }

                $weldings_svg = $this->render_weldings_svg($diam, $height);
                echo $weldings_svg;
                $this->logger->log_user_action('tank_svg_generator', 'weldings_rendered', [], $user_id);

                $plates_svg = $this->render_drilled_plate_svg($diam, $height);
                echo $plates_svg;
                $this->logger->log_user_action('tank_svg_generator', 'drilled_plates_rendered', [], $user_id);

                if ($with_cotation)
                {
                    $cotations_svg = $this->render_cotations_svg($diam, $height);
                    echo $cotations_svg;
                    $this->logger->log_user_action('tank_svg_generator', 'cotations_rendered', [], $user_id);
                }
                ?>
            </g>
        </svg>
        <?php
        $svg_content = ob_get_clean();
        $this->logger->log_user_action('tank_svg_generator', 'render_svg_complete', ['content_length' => strlen($svg_content)], $user_id);

        return $svg_content;
    }

    protected function render_cotations_svg($diam, $height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'render_cotations_svg_start', ['diam' => $diam, 'height' => $height], $user_id);

        $css_line = 'stroke: #000; stroke-width: 2;';
        $css_text = 'font-size: 100; fill: #000; dominant-baseline: middle;';
        $svg = '';

        $this->logger->log_user_action('tank_svg_generator', 'cotation_styles_set', [], $user_id);

        $last_y_positions = [];
        $min_spacing = 30; // espacement vertical minimal
        $drawn_heights = [];

        $this->logger->log_user_action('tank_svg_generator', 'cotation_parameters_set', [
            'min_spacing' => $min_spacing
        ], $user_id);

        $draw_cotation = function($y_val, $label) use ($diam, $height, $css_line, $css_text, &$last_y_positions, $min_spacing, &$svg, &$drawn_heights, $user_id)
        {
            $logger = ISPAG_Logger::get_instance();

            $logger->log_user_action('tank_svg_generator', 'draw_cotation_start', ['y_val' => $y_val, 'label' => $label], $user_id);

            // Skip si hauteur déjà dessinée (tolérance 0.1)
            foreach ($drawn_heights as $h)
            {
                if (abs($h - $y_val) < 0.1)
                {
                    $logger->log_user_action('tank_svg_generator', 'cotation_skipped_duplicate', ['y_val' => $y_val, 'existing_h' => $h], $user_id);
                    return;
                }
            }

            $drawn_heights[] = $y_val;
            $logger->log_user_action('tank_svg_generator', 'cotation_height_added_to_drawn', ['y_val' => $y_val], $user_id);

            $y_real = $height - floatval($y_val); // position réelle

            // Calcul de la position du texte pour éviter collisions
            $y_text = $y_real;
            foreach ($last_y_positions as $prev_y)
            {
                if (abs($y_text - $prev_y) < $min_spacing)
                {
                    $y_text = $prev_y - $min_spacing; // décalage uniquement du texte
                    $logger->log_user_action('tank_svg_generator', 'cotation_text_position_adjusted', ['y_text' => $y_text, 'prev_y' => $prev_y], $user_id);
                }
            }
            $last_y_positions[] = $y_text;
            $logger->log_user_action('tank_svg_generator', 'cotation_text_position_set', ['y_text' => $y_text], $user_id);

            $x_start = $diam + 20;
            $x_mid = $diam + 200;
            $x_text = $diam + 220;

            $logger->log_user_action('tank_svg_generator', 'cotation_x_positions_set', [
                'x_start' => $x_start,
                'x_mid' => $x_mid,
                'x_text' => $x_text
            ], $user_id);

            // Ligne horizontale jusqu'au décrochage
            $svg .= "<line x1='{$x_start}' y1='{$y_real}' x2='{$x_mid}' y2='{$y_real}' style='{$css_line}' />";
            $logger->log_user_action('tank_svg_generator', 'cotation_horizontal_line_added', [], $user_id);

            // Décrochage vertical si texte déplacé
            if ($y_text != $y_real)
            {
                $svg .= "<line x1='{$x_mid}' y1='{$y_real}' x2='{$x_mid}' y2='{$y_text}' style='{$css_line}' />";
                $logger->log_user_action('tank_svg_generator', 'cotation_vertical_line_added', [], $user_id);
            }

            // Ligne horizontale jusqu'au texte
            $svg .= "<line x1='{$x_mid}' y1='{$y_text}' x2='{$x_text}' y2='{$y_text}' style='{$css_line}' />";
            $logger->log_user_action('tank_svg_generator', 'cotation_text_line_added', [], $user_id);

            // Texte
            $svg .= "<text x='" . ($x_text + 20) . "' y='{$y_text}' style='{$css_text}'>{$label}</text>";
            $logger->log_user_action('tank_svg_generator', 'cotation_text_added', [], $user_id);

            $logger->log_user_action('tank_svg_generator', 'draw_cotation_complete', [], $user_id);
        };

        // Cotes cuve
        foreach ($this->tank_cotations as $cotation)
        {
            $this->logger->log_user_action('tank_svg_generator', 'processing_tank_cotation', ['cotation' => $cotation], $user_id);
            $draw_cotation($cotation->Height, round($cotation->Height));
        }

        // Cotes soudures
        foreach ($this->weldings as $weld)
        {
            $this->logger->log_user_action('tank_svg_generator', 'processing_welding_cotation', ['weld' => $weld], $user_id);
            $draw_cotation($weld->Height, round($weld->Height) . " (" . __('Welding', 'creation-reservoir') . ")");
        }

        // Cotes tôles
        foreach ($this->drilled_plate as $plate)
        {
            $this->logger->log_user_action('tank_svg_generator', 'processing_plate_cotation', ['plate' => $plate], $user_id);
            $draw_cotation($plate->Height, round($plate->Height) . " (" . __('Drilled plate', 'creation-reservoir') . ")");
        }

        // Cotes piquages
        foreach ($this->fittings as $fitting)
        {
            $this->logger->log_user_action('tank_svg_generator', 'processing_fitting_cotation', ['fitting' => $fitting], $user_id);
            $label = !empty($fitting->Name) ? $fitting->Name : round($fitting->Height);
            $draw_cotation($fitting->Height, $label);
        }

        $this->logger->log_user_action('tank_svg_generator', 'render_cotations_svg_complete', [], $user_id);
        return $svg;
    }

    public function convert_svg_to_png($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_generator', 'convert_svg_to_png_start', ['article_id' => $article_id], $user_id);

        $upload_dir = wp_upload_dir();
        $svg_dir = trailingslashit($upload_dir['basedir']) . 'ispag-svg/';
        $svg_path = $svg_dir . "cuves_{$article_id}.svg";
        $png_path = $svg_dir . "cuves_{$article_id}.png";

        $this->logger->log_user_action('tank_svg_generator', 'file_paths_prepared', ['svg_path' => $svg_path, 'png_path' => $png_path], $user_id);

        if (extension_loaded('imagick'))
        {
            $this->logger->log_user_action('tank_svg_generator', 'imagick_extension_loaded', [], $user_id);

            try
            {
                $imagick = new Imagick();
                $this->logger->log_user_action('tank_svg_generator', 'imagick_instance_created', [], $user_id);

                $imagick->setBackgroundColor(new ImagickPixel('transparent'));
                $imagick->readImage($svg_path);
                $this->logger->log_user_action('tank_svg_generator', 'svg_image_read', [], $user_id);

                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $imagick->setImageFormat("png");
                $imagick->setImageDepth(8);
                $this->logger->log_user_action('tank_svg_generator', 'image_properties_set', [], $user_id);

                $imagick->writeImage($png_path);
                $this->logger->log_user_action('tank_svg_generator', 'png_image_written', [], $user_id);

                $imagick->clear();
                $imagick->destroy();
                $this->logger->log_user_action('tank_svg_generator', 'imagick_resources_freed', [], $user_id);

                $png_url = trailingslashit($upload_dir['baseurl']) . 'ispag-svg/cuves_' . $article_id . '.png';
                $this->logger->log_user_action('tank_svg_generator', 'convert_svg_to_png_complete_imagick', ['png_url' => $png_url], $user_id);

                return $png_url;
            }
            catch (Exception $e)
            {
                $this->logger->log('tank_svg_generator', 'ERROR: Imagick conversion failed - ' . $e->getMessage(), $user_id);
                // error_log("Imagick conversion failed: " . $e->getMessage());
            }
        }
        else
        {
            $this->logger->log_user_action('tank_svg_generator', 'imagick_not_loaded_using_rsvg', [], $user_id);
        }

        $cmd = "rsvg-convert -w 1000 -h 1000 -f png -o " . escapeshellarg($png_path) . " " . escapeshellarg($svg_path);
        $this->logger->log_user_action('tank_svg_generator', 'rsvg_command_prepared', ['command' => $cmd], $user_id);

        exec($cmd, $output, $return_var);
        $this->logger->log_user_action('tank_svg_generator', 'rsvg_command_executed', ['output' => $output, 'return_var' => $return_var], $user_id);

        if ($return_var === 0)
        {
            $png_url = trailingslashit($upload_dir['baseurl']) . 'ispag-svg/cuves_' . $article_id . '.png';
            $this->logger->log_user_action('tank_svg_generator', 'convert_svg_to_png_complete_rsvg', ['png_url' => $png_url], $user_id);

            return $png_url;
        }
        else
        {
            $error = error_get_last();
            $error_message = $error ? $error['message'] : 'Unknown error';
            $this->logger->log('tank_svg_generator', 'ERROR: rsvg conversion failed - ' . $error_message, $user_id, ['return_var' => $return_var, 'output' => $output]);

            throw new Exception("Conversion SVG vers PNG échouée.");
        }
    }
}