<?php

class ISPAG_Tank_SVG_Generator {
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

    protected $margin_left;  // marge gauche en mm
    protected $margin_right; // marge droite en mm

    public function __construct($margin_left = 200, $margin_right = 1000) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_dimension = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';

        $this->margin_left = $margin_left;
        $this->margin_right = $margin_right;
    }

    // Ajout d'une nouvelle méthode pour charger les piquages depuis la BDD
    protected function loadFittings($article_id) {
        $tank_fittings = new ISPAG_Tank_Fittings();
        $this->fittings = $tank_fittings->get_all_fittings($article_id);

    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_tank_svg', [self::$instance, 'get_tank_svg'], 10, 3);
        add_filter('ispag_design_tank_svg', [self::$instance, 'design_tank_svg'], 10, 3);
        add_filter('ispag_get_tank_png', [self::$instance, 'get_tank_png'], 10, 3);
    }

    public function design_tank_svg($html, $article_id, $with_cotation = false) {

        $this->load_data($article_id);
        $this->loadFittings($article_id);
        $this->loadWeldings($article_id);
        $this->loadPlate($article_id);
        return $this->render_svg($with_cotation) ?: $html;


    }

    public function get_tank_svg($html, $article_id, $with_cotation = false) {
        // $this->load_data($article_id);
        // $this->loadFittings($article_id);

        $svg_content = $this->design_tank_svg(null, $article_id, $with_cotation);
        
        return $this->save_svg_file($svg_content, $article_id);
    }
    public function get_tank_png($html, $article_id, $with_cotation = false) {
        // $this->load_data($article_id);
        // $this->loadFittings($article_id);

        $svg_content = $this->design_tank_svg(null, $article_id, $with_cotation);
        
        $this->save_svg_file($svg_content, $article_id);
        return $this->convert_svg_to_png($article_id);
    }

    protected function save_svg_file($svg_content, $article_id){
        // $svg_content = $this->generate_svg_content($tank_id); // ta méthode qui génère le contenu
        $svg_path = plugin_dir_path(__FILE__) . "../assets/svg/cuves/cuves_$article_id.svg";

        // Créer le dossier si besoin
        if (!file_exists(dirname($svg_path))) {
            mkdir(dirname($svg_path), 0755, true);
        }

        // Écrire le fichier
        file_put_contents($svg_path, $svg_content);

        // Retourner l'URL pour affichage
        return plugin_dir_url(__FILE__) . "../assets/svg/cuves/cuves_$article_id.svg";

    }
    

    public function load_data($article_id) {
        $designer = new ISPAG_Tank_Designer();
        $this->tank_data = $designer->get_tank_data(null, $article_id);
    }

    protected function designTankBodyPath() {
        $d = $this->tank_data['dimensions'];
        $jsonString = file_get_contents(__DIR__ . '/../assets/js/tank_data.json');
        $data = json_decode($jsonString, true);

        if (!$d || !$data) return '';
        $d->arrayBottomHeight = $data['arrayBottomHeight'][$this->tank_data['conception']->Material] ?? [];

        $diam = intval($d->Diameter);
        $height = intval($d->Height);
        $ground_clearance = intval($d->GroundClearance);
        $bottom_height = intval($d->arrayBottomHeight[$d->Diameter] ?? 0);
        $body_height = $height - $ground_clearance - 2 * $bottom_height;
        $y = $body_height + $bottom_height;

        $x_mesure_start = $diam / 3;
        $x_mesure_end = $diam + 50;

        $css = 'stroke: #000; stroke-width: 1;';
        $return = '';

        if ($this->tank_data['conception']->Support == 10) {
            $return .= $this->designFeet($diam, $y, $height);
        }

        $return .= '<path d="M 0 ' . $bottom_height . ' A ' . ($diam / 2) . ' ' . $bottom_height . ' 0 0 1 ' . $diam . ' ' . $bottom_height . '" style="' . $css . '" fill="url(#topEllipse)"/>';

        $return .= '<rect id="designMantel" width="' . $diam . '" height="' . $body_height . '" x="0" y="' . $bottom_height . '" fill="url(#cylinderBody)" style="' . $css . '"/>';

        $return .= '<path d="M 0 ' . $y . ' A ' . ($diam / 2) . ' ' . $bottom_height . ' 0 0 0 ' . $diam . ' ' . $y . '" style="' . $css . '" fill="url(#topEllipse)"/>';

        if ($this->tank_data['conception']->Support == 11) {
            $return .= $this->designVirole($diam, $height, $ground_clearance, $bottom_height, $body_height);
        }

        $return .= $this->designGroundLine($diam, $height, $ground_clearance);
        
        $this->tank_cotations[] = (object)['Height' => $height];
        $this->tank_cotations[] = (object)['Height' => $height - $bottom_height];
        $this->tank_cotations[] = (object)['Height' => $height - $y];
        $this->tank_cotations[] = (object)['Height' => $ground_clearance];


        // $return .= $this->designRightTextLabel($x_mesure_start, 0, $height - 0);
        // $return .= $this->designRightTextLabel($x_mesure_start, $bottom_height, $height - $bottom_height);
        // $return .= $this->designRightTextLabel($x_mesure_start, $y, $height - $y);
        // $return .= $this->designRightTextLabel($x_mesure_start, $height - $ground_clearance, $ground_clearance);

        return $return;
    }

    protected function designRightTextLabel($x = null, $y = 0, $label = '') {
        $css = 'fill: #ccc; stroke: #000;';

        $d = $this->tank_data['dimensions'];

        $default_x = $this->margin_left + $d->Diameter;

        $x_start = !empty($x) ? $x : $default_x;
        $x_end = $this->margin_left + $d->Diameter + 100;
        $return = '';
        $return .= '<line x1="' . $x_start .'" y1="' . $y . '" x2="' . $x_end . '" y2="' . $y . '" style="' . $css . '" />';
        $return .= '<text x="' . $x_end - 100 . '" y="' . $y-50 . '" font-size="100" fill="#000" dominant-baseline="middle">' . $label . '</text>';

        return $return;
    }

    protected function designFeet($diam, $bottom_y_end, $tank_height) {
        $css = 'stroke: #000;';
        $feet_width = 30;
        $positions = [
            0,
            ($diam - $feet_width) / 2,
            $diam - $feet_width
        ];

        $feet_height = $tank_height - $bottom_y_end;

        $feet_svg = '';
        foreach ($positions as $x) {
            $feet_svg .= '<rect x="' . $x . '" y="' . ($bottom_y_end) . '" width="' . $feet_width . '" height="' . $feet_height . '" style="' . $css . '" fill="url(#cylinderBody)"/>';
        }

        return $feet_svg;
    }

    protected function designVirole($diam, $tank_height, $ground_clearance, $bottom_height, $body_height) {
        // Sécurité : Si le diamètre est nul ou négatif, on ne dessine rien
        if ($diam <= 0) {
            return '';
        }

        $css = 'stroke: #000; fill-opacity: 0.8;';
        $virole_width = $diam - 200;
        
        // Si la virole est plus large que le diamètre (cas diam < 200), on ajuste
        if ($virole_width <= 0) $virole_width = $diam * 0.8;

        $rx = ($diam / 2);
        $x_offset = ($virole_width / 2);
        $x_position = $rx - $x_offset;
        $x = ($diam - $virole_width) / 2;

        $y = $tank_height - $ground_clearance;
        $y_position = (sqrt($bottom_height * $bottom_height * (1 - (($x_position - $rx) * ($x_position - $rx)) / ($rx * $rx)))) + $body_height + $bottom_height;
        $virole_height = $tank_height - $y_position;

        return '<rect x="' . $x . '" y="' . $y_position . '" width="' . $virole_width . '" height="' . $virole_height . '" style="' . $css . '" fill="url(#cylinderBody)"/>';
    }

    protected function designGroundLine($diam, $bottom_y_end, $ground_clearance) {
        $y_sol = $bottom_y_end;

        $css = 'stroke: #7a7a52 ; stroke-width: 5;';
        return '<line x1="-50" x2="' . ($diam + 50) . '" y1="' . $y_sol . '" y2="' . $y_sol . '" style="' . $css . '" />';
    }
    /****************************** Debut de cration des piquages *************************************************************************/

    protected function render_threaded_fitting_svg($fitting, $diameter = 1000, $insulation = 160, $tank_height = 2000, $bottom_height = 0) {
        $css = 'stroke: black; stroke-width: 1px;';
        $dn = $fitting->InternalDiamter ?? 50;
        $r = $dn / 2;
        $svg = '';

        $angle_deg = intval($fitting->Angle);
        $fitting_height = $fitting->Height ?? 1000;
        $base_cx = $diameter / 2;
        
        // Position Y de départ pour les raccords latéraux (référence si incliné)
        $cy_lateral = $tank_height - $fitting_height; 

        // =====================================================================
        // 👉 DÉTECTION DU RACCORD VERTICAL (Plus haut que la cuve)
        // =====================================================================
        $is_vertical_top = false;
        if ($fitting_height > $tank_height) { 
            $angle_deg = 90; // Force l'angle pour un dessin vertical
            $is_vertical_top = true;
        }
        
        $angle_rad = deg2rad($angle_deg);

        // =====================================================================
        // 👉 TRAITEMENT DU CAS VERTICAL (Sur le dôme supérieur)
        // =====================================================================
        if ($is_vertical_top) {
            

            // 🔑 CORRECTION CLÉ : Le raccord démarre au sommet du corps de la cuve (y = $bottom_height)
            $cy = 0; 
            $cx = $base_cx; // Centré horizontalement

            $cy_end = $cy - $insulation; // Extrémité vers le haut (Y SVG diminue)
            $cx2 = $cx; // Raccord vertical

            $width = 2 * $r;
            $height = $insulation;
            $x = $cx - $r;
            $y = $cy_end; // Le haut du rectangle (début du dessin)

            // 1. Dessin des accessoires (plaque, etc.)
            $svg .= $this->render_fitting_with_accessories($fitting, $diameter, $tank_height);
            
            // 2. Dessin du tube (rectangle vertical)
            $svg .= "<rect x='{$x}' y='{$y}' width='{$width}' height='{$height}' style='{$css}' fill='url(#fittingsBody)' />";

            // 3. Dessin des ellipses (ouverture en haut et en bas)
            $rx_vert = $r;
            $ry_vert = $r;
            
            // Ellipse du bas (sur le toit de la cuve, position $cy)
            // $svg .= "<ellipse cx='{$cx}' cy='{$cy}' rx='{$rx_vert}' ry='{$ry_vert}' style='{$css}' fill='url(#fittingsBody)' />";
            
            // Ellipse du haut (extrémité du raccord, position $cy_end)
            $svg .= "<ellipse cx='{$cx}' cy='{$cy_end}' rx='{$rx_vert}' ry='{$ry_vert}' style='{$css}' fill='url(#fittingsBody)' />";
            
            // 4. Flange (sur l'ellipse supérieure)
            $svg .= $this->render_flange_svg($fitting, $cx, $cy_end, $angle_deg, $insulation);

            return $svg;
        }
        // =====================================================================

        // CAS STANDARD (Raccord latéral/incliné)
        
        $cy = $cy_lateral; // On reprend la position Y calculée pour les raccords latéraux
        
        // Récupération de la logique de projection pour l'angle réel
        $tiltAdjustedDiameter = abs(($r) * cos((($angle_deg / 90) * M_PI) / 2));
        $tiltAdjustedLength = $insulation * cos(((($angle_deg - 90) / -90) * M_PI) / 2);
        $cx = $base_cx * (1 + cos($angle_rad - M_PI_2));
        $cx2 = $cx + (($angle_deg > 90 && $angle_deg <= 270) ? -abs($tiltAdjustedLength) : abs($tiltAdjustedLength));

        $y1 = $cy - $r;
        $height = 2 * $r;
        $width = abs($cx2 - $cx);
        $x = min($cx, $cx2);

        // fittings classiques
        $svg .= $this->render_fitting_with_accessories($fitting, $diameter, $tank_height);

        if ($angle_deg > 180 && $angle_deg < 360) {
            // tube orienté gauche
            $svg .= "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<rect x='{$x}' y='{$y1}' width='{$width}' height='{$height}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<ellipse cx='{$cx}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
        } else {
            // tube orienté droite
            $svg .= "<ellipse cx='{$cx}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<rect x='{$x}' y='{$y1}' width='{$width}' height='{$height}' style='{$css}' fill='url(#fittingsBody)' />";
            $svg .= "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$tiltAdjustedDiameter}' ry='{$r}' style='{$css}' fill='url(#fittingsBody)' />";
        }

        // Flange
        $svg .= $this->render_flange_svg($fitting, $cx, $cy, $angle_deg, $insulation);

        return $svg;
    }
    
    protected function render_flange_svg($fitting, $cx, $cy, $angle_deg, $insulation) {
        if (empty($fitting->NbDrilling) || $fitting->NbDrilling <= 0) {
            return '';
        }

        $css_flange = 'stroke: black; stroke-width: 1px;';
        $css_hole = 'fill: #fff; stroke: black; stroke-width: 0.5px;';
        $css_thickness = 'fill: #777; stroke: black; stroke-width: 1px;';

        $int_diam = $fitting->InternalDiamter ?? 50;
        $r_int = $int_diam / 2;
        $ext_diam = $fitting->ExternalDiameter ?? $int_diam + 20;
        $r_ext = $ext_diam / 2;
        $hole_diam = $fitting->Drilling ?? 5;
        $nb_holes = intval($fitting->NbDrilling);

        $tilt_factor = abs(cos(($angle_deg / 90) * M_PI / 2));
        $rx_int = $r_int * $tilt_factor;
        $rx_ext = $r_ext * $tilt_factor;
        $ry_int = $r_int;
        $ry_ext = $r_ext;

        $tiltAdjustedLength = $insulation * cos((($angle_deg - 90) / -90) * M_PI / 2);
        $is_left_side = ($angle_deg > 180 && $angle_deg < 360);
        
        // $cx2 est la position de la bride (le bord extérieur du raccord)
        // Note: pour le raccord vertical (90°), $cx2 = $cx dans la logique précédente.
        // On doit le recalculer ici pour être sûr.
        if ($angle_deg != 90) {
             $cx2 = $cx + ($is_left_side ? -abs($tiltAdjustedLength) : abs($tiltAdjustedLength));
        } else {
            $cx2 = $cx;
        }

        $flange_ellipses = "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$rx_ext}' ry='{$ry_ext}' style='{$css_flange}' fill='url(#cylinderBody)'/>";
        $flange_ellipses .= "<ellipse cx='{$cx2}' cy='{$cy}' rx='{$rx_int}' ry='{$ry_int}' fill='#ccc' stroke='none' />";

        $thickness = $fitting->Thickness ?? ($ext_diam - $int_diam) / 2;
        $thickness_proj = $thickness * abs(sin(($angle_deg / 90) * M_PI / 2));

        $rect_x = ($angle_deg > 90 && $angle_deg <= 270) ? $cx2 - $thickness_proj : $cx2;
        $rect_y = $cy - $ry_ext;
        $rect_width = $thickness_proj;
        $rect_height = 2 * $ry_ext;

        $flange_rect = '';
        if ($angle_deg == 90 || $angle_deg == 270) {
            // Le rectangle d'épaisseur n'est visible que pour un angle de 90 (vertical) ou 270 (horizontal vu de côté)
            // L'épaisseur n'est pas projetée ici si $angle_deg = 90
            $thickness_vert = ($angle_deg == 90) ? $thickness : $thickness_proj;
            $rect_x_vert = $cx2;
            $rect_y_vert = $cy - $ry_ext;
            
            // Pour le cas vertical (90°), l'épaisseur est vue de côté et n'est pas projetée.
            if ($angle_deg == 90) {
                 $flange_rect = "<rect x='" . ($rect_x_vert + $thickness) . "' y='{$rect_y_vert}' width='{$thickness}' height='{$rect_height}' style='{$css_thickness}' />";
            }
        }

        $flange_holes = '';
        // Les trous sont visibles si le raccord n'est pas de côté (90° ou 270°)
        if (!($angle_deg > 90 && $angle_deg < 270)) {
            $hole_rx = ($hole_diam / 2) * $tilt_factor;
            $hole_ry = $hole_diam / 2;
            $hole_circle_rx = ($rx_ext + $rx_int) / 2;
            $hole_circle_ry = ($ry_ext + $ry_int) / 2;

            for ($i = 0; $i < $nb_holes; $i++) {
                $angle = 2 * M_PI * $i / $nb_holes - M_PI / 2;
                $hx = $cx2 + $hole_circle_rx * cos($angle);
                $hy = $cy + $hole_circle_ry * sin($angle);
                
                // Pour le raccord vertical (90°), l'ellipse est un cercle.
                if ($angle_deg == 90) {
                    $flange_holes .= "<circle cx='{$hx}' cy='{$hy}' r='" . ($hole_diam / 2) . "' style='{$css_hole}' />";
                } else {
                    $flange_holes .= "<ellipse cx='{$hx}' cy='{$hy}' rx='{$hole_rx}' ry='{$hole_ry}' style='{$css_hole}' />";
                }
            }
        }

        // Si angle entre 90° et 270°, bride derrière → retour avant tube
        if ($angle_deg > 90 && $angle_deg < 270) {
            return $flange_ellipses . $flange_rect;
        }

        // Sinon, bride devant → retour après tube
        return $flange_rect . $flange_ellipses . $flange_holes;
    }



    /****************************** Gestion des accessoires ******************************************************************************/
    protected function render_fitting_with_accessories($fitting, $diameter, $tank_height) {
        // $svg = $this->render_fitting_svg($fitting, $diameter, $tank_height);
        $svg = '';
        // Vérifie s’il y a des accessoires
        if (!empty($fitting->id_accessories)) {
            if ($fitting->id_accessories == 16) {
                // IMPORTANT : Il faudrait passer $bottom_height à cette fonction 
                // si elle doit être utilisée pour le cas vertical !
                // Pour l'instant, je la laisse telle quelle.
                $svg .= $this->render_plate_svg($fitting, $diameter, $tank_height);
            }
        }

        return $svg;
    }

    protected function render_plate_svg($fitting, $diameter, $tank_height) {
        $insulation = 160;
        $plate_height = 10;

        $r = $fitting->InternalDiamter / 2;
        $fitting_height = $fitting->Height ?? 1000;
        $angle_deg = intval($fitting->Angle);
        $angle_rad = deg2rad($angle_deg);
        $base_cx = $diameter / 2;

        $plate_length = 200;
        $tiltAdjustedPlateLength = $plate_length * cos(((($angle_deg - 90) / -90) * M_PI) / 2);

        // Calcul des positions comme dans render_threaded_fitting_svg
        $cx = $base_cx * (1 + cos($angle_rad - M_PI_2));
        $tiltAdjustedLength = $insulation * cos(((($angle_deg - 90) / -90) * M_PI) / 2);
        $cx2 = $cx + (($angle_deg > 90 && $angle_deg <= 270) ? -abs($tiltAdjustedLength) : abs($tiltAdjustedLength));
        $cy = $tank_height - $fitting_height - ($r);

        $x_rect1 = ($angle_deg > 90 && $angle_deg <= 270) ? ($cx2 + $tiltAdjustedPlateLength) : ($cx - $tiltAdjustedPlateLength);
        $y_rect1 = $cy - ($plate_height);
        $svg = "<rect x='{$x_rect1}' y='{$y_rect1}' width='{$tiltAdjustedPlateLength}' height='{$plate_height}' fill='grey' opacity='0.7' />";
        $svg .= "<rect x='{$x_rect1}' y='{$y_rect1}' width='{$plate_height}' height='" . ($r*2) . "' fill='grey' opacity='0.5' />";

        return $svg;
    }





    /****************************** Gestion de la soudure ******************************************************************************/
    protected $weldings = [];

    protected function loadWeldings($article_id) {
        $conn_table = $this->table_connections;
        $dim_table = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 23",
            $article_id
        );

        $this->weldings = $this->wpdb->get_results($sql);
    }

    protected function render_weldings_svg($diameter, $height) {
        if (empty($this->weldings)) return '';

        $css = 'stroke: red; stroke-width: 3; stroke-dasharray: 50,50;';
        $svg = '';

        foreach ($this->weldings as $weld) {
            // On récupère la hauteur depuis la BDD
            $y = $height - floatval($weld->Height); // en SVG, l’origine est en haut, on inverse la hauteur

            // On dessine une ligne horizontale rouge sur toute la largeur de la cuve
            $svg .= '<line x1="0" y1="' . $y . '" x2="' . $diameter . '" y2="' . $y . '" style="' . $css . '" />';
        }
        return $svg;
    }
    /****************************** Gestion des tôles perforées ******************************************************************************/
    protected $drilled_plate = [];

    protected function loadPlate($article_id) {
        $conn_table = $this->table_connections;
        $dim_table = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 22",
            $article_id
        );

        $this->drilled_plate = $this->wpdb->get_results($sql);
    }
    protected function render_drilled_plate_svg($diameter, $height) {
        if (empty($this->drilled_plate)) return '';

        $css = 'stroke: green; stroke-width: 5; stroke-dasharray: 70,30;';
        $svg = '';

        foreach ($this->drilled_plate as $plate) {
            // On récupère la hauteur depuis la BDD
            $y = $height - floatval($plate->Height); // en SVG, l’origine est en haut, on inverse la hauteur

            // On dessine une ligne horizontale rouge sur toute la largeur de la cuve
            $svg .= '<line x1="0" y1="' . $y . '" x2="' . $diameter . '" y2="' . $y . '" style="' . $css . '" />';
        }
        return $svg;
    }


    /****************************** Creation de degradé ******************************************************************************/

    protected function defs($is_sketch = false) {
        if(!$is_sketch){
            return '
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
        }
        else{

            return '
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
        }
    }



    /******************************DESSIN DU SVG DE LA CUVE ******************************************************************************/

    public function render_svg($with_cotation = false) {
        $insulation = 160;
        if (!$this->tank_data || !isset($this->tank_data['dimensions'])) return '';

        $d = $this->tank_data['dimensions'];
        $jsonString = file_get_contents(__DIR__ . '/../assets/js/tank_data.json');
        $data = json_decode($jsonString, true);

        if (!$d || !$data) return '';
        $d->arrayBottomHeight = $data['arrayBottomHeight'][$this->tank_data['conception']->Material] ?? [];

        $diam = empty($diam) ? $d->Diameter : $diam;
        $height = empty($height) ? $d->Height : $height;
        
        // 🔑 NOUVEAU : Récupération de la hauteur du dôme (bottom_height)
        $bottom_height = intval($d->arrayBottomHeight[$d->Diameter] ?? 0); 
        
        $dome = round($diam * 0.2);
        $clearance = empty($ground_clearance) ? $d->GroundClearance : $ground_clearance;

        $svg_width = $diam + $this->margin_left + $this->margin_right;
        $svg_height = $dome * 2 + $height + $clearance + 50;

        

        ob_start(); ?>
        
        <svg viewBox="0 0 <?= $svg_width ?> <?= $svg_height ?>"
            class="responsive-svg"
            xmlns="http://www.w3.org/2000/svg">
            <?= $this->defs(); ?>
            <g transform="translate(<?= $this->margin_left ?>,<?= $dome ?>)">

                <?= $this->designTankBodyPath(); ?>
                <?php foreach ($this->fittings as $fitting) {
                   // 🔑 NOUVEAU : On passe $bottom_height à la fonction de rendu
                   echo $this->render_threaded_fitting_svg($fitting, $diam, $insulation, $height, $bottom_height);
                }
                echo $this->render_weldings_svg($diam, $height);
                echo $this->render_drilled_plate_svg($diam, $height);
                
                if ($with_cotation) {
                    echo $this->render_cotations_svg($diam, $height);
                }
                ?>
            </g>
        </svg>

        <?php
        return ob_get_clean();
    }

