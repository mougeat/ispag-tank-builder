<?php
class ISPAG_Tank3D_Renderer {

    protected static $instance = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('wp_enqueue_scripts', [self::$instance, 'register_scripts']);
        add_shortcode('ispag_tank_3d', [self::$instance, 'render_shortcode']);
        add_filter('ispag_get_3d_renderer_btn', [self::$instance, 'get_3d_renderer_btn'], 10, 2 );

    }

    public function get_3d_renderer_btn($html, $article_id){
        if(!current_user_can('display_beta')) return;
        return '
        <a href="/rendu-3d/?article_id=' . $article_id .'" target="_blank" class="ispag-btn ispag-btn-red-outlined display-tank-3d">
            <span class="dashicons dashicons-layout"></span>
            ' . __('Display tank 3D view', 'creation-reservoir') . '
        </a>';
    }

    public function register_scripts() {
        // Three.js
        wp_enqueue_script(
            'three-js',
            'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r134/build/three.min.js',
            [],
            null,
            true
        );

        // OrbitControls
        wp_enqueue_script(
            'three-orbitcontrols',
            ISPAG_PLUGIN_URL . 'assets/js/OrbitControls.js',
            ['three-js'],
            null,
            true
        );

        // Ton script custom
        wp_register_script(
            'ispag-tank-3d',
            ISPAG_PLUGIN_URL . 'assets/js/ispag-tank-3d.js',
            ['three-js','three-orbitcontrols'],
            null,
            true
        );
    }

    public function render_shortcode($atts) {

        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        if ($article_id <= 0) {
            return '<p>' . __('Error: no article specified or article not valid!', ' creation-reservoir') .'</p>';
        }
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id );

        $jsonString = file_get_contents(__DIR__ . '/../assets/json/tank_data.json');
        $data = json_decode($jsonString, true);
        $tank_datas['dimensions']->bottom_height = $data['arrayBottomHeight'][$tank_datas['conception']->Material][$tank_datas['dimensions']->Diameter] ?? [];
        $tank_datas['dimensions']->body_height = $tank_datas['dimensions']->Height - (2 * $tank_datas['dimensions']->bottom_height) - $tank_datas['dimensions']->GroundClearance;
        $material = '';

        if ($tank_datas['conception']->Material == 1 || $tank_datas['conception']->Material == 3) {
            $material = 'inox';
        } elseif ($tank_datas['conception']->Material == 2) {
            $material = 'acier';
        } else {
            $material = 'inox'; // Default to 'inox' if none of the above conditions are met
        }

        $a = [
            'diameter_mm'       => $tank_datas['dimensions']->Diameter,
            'height_mm'         => $tank_datas['dimensions']->Height,
            'body_height'       => $tank_datas['dimensions']->body_height,
            'ground_clearance'  => $tank_datas['dimensions']->GroundClearance,
            'head_type'         => 'elliptical',
            'head_height'       => $tank_datas['dimensions']->bottom_height,
            'support'           => $tank_datas['conception']->Support, 
            'head_ratio'        => 0.2,
            'insulation_mm'     => $tank_datas['insulation']->InsulationThickness,
            'material'          => $material // inox ou acier
        ];

        // type = pipe or flange
        $fittings_datas = apply_filters('ispag_get_fittings_with_tank_id', null, $article_id);

        $type_labels = [
            'threaded fitting'  => 'pipe',
            'flange'            => 'flange',
            'revision flange'   => 'flange',
            // ajoute les autres si besoin
        ];


        foreach ($fittings_datas as $fit) {
            $fittings[] = [
                'id'                    => (int) $fit->fitting_id,
                'type'                  => $type_labels[$fit->Type] ?? 'unknown',
                // 'type'                  => $fit->Type,
                'dn'                    => $fit->DN,              // ex: DN50
                'diameter_mm'           => (float) $fit->InternalDiamter,
                'flange_diameter_mm'    => (float) $fit->ExternalDiameter,
                'flange_thickness'      => (float) $fit->Thickness,
                'flange_drilling'       => (float) $fit->Drilling,
                'flange_nb_drilling'    => (float) $fit->NbDrilling,
                'height_from_ground'    => (float) $fit->Height,  // en mm
                'angle_deg'             => (float) $fit->Angle,   // en °
                'accessories'           => $fit->Accessories,
                'id_accessories'        => (int) $fit->id_accessories,
                'made_for'              => $fit->madeFor,
                'qty'                   => isset($fit->qty) ? (int) $fit->qty : 1,
            ];
        }

        // Convertir en JSON pour le passer au JS
        $fittings_json = esc_attr(wp_json_encode($fittings));

        //Soudures
        $welding_datas = apply_filters('ispag_get_all_welding_drilled_plate', null, $article_id, false);
        foreach ($welding_datas as $weld) {
            $weldings[] = [
                'id'                    => (int) $weld->fitting_id,
                'type'                  => $weld->Type,
                'height_from_ground'    => (float) $weld->Height,  // en mms
            ];
        }
        // Convertir en JSON pour le passer au JS
        $weldings_json = esc_attr(wp_json_encode($weldings));



        wp_enqueue_script('three-js');
        wp_enqueue_script('three-orbitcontrols');
        wp_enqueue_script('ispag-tank-3d');

        $id = 'ispag-tank-3d-' . wp_generate_uuid4();

        ob_start(); ?>
        .

        
        <div id="<?php echo esc_attr($id); ?>"
            class="ispag-tank-3d"
            style="width:100%;max-width:1200px;aspect-ratio:3/2;border:1px solid #eee"
            data-diameter="<?php echo esc_attr($a['diameter_mm']); ?>"
            data-height="<?php echo esc_attr($a['height_mm']); ?>"
            data-body-height="<?php echo esc_attr($a['body_height']); ?>"
            data-head-height="<?php echo esc_attr($a['head_height']); ?>"
            data-ground-clearance="<?php echo esc_attr($a['ground_clearance']); ?>"
            data-head-type="<?php echo esc_attr($a['head_type']); ?>"
            data-head-ratio="<?php echo esc_attr($a['head_ratio']); ?>"
            data-support="<?php echo esc_attr($a['support']); ?>"
            data-insulation="<?php echo esc_attr($a['insulation_mm']); ?>"
            data-material="<?php echo esc_attr($a['material']); ?>"
            data-fittings='<?php echo $fittings_json; ?>'
            data-weldings='<?php echo $weldings_json; ?>'>
        </div>
        <?php
        return ob_get_clean();
    }

}
