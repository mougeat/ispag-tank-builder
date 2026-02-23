<?php

class ISPAG_Tank_Fittings {
    private $wpdb;
    private $table_flange_dimension;
    private $table_conception;
    private $table_connections;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_get_fitting_btn', [self::$instance, 'get_fitting_btn'], 10, 2 );
        add_filter('ispag_get_modal_fitting', [self::$instance, 'get_modal_fitting']);
        add_action('wp_ajax_ispag_load_fittings_form', [self::$instance, 'load_fittings_form']);
        add_action('wp_ajax_ispag_save_fittings', [self::$instance, 'ajax_save_fittings']);
        add_action('wp_ajax_ispag_delete_fitting', [self::$instance, 'ajax_delete_fitting']);
        add_filter('ispag_get_tank_connections_description', [self::$instance, 'get_tank_connections_description'], 10, 2 );
        add_filter('ispag_get_tank_drilled_plate_description', [self::$instance, 'get_tank_drilled_plate_description'], 10, 2 );
        add_action('ispag_delete_fittings_with_tank_id', [self::$instance, 'delete_fittings_with_tank_id'],10,2);
        add_filter('ispag_get_fittings_with_tank_id', [self::$instance, 'get_fittings_with_tank_id'],10,2);
    }

    public function get_fitting_btn($title, $article_id){
        return '<button type="button" class="ispag-btn ispag-btn-grey-outlined" id="open-tank-fittings-modal" data-article-id="'. $article_id . '">
            ' . __('Configure fittings', 'creation-reservoir') . '
        </button>';
    }

    public function delete_fittings_with_tank_id($html, $tank_id){
        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_connection';
        $wpdb->delete($table, ['TankId' => $tank_id]);
    }

    public function get_modal_fitting(){
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/form-tank-fittings.php';
        $template_form = ob_get_clean();
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/form-tank-welding.php';
        $template_welding_form = ob_get_clean();
        
        return '<div id="tank-fittings-modal" class="ispag-modal-fullscreen" style="display:none;">
            <div class="ispag-modal-fullscreen-inner">
                <div class="ispag-modal-fitting-left" id="ispag-modal-svg" style="height: 500px; border: 1px solid #ccc;">
                    <!-- Ici le dessin ou l’image -->
                    <img src="' . plugin_dir_url(__FILE__) . '../assets/img/placeholder.webp" alt="Cuve" style="max-width:100%;">
                </div>
                <div class="ispag-modal-fitting-right" id="tank-fittings-form-container">
                    <h2>' . __('Fittings configuration', 'creation-reservoir') . '</h2>
                    <p>' . apply_filters('ispag_get_3d_renderer_btn', null, null) . '</p>
                    <form id="fittings-form">
                        <!-- Formulaire généré dynamiquement ici -->
                        
                    </form>
                    <button type="button" class="ispag-btn ispag-btn-grey-outlined" id="add-fitting-row">
                        <span class="dashicons dashicons-plus-alt"></span> ' . __('Add fitting', 'creation-reservoir') . '
                    </button>
                    <button type="button" class="ispag-btn ispag-btn-grey-outlined" id="add-welding-row">
                        <span class="dashicons dashicons-plus-alt"></span> ' . __('Add welding / drilled plate', 'creation-reservoir') . '
                    </button>
                    <button type="submit" class="ispag-btn ispag-btn-danger-outlined" id="ispag-btn-save-tank-fittings">
                        <span class="dashicons dashicons-media-archive"></span> ' . __('Save fittings', 'creation-reservoir') . '
                    </button>
                    <!-- Template HTML à cloner -->
                    <template id="fitting-row-template">
                        ' . $template_form . '
                    </template>
                    <template id="welding-row-template">
                        ' . $template_welding_form . '
                    </template>
                </div>
            </div>
            <button class="ispag-modal-close" onclick="closeFittingsModal()">×</button>
        </div>
        ';
    }
    public function get_fittings_with_tank_id($html, $article_id){
        return $this->get_all_fittings($article_id, false);
    }
    
    public function get_all_fittings($article_id, $is_description = false){

        if (empty($article_id) || !is_numeric($article_id)) {
            return [];
        }

        $group_by = $is_description ? "GROUP BY c.Type, c.Pouces, c.Accessories, c.madeFor" : '';
        $count = $is_description ? ", COUNT(*) as qty" : '';

        $tank_designer = new ISPAG_Tank_Designer();
        $tank_id = $tank_designer->get_tank_id_by_article_id($article_id);
        $query = $this->wpdb->prepare(
            "SELECT c.Id AS fitting_id, tc.Value AS Type, tp.DN AS Pouces, ta.Value AS Accessories, c.Accessories AS id_accessories, c.madeFor, c.Height, c.Angle, tp.*  $count
            FROM $this->table_connections c
            LEFT JOIN $this->table_flange_dimension tp ON tp.Id = c.Pouces
            LEFT JOIN $this->table_conception tc ON tc.Id = tp.Typ
            LEFT JOIN $this->table_conception ta ON ta.Id = c.Accessories
            WHERE c.TankId = %d
            AND c.Type NOT IN (22, 23)
            $group_by
            ORDER BY tc.sort ASC, tp.InternalDiamter DESC",
            $tank_id
        );

        $connections = $this->wpdb->get_results($query);
// \1('get_all_fittings : ' . print_r($query, true));
// echo '<pre>';
// var_dump($connections);
// echo '</pre>';
        return $connections;

    }

    public function get_tank_connections_description($html, $article_id) {
        $connections = $this->get_all_fittings($article_id, true);
        $lines = [];
        
        foreach ($connections as $conn) {
            $acc_text = '';
            
            // Gestion des accessoires (déjà fait précédemment)
            if (!empty($conn->Accessories)) {
                $translated_acc = __($conn->Accessories, 'creation-reservoir');
                if (mb_stripos(trim($translated_acc), 'pour') === 0) {
                    $acc_text = $translated_acc;
                } else {
                    $acc_text = __('with', 'creation-reservoir') . ' ' . $translated_acc;
                }
            }

            // --- NOUVELLE LOGIQUE POUR LE "MADE FOR" ---
            $for = '';
            if (!empty($conn->madeFor)) {
                $trimmed_for = trim($conn->madeFor);
                
                // On vérifie si ça commence par "pour" (insensible à la casse) ou par "("
                $starts_with_pour = (mb_stripos($trimmed_for, 'pour') === 0);
                $starts_with_bracket = (mb_substr($trimmed_for, 0, 1) === '(');

                if ($starts_with_pour || $starts_with_bracket) {
                    // On affiche tel quel
                    $for = $trimmed_for;
                } else {
                    // On ajoute le préfixe "pour" (traduit)
                    $for = __('for', 'creation-reservoir') . ' ' . $trimmed_for;
                }
            }

            $lines[] = sprintf(
                '%d x %s %s %s %s',
                $conn->qty,
                __($conn->Type, 'creation-reservoir'),
                $conn->Pouces,
                $acc_text,
                $for
            );
        }
        
        return implode("\n", $lines);
    }

    public function load_fittings_form() {
        $article_id = intval($_POST['article_id'] ?? 0);
        $data = [];
        if (!$article_id) {
            wp_send_json_error('Invalid article ID');
        }
        $connections = $this->get_all_fittings($article_id, false);
        $weldings = apply_filters('ispag_get_all_welding_drilled_plate', null, $article_id, false);
        
        ob_start();
        echo '<input type="hidden" name="article_id" value="' . $article_id . '">';
        foreach ($connections as $fitting):
            include plugin_dir_path(__FILE__) . 'templates/form-tank-fittings.php'; 
        endforeach;

        foreach ($weldings as $welding):
            include plugin_dir_path(__FILE__) . 'templates/form-tank-welding.php'; 
        endforeach;



        // $svg_generator = new ISPAG_Tank_SVG_Generator();
        // $svg_generator->load_data($article_id); // tu mets l’ID de l’article cuve ici
        // $data['svg'] = $svg_generator->render_svg();
        $data['svg'] = apply_filters('ispag_design_tank_svg', null, $article_id, true); 
        $data['svg'] .= apply_filters('ispag_design_tank_top_view_svg', null, $article_id); 

        $data['html'] = ob_get_clean();

        wp_send_json_success($data);
    }

    public function save_fittings($article_id, $data) {
        if (empty($article_id) || !is_numeric($article_id)) return;

        $tank_data = new ISPAG_Tank_Designer();
        $tank_id = $tank_data->get_tank_id_by_article_id($article_id);

        $inserted_ids = [];
// \1('save_fittings : ' . print_r($data, true));

        foreach ($data['id'] as $index => $id) {
            $id = intval($id);
            $values = [
                'TankId'            => $tank_id,
                'Type'              => intval($data['type'][$index] ?? 0),
                'Pouces'            => intval($data['diameter'][$index] ?? ''),
                'Accessories'       => intval($data['accessories'][$index] ?? 0),
                'madeFor'           => sanitize_text_field($data['madeFor'][$index] ?? ''),
                'Height'            => intval($data['height'][$index] ?? 0),
                'Angle'             => intval($data['angle'][$index] ?? 0),
                'heightApproved'    => 1,
            ];
//             error_log('save_fittings ' . $id . ' : ' . print_r($values, true));

            if ($id > 0) {
                $this->wpdb->update($this->table_connections, $values, ['Id' => $id]);
            } else {
                $this->wpdb->insert($this->table_connections, $values);
                $new_id = $this->wpdb->insert_id;
                $inserted_ids[] = [
                    'index' => $index,
                    'id'    => $new_id,
                ];
            }
        }
        $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : 0;

        do_action('ispag_add_note', null, $data, $deal_id, null, false, true);

        return $inserted_ids;
    }

    private function get_fitting_type($diamter = null){
        if($diamter == null) return '';

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT Typ
            FROM $this->table_flange_dimension
            WHERE Id = %d
            ",
            $diamter
        ));

    }


    public function ajax_save_fittings() {
        if (!current_user_can('generate_tank')) {
            wp_send_json_error('Unauthorized');
        }

        $article_id = intval($_POST['article_id'] ?? 0);
        $fittings = $_POST['fitting'] ?? [];

        if (!$article_id || empty($fittings)) {
            wp_send_json_error('Invalid data');
        }

//         error_log('ajax_save_fittings : ' . print_r($fittings, true));

        $inserted = $this->save_fittings($article_id, $fittings);
        $drawing = apply_filters('ispag_design_tank_svg', null, $article_id, true);
        $drawing .= apply_filters('ispag_design_tank_top_view_svg', null, $article_id); 

        wp_send_json_success([
            'inserted' => $inserted,
            'drawing'  => $drawing,
        ]);
    }


    public function ajax_delete_fitting() {
        if (!current_user_can('generate_tank')) {
            wp_send_json_error('Unauthorized');
        }

        $fitting_id = intval($_POST['fitting_id'] ?? 0);

        if (!$fitting_id) {
            wp_send_json_error('Invalid fitting ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_connection';
        $deleted = $wpdb->delete($table, ['id' => $fitting_id]);

        if ($deleted === false) {
            wp_send_json_error('Database error');
        }

        wp_send_json_success();
    }

    public function get_all_drilled_plate($article_id, $is_description = false){

        if (empty($article_id) || !is_numeric($article_id)) {
            return [];
        }

        $group_by = $is_description ? "GROUP BY c.Type" : '';
        $count = $is_description ? ", COUNT(*) as qty" : '';

        $tank_designer = new ISPAG_Tank_Designer();
        $tank_id = $tank_designer->get_tank_id_by_article_id($article_id);

        $connections = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT c.Id AS fitting_id, tc.Value AS Type $count
            FROM $this->table_connections c
            LEFT JOIN $this->table_conception tc ON tc.Id = c.Type
            WHERE c.TankId = %d
            AND c.Type IN (22)
            $group_by",
            $tank_id
        ));
// echo '<pre>';
// var_dump($connections);
// echo '</pre>';
        return $connections;

    }
    public function get_tank_drilled_plate_description($html, $article_id) {
        $connections = $this->get_all_drilled_plate($article_id, true);
        $lines = [];
        foreach ($connections as $conn) {

           
            $lines[] = str_replace('%NB_PIECES%', $conn->qty, __("With %NB_PIECES% drilled plate for better stratification", "creation-reservoir"));
        }
        // error_log('ERREUR: $lines n\'est pas un tableau dans ' . __METHOD__);
        // error_log(print_r($lines, true));
        return implode("\n", $lines);
    } 
}