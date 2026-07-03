<?php

class ISPAG_Tank_Exchanger {
    private $wpdb;
    protected static $instance = null;
    private $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'achats_tank_heat_exchanger';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);

        // Hooks AJAX - On retire le self::$instance et on pointe vers l'instance directement
        add_action('wp_ajax_ispag_save_heat_exchangers', [self::$instance, 'save_heat_exchangers']);
        // Ajoute celui-ci pour tester, même si tu es connecté
        add_action('wp_ajax_nopriv_ispag_save_heat_exchangers', [self::$instance, 'save_heat_exchangers']);

        add_filter('ispag_get_heat_exchanger_description', [self::$instance, 'get_description'], 10, 2);
        add_filter('ispag_get_heat_exchanger_titles_array', [self::$instance, 'get_titles_array'], 10, 2);
        add_filter('ispag_get_heat_exchanger_nb', [self::$instance, 'get_heat_exchanger_nb'], 10, 2);
        add_filter('ispag_get_heat_exchanger_datas', [self::$instance, 'get_coils'], 10, 2);
        add_filter('ispag_get_exchanger_btn', [self::$instance, 'get_exchanger_btn'], 10, 2);

        add_action('ispag_delete_exchanger_with_tank_id', [self::$instance, 'delete_exchanger_with_tank_id'],10,2);

        add_action('wp_ajax_ispag_add_heat_exchanger_form', [self::$instance, 'ispag_handle_ajax_exchanger_form']);


        

    }

    public static function enqueue_assets($hook) {
        $handle = 'ispag-heat-exchanger';
        $version = '1.0.' . time(); // Force le refresh
        
        // 1. On enregistre d'abord
        wp_register_script(
            $handle, 
            plugin_dir_url(__FILE__) . '../assets/js/heat-exchanger.js', 
            ['jquery'], 
            $version, 
            true
        );

        // 2. On localise IMMÉDIATEMENT sur le handle enregistré
        wp_localize_script($handle, 'ispag_ajax', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag_exchanger_nonce'),
            'i18n' => [
                'select_action'  => __('Please select an action.', 'ispag-crm'),
                'select_contact' => __('Please select at least one contact.', 'ispag-crm'),
                'confirm_delete' => __('Are you sure you want to delete the selected contacts?', 'ispag-crm'),
                'high'           => __('A - High', 'ispag-crm'),
                'medium'         => __('B - Medium', 'ispag-crm'),
                'low'            => __('C - Low', 'ispag-crm'),
                'company_id'     => __('Company ID', 'ispag-crm'),
                'select_owner'   => __('-- Select owner --', 'ispag-crm'),
                'preparing'      => __('Preparing...', 'ispag-crm'),
                'prepare_meeting'=> __('Prepare meeting', 'ispag-crm'),
            ]
        ]);

        // 3. On envoie au navigateur
        wp_enqueue_script($handle);
    }

    public function delete_exchanger_with_tank_id($html, $tank_id){
        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_heat_exchanger';
        $wpdb->delete($table, ['tank_id' => $tank_id]);
    }

    public function get_exchanger_btn($html, $article_id = null){
        $tank_id = apply_filters('ispag_get_tank_id_by_article_id', $article_id);
        if (!$tank_id) return $html;

        return '<button class="openExchangerModal ispag-btn ispag-btn-grey-outlined" data-tank-id="' . esc_attr($tank_id) . '">' 
            . __('Heat exchanger', 'creation-reservoir') 
            . '</button>' 
            . $this->heat_exchanger_modal($tank_id);
    }

    public function ispag_handle_ajax_exchanger_form() {
        error_log("--- AJAX ISPAG : Début de l'appel ---");
        
        // Log des données reçues
        error_log("Données POST reçues : " . print_r($_POST, true));

        if (!current_user_can('edit_posts')) {
            error_log("ERREUR : Utilisateur n'a pas les droits edit_posts");
            wp_die('Accès refusé');
        }

        $coil_nb = isset($_POST['coil_nb']) ? intval($_POST['coil_nb']) : 1;
        $tank_id = isset($_POST['tank_id']) ? intval($_POST['tank_id']) : 0;

        if ($tank_id === 0) {
            wp_send_json_error(['message' => 'ID du réservoir manquant.']);
        }
        
        error_log("Traitement pour Tank ID: $tank_id | Coil No: $coil_nb");

        // On instancie la classe
        if (!class_exists('ISPAG_Tank_Exchanger')) {
            error_log("ERREUR : La classe ISPAG_Tank_Exchanger n'existe pas !");
            wp_die('Classe introuvable');
        }

        $exchanger_manager = new ISPAG_Tank_Exchanger();
        $form_html = $exchanger_manager->render_heat_exchanger_form($tank_id, $coil_nb, []);

        if (empty($form)) {
            error_log("ALERTE : Le formulaire rendu est vide.");
        } else {
            error_log("SUCCÈS : Formulaire généré (Taille : " . strlen($form) . " caractères)");
        }

        wp_send_json_success($form_html);
        // echo $form;
        
        // error_log("--- AJAX ISPAG : Fin de l'appel (wp_die) ---");
        // wp_die();
    }

    public function heat_exchanger_modal($tank_id = null) {
        if(empty($tank_id)){
            return '';
        }
        return '
        <div id="exchangerModal_' . esc_attr($tank_id) . '" 
            class="ispag-product-modal" 
            style="display: none;" 
            data-tank-id="' . esc_attr($tank_id) . '">
            <div class="ispag-modal-content">
                <span class="closeExchangerModal ispag-modal-close">&times;</span>
                <div class="exchangerFormsContainer ispag-modal-grid">'
                    . $this->load_heat_exchanger_forms($tank_id) .
                '</div>
                <div class="ispag-modal-footer">
                    <button class="addExchangerForm ispag-btn ispag-btn-secondary-outlined"><span class="dashicons dashicons-plus-alt"></span> '. __('Add exchanger', 'creation-reservoir') . '</button>
                    <button class="saveExchangers ispag-btn ispag-btn-red-outlined" data-tank-id="' . esc_attr($tank_id) . '"><span class="dashicons dashicons-media-archive"></span> ' . __('Save', 'creation-reservoir'). '</button>
                </div>
            </div>
        </div>
        ';
    }



    public function load_heat_exchanger_forms($tank_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_heat_exchanger';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE tank_id = %d", $tank_id
        ));

        $forms = '';
        if ($row) {
            $coils = json_decode($row->coilDetails, true);
            foreach ($coils as $index => $coilData) {
                // Index type "coil1", "coil2" → on extrait le numéro
                $coil_nb = intval(str_replace('coil', '', $index));
                $forms .= $this->render_heat_exchanger_form($tank_id, $coil_nb, $coilData);
            }
        } else {
            $forms .= $this->render_heat_exchanger_form($tank_id, 1); // formulaire vierge
        }

        return $forms;
    }

    public function render_heat_exchanger_form($tank_id, $coil_nb, $data = []) {
        ob_start();
        $args = ['coil_nb' => $coil_nb, 'data' => $data];
        include plugin_dir_path(__FILE__) . 'templates/heat-exchanger-form.php';
        return ob_get_clean();
    }


    public function get_coils($thml, $article_id) {
        $article_id = intval($article_id);
        // error_log('article Id for coil : ' . $article_id);
        $tank_id = apply_filters('ispag_get_tank_id_by_article_id', $article_id);
        // error_log('Tank Id for coil : ' . $tank_id);
        if ($tank_id <= 0) return [];

        $sql = $this->wpdb->prepare("SELECT coilDetails FROM {$this->table} WHERE tank_id = %d LIMIT 1", $tank_id);
        $json = $this->wpdb->get_var($sql);
        if (!$json) return [];

        $data = json_decode($json, true);
        if (!is_array($data)) return [];

        return $data;
    }

    public function get_heat_exchanger_nb($nb, $article_id) {
        $coils = $this->get_coils(null, $article_id);
        return count($coils);
    }


    public function get_description($description, $article_id) {
        $coils = $this->get_coils(null, $article_id);
        if (empty($coils)) return $description;

        $lines = [];

        foreach ($coils as $key => $coil) {
            $surface = $coil['coilSurface'] ?? '?';
            $input = $coil['loadInputTemperature'] ?? '?';
            $output = $coil['loadOutputTemperature'] ?? '?';
            $cold = $coil['coldWaterInputTemperature'] ?? '?';
            $hot = $coil['hotWaterOutputTemperature'] ?? '?';
            $power = $coil['exchangerPower'] ?? '?';
            $comment = $coil['comment'] ?? '?';

            $line = sprintf(
                __('Coil %s: %sm² – In %s°C / Out %s°C – Water %s°C / %s°C – %s kW %s', 'creation-reservoir'),
                $key, $surface, $input, $output, $cold, $hot, $power, $comment
            );
            $lines[] = $line;
        }

        return implode('<br />', $lines);
    }

    public function get_titles_array($titles, $article_id) {
        $coils = $this->get_coils(null, $article_id);
        if (empty($coils)) return [];

        $result = [];

        // error_log('Données échangeurs de l\'article ' . $article_id .' : ' . print_r($coils, true));

        // $result['1'] = 'salut';
        // $result['2'] = 'au revoir';
        foreach ($coils as $key => $coil) {
            
            $surface = $coil['coilSurface'] ?? '?';
            $input = $coil['loadInputTemperature'] ?? '?';
            $output = $coil['loadOutputTemperature'] ?? '?';
            $power = $coil['exchangerPower'] ?? '?';
            $clean_key = str_ireplace('coil', '', $key);
            $comment = $coil['comment'] ?? '';

            $result[$key] = sprintf(
                __('Heat exchanger %s : %sm² %s', 'creation-reservoir'),
                $clean_key, $surface, $comment
            );
        }

        return $result;
    }

    public function save_heat_exchangers() {
        // 1. Log de début
        // error_log('--- ISPAG DEBUG: Début save_heat_exchangers ---');

        // 2. Sécurité
        if (!current_user_can('generate_tank')) {
            // error_log('ISPAG ERROR: Utilisateur non autorisé');
            wp_send_json_error(__('Unauthorized', 'creation-reservoir'));
        }

        // 3. Récupération brute des données POST
        // error_log('POST raw data: ' . print_r($_POST, true));

        $tank_id = isset($_POST['tank_id']) ? intval($_POST['tank_id']) : 0;
        $exchangers_json = isset($_POST['exchangers']) ? stripslashes($_POST['exchangers']) : '';

        // error_log("Tank ID: $tank_id");
        // error_log("JSON reçu: $exchangers_json");

        if (!$tank_id || empty($exchangers_json)) {
            // error_log('ISPAG ERROR: Tank ID ou JSON vide');
            wp_send_json_error(__('Données manquantes', 'creation-reservoir'));
        }

        $exchangers_array = json_decode($exchangers_json, true);

        if (!is_array($exchangers_array)) {
            // error_log('ISPAG ERROR: Échec du json_decode. Erreur JSON: ' . json_last_error_msg());
            wp_send_json_error(__('Format de données invalide', 'creation-reservoir'));
        }

        // 4. Calcul de la surface totale
        $totalSurface = 0;
        foreach ($exchangers_array as $coil) {
            $totalSurface += floatval($coil['coilSurface'] ?? 0);
        }
        // error_log("Surface totale calculée: $totalSurface");

        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_heat_exchanger';

        // 5. Vérification existence
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM $table WHERE tank_id = %d LIMIT 1", 
            $tank_id
        ));
        // error_log("Entrée existante (ID): " . ($exists ? $exists : 'Aucune'));

        $final_json = json_encode($exchangers_array, JSON_UNESCAPED_UNICODE);

        if ($exists) {
            // error_log("Tentative de UPDATE sur table: $table");
            $result = $wpdb->update(
                $table,
                [
                    'heatExchangerSurface' => $totalSurface,
                    'coilDetails'          => $final_json
                ],
                ['tank_id' => $tank_id],
                ['%f', '%s'],
                ['%d']
            );
        } else {
            // error_log("Tentative de INSERT sur table: $table");
            $result = $wpdb->insert(
                $table,
                [
                    'tank_id'              => $tank_id,
                    'heatExchangerSurface' => $totalSurface,
                    'coilDetails'          => $final_json
                ],
                ['%d', '%f', '%s']
            );
        }

        if ($result === false) {
            // error_log('ISPAG SQL ERROR: ' . $wpdb->last_error);
            wp_send_json_error($wpdb->last_error);
        }

        // error_log('--- ISPAG DEBUG: Fin avec succès ---');
        wp_send_json_success(__('Les données de l’échangeur ont été sauvegardées.', 'creation-reservoir'));
    }
}
 