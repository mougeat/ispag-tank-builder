<?php

class ISPAG_Tank_SVG_Top_View_Generator {
    protected $fittings = [];
    protected $diameter;
    protected $height;
    protected $insulation;
    protected $tank_data;
    protected static $instance = null;
    protected $margin_left = 100;  // marge gauche en mm
    protected $margin_right = 1000; // marge droite en mm

    public function __construct($diameter, $insulation = 160, $fittings = [], $height = 2000) {
        $this->diameter = $diameter;
        $this->height = $height;
        $this->insulation = $insulation;
        $this->fittings = $fittings;
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self(3);
        }
        add_filter('ispag_design_tank_top_view_svg', [self::$instance, 'design_tank_top_view'], 10, 2);
        add_filter('ispag_get_tank_top_view_svg', [self::$instance, 'get_tank_top_view_svg'], 10, 2);
        add_filter('ispag_get_tank_top_view_png', [self::$instance, 'get_tank_top_view_png'], 10, 2);
    }

    public function load_data($article_id) {
        $designer = new ISPAG_Tank_Designer();
        $this->tank_data = $designer->get_tank_data(null, $article_id);
    }

    protected function loadFittings($article_id) {
        $tank_fittings = new ISPAG_Tank_Fittings();
        $this->fittings = $tank_fittings->get_all_fittings($article_id);
    }

    public function design_tank_top_view($html, $article_id) {
        $this->load_data($article_id);
        $this->loadFittings($article_id);

        $d = $this->tank_data['dimensions'];
        $generator = new ISPAG_Tank_SVG_Top_View_Generator($d->Diameter, 160, $this->fittings, $d->Height);
        return $generator->render_svg();
    }

    public function get_tank_top_view_svg($html, $article_id) {
        $svg_content = $this->design_tank_top_view(null, $article_id);
        return $this->save_svg_file($svg_content, $article_id);
    }

    public function get_tank_top_view_png($html, $article_id) {
        $svg_content = $this->design_tank_top_view(null, $article_id);
        $this->save_svg_file($svg_content, $article_id);
        return $this->convert_svg_to_png($article_id);
    }

    protected function save_svg_file($svg_content, $article_id) {
        $svg_path = plugin_dir_path(__FILE__) . "../assets/svg/cuves/cuves_top_view_$article_id.svg";
        if (!file_exists(dirname($svg_path))) {
            mkdir(dirname($svg_path), 0755, true);
        }
        file_put_contents($svg_path, $svg_content);
        return plugin_dir_url(__FILE__) . "../assets/svg/cuves/cuves_top_view_$article_id.svg";
    }

    public function convert_svg_to_png($article_id) {
        $svg_path = plugin_dir_path(__FILE__) . "../assets/svg/cuves/cuves_top_view_$article_id.svg";
        $png_path = plugin_dir_path(__FILE__) . "../assets/svg/cuves/cuves_top_view_$article_id.png";

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setBackgroundColor(new ImagickPixel('transparent'));
                $imagick->readImage($svg_path);
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $imagick->setImageFormat("png");
                $imagick->setImageDepth(8);
                $imagick->writeImage($png_path);
                $imagick->clear();
                $imagick->destroy();
                return plugin_dir_url(__FILE__) . "../assets/svg/cuves/cuves_top_view_$article_id.png";
            } catch (Exception $e) {
                error_log("Imagick conversion failed: " . $e->getMessage());
            }
        }

        $cmd = "rsvg-convert -a -w 1000 -h 1000 -f png -o " . escapeshellarg($png_path) . " " . escapeshellarg($svg_path);
        exec($cmd, $output, $return_var);
        if ($return_var === 0) {
            return plugin_dir_url(__FILE__) . "../assets/svg/cuves/cuves_top_view_$article_id.png";
        } else {
            throw new Exception("Conversion SVG vers PNG échouée.");
        }
    }

    public function render_svg() {
        $diam = $this->diameter;
        $height = $this->height;

        $cx = $cy = $diam / 2 + $this->insulation + $this->margin_left;
        $svg_width = $diam + $this->margin_left + $this->margin_right;
        $svg_height = $diam + $this->insulation * 2 + 100;

        ob_start(); ?>
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

                <?php foreach ($this->fittings as $fitting): ?>
                    <?= $this->render_fitting($fitting, $cx, $cy, $diam / 2, $height); ?>
                <?php endforeach; ?>
            </g>
        </svg>
        <?php
        return ob_get_clean();
    }

    protected function render_fitting($fitting, $cx, $cy, $radius, $tank_height = 2000) {
        $angle_deg = $fitting->Angle ?? 0;
        $angle_rad = deg2rad(360 - $angle_deg + 180); // Conversion pour la vue de dessus
        $svg_angle = 180 - $angle_deg;

        // =====================================================================
        // 👉 DÉTECTION DU RACCORD VERTICAL (Plus haut que la cuve)
        // =====================================================================
        $is_vertical_top = false;
        if (isset($fitting->Height) && $fitting->Height > $tank_height) {
            $angle_deg = 90;
            $is_vertical_top = true;
        }

        // =====================================================================
        // 👉 TRAITEMENT DU CAS VERTICAL (Sur le dôme supérieur)
        // =====================================================================
        if ($is_vertical_top) {
            $internal_diam = $fitting->InternalDiamter ?? 50;
            $svg = "<ellipse cx='{$cx}' cy='{$cy}' rx='{$internal_diam}' ry='{$internal_diam}' fill='url(#fittingBody)' />";
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

        // Longueur du raccord
        $length = $this->insulation + $decalage;
        if (isset($fitting->Type) && in_array($fitting->Type, [24])) { // Type 24 = Flange
            $length += 30;
        }

        // Point d'arrivée
        $x2 = $cx + ($start_radius + $length) * sin($angle_rad);
        $y2 = $cy - ($start_radius + $length) * cos($angle_rad);

        // Centre du rectangle raccord
        $rect_cx = ($x1 + $x2) / 2;
        $rect_cy = ($y1 + $y2) / 2;

        // Largeur du raccord
        $width = $fitting->InternalDiamter ?? 20;

        // Bride (si applicable)
        $flange_svg = '';
        if (isset($fitting->Type) && in_array($fitting->Type, [24])) {
            $flange_width = $fitting->ExternalDiameter ?? $width + 20;
            $flange_thickness = $fitting->Thickness ?? 10;

            // Position de la bride (au bout du raccord)
            $flange_x = - $flange_width / 2;
            $flange_y = - ($length / 2) - ($flange_thickness / 2);

            $flange_svg = "<rect x='$flange_x' y='$flange_y' width='$flange_width' height='$flange_thickness' fill='#777' />";
        }

        // Retourner le groupe avec rotation
        return "
            <g transform='translate($rect_cx, $rect_cy) rotate($svg_angle)'>
                <rect x='" . (-$width / 2) . "' y='" . (-$length / 2) . "' width='$width' height='$length' fill='url(#fittingBody)' />
                $flange_svg
            </g>
        ";
    }
}