protected function render_cotations_svg($diam, $height) {
    $css_line = 'stroke: #000; stroke-width: 2;';
    $css_text = 'font-size: 100; fill: #000; dominant-baseline: middle;';
    $svg = '';

    $last_y_positions = [];
    $min_spacing = 30; // espacement vertical minimal
    $drawn_heights = [];

    $draw_cotation = function($y_val, $label) use ($diam, $height, $css_line, $css_text, &$last_y_positions, $min_spacing, &$svg, &$drawn_heights) {
        // Skip si hauteur déjà dessinée (tolérance 0.1)
        foreach ($drawn_heights as $h) {
            if (abs($h - $y_val) < 0.1) {
                return;
            }
        }
        $drawn_heights[] = $y_val;

        $y_real = $height - floatval($y_val); // position réelle

        // Calcul de la position du texte pour éviter collisions
        $y_text = $y_real;
        foreach ($last_y_positions as $prev_y) {
            if (abs($y_text - $prev_y) < $min_spacing) {
                $y_text = $prev_y - $min_spacing; // décalage uniquement du texte
            }
        }
        $last_y_positions[] = $y_text;

        $x_start = $diam + 20;
        $x_mid = $diam + 200;
        $x_text = $diam + 220;

        // Ligne horizontale jusqu'au décrochage
        $svg .= "<line x1='{$x_start}' y1='{$y_real}' x2='{$x_mid}' y2='{$y_real}' style='{$css_line}' />";

        // Décrochage vertical si texte déplacé
        if ($y_text != $y_real) {
            $svg .= "<line x1='{$x_mid}' y1='{$y_real}' x2='{$x_mid}' y2='{$y_text}' style='{$css_line}' />";
        }

        // Ligne horizontale jusqu'au texte
        $svg .= "<line x1='{$x_mid}' y1='{$y_text}' x2='{$x_text}' y2='{$y_text}' style='{$css_line}' />";

        // Texte
        $svg .= "<text x='" . ($x_text + 20) . "' y='{$y_text}' style='{$css_text}'>{$label}</text>";
    };

    // Cotes cuve
    foreach ($this->tank_cotations as $cotation) {
        $draw_cotation($cotation->Height, round($cotation->Height));
    }

    // Cotes soudures
    foreach ($this->weldings as $weld) {
        $draw_cotation($weld->Height, round($weld->Height) . " (" . __('Welding', 'creation-reservoir') . ")");
    }

    // Cotes tôles
    foreach ($this->drilled_plate as $plate) {
        $draw_cotation($plate->Height, round($plate->Height) . " (" . __('Drilled plate', 'creation-reservoir') . ")");
    }

    // Cotes piquages
    foreach ($this->fittings as $fitting) {
        $label = !empty($fitting->Name) ? $fitting->Name : round($fitting->Height);
        $draw_cotation($fitting->Height, $label);
    }

    return $svg;
}




    public function convert_svg_to_png($article_id) {
        $svg_path = plugin_dir_path(__FILE__) . "../assets/svg/cuves/cuves_$article_id.svg";
        $png_path = plugin_dir_path(__FILE__) . "../assets/svg/cuves/cuves_$article_id.png";

        // Tentative avec Imagick
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

                return plugin_dir_url(__FILE__) . "../assets/svg/cuves/cuves_$article_id.png";
            } catch (Exception $e) {
                // Échec d’Imagick, on essaie rsvg-convert
// \1('❌ Échec d’Imagick, on essaie rsvg-convert');
            }
        }

        // Fallback : rsvg-convert
        $cmd = "rsvg-convert -w 1000 -h 1000 -f png -o " . escapeshellarg($png_path) . " " . escapeshellarg($svg_path);
        exec($cmd, $output, $return_var);

        if ($return_var === 0) {
            return plugin_dir_url(__FILE__) . "../assets/svg/cuves/cuves_$article_id.png";
        } else {
// \1('❌ Conversion SVG vers PNG échouée (Imagick et rsvg-convert).');
            throw new Exception("Conversion SVG vers PNG échouée (Imagick et rsvg-convert).");
        }
    }

}