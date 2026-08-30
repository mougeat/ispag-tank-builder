<?php
/**
 * Class ISPAG_Tank_SVG_Top_View_Generator
 * Génère une vue de dessus en SVG pour les réservoirs ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_tank_svg_top_view_generator.log.
 */
class ISPAG_Tank_SVG_Top_View_Generator
{
    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    protected $fittings = [];
    protected $diameter;
    protected $height;
    protected $insulation;
    protected $tank_data;
    protected static $instance = null;
    protected $margin_left = 100;  // marge gauche en mm
    protected $margin_right = 1000; // marge droite en mm

    public function __construct($diameter, $insulation = 160, $fittings = [], $height = 2000)
    {
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->diameter = $diameter;
        $this->height = $height;
        $this->insulation = $insulation;
        $this->fittings = $fittings;

        $this->logger->log_user_action('tank_svg_top_view_generator', 'class_constructed', [
            'diameter' => $diameter,
            'insulation' => $insulation,
            'height' => $height,
            'fittings_count' => count($fittings)
        ], $user_id);
    }

    public static function init()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null)
        {
            self::$instance = new self(3);
            $logger->log_user_action('tank_svg_top_view_generator', 'instance_initialized', [], $user_id);
        }

        add_filter('ispag_design_tank_top_view_svg', [self::$instance, 'design_tank_top_view'], 10, 2);
        add_filter('ispag_get_tank_top_view_svg', [self::$instance, 'get_tank_top_view_svg'], 10, 2);
        add_filter('ispag_get_tank_top_view_png', [self::$instance, 'get_tank_top_view_png'], 10, 2);

        $logger->log_user_action('tank_svg_top_view_generator', 'filters_registered', [], $user_id);
    }

    public function load_data($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'load_data_start', ['article_id' => $article_id], $user_id);

        $designer = new ISPAG_Tank_Designer();
        $this->tank_data = $designer->get_tank_data(null, $article_id);

        $this->logger->log_user_action('tank_svg_top_view_generator', 'tank_data_loaded', ['article_id' => $article_id], $user_id);
    }

    protected function loadFittings($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'loadFittings_start', ['article_id' => $article_id], $user_id);

        $tank_fittings = new ISPAG_Tank_Fittings();
        $this->fittings = $tank_fittings->get_all_fittings($article_id);

        $this->logger->log_user_action('tank_svg_top_view_generator', 'fittings_loaded', ['article_id' => $article_id, 'fittings_count' => count($this->fittings)], $user_id);
    }

    public function design_tank_top_view($html, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'design_tank_top_view_start', ['article_id' => $article_id], $user_id);

        $this->load_data($article_id);
        $this->loadFittings($article_id);

        if (empty($this->tank_data) || empty($this->tank_data['dimensions']))
        {
            $this->logger->log('tank_svg_top_view_generator', 'ERROR: No tank data or dimensions found', $user_id, ['article_id' => $article_id]);
            return '';
        }

        $d = $this->tank_data['dimensions'];
        $this->logger->log_user_action('tank_svg_top_view_generator', 'tank_dimensions_retrieved', ['diameter' => $d->Diameter, 'height' => $d->Height], $user_id);

        $generator = new ISPAG_Tank_SVG_Top_View_Generator($d->Diameter, 160, $this->fittings, $d->Height);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'generator_instance_created', ['diameter' => $d->Diameter, 'height' => $d->Height, 'fittings_count' => count($this->fittings)], $user_id);

        $svg = $generator->render_svg();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'design_tank_top_view_complete', [], $user_id);

        return $svg;
    }

    public function get_tank_top_view_svg($html, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'get_tank_top_view_svg_start', ['article_id' => $article_id], $user_id);

        $svg_content = $this->design_tank_top_view(null, $article_id);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_content_generated', ['article_id' => $article_id, 'content_length' => strlen($svg_content)], $user_id);

        $svg_url = $this->save_svg_file($svg_content, $article_id);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'get_tank_top_view_svg_complete', ['svg_url' => $svg_url], $user_id);

        return $svg_url;
    }

    public function get_tank_top_view_png($html, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'get_tank_top_view_png_start', ['article_id' => $article_id], $user_id);

        $svg_content = $this->design_tank_top_view(null, $article_id);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_content_generated', ['article_id' => $article_id, 'content_length' => strlen($svg_content)], $user_id);

        $svg_url = $this->save_svg_file($svg_content, $article_id);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_file_saved', ['svg_url' => $svg_url], $user_id);

        $png_url = $this->convert_svg_to_png($article_id);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'get_tank_top_view_png_complete', ['png_url' => $png_url], $user_id);

        return $png_url;
    }

    protected function save_svg_file($svg_content, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'save_svg_file_start', ['article_id' => $article_id], $user_id);

        // Utiliser le dossier uploads de WordPress
        $upload_dir = wp_upload_dir();
        $svg_dir = trailingslashit($upload_dir['basedir']) . 'ispag-svg/';

        $this->logger->log_user_action('tank_svg_top_view_generator', 'upload_dir_retrieved', ['basedir' => $upload_dir['basedir'], 'baseurl' => $upload_dir['baseurl']], $user_id);

        // Créer le dossier s'il n'existe pas
        if (!file_exists($svg_dir))
        {
            wp_mkdir_p($svg_dir);
            $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_directory_created', ['svg_dir' => $svg_dir], $user_id);
        }

        $svg_path = $svg_dir . "cuves_top_view_{$article_id}.svg";
        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_path_prepared', ['svg_path' => $svg_path], $user_id);

        $bytes_written = file_put_contents($svg_path, $svg_content);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_file_written', ['bytes_written' => $bytes_written], $user_id);

        // Retourner l'URL publique
        $svg_url = trailingslashit($upload_dir['baseurl']) . 'ispag-svg/cuves_top_view_' . $article_id . '.svg';
        $this->logger->log_user_action('tank_svg_top_view_generator', 'save_svg_file_complete', ['svg_url' => $svg_url], $user_id);

        return $svg_url;
    }

    public function convert_svg_to_png($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'convert_svg_to_png_start', ['article_id' => $article_id], $user_id);

        $upload_dir = wp_upload_dir();
        $svg_dir = trailingslashit($upload_dir['basedir']) . 'ispag-svg/';
        $svg_path = $svg_dir . "cuves_top_view_{$article_id}.svg";
        $png_path = $svg_dir . "cuves_top_view_{$article_id}.png";

        $this->logger->log_user_action('tank_svg_top_view_generator', 'file_paths_prepared', ['svg_path' => $svg_path, 'png_path' => $png_path], $user_id);

        if (extension_loaded('imagick'))
        {
            $this->logger->log_user_action('tank_svg_top_view_generator', 'imagick_extension_loaded', [], $user_id);

            try
            {
                $imagick = new Imagick();
                $this->logger->log_user_action('tank_svg_top_view_generator', 'imagick_instance_created', [], $user_id);

                $imagick->setBackgroundColor(new ImagickPixel('transparent'));
                $imagick->readImage($svg_path);
                $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_image_read', [], $user_id);

                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $imagick->setImageFormat("png");
                $imagick->setImageDepth(8);
                $this->logger->log_user_action('tank_svg_top_view_generator', 'image_properties_set', [], $user_id);

                $imagick->writeImage($png_path);
                $this->logger->log_user_action('tank_svg_top_view_generator', 'png_image_written', [], $user_id);

                $imagick->clear();
                $imagick->destroy();
                $this->logger->log_user_action('tank_svg_top_view_generator', 'imagick_resources_freed', [], $user_id);

                $png_url = trailingslashit($upload_dir['baseurl']) . 'ispag-svg/cuves_top_view_' . $article_id . '.png';
                $this->logger->log_user_action('tank_svg_top_view_generator', 'convert_svg_to_png_complete_imagick', ['png_url' => $png_url], $user_id);

                return $png_url;
            }
            catch (Exception $e)
            {
                $this->logger->log('tank_svg_top_view_generator', 'ERROR: Imagick conversion failed - ' . $e->getMessage(), $user_id);
                // error_log("Imagick conversion failed: " . $e->getMessage());
            }
        }
        else
        {
            $this->logger->log_user_action('tank_svg_top_view_generator', 'imagick_not_loaded_using_rsvg', [], $user_id);
        }

        $cmd = "rsvg-convert -a -w 1000 -h 1000 -f png -o " . escapeshellarg($png_path) . " " . escapeshellarg($svg_path);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'rsvg_command_prepared', ['command' => $cmd], $user_id);

        exec($cmd, $output, $return_var);
        $this->logger->log_user_action('tank_svg_top_view_generator', 'rsvg_command_executed', ['output' => $output, 'return_var' => $return_var], $user_id);

        if ($return_var === 0)
        {
            $png_url = trailingslashit($upload_dir['baseurl']) . 'ispag-svg/cuves_top_view_' . $article_id . '.png';
            $this->logger->log_user_action('tank_svg_top_view_generator', 'convert_svg_to_png_complete_rsvg', ['png_url' => $png_url], $user_id);

            return $png_url;
        }
        else
        {
            $error = error_get_last();
            $error_message = $error ? $error['message'] : 'Unknown error';
            $this->logger->log('tank_svg_top_view_generator', 'ERROR: rsvg conversion failed - ' . $error_message, $user_id, ['return_var' => $return_var, 'output' => $output]);

            throw new Exception("Conversion SVG vers PNG échouée.");
        }
    }

    public function render_svg()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'render_svg_start', [], $user_id);

        $diam = $this->diameter;
        $height = $this->height;

        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_dimensions_calculated', ['diam' => $diam, 'height' => $height], $user_id);

        $cx = $cy = $diam / 2 + $this->insulation + $this->margin_left;
        $svg_width = $diam + $this->margin_left + $this->margin_right;
        $svg_height = $diam + $this->insulation * 2 + 100;

        $this->logger->log_user_action('tank_svg_top_view_generator', 'svg_viewbox_calculated', [
            'cx' => $cx,
            'cy' => $cy,
            'svg_width' => $svg_width,
            'svg_height' => $svg_height
        ], $user_id);

        ob_start();
        ?>
        <svg viewBox="0 0 <?= $svg_width ?> <?= $svg_width ?>"
             class="responsive-svg-top"
             xmlns="http://www.w3.org/2000/svg">

            <defs>
                <linearGradient id="cylinderBody" x1="0" x2="1" y1="0" y2="0">
                    <stop offset="0%" stop-color="#bbb" />
                    <stop offset="50%" stop-color="#eee" />
                    <stop offset="100%" stop-color="#999" />
                </linearGradient>
                <linearGradient id="fittingBody" x1="0" x2="1" y1="0" y2="0">
                    <stop offset="0%" stop-color="#aaa" />
                    <stop offset="50%" stop-color="#ddd" />
                    <stop offset="100%" stop-color="#888" />
                </linearGradient>
            </defs>

            <g transform="translate(<?= $this->margin_left ?>, <?= $this->insulation + 50 ?>)">
                <!-- Cercle principal de la cuve -->
                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $diam/2 ?>" stroke="black" stroke-width="1" fill="url(#cylinderBody)" />
                <!-- Cercle d'isolation -->
                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $diam/2 + $this->insulation ?>" stroke="#999" stroke-dasharray="5,5" fill="none" />

                <?php
                $this->logger->log_user_action('tank_svg_top_view_generator', 'rendering_fittings_start', ['fittings_count' => count($this->fittings)], $user_id);

                foreach ($this->fittings as $fitting)
                {
                    $this->logger->log_user_action('tank_svg_top_view_generator', 'rendering_fitting', ['fitting' => $fitting], $user_id);
                    echo $this->render_fitting($fitting, $cx, $cy, $diam / 2, $height);
                }

                $this->logger->log_user_action('tank_svg_top_view_generator', 'rendering_fittings_complete', [], $user_id);
                ?>
            </g>
        </svg>
        <?php
        $svg_content = ob_get_clean();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'render_svg_complete', ['content_length' => strlen($svg_content)], $user_id);

        return $svg_content;
    }

    protected function render_fitting($fitting, $cx, $cy, $radius, $tank_height = 2000)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('tank_svg_top_view_generator', 'render_fitting_start', [
            'fitting' => $fitting,
            'cx' => $cx,
            'cy' => $cy,
            'radius' => $radius,
            'tank_height' => $tank_height
        ], $user_id);

        $angle_deg = $fitting->Angle ?? 0;
        $angle_rad = deg2rad(360 - $angle_deg + 180); // Conversion pour la vue de dessus
        $svg_angle = 180 - $angle_deg;

        $this->logger->log_user_action('tank_svg_top_view_generator', 'fitting_angles_calculated', [
            'angle_deg' => $angle_deg,
            'angle_rad' => $angle_rad,
            'svg_angle' => $svg_angle
        ], $user_id);

        // =====================================================================
        // 👉 DÉTECTION DU RACCORD VERTICAL (Plus haut que la cuve)
        // =====================================================================
        $is_vertical_top = false;
        if (isset($fitting->Height) && $fitting->Height > $tank_height)
        {
            $angle_deg = 90;
            $is_vertical_top = true;
            $this->logger->log_user_action('tank_svg_top_view_generator', 'vertical_top_fitting_detected', ['original_angle' => $fitting->Angle, 'new_angle' => $angle_deg], $user_id);
        }

        // =====================================================================
        // 👉 TRAITEMENT DU CAS VERTICAL (Sur le dôme supérieur)
        // =====================================================================
        if ($is_vertical_top)
        {
            $internal_diam = $fitting->InternalDiamter ?? 50;
            $svg = "<ellipse cx='{$cx}' cy='{$cy}' rx='{$internal_diam}' ry='{$internal_diam}' fill='url(#fittingBody)' />";
            $this->logger->log_user_action('tank_svg_top_view_generator', 'vertical_fitting_rendered', ['internal_diam' => $internal_diam], $user_id);

            return $svg;
        }

        // =====================================================================
        // 👉 CAS STANDARD (Raccord latéral)
        // =====================================================================
        // Point de départ sur le bord de la cuve
        $decalage = 50; // Décalage réduit pour éviter que les raccords ne soient trop longs
        $start_radius = $radius - $decalage;
        $x1 = $cx + $start_radius * sin($angle_rad);
        $y1 = $cy - $start_radius * cos($angle_rad);

        $this->logger->log_user_action('tank_svg_top_view_generator', 'fitting_start_point_calculated', [
            'start_radius' => $start_radius,
            'x1' => $x1,
            'y1' => $y1
        ], $user_id);

        // Longueur du raccord
        $length = $this->insulation + $decalage;
        if (isset($fitting->Type) && in_array($fitting->Type, [24]))
        { // Type 24 = Flange
            $length += 30;
            $this->logger->log_user_action('tank_svg_top_view_generator', 'flange_length_adjusted', ['original_length' => $this->insulation + $decalage, 'new_length' => $length], $user_id);
        }

        // Point d'arrivée
        $x2 = $cx + ($start_radius + $length) * sin($angle_rad);
        $y2 = $cy - ($start_radius + $length) * cos($angle_rad);

        $this->logger->log_user_action('tank_svg_top_view_generator', 'fitting_end_point_calculated', ['x2' => $x2, 'y2' => $y2], $user_id);

        // Centre du rectangle raccord
        $rect_cx = ($x1 + $x2) / 2;
        $rect_cy = ($y1 + $y2) / 2;

        $this->logger->log_user_action('tank_svg_top_view_generator', 'fitting_rect_center_calculated', ['rect_cx' => $rect_cx, 'rect_cy' => $rect_cy], $user_id);

        // Largeur du raccord
        $width = $fitting->InternalDiamter ?? 20;
        $this->logger->log_user_action('tank_svg_top_view_generator', 'fitting_width_set', ['width' => $width], $user_id);

        // Bride (si applicable)
        $flange_svg = '';
        if (isset($fitting->Type) && in_array($fitting->Type, [24]))
        {
            $flange_width = $fitting->ExternalDiameter ?? $width + 20;
            $flange_thickness = $fitting->Thickness ?? 10;

            // Position de la bride (au bout du raccord)
            $flange_x = - $flange_width / 2;
            $flange_y = - ($length / 2) - ($flange_thickness / 2);

            $flange_svg = "<rect x='$flange_x' y='$flange_y' width='$flange_width' height='$flange_thickness' fill='#777' />";
            $this->logger->log_user_action('tank_svg_top_view_generator', 'flange_svg_generated', ['flange_width' => $flange_width, 'flange_thickness' => $flange_thickness], $user_id);
        }

        // Retourner le groupe avec rotation
        $svg = "
            <g transform='translate($rect_cx, $rect_cy) rotate($svg_angle)'>
                <rect x='" . (-$width / 2) . "' y='" . (-$length / 2) . "' width='$width' height='$length' fill='url(#fittingBody)' />
                $flange_svg
            </g>
        ";

        $this->logger->log_user_action('tank_svg_top_view_generator', 'fitting_svg_generated', [], $user_id);
        return $svg;
    }
}