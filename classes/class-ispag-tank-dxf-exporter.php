<?php
class ISPAG_Tank_DXF_Exporter {

    protected $wpdb;
    protected $table_dimension;
    protected $table_flange_dimension;
    protected $table_conception;
    protected $table_connections;
    protected $tank_data;
    protected $scale_factor = 0.5;
    protected $cartouche_height = 30;
    protected $is_sketch = false;
    protected $fittings = [];
    protected $tank_cotations = [];

    protected $entities = [];
    protected static $instance;

    public function __construct($margin_left = 200, $margin_right = 1000) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_dimension = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        // add_filter('ispag_get_dxf_btn', [self::$instance, 'get_dxf_btn'], 10, 2);
        add_action('wp_ajax_ispag_export_dxf', [self::$instance, 'ispag_export_dxf']);
        add_action('wp_ajax_nopriv_ispag_export_dxf', [self::$instance, 'ispag_export_dxf']);
    }

    // public function get_dxf_btn($html, $article_id) {
    //     if(!current_user_can('display_beta')) return;
    //     $url = admin_url('admin-ajax.php?action=ispag_export_dxf&article_id=' . $article_id);
    //     return '<button class="ispag-btn downloadDXF" data-url="' . esc_attr($url) . '">' . __('Download DXF', 'creation-reservoir') . '</button>
    //     <script>
    //     document.addEventListener("click", function(e) {
    //         if(e.target && e.target.classList.contains("downloadDXF")) {
    //             window.location.href = e.target.dataset.url;
    //         }
    //     });
    //     </script>';

    // }


    public function ispag_export_dxf() {
        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        if(!$article_id) return;
    //     $jsonString = file_get_contents(__DIR__ . '/../assets/js/tank_data.json');
    //     $data = json_decode($jsonString, true);

    //     $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        $article_title = $article->Article;
    
    //     $deal_id = apply_filters('ispag_get_article_deal_id', null, $article_id);   
    //     $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);

        $filename = sanitize_filename($article_title) . ".dxf";

    //     while (ob_get_level()) { ob_end_clean(); }
    //     $nl = "\r\n";

    //     // page A4 paysage
    //     $page_width = 297;
    //     $page_height = 210;

    //     // zone dessin moitié gauche
    //     $margin = 10;
    //     $drawing_width = ($page_width / 2) - 2 * $margin; // 148 - 20 = 128mm
    //     $drawing_height = $page_height - 2 * $margin - 30; // 30mm cartouche

    //     $drawing_width *= $this->scale_factor;
    //     $drawing_height *= $this->scale_factor;

    //     // dimensions réelles cuve
    //     $tank_diameter = $tank_datas['dimensions']->Diameter;
    //     $insulation_thickness = $tank_datas['insulation']->InsulationThickness;
    //     $tank_height   = $tank_datas['dimensions']->Height;
    //     $head_height   = $data['arrayBottomHeight'][$tank_datas['conception']->Material][$tank_datas['dimensions']->Diameter] ?? [];
    //     $tank_body_height   = $tank_datas['dimensions']->Height - (2 * $head_height) - $tank_datas['dimensions']->GroundClearance; // mm (cylindre seul, hors fonds)

    //     // échelle
    //     $scale_x = $drawing_width / $tank_diameter;
    //     $scale_y = $drawing_height / $tank_height;
    //     $scale = min($scale_x, $scale_y);

    //     // largeur de la moitié gauche
    //     $half_width = $page_width / 2;   // 105mm pour A4

    //     // centrage horizontal dans cette moitié
    //     $offset_x = $margin + ($half_width - ($tank_diameter + 2* $insulation_thickness) * $scale) / 2;

    //     // ici on centre verticalement :
    //     $offset_y = $margin + $this->cartouche_height 
    //       + (($page_height - $this->cartouche_height - 2*$margin - $tank_height * $scale)/2);



    //     // fonction helper pour appliquer échelle + offset
    //     $transform = function($x, $y) use($scale, $offset_x, $offset_y) {
    //         return [ $x*$scale + $offset_x, $y*$scale + $offset_y ];
    //     };

    //     $entities = "";

    //     // cartouche bas
    //     $content = array(
    //         'project' => $project,
    //         'article' => $article,
    //     );
    //     $entities .= $this->render_cartouche_a4($content);


    //     // calculer le centre et hauteur
    //     $x_center = $tank_diameter / 2;
    //     // $y_bottom = $tank_datas['dimensions']->GroundClearance + $head_height;
    //     $y_bottom = $tank_datas['dimensions']->GroundClearance + $head_height;
    //     $y_top    = $tank_body_height + $y_bottom;

    //     // paroi gauche
    //     list($x0, $y0) = $transform(0, $y_bottom);
    //     list($x1, $y1) = $transform(0, $y_top);
    //     $entities .= "0\nLINE\n8\nTANK_BODY\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";

    //     // paroi droite
    //     list($x0, $y0) = $transform($tank_diameter, $y_bottom);
    //     list($x1, $y1) = $transform($tank_diameter, $y_top);
    //     $entities .= "0\nLINE\n8\nTANK_BODY\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";

    //     // fond bas (ellipse)
    //     list($cx, $cy) = $transform($x_center, $y_bottom);
    //     $rx = $tank_diameter / 2 * $scale;  // si besoin d'un scale
    //     $ry = $head_height * $scale;
    //     $entities .= "0\nELLIPSE\n8\nTANK_BODY\n10\n$cx\n20\n$cy\n30\n0\n11\n$rx\n21\n0\n31\n0\n40\n" . ($ry/$rx) . "\n41\n3.14159265\n42\n6.2831853\n";

    //     // fond haut (ellipse)
    //     list($cx, $cy) = $transform($x_center, $y_top);
    //     $entities .= "0\nELLIPSE\n8\nTANK_BODY\n10\n$cx\n20\n$cy\n30\n0\n11\n$rx\n21\n0\n31\n0\n40\n" . ($ry/$rx) . "\n41\n0\n42\n3.14159265\n";
    // // Ajout de la cote de hauteur de cuve
    // $entities .= $this->render_dimension_dxf(0, 0, 0, $tank_datas['dimensions']->Height, -800, $tank_datas['dimensions']->Height, $transform);

    // // // Ajout de la cote ground clearance
    // // $entities .= $this->render_dimension_dxf(0, 0, 0, $tank_datas['dimensions']->GroundClearance, -450, $tank_datas['dimensions']->GroundClearance, $transform);

    // // // Ajout de la cote fond bombe bas
    // // $entities .= $this->render_dimension_dxf(0, $tank_datas['dimensions']->GroundClearance, 0, ($tank_datas['dimensions']->GroundClearance + $head_height), -450, $head_height, $transform);

    // // // Ajout de la cote corps de cuve
    // // $entities .= $this->render_dimension_dxf(0, ($tank_datas['dimensions']->GroundClearance + $head_height), 0, $y_top, -450, $tank_body_height , $transform);

    // // // Ajout de la cote fond bombe haut
    // // $entities .= $this->render_dimension_dxf(0, $y_top, 0, ($y_top + $head_height), -450, $head_height , $transform);

    // // Ajout de la cote de diamètre de cuve
    // $entities .= $this->render_dimension_dxf(0, $y_bottom, $tank_diameter, $y_bottom, -600, $tank_diameter , $transform);

    //     // if ($supports_type == '10') {
    //     // largeur virole 200mm de moins que le diamètre
    //     $entities .= $this->render_virole_dxf($tank_diameter, $tank_datas['dimensions']->GroundClearance, $head_height, $scale, $transform);

    //     // } else {
    //     //     // 3 pieds
    //     //     $foot_width  = 50;
    //     //     $foot_height = 150;
    //     //     $foot_spacing = $tank_diameter / 2;
    //     //     $x_positions = [0, $foot_spacing, $tank_diameter];
    //     //     foreach($x_positions as $x) {
    //     //         // $entities .= "0\nLINE\n8\nSUPPORTS\n10\n$x\n20\n0\n30\n0\n11\n$x\n21\n$foot_height\n31\n0\n";
    //     //         // $entities .= "0\nLINE\n8\nSUPPORTS\n10\n$x\n20\n$foot_height\n30\n0\n11\n" . ($x+$foot_width) . "\n21\n$foot_height\n31\n0\n";
    //     //         // $entities .= "0\nLINE\n8\nSUPPORTS\n10\n" . ($x+$foot_width) . "\n20\n$foot_height\n30\n0\n11\n" . ($x+$foot_width) . "\n21\n0\n31\n0\n";
    //     //         // $entities .= "0\nLINE\n8\nSUPPORTS\n10\n$x\n20\n0\n30\n0\n11\n" . ($x+$foot_width) . "\n21\n0\n31\n0\n";
    //     //     }
    //     // }


    //     // Charger soudures et tôles
    //     $this->loadWeldings($article_id);
    //     $this->loadPlate($article_id);
    //     $this->loadFittings($article_id);

    //     // Ajouter au DXF
    //     $entities .= $this->render_weldings_dxf($tank_diameter, $tank_datas['dimensions']->Height, $transform);
    //     $entities .= $this->render_drilled_plate_dxf($tank_diameter, $tank_datas['dimensions']->Height, $transform);
    //     $entities .= $this->render_fittings_dxf($tank_diameter, $tank_datas['dimensions']->Height, $insulation_thickness, $transform);

    //     $dxf  = "0{$nl}SECTION{$nl}2{$nl}HEADER{$nl}0{$nl}ENDSEC{$nl}";
    //     $dxf .= "0{$nl}SECTION{$nl}2{$nl}TABLES{$nl}";
    //     $dxf .= "0{$nl}TABLE{$nl}2{$nl}LAYER{$nl}70{$nl}1{$nl}";
    //     $dxf .= "0{$nl}LAYER{$nl}2{$nl}0{$nl}70{$nl}64{$nl}62{$nl}7{$nl}6{$nl}CONTINUOUS{$nl}";
    //     $dxf .= "0{$nl}ENDTAB{$nl}0{$nl}ENDSEC{$nl}";
    //     $dxf .= "0{$nl}SECTION{$nl}2{$nl}BLOCKS{$nl}0{$nl}ENDSEC{$nl}";
    //     $dxf .= "0{$nl}SECTION{$nl}2{$nl}ENTITIES{$nl}{$entities}0{$nl}ENDSEC{$nl}0{$nl}EOF{$nl}";

        $dxf = $this->genererDxfDepuisJson();

        header('Content-Type: application/dxf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $dxf;
        exit;
    }


    protected function render_virole_dxf($tank_diameter, $groundclearance, $head_height, $scale, $transform) {
        $entities = "";

        // largeur virole 200mm de moins que le diamètre
        $virole_width = $tank_diameter - 200;

        // coordonnées X réelles du bord gauche et droit
        $x_left_real  = ($tank_diameter - $virole_width) / 2;
        $x_right_real = ($tank_diameter + $virole_width) / 2;

        // ellipse du fond (ellipsoïde)
        $a = $tank_diameter / 2;  // demi-axe horizontal
        $b = $head_height;        // demi-axe vertical
        $cx = $a;
        $cy = $b;                 // centre de l'ellipse depuis le sol

        // hauteur de la virole = distance du sol au point de contact avec le fond ellipsoïdal
        $dx = $x_left_real - $cx;
        $y_contact = $cy + $b * sqrt(1 - ($dx*$dx)/($a*$a));
        $virole_height_real = $y_contact - $groundclearance - $groundclearance/2; // du sol jusqu’au contact

        // transformer les coordonnées pour le DXF
        list($x0, $y0) = $transform($x_left_real, 0); // base de la virole (sol)
        list($x1, $y1) = $transform($x_right_real, $virole_height_real); // haut = contact ellipse

        // rectangle virole
        $entities .= "0\nLINE\n8\nTANK_BODY_SUPPORTS\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y0\n31\n0\n"; // bas
        $entities .= "0\nLINE\n8\nTANK_BODY_SUPPORTS\n10\n$x0\n20\n$y0\n30\n0\n11\n$x0\n21\n$y1\n31\n0\n"; // gauche
        $entities .= "0\nLINE\n8\nTANK_BODY_SUPPORTS\n10\n$x1\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n"; // droite
        $entities .= "0\nLINE\n8\nTANK_BODY_SUPPORTS\n10\n$x0\n20\n$y1\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n"; // haut

        return $entities;
    }




    /****************************** Gestion de la soudure ******************************************************************************/
    // Chargement des soudures (déjà ok)
    protected $weldings = [];

    protected function loadWeldings($article_id) {
        $conn_table = $this->table_connections;
        $dim_table  = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* 
            FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 23",
            $article_id
        );

        $this->weldings = $this->wpdb->get_results($sql);
    }

    // Génération DXF pour les soudures (trait rouge plein + texte hauteur)
    protected function render_weldings_dxf($diameter, $height, $transform = null) {
        if (empty($this->weldings)) return '';

        $entities = "";

        foreach ($this->weldings as $weld) {
            $y = floatval($weld->Height);
            list($x0, $y0) = $transform(0, $y);
            list($x1, $y1) = $transform($diameter, $y);

            $entities .= "0\nLINE\n8\nTANK_BODY_WELDINGS\n62\n1\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";

            $entities .= $this->render_dimension_dxf(0, 0, 0, $y, -500, $y, $transform);
        }

        return $entities;
    }



    /****************************** Gestion des tôles perforées ******************************************************************************/
    // Chargement des tôles (déjà ok)
    protected $drilled_plate = [];

    protected function loadPlate($article_id) {
        $conn_table = $this->table_connections;
        $dim_table  = $this->table_dimension;

        $sql = $this->wpdb->prepare(
            "SELECT c.* 
            FROM $conn_table c
            LEFT JOIN $dim_table d ON c.TankId = d.Id
            WHERE d.customerTankId = %d AND c.Type = 22",
            $article_id
        );

        $this->drilled_plate = $this->wpdb->get_results($sql);
    }

    // Génération DXF pour les tôles perforées (trait vert pointillés)
    protected function render_drilled_plate_dxf($diameter, $height, $transform = null) {
        if (empty($this->drilled_plate)) return '';

        $entities = "";
        
        foreach ($this->drilled_plate as $plate) {
            $y = floatval($plate->Height);

            list($x0, $y0) = $transform(0, $y);
            list($x1, $y1) = $transform($diameter, $y);

            $entities .= "0\nLINE\n8\nTANK_BODY_DRILLED_PLATE\n6\nDASHED\n62\n3\n48\n1.0\n";
            $entities .= "10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";

            $entities .= $this->render_dimension_dxf(0, 0, 0, $y, -500, $y, $transform);
        }

        return $entities;
    }


    /****************************** Gestion des raccords ******************************************************************************/
    // Ajout d'une nouvelle méthode pour charger les piquages depuis la BDD
    protected function loadFittings($article_id) {
        $tank_fittings = new ISPAG_Tank_Fittings();
        $this->fittings = $tank_fittings->get_all_fittings($article_id);
    }
    
