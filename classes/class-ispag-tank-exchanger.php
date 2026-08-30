<?php
/**
 * Class ISPAG_Tank_Exchanger
 * Gère les échangeurs thermiques pour les réservoirs ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_tank_exchanger.log.
 */
class ISPAG_Tank_Exchanger
{
    private $wpdb;
    protected static $instance = null;
    private $table;
    private const LOG_NAME = 'tank_exchanger';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'achats_tank_heat_exchanger';
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
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

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        // $logger->log_user_action(self::LOG_NAME, 'scripts_hooks_registered', [], $user_id);

        add_action('wp_ajax_ispag_save_heat_exchangers', [self::$instance, 'save_heat_exchangers']);
        add_action('wp_ajax_nopriv_ispag_save_heat_exchangers', [self::$instance, 'save_heat_exchangers']);
        // $logger->log_user_action(self::LOG_NAME, 'ajax_hooks_registered', ['hook' => 'ispag_save_heat_exchangers'], $user_id);

        add_filter('ispag_get_heat_exchanger_description', [self::$instance, 'get_description'], 10, 2);
        add_filter('ispag_get_heat_exchanger_titles_array', [self::$instance, 'get_titles_array'], 10, 2);
        add_filter('ispag_get_heat_exchanger_nb', [self::$instance, 'get_heat_exchanger_nb'], 10, 2);
        add_filter('ispag_get_heat_exchanger_datas', [self::$instance, 'get_coils'], 10, 2);
        add_filter('ispag_get_exchanger_btn', [self::$instance, 'get_exchanger_btn'], 10, 2);
        // $logger->log_user_action(self::LOG_NAME, 'filters_registered', [], $user_id);

        add_action('ispag_delete_exchanger_with_tank_id', [self::$instance, 'delete_exchanger_with_tank_id'], 10, 2);
        // $logger->log_user_action(self::LOG_NAME, 'delete_hook_registered', [], $user_id);