protected function render_fittings_dxf($diameter = 1000, $tank_height = 2000, $insulation = 160, $transform = null) {
    if (empty($this->fittings)) return '';

    $entities = "";

    foreach ($this->fittings as $fit) {
        $layer = 'FITTING_' . ($fit->Pouces ?? 'X');
        $dn = $fit->InternalDiamter ?? 50;
        $r = $dn / 2;
        $angle_deg = intval($fit->Angle);
        $angle_rad = deg2rad($angle_deg);

        // Position de base (centre de la cuve)
        $base_cx = $diameter / 2;
        $cx = $base_cx * (1 + cos($angle_rad - M_PI_2));
        $cy = floatval($fit->Height ?? 1000);

        // Ajustement diamètre visuel
        $tiltAdjustedDiameter = abs($r * cos((($angle_deg / 90) * M_PI) / 2));

        // Longueur de sortie (raccord)
        $raccordLength = $insulation;
        if (isset($fit->Value) && stripos($fit->Value, 'flange') !== false) {
            $raccordLength += 50;
        }

        // Décalage du deuxième centre
        if ($angle_deg == 0) {
            // superposition parfaite
            $dx = 0; 
            $dy = 0;
        } else {
            $dx = $raccordLength * cos($angle_rad);
            $dy = $raccordLength * sin($angle_rad);
        }

        $cx2 = $cx + $dx;
        $cy2 = $cy;

        // Transformation si nécessaire
        if ($transform) {
            list($cx, $cy) = $transform($cx, $cy);       // rouge
            list($cx2, $cy2) = $transform($cx2, $cy2);   // bleu
        }

        // Mise à l’échelle de l’ellipse rouge
        $ptX1 = $cx - $tiltAdjustedDiameter/2;
        $ptX2 = $cx + $tiltAdjustedDiameter/2;
        $ptY1 = $cy - $r;
        $ptY2 = $cy + $r;
        if ($transform) {
            list($ptX1, $ptY1) = $transform($ptX1, $ptY1);
            list($ptX2, $ptY2) = $transform($ptX2, $ptY2);
        }
        $tiltAdjustedDiameter = abs($ptX2 - $ptX1);
        $r = abs($ptY2 - $ptY1)/2;

        // Rectangle reliant les 2 ellipses
        $left   = min($cx, $cx2);
        $right  = max($cx, $cx2);
        $bottom = min($cy - $r, $cy2 - $r);
        $top    = max($cy + $r, $cy2 + $r);

        $x_rect = $left;
        $y_rect = $bottom;
        $width_rect = $right - $left;
        $height_rect = $top - $bottom;

        // Dessin (couleurs différenciées)
        $entities .= $this->dxfEllipse($cx, $cy, $tiltAdjustedDiameter, $r, $layer, 'white');     // raccord
        $entities .= $this->dxfRect($x_rect, $y_rect, $width_rect, $height_rect, $layer, 'white');
        $entities .= $this->dxfEllipse($cx2, $cy2, $tiltAdjustedDiameter, $r, $layer, 'white'); // cuve
    }

    return $entities;
}



    protected function render_flange_dxf($fitting, $cx, $cy, $angle_deg, $insulation, $drawing_width = 128, $drawing_height = 180, $layer = 'FLANGE') {
        if (empty($fitting->NbDrilling) || $fitting->NbDrilling <= 0) return '';

        $entities = "";
        $scale = min($drawing_width/$cx*2, $drawing_height/$cy*2); // approximation pour le scaling
        $offset_x = 10;
        $offset_y = 10 + 30;

        $transform = function($x, $y) use($scale, $offset_x, $offset_y){
            return [$x*$scale + $offset_x, $y*$scale + $offset_y];
        };

        // ensuite tu appliques $transform à toutes les coordonnées de l'ellipse, rectangle et trous
        // exactement comme pour render_fittings_dxf

        return $entities;
    }







    // Helpers
    protected function dxfEllipse($cx, $cy, $rx, $ry, $layer = "FITTINGS", $color = null) {
        $dxf = "0\nELLIPSE\n8\n$layer\n10\n$cx\n20\n$cy\n11\n$rx\n21\n0.0\n40\n" . ($ry/$rx) . "\n";
        if ($color !== null) {
            // Code groupe 62 = couleur DXF (1=rouge, 5=bleu, etc.)
            $colorCode = 7; // blanc par défaut
            switch(strtolower($color)) {
                case 'red': $colorCode = 1; break;
                case 'yellow': $colorCode = 2; break;
                case 'green': $colorCode = 3; break;
                case 'cyan': $colorCode = 4; break;
                case 'blue': $colorCode = 5; break;
                case 'magenta': $colorCode = 6; break;
                case 'white': $colorCode = 7; break;
            }
            $dxf .= "62\n$colorCode\n";
        }
        return $dxf;
    }

    protected function dxfRect($x, $y, $w, $h, $layer = "FITTINGS", $color = null) {
        $dxf = "0\nLWPOLYLINE\n8\n$layer\n90\n4\n70\n1\n";
        $dxf .= "10\n$x\n20\n$y\n";
        $dxf .= "10\n" . ($x+$w) . "\n20\n$y\n";
        $dxf .= "10\n" . ($x+$w) . "\n20\n" . ($y+$h) . "\n";
        $dxf .= "10\n$x\n20\n" . ($y+$h) . "\n";

        if ($color !== null) {
            // Code groupe 62 = couleur DXF (1=rouge, 5=bleu, etc.)
            $colorCode = 7; // blanc par défaut
            switch(strtolower($color)) {
                case 'red': $colorCode = 1; break;
                case 'yellow': $colorCode = 2; break;
                case 'green': $colorCode = 3; break;
                case 'cyan': $colorCode = 4; break;
                case 'blue': $colorCode = 5; break;
                case 'magenta': $colorCode = 6; break;
                case 'white': $colorCode = 7; break;
            }
            $dxf .= "62\n$colorCode\n";
        }

        return $dxf;
    }


    // Génération d'une cote simple (horizontale ou verticale)
    protected function render_dimension_dxf($x1, $y1, $x2, $y2, $offset = 200, $text = '', $transform = null) {
        $entities = "";

        if ($transform) {
            // on calcule d'abord le scale (différent X ou Y)
            $scale_x = $transform(1,0)[0] - $transform(0,0)[0];
            $scale_y = $transform(0,1)[1] - $transform(0,0)[1];

            if ($x1 == $x2) {
                // cote verticale → utiliser l'échelle Y
                $offset *= $scale_x; 
            } else {
                // cote horizontale → utiliser l'échelle X
                $offset *= $scale_y;
            }

            list($x1, $y1) = $transform($x1, $y1);
            list($x2, $y2) = $transform($x2, $y2);
        }

        if ($x1 == $x2) { 
            // Cote verticale
            $x_dim = $x1 + $offset;
            $entities .= "0\nLINE\n8\nDIMENSIONS\n62\n2\n10\n$x_dim\n20\n$y1\n30\n0\n11\n$x_dim\n21\n$y2\n31\n0\n";
            $entities .= "0\nLINE\n8\nDIMENSIONS\n62\n2\n10\n$x1\n20\n$y1\n30\n0\n11\n$x_dim\n21\n$y1\n31\n0\n";
            $entities .= "0\nLINE\n8\nDIMENSIONS\n62\n2\n10\n$x1\n20\n$y2\n30\n0\n11\n$x_dim\n21\n$y2\n31\n0\n";
            $entities .= "0\nTEXT\n8\nDIMENSIONS\n62\n2\n10\n" . ($x_dim + $offset/2) . "\n20\n$y2\n30\n0\n40\n3\n1\n$text\n";
        } else {
            // Cote horizontale
            $y_dim = $y1 + $offset;
            $entities .= "0\nLINE\n8\nDIMENSIONS\n62\n2\n10\n$x1\n20\n$y_dim\n30\n0\n11\n$x2\n21\n$y_dim\n31\n0\n";
            $entities .= "0\nLINE\n8\nDIMENSIONS\n62\n2\n10\n$x1\n20\n$y1\n30\n0\n11\n$x1\n21\n$y_dim\n31\n0\n";
            $entities .= "0\nLINE\n8\nDIMENSIONS\n62\n2\n10\n$x2\n20\n$y2\n30\n0\n11\n$x2\n21\n$y_dim\n31\n0\n";
            $x_text = ($x1 + $x2) / 2;
            $entities .= "0\nTEXT\n8\nDIMENSIONS\n62\n2\n10\n$x_text\n20\n" . ($y_dim + $offset/2) . "\n30\n0\n40\n3\n1\n$text\n";
        }

        return $entities;
    }


    function sanitize_filename($title) {
        // Convertir en ASCII (enlève les accents)
        $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);

        // Mettre en minuscules
        $title = strtolower($title);

        // Remplacer tout ce qui n'est pas alphanumérique par un tiret
        $title = preg_replace('/[^a-z0-9]+/', '-', $title);

        // Nettoyer les tirets en trop
        $title = trim($title, '-');

        return $title;
    }

    // protected function render_cartouche_a4($content = array(), $page_width = 297, $page_height = 210, $margin = 10) {
    //     $entities = "";

    //     // coordonnées du cartouche
    //     $x0 = $margin;
    //     $y0 = $margin;
    //     $x1 = $page_width - $margin;
    //     $y1 = $margin + $this->cartouche_height;

    //     // rectangle du cartouche
    //     $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y0\n31\n0\n";
    //     $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x1\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";
    //     $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x1\n20\n$y1\n30\n0\n11\n$x0\n21\n$y1\n31\n0\n";
    //     $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x0\n20\n$y1\n30\n0\n11\n$x0\n21\n$y0\n31\n0\n";

    //     // marge intérieure pour le texte
    //     $inner_margin = 5;
    //     $tx = $x0 + $inner_margin;

    //     // hauteur entre les lignes et point de départ du texte
    //     $line_height = 6;
    //     $ty_base = $y0 + 5;

    //     // textes du cartouche
    //     $texts = [
    //         __('Article', 'creation-reservoir') . " : " . ($content['article_title'] ?? ''),
    //         __('Project', 'creation-reservoir') . " : " . ($content['project'] ?? ''),
    //         __('Customer', 'creation-reservoir') . " : " . ($content['client'] ?? ''),
    //         __('Date', 'creation-reservoir') . " : " . date('d/m/Y')
    //     ];

    //     foreach ($texts as $i => $text) {
    //         $ty = $ty_base + $i * $line_height;
    //         $entities .= "0\nTEXT\n8\nCARTOUCHE\n10\n$tx\n20\n$ty\n30\n0\n40\n4\n1\n$text\n";
    //     }

    //     // cadre de la feuille
    //     $entities .= $this->render_frame_a4($page_width, $page_height, $margin);

    //     return $entities;
    // }