        add_action('wp_ajax_ispag_add_heat_exchanger_form', [self::$instance, 'ispag_handle_ajax_exchanger_form']);
        // $logger->log_user_action(self::LOG_NAME, 'add_form_hook_registered', [], $user_id);
    }

    public static function enqueue_assets($hook)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        // $logger->log_user_action(self::LOG_NAME, 'enqueue_assets_start', [], $user_id);

        $handle = 'ispag-heat-exchanger';
        $version = '1.0.' . time();

        wp_register_script(
            $handle,
            plugin_dir_url(__FILE__) . '../assets/js/heat-exchanger.js',
            ['jquery'],
            $version,
            true
        );

        wp_localize_script($handle, 'ispag_ajax', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag_exchanger_nonce'),
            'i18n' => [
                'select_action' => __('Please select an action.', 'ispag-crm'),
                'select_contact' => __('Please select at least one contact.', 'ispag-crm'),
                'confirm_delete' => __('Are you sure you want to delete the selected contacts?', 'ispag-crm'),
                'high' => __('A - High', 'ispag-crm'),
                'medium' => __('B - Medium', 'ispag-crm'),
                'low' => __('C - Low', 'ispag-crm'),
                'company_id' => __('Company ID', 'ispag-crm'),
                'select_owner' => __('-- Select owner --', 'ispag-crm'),
                'preparing' => __('Preparing...', 'ispag-crm'),
                'prepare_meeting' => __('Prepare meeting', 'ispag-crm'),
            ]
        ]);

        wp_enqueue_script($handle);
        // $logger->log_user_action(self::LOG_NAME, 'script_enqueued', ['handle' => $handle], $user_id);
    }

    public function delete_exchanger_with_tank_id($html, $tank_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'delete_exchanger_with_tank_id_start', ['tank_id' => $tank_id], $user_id);

        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_heat_exchanger';
        $result = $wpdb->delete($table, ['tank_id' => $tank_id]);

        $this->logger->log_db_change(self::LOG_NAME, $table, 'DELETE', ['tank_id' => $tank_id, 'result' => $result], $user_id);
    }

    public function get_exchanger_btn($html, $article_id = null)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_exchanger_btn_start', ['article_id' => $article_id], $user_id);

        $tank_id = apply_filters('ispag_get_tank_id_by_article_id', $article_id);
        if (!$tank_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Tank ID not found for article ' . $article_id, $user_id);
            return $html;
        }

        $this->logger->log_db_change(self::LOG_NAME, 'tank_ids', 'FETCH', ['article_id' => $article_id, 'tank_id' => $tank_id], $user_id);

        $btn = '<button class="openExchangerModal ispag-btn ispag-btn-grey-outlined" data-tank-id="' . esc_attr($tank_id) . '">'
            . __('Heat exchanger', 'creation-reservoir')
            . '</button>'
            . $this->heat_exchanger_modal($tank_id);

        $this->logger->log_user_action(self::LOG_NAME, 'exchanger_btn_rendered', ['tank_id' => $tank_id], $user_id);
        return $btn;
    }

    public function ispag_handle_ajax_exchanger_form()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ispag_handle_ajax_exchanger_form_start', [], $user_id);

        if (!current_user_can('edit_posts'))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: User cannot edit posts', $user_id);
            wp_die('Accès refusé');
        }

        $this->logger->log_user_action(self::LOG_NAME, 'post_data_received', ['data' => $_POST], $user_id);

        $coil_nb = isset($_POST['coil_nb']) ? intval($_POST['coil_nb']) : 1;
        $tank_id = isset($_POST['tank_id']) ? intval($_POST['tank_id']) : 0;

        if ($tank_id === 0)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing tank_id', $user_id);
            wp_send_json_error(['message' => 'ID du réservoir manquant.']);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'form_params_validated', ['tank_id' => $tank_id, 'coil_nb' => $coil_nb], $user_id);

        if (!class_exists('ISPAG_Tank_Exchanger'))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: ISPAG_Tank_Exchanger class not found', $user_id);
            wp_die('Classe introuvable');
        }

        $form_html = $this->render_heat_exchanger_form($tank_id, $coil_nb, []);

        if (empty($form_html))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty form HTML', $user_id);
        }
        else
        {
            $this->logger->log_user_action(self::LOG_NAME, 'form_html_generated', ['length' => strlen($form_html)], $user_id);
        }

        wp_send_json_success($form_html);
    }

    public function heat_exchanger_modal($tank_id = null)
    {
        $user_id = get_current_user_id();

        if (empty($tank_id))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty tank_id for modal', $user_id);
            return '';
        }

        $this->logger->log_user_action(self::LOG_NAME, 'heat_exchanger_modal_rendered', ['tank_id' => $tank_id], $user_id);

        return '
        <div id="exchangerModal_' . esc_attr($tank_id) . '"
            class="ispag-product-modal"
            style="display: none;"
            data-tank-id="' . esc_attr($tank_id) . '">
            <div class="ispag-modal-content">
                <span class="closeExchangerModal ispag-modal-close ispag-close-croix">&times;</span>
                <div class="exchangerFormsContainer ispag-modal-grid">
                    ' . $this->load_heat_exchanger_forms($tank_id) . '
                </div>
                <div class="ispag-modal-footer">
                    <button class="addExchangerForm ispag-btn ispag-btn-secondary-outlined"><span class="dashicons dashicons-plus-alt"></span> ' . __('Add exchanger', 'creation-reservoir') . '</button>
                    <button class="saveExchangers ispag-btn ispag-btn-red-outlined" data-tank-id="' . esc_attr($tank_id) . '"><span class="dashicons dashicons-media-archive"></span> ' . __('Save', 'creation-reservoir') . '</button>
                </div>
            </div>
        </div>
        ';
    }

    public function load_heat_exchanger_forms($tank_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'load_heat_exchanger_forms_start', ['tank_id' => $tank_id], $user_id);

        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_heat_exchanger';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE tank_id = %d",
            $tank_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $table, 'SELECT', ['tank_id' => $tank_id, 'result' => !empty($row)], $user_id);

        $forms = '';
        if ($row)
        {
            $coils = json_decode($row->coilDetails, true);
            $this->logger->log_user_action(self::LOG_NAME, 'coils_data_decoded', ['count' => count($coils)], $user_id);

            foreach ($coils as $index => $coilData)
            {
                $coil_nb = intval(str_replace('coil', '', $index));
                $this->logger->log_user_action(self::LOG_NAME, 'rendering_coil_form', ['coil_nb' => $coil_nb], $user_id);
                $forms .= $this->render_heat_exchanger_form($tank_id, $coil_nb, $coilData);
            }
        }
        else
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_existing_data_empty_form', [], $user_id);
            $forms .= $this->render_heat_exchanger_form($tank_id, 1);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'load_heat_exchanger_forms_complete', [], $user_id);
        return $forms;
    }

    public function render_heat_exchanger_form($tank_id, $coil_nb, $data = [])
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_heat_exchanger_form_start', ['tank_id' => $tank_id, 'coil_nb' => $coil_nb, 'data' => $data], $user_id);

        ob_start();
        $args = ['coil_nb' => $coil_nb, 'data' => $data];
        include plugin_dir_path(__FILE__) . 'templates/heat-exchanger-form.php';
        $form_html = ob_get_clean();

        $this->logger->log_user_action(self::LOG_NAME, 'form_rendered', ['length' => strlen($form_html)], $user_id);
        return $form_html;
    }

    public function get_coils($thml, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_coils_start', ['article_id' => $article_id], $user_id);

        $article_id = intval($article_id);
        $tank_id = apply_filters('ispag_get_tank_id_by_article_id', $article_id);
        $this->logger->log_db_change(self::LOG_NAME, 'tank_ids', 'FETCH', ['article_id' => $article_id, 'tank_id' => $tank_id], $user_id);

        if ($tank_id <= 0)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid tank_id', $user_id);
            return [];
        }

        $sql = $this->wpdb->prepare("SELECT coilDetails FROM {$this->table} WHERE tank_id = %d LIMIT 1", $tank_id);
        $json = $this->wpdb->get_var($sql);
        $this->logger->log_db_change(self::LOG_NAME, $this->table, 'SELECT_COIL_DETAILS', ['tank_id' => $tank_id, 'json' => $json], $user_id);

        if (!$json)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_coil_data_found', ['tank_id' => $tank_id], $user_id);
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid JSON data', $user_id);
            return [];
        }

        $this->logger->log_user_action(self::LOG_NAME, 'coils_data_returned', ['count' => count($data)], $user_id);
        return $data;
    }

    public function get_heat_exchanger_nb($nb, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_heat_exchanger_nb_start', ['article_id' => $article_id], $user_id);

        $coils = $this->get_coils(null, $article_id);
        $count = count($coils);
        $this->logger->log_user_action(self::LOG_NAME, 'heat_exchanger_count', ['article_id' => $article_id, 'count' => $count], $user_id);

        return $count;
    }

    public function get_description($description, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_description_start', ['article_id' => $article_id], $user_id);

        $coils = $this->get_coils(null, $article_id);
        if (empty($coils))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_coils_for_description', [], $user_id);
            return $description;
        }

        $lines = [];
        foreach ($coils as $key => $coil)
        {
            $surface = $coil['coilSurface'] ?? '?';
            $input = $coil['loadInputTemperature'] ?? '?';
            $output = $coil['loadOutputTemperature'] ?? '?';
            $cold = $coil['coldWaterInputTemperature'] ?? '?';
            $hot = $coil['hotWaterOutputTemperature'] ?? '?';
            $power = $coil['exchangerPower'] ?? '?';
            $comment = $coil['comment'] ?? '?';
            $spiraflex = $coil['spiraflex'] ?? '0';
            $exchanger_pression = $coil['exchangerPression'] ?? null;

            $this->logger->log_user_action(self::LOG_NAME, 'coil_data_processed', [
                'key' => $key, 
                'surface' => $surface, 
                'input' => $input, 
                'output' => $output, 
                'cold' => $cold, 
                'hot' => $hot, 
                'power' => $power, 
                'comment' => $comment, 
                'spiraflex' => $spiraflex, 
                'exchanger_pression' => $exchanger_pression
            ], $user_id);

            if ($spiraflex == 1) {
                $line = sprintf(
                    __('Spiraflex Coil %s: %sm²', 'creation-reservoir'),
                    $key, $surface
                );
            } else {
                $line = sprintf(
                    __('%s Coil %s: %sm² – In %s°C / Out %s°C – Water %s°C / %s°C – %s kW %s', 'creation-reservoir'),
                    $spiraflex, $key, $surface, $input, $output, $cold, $hot, $power, $comment
                );
            }

            // Ajout de la pression entre parenthèses si elle existe
            if (!empty($exchanger_pression) && $exchanger_pression !== '0') {
                $line .= sprintf(' (%s bar)', $exchanger_pression);
            }

            $lines[] = $line;
        }

        $final_description = implode('<br />', $lines);
        $this->logger->log_user_action(self::LOG_NAME, 'description_generated', ['description' => $final_description], $user_id);
        return $final_description;
    }

    public function get_titles_array($titles, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_titles_array_start', ['article_id' => $article_id], $user_id);

        $coils = $this->get_coils(null, $article_id);
        if (empty($coils))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_coils_for_titles', [], $user_id);
            return [];
        }

        $result = [];
        foreach ($coils as $key => $coil)
        {
            $surface = $coil['coilSurface'] ?? '?';
            $input = $coil['loadInputTemperature'] ?? '?';
            $output = $coil['loadOutputTemperature'] ?? '?';
            $power = $coil['exchangerPower'] ?? '?';
            $clean_key = str_ireplace('coil', '', $key);
            $comment = trim($coil['comment'] ?? '');
            $spiraflex = $coil['spiraflex'] ?? '';
            $exchanger_pression = $coil['exchangerPression'] ?? null;

            $this->logger->log_user_action(self::LOG_NAME, 'title_processed', [
                'key' => $key, 
                'surface' => $surface, 
                'input' => $input, 
                'output' => $output, 
                'power' => $power, 
                'comment' => $comment,
                'exchanger_pression' => $exchanger_pression
            ], $user_id);

            if ($spiraflex == 1) {
                $title = sprintf(
                    __('Spiraflex DN32 %s : %sm²', 'creation-reservoir'),
                    $clean_key, $surface
                );
            } else {
                $title = sprintf(
                    __('Heat exchanger %s : %sm²', 'creation-reservoir'),
                    $clean_key, $surface
                );
                
            }

            // Construction des détails à mettre entre parenthèses
            $extra_details = [];

            if (!empty($exchanger_pression) && $exchanger_pression !== '0') {
                $extra_details[] = sprintf('%s bar', $exchanger_pression);
            }

            if (!empty($comment) && $comment !== '?') {
                $extra_details[] = $comment;
            }

            // Si on a de la pression ou un commentaire, on l'ajoute entre parenthèses
            if (!empty($extra_details)) {
                $title .= sprintf(' (%s)', implode(' - ', $extra_details));
            }

            $result[$key] = $title;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'titles_array_generated', ['count' => count($result)], $user_id);
        return $result;
    }

    public function save_heat_exchangers()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'save_heat_exchangers_start', [], $user_id);

        if (!current_user_can('generate_tank'))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: User cannot generate tank', $user_id);
            wp_send_json_error(__('Unauthorized', 'creation-reservoir'));
        }

        $this->logger->log_user_action(self::LOG_NAME, 'post_data_received', ['data' => $_POST], $user_id);

        $tank_id = isset($_POST['tank_id']) ? intval($_POST['tank_id']) : 0;
        $exchangers_json = isset($_POST['exchangers']) ? stripslashes($_POST['exchangers']) : '';

        if (!$tank_id || empty($exchangers_json))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing tank_id or exchangers_json', $user_id);
            wp_send_json_error(__('Données manquantes', 'creation-reservoir'));
        }

        $this->logger->log_user_action(self::LOG_NAME, 'exchangers_json_received', ['tank_id' => $tank_id, 'json_length' => strlen($exchangers_json)], $user_id);

        $exchangers_array = json_decode($exchangers_json, true);
        if (!is_array($exchangers_array))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: JSON decode failed - ' . json_last_error_msg(), $user_id);
            wp_send_json_error(__('Format de données invalide', 'creation-reservoir'));
        }

        $this->logger->log_user_action(self::LOG_NAME, 'exchangers_decoded', ['count' => count($exchangers_array)], $user_id);

        $totalSurface = 0;
        foreach ($exchangers_array as $coil)
        {
            $surface = floatval($coil['coilSurface'] ?? 0);
            $totalSurface += $surface;
            $this->logger->log_user_action(self::LOG_NAME, 'surface_calculated', ['coil_surface' => $surface, 'total_surface' => $totalSurface], $user_id);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_heat_exchanger';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM $table WHERE tank_id = %d LIMIT 1",
            $tank_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $table, 'CHECK_EXISTS', ['tank_id' => $tank_id, 'exists' => $exists], $user_id);

        $final_json = json_encode($exchangers_array, JSON_UNESCAPED_UNICODE);
        $this->logger->log_user_action(self::LOG_NAME, 'final_json_prepared', ['json_length' => strlen($final_json)], $user_id);

        if ($exists)
        {
            $result = $wpdb->update(
                $table,
                [
                    'heatExchangerSurface' => $totalSurface,
                    'coilDetails' => $final_json
                ],
                ['tank_id' => $tank_id],
                ['%f', '%s'],
                ['%d']
            );
            $this->logger->log_db_change(self::LOG_NAME, $table, 'UPDATE', ['tank_id' => $tank_id, 'heatExchangerSurface' => $totalSurface, 'result' => $result], $user_id);
        }
        else
        {
            $result = $wpdb->insert(
                $table,
                [
                    'tank_id' => $tank_id,
                    'heatExchangerSurface' => $totalSurface,
                    'coilDetails' => $final_json
                ],
                ['%d', '%f', '%s']
            );
            $this->logger->log_db_change(self::LOG_NAME, $table, 'INSERT', ['tank_id' => $tank_id, 'heatExchangerSurface' => $totalSurface, 'result' => $result], $user_id);
        }

        if ($result === false)
        {
            $error = $wpdb->last_error;
            $this->logger->log(self::LOG_NAME, 'ERROR: DB operation failed - ' . $error, $user_id);
            wp_send_json_error($wpdb->last_error);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'save_heat_exchangers_complete', [], $user_id);
        wp_send_json_success(__('Exchanger data has been saved.', 'creation-reservoir'));
    }
}