protected function render_cartouche_a4($content = array(), $page_width = 297, $page_height = 210, $margin = 10) {
    $entities = "";

    // Coordonnées du cartouche
    $x0 = $margin;
    $y0 = $margin;
    $x1 = $page_width - $margin;
    $y1 = $margin + $this->cartouche_height;
    $font_size = 4; // Utilisation de cette variable pour la taille de la police

    // Rectangle extérieur du cartouche
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y0\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x1\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x1\n20\n$y1\n30\n0\n11\n$x0\n21\n$y1\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$x0\n20\n$y1\n30\n0\n11\n$x0\n21\n$y0\n31\n0\n";

    // Marge intérieure pour le texte et les lignes
    $inner_margin = 5;
    $line_height = 6;
    $text_height = $font_size * 0.5;

    // Définition des zones de texte
    $texts_left = [
        __('Designation', 'creation-reservoir') . " : " . ($content['article']->Article ?? 'Warmwasserspeicher'),
        __('Article Nr.', 'creation-reservoir') . " : " . ($content['article']->Id ?? ''),
        __('Order Nr.', 'creation-reservoir') . " : " . ($content['project']->NumCommande ?? ''),
        __('Project', 'creation-reservoir') . " : " . ($content['project']->ObjetCommande ?? ''),
    ];

    $texts_right = [
        __('Appr.', 'creation-reservoir') . " : " . ($content['appr'] ?? 'Wihan'),
        __('Drawn', 'creation-reservoir') . " : " . (get_the_author_meta('display_name', $content['article']->created_by_id) ?? 'C. Barthel'),
        __('Date', 'creation-reservoir') . " : " . date('d.m.Y'),
    ];
    
    // Positionnement des textes à gauche
    $ty_base_left = $y0 + 5;
    $max_index_left = count($texts_left) - 1;
    foreach ($texts_left as $i => $text) {
        $ty = $ty_base_left + $i * $line_height;
        $entities .= "0\nTEXT\n8\nCARTOUCHE\n10\n" . ($x0 + $inner_margin) . "\n20\n$ty\n30\n0\n40\n$text_height\n1\n$text\n";

        // Ajoute une ligne horizontale en dessous de chaque texte, sauf le dernier
        if ($i < $max_index_left) {
            $line_y = $ty + ($line_height / 2) + 2;
            $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n" . ($x0) . "\n20\n$line_y\n30\n0\n11\n" . ($x1 - 100) . "\n21\n$line_y\n31\n0\n";
        }
    }

    // Positionnement des textes à droite
    $ty_base_right = $y0 + 5;
    $tx_right = $x1 - 95;
    $max_index_right = count($texts_right) - 1;
    foreach ($texts_right as $i => $text) {
        $ty = $ty_base_right + $i * $line_height;
        $entities .= "0\nTEXT\n8\nCARTOUCHE\n10\n$tx_right\n20\n$ty\n30\n0\n40\n$text_height\n1\n$text\n";

        // Ajoute une ligne horizontale en dessous de chaque texte, sauf le dernier
        if ($i < $max_index_right) {
            $line_y = $ty + ($line_height / 2) + 2;
            $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n" . ($x1 - 100) . "\n20\n$line_y\n30\n0\n11\n" . ($x1 - 50) . "\n21\n$line_y\n31\n0\n";
        }
    }

    // Cadre du logo
    $logo_width = 40;
    $logo_height = 18;

    $logo_x_start = $x1 - $logo_width;
    $logo_y_start = $y0;
    $logo_x_end = $x1;
    $logo_y_end = $y0 + $logo_height;

    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$logo_x_start\n20\n$logo_y_start\n30\n0\n11\n$logo_x_end\n21\n$logo_y_start\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$logo_x_end\n20\n$logo_y_start\n30\n0\n11\n$logo_x_end\n21\n$logo_y_end\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$logo_x_end\n20\n$logo_y_end\n30\n0\n11\n$logo_x_start\n21\n$logo_y_end\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n$logo_x_start\n20\n$logo_y_end\n30\n0\n11\n$logo_x_start\n21\n$logo_y_start\n31\n0\n";
    
    // En cas d'échec de la récupération du fichier, on peut afficher un texte de remplacement.
    $logo_x = $logo_x_start;
    $logo_y = $logo_y_start;
    $entities .= "0\nTEXT\n8\nCARTOUCHE\n10\n$logo_x\n20\n$logo_y\n30\n0\n40\n" . ($font_size + 2) . "\n1\nISPAG\n";
    $entities .= "72\n1\n73\n2\n";

    // Lignes de séparation verticales pour créer des colonnes
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n" . ($x1 - 100) . "\n20\n$y0\n30\n0\n11\n" . ($x1 - 100) . "\n21\n$y1\n31\n0\n";
    $entities .= "0\nLINE\n8\nCARTOUCHE\n10\n" . ($x1 - 50) . "\n20\n$y0\n30\n0\n11\n" . ($x1 - 50) . "\n21\n$y1\n31\n0\n";

    // Cadre de la feuille
    $entities .= $this->render_frame_a4($page_width, $page_height, $margin);

    return $entities;
}


    protected function render_frame_a4($page_width = 297, $page_height = 210, $margin = 10) {
        $entities = "";

        $x0 = $margin;
        $y0 = $margin;
        $x1 = $page_width - $margin;
        $y1 = $page_height - $margin;

        // rectangle autour de la feuille
        $entities .= "0\nLINE\n8\nFRAME\n10\n$x0\n20\n$y0\n30\n0\n11\n$x1\n21\n$y0\n31\n0\n";
        $entities .= "0\nLINE\n8\nFRAME\n10\n$x1\n20\n$y0\n30\n0\n11\n$x1\n21\n$y1\n31\n0\n";
        $entities .= "0\nLINE\n8\nFRAME\n10\n$x1\n20\n$y1\n30\n0\n11\n$x0\n21\n$y1\n31\n0\n";
        $entities .= "0\nLINE\n8\nFRAME\n10\n$x0\n20\n$y1\n30\n0\n11\n$x0\n21\n$y0\n31\n0\n";

        return $entities;
    }


    /**
     * Génère le contenu d'un fichier DXF à partir d'une structure JSON.
     * (Avec nettoyage de texte et exclusion des entités COMMENT/0)
     *
     * @return string|false Le contenu DXF généré, ou false en cas d'erreur.
     */
    private function genererDxfDepuisJson(): string|false
    {
        $jsonFilePath = plugin_dir_path(__FILE__) . '../assets/js/cuve.json';
        // ... (Vérifications du fichier et du JSON) ...
        if (!file_exists($jsonFilePath)) return false;
        $jsonContent = file_get_contents($jsonFilePath);
        $dxfStructure = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($dxfStructure)) return false;

        $dxfContent = '';
        $current_entity_type = ''; // Pour détecter l'entité actuelle

        // Parcourir chaque section (HEADER, TABLES, ENTITIES, EOF)
        foreach ($dxfStructure as $section) {
            if (isset($section['code']) && is_array($section['code'])) {
                
                // Itérer sur chaque paire code/valeur
                foreach ($section['code'] as $item) {
                    if (isset($item['code']) && isset($item['value'])) {
                        $code = $item['code'];
                        $value = $item['value'];
                        
                        // Si le code est 0, c'est le début d'une nouvelle entité/section
                        if ($code == 0) {
                            $current_entity_type = strtoupper(trim($value));
                        }
                        
                        // --- NOUVELLE RÈGLE : IGNORER LES COMMENTAIRES DANS LA GÉNÉRATION FINALE ---
                        if ($current_entity_type === 'COMMENT') {
                            continue; 
                        }
                        
                        // --- NETTOYAGE pour les codes de texte (si vous avez d'autres textes que COMMENT) ---
                        if ($code == 1 || $code == 3) { 
                            // S'assurer que les chaînes de texte n'ont pas de retours à la ligne ou espaces multiples
                            $value = str_replace(array("\r", "\n"), ' ', $value);
                            $value = preg_replace('/\s+/', ' ', $value);
                            $value = trim($value);
                        }
                        // --- FIN NETTOYAGE ---

                        // Écriture de la paire Code/Valeur
                        $dxfContent .= $code . "\n";
                        $dxfContent .= $value . "\n";
                    }
                }
            }
        }

        return $dxfContent;
    }

}
