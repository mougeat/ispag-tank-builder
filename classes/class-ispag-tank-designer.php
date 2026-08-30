<?php
/**
 * Class ISPAG_Tank_Designer
 * Gère la conception et les dimensions des réservoirs ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_tank_designer.log.
 */
class ISPAG_Tank_Designer
{
    private $conception_table;
    private $dimension_table;
    private $wpdb;
    protected static $instance = null;
    private const LOG_NAME = 'tank_designer';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->conception_table = $wpdb->prefix . 'achats_tank_conception';
        $this->dimension_table = $wpdb->prefix . 'achats_tank_dimensions';

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

        add_action('ispag_render_tank_form', [self::$instance, 'render_tank_form']);
        add_action('ispag_render_tank_dimensions_form', [self::$instance, 'render_dimensions_form']);
        add_action('wp_ajax_ispag_save_tank_data', [self::$instance, 'ajax_save_tank_data']);
        add_action('ispag_duplicate_tank_data', [self::$instance, 'duplicate_tank_data'], 10, 2);
        add_filter('ispag_get_tank_id_by_article_id', [self::$instance, 'get_tank_id_by_article_id'], 10, 1);
        add_filter('ispag_get_tank_datas', [self::$instance, 'get_tank_data'], 10, 2);
        add_filter('ispag_auto_saver_tank_data', [self::$instance, 'save_tank_data'], 10, 3);
        add_action('ispag_get_tank_created_by_id', [self::$instance, 'get_tank_created_by_id'], 10, 2);

        add_action('wp_ajax_ispag_save_tank_unit_price', [self::$instance, 'save_tank_unit_price']);
        add_action('wp_ajax_nopriv_ispag_save_tank_unit_price', [self::class, 'save_tank_unit_price']);

        add_action('wp_ajax_ispag_select_tank_type', [self::$instance, 'select_tank_type']);
        add_action('wp_ajax_nopriv_ispag_select_tank_type', [self::$instance, 'select_tank_type']);
        add_action('wp_ajax_ispag_tank_conception', [self::$instance, 'render_tank_conception']);
        add_action('wp_ajax_nopriv_ispag_tank_conception', [self::$instance, 'render_tank_conception']);

        // $logger->log_user_action(self::LOG_NAME, 'hooks_and_filters_registered', [], $user_id);
    }

    /**
     * Affiche le formulaire de conception pour les articles de type 1
     */
    public function render_tank_form($article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_tank_form_start', ['article_id' => $article_id], $user_id);

        $data = $this->get_tank_data(null, $article_id);
        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_TANK_DATA', ['article_id' => $article_id], $user_id);

        include plugin_dir_path(__FILE__) . 'templates/form-tank-fields.php';
        $this->logger->log_user_action(self::LOG_NAME, 'tank_form_rendered', ['article_id' => $article_id], $user_id);
    }

    public function render_tank_conception()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_tank_conception_start', [], $user_id);

        $data = $this->get_tank_data(null, null);
        $this->logger->log_user_action(self::LOG_NAME, 'tank_conception_data_fetched', [], $user_id);

        include plugin_dir_path(__FILE__) . 'templates/form-tank-conception-field.php';
        $this->logger->log_user_action(self::LOG_NAME, 'tank_conception_rendered', [], $user_id);
    }

    public function select_tank_type()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'select_tank_type_start', [], $user_id);

        $type_id = intval($_POST['type_id']);
        $deal_id = intval($_POST['deal_id']);
        $achat_id = intval($_POST['poid']);

        $this->logger->log_user_action(self::LOG_NAME, 'type_selection_params', ['type_id' => $type_id, 'deal_id' => $deal_id, 'achat_id' => $achat_id], $user_id);

        global $wpdb;
        $table_prestation = $wpdb->prefix . 'achats_tank_conception';

        $types = $wpdb->get_results("SELECT Id, Value AS type, image FROM $table_prestation WHERE SelectType = 'typ'");
        $this->logger->log_db_change(self::LOG_NAME, $table_prestation, 'FETCH_TANK_TYPES', ['count' => count($types)], $user_id);

        $user = wp_get_current_user();
        $isAdmin = in_array('administrator', $user->roles);
        $this->logger->log_user_action(self::LOG_NAME, 'user_roles_checked', ['is_admin' => $isAdmin], $user_id);

        $translated_types = [];
        foreach ($types as $type)
        {
            $translated_label = __($type->type, 'creation-reservoir');
            $translated_types[] = [
                'Id' => $type->Id,
                'type' => $type->type,
                'translated_label' => $translated_label,
                'image' => $type->image
            ];
            $this->logger->log_user_action(self::LOG_NAME, 'type_translated', ['type_id' => $type->Id, 'translated_label' => $translated_label], $user_id);
        }

        usort($translated_types, function($a, $b)
        {
            return strcmp($a['translated_label'], $b['translated_label']);
        });
        $this->logger->log_user_action(self::LOG_NAME, 'types_sorted', [], $user_id);

        echo '<div id="ispag-tank-typ-selector">';
        echo '<p class="ispag-modal-subtitle">' . __('Select tank type', 'creation-reservoir') . '</p>';
        echo '<div class="ispag-type-grid">';

        foreach ($translated_types as $type)
        {
            $image_attributes = wp_get_attachment_image_src($type['image'], 'thumbnail');
            $image_url = $image_attributes ? $image_attributes[0] : '';

            $this->logger->log_user_action(self::LOG_NAME, 'type_card_rendered', ['type_id' => $type['Id'], 'has_image' => !empty($image_url)], $user_id);

            echo '<div class="ispag-type-card" data-id="' . esc_attr($type['Id']) . '" data-card-titel="' . esc_html($type['translated_label']) . '" data-selector-type="tank_type" data-is-admin="' . $isAdmin . '">';
            echo '  <div class="ispag-type-image-wrapper">';
            if ($image_url)
            {
                echo '    <img src="' . esc_url($image_url) . '" alt="' . esc_attr($type['translated_label']) . '" class="ispag-type-img">';
            }
            else
            {
                echo '    <span class="dashicons dashicons-archive"></span>';
            }
            echo '  </div>';
            echo '  <span class="ispag-type-label">' . esc_html($type['translated_label']) . '</span>';
            echo '</div>';
        }

        echo '</div>';
        echo '<div id="new-article-form-container" style="margin-top:20px;"></div>';
        echo '</div>';

        $this->logger->log_user_action(self::LOG_NAME, 'select_tank_type_complete', [], $user_id);
        wp_die();
    }

    /**
     * Affiche le formulaire de dimensions pour les articles de type 1
     */
    public function render_dimensions_form($article_id, $source = 'project')
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_dimensions_form_start', ['article_id' => $article_id, 'source' => $source], $user_id);

        $data = $this->get_tank_data(null, $article_id);
        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_TANK_DATA', ['article_id' => $article_id], $user_id);

        $display = $article_id != 0 ? "" : 'style="display:none;"';
        $this->logger->log_user_action(self::LOG_NAME, 'dimensions_form_display_set', ['display' => $display], $user_id);

        include plugin_dir_path(__FILE__) . 'templates/form-tank-dimensions-field.php';
        $this->logger->log_user_action(self::LOG_NAME, 'dimensions_form_rendered', ['article_id' => $article_id], $user_id);
    }

    private function safe_get($obj, $prop)
    {
        $result = (is_object($obj) && isset($obj->$prop)) ? $obj->$prop : null;
        $this->logger->log_user_action(self::LOG_NAME, 'safe_get_called', ['prop' => $prop, 'result' => $result], get_current_user_id());
        return $result;
    }

    public function get_tank_data($html, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_data_start', ['article_id' => $article_id], $user_id);

        if ($article_id == 0 || empty($article_id))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty or zero article_id', $user_id);
            return [
                'conception' => '',
                'dimensions' => '',
                'insulation' => ''
            ];
        }

        $conception = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT d.TankType, d.Material, d.Support, c.Value AS material_text, d.openComment
            FROM {$this->dimension_table} d
            LEFT JOIN {$this->conception_table} c ON c.Id = d.Material
            WHERE d.customerTankId = %d", $article_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_CONCEPTION', ['article_id' => $article_id, 'result' => !empty($conception)], $user_id);

        if ($conception)
        {
            $material = $conception->Material ?? null;
            $type = $conception->TankType ?? null;

            if ($material !== null && $type !== null)
            {
                $conception->Finition = $this->get_tank_finition($material, $type);
                $this->logger->log_user_action(self::LOG_NAME, 'finition_resolved', ['material' => $material, 'type' => $type, 'finition' => $conception->Finition], $user_id);
            }
            else
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Missing material or type for finition', $user_id);
                $conception->Finition = null;
            }
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Conception is null', $user_id);
        }

        $dimensions = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT Volume, Diameter, Height, FeetHeight, GroundClearance, MaxPressure, TestPressure, usingTemperature FROM {$this->dimension_table} WHERE customerTankId = %d", $article_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_DIMENSIONS', ['article_id' => $article_id, 'result' => !empty($dimensions)], $user_id);

        if ($dimensions)
        {
            $diameter = $this->safe_get($dimensions, 'Diameter');
            $height = $this->safe_get($dimensions, 'Height');

            if ($diameter !== null && $height !== null)
            {
                $dimensions->TippingHeight = $this->calculate_tipping($diameter, $height);
                $this->logger->log_user_action(self::LOG_NAME, 'tipping_height_calculated', ['diameter' => $diameter, 'height' => $height, 'tipping_height' => $dimensions->TippingHeight], $user_id);
            }
            else
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Missing diameter or height for tipping calculation', $user_id);
            }
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Dimensions is null', $user_id);
        }

        $insulation = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT it.Value AS InsulationThickness, i.Value AS insulation, c.Value AS insulationCover
                FROM {$this->dimension_table} dt
                LEFT JOIN {$this->conception_table} it
                    ON it.Id = dt.InsulationThickness
                LEFT JOIN {$this->conception_table} i
                    ON i.Id = dt.insulation
                LEFT JOIN {$this->conception_table} c
                    ON c.Id = dt.insulationCover
                WHERE dt.customerTankId = %d", $article_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_INSULATION', ['article_id' => $article_id, 'result' => !empty($insulation)], $user_id);

        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_data_complete', [], $user_id);
        return [
            'conception' => $conception,
            'dimensions' => $dimensions,
            'insulation' => $insulation
        ];
    }

    public function get_tank_text_data($conception_id)
    {
        $user_id = get_current_user_id();

        if (empty($conception_id))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty conception_id', $user_id);
            return;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_text_data_start', ['conception_id' => $conception_id], $user_id);

        $text = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Value FROM {$this->conception_table} WHERE Id = %d", $conception_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->conception_table, 'FETCH_TEXT', ['conception_id' => $conception_id, 'text' => $text], $user_id);

        return __($text, 'creation-reservoir');
    }

    public function get_tank_types()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_types_start', [], $user_id);

        $results = $this->wpdb->get_results(
            "SELECT Id, Value FROM {$this->conception_table} WHERE SelectType = 'typ' ORDER BY sort ASC"
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->conception_table, 'FETCH_TYPES', ['count' => count($results)], $user_id);
        return $results;
    }

    /**
     * Récupère les options pour les matériaux de réservoir depuis la base
     */
    public function get_tank_materials()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_materials_start', [], $user_id);

        $results = $this->wpdb->get_results(
            "SELECT Id, Value FROM {$this->conception_table} WHERE SelectType = 'material' ORDER BY sort ASC"
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->conception_table, 'FETCH_MATERIALS', ['count' => count($results)], $user_id);
        return $results;
    }

    /**
     * Récupère les options pour les supports de réservoir depuis la base
     */
    public function get_tank_support()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_support_start', [], $user_id);

        $results = $this->wpdb->get_results(
            "SELECT Id, Value FROM {$this->conception_table} WHERE SelectType = 'support' ORDER BY sort ASC"
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->conception_table, 'FETCH_SUPPORTS', ['count' => count($results)], $user_id);
        return $results;
    }

    public function ajax_save_tank_data()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_save_tank_data_start', [], $user_id);

        $result = $this->save_tank_data(null, $_POST);
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_save_tank_data_result', ['result' => $result], $user_id);

        wp_send_json_success(['debug' => $result]);
    }

    public function save_tank_data($html, $datas, $autoUpdateFromGemini = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'save_tank_data_start', ['autoUpdateFromGemini' => $autoUpdateFromGemini], $user_id);

        global $wpdb;
        $debug = [];
        $debug['start'] = "IN Tank save_tank_data : " . print_r($datas, true);
        $this->logger->log_user_action(self::LOG_NAME, 'save_tank_data_input', ['datas' => $datas], $user_id);

        $article_id = !empty($datas['article_id']) ? intval($datas['article_id']) : 0;
        $deal_id = !empty($datas['deal_id']) ? intval($datas['deal_id']) : 0;
        $data_received = $datas['tank'] ?? [];
        $is_purchase = !empty($datas['is_purchase']) && $datas['is_purchase'] === 'true';

        $this->logger->log_user_action(self::LOG_NAME, 'save_params_parsed', ['article_id' => $article_id, 'deal_id' => $deal_id, 'is_purchase' => $is_purchase], $user_id);

        if ($is_purchase)
        {
            $idCommandeClient = $wpdb->get_var($wpdb->prepare(
                "SELECT IdCommandeClient FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs WHERE Id = %d",
                $article_id
            ));

            if ($idCommandeClient)
            {
                $article_id = $idCommandeClient;
                $this->logger->log_db_change(self::LOG_NAME, 'achats_articles_cmd_fournisseurs', 'FETCH_ID_COMMAND_CLIENT', ['article_id' => $article_id, 'idCommandeClient' => $idCommandeClient], $user_id);
            }
        }

        if (empty($article_id))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing article_id', $user_id);
            return ['success' => false, 'message' => 'Article ID manquant'];
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->dimension_table} WHERE customerTankId = %d",
            $article_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'CHECK_EXISTS', ['article_id' => $article_id, 'exists' => $exists], $user_id);

        $newData = [];

        $mapping = [
            'TankType'              => 'type',
            'Material'              => 'materiau',
            'Support'               => 'support',
            'MaxPressure'           => 'max_pressure',
            'TestPressure'          => 'test_pressure',
            'Volume'                => 'volume',
            'GroundClearance'       => 'clearance',
            'usingTemperature'      => 'temperature',
            'insulation'            => 'insulation',
            'InsulationThickness'   => 'InsulationThickness',
            'insulationCover'       => 'insulationCover',
            'Diameter'              => 'diameter',
            'Height'                => 'height',
            'weldingByClient'       => 'weldingByClient',
            'openComment'           => 'openComment',
        ];

        $this->logger->log_user_action(self::LOG_NAME, 'mapping_prepared', ['mapping' => $mapping], $user_id);

        foreach ($mapping as $sqlKey => $inputKey)
        {
            if (isset($data_received[$inputKey]) && $data_received[$inputKey] !== '')
            {
                // Nettoyage spécifique si c'est le commentaire
                if ($inputKey === 'openComment') {
                    $newData[$sqlKey] = sanitize_textarea_field($data_received[$inputKey]);
                } else {
                    $newData[$sqlKey] = $data_received[$inputKey];
                }
                
                $this->logger->log_user_action(self::LOG_NAME, 'mapped_value_added', ['sqlKey' => $sqlKey, 'inputKey' => $inputKey, 'value' => $data_received[$inputKey]], $user_id);
            }
            elseif (isset($data_received[$sqlKey]) && $data_received[$sqlKey] !== '')
            {
                $newData[$sqlKey] = $data_received[$sqlKey];
                $this->logger->log_user_action(self::LOG_NAME, 'direct_value_added', ['sqlKey' => $sqlKey, 'value' => $data_received[$sqlKey]], $user_id);
            }
        }

        if (!$autoUpdateFromGemini)
        {
            if (!isset($newData['Diameter']))
            {
                $newData['Diameter'] = $this->get_default_diameter($newData['Material'] ?? 2, $newData['Volume'] ?? 100);
                $this->logger->log_user_action(self::LOG_NAME, 'default_diameter_applied', ['material' => $newData['Material'] ?? 2, 'volume' => $newData['Volume'] ?? 100, 'diameter' => $newData['Diameter']], $user_id);
            }
            if (!isset($newData['Height']))
            {
                $newData['Height'] = $this->get_default_height($newData['Material'] ?? 2, $newData['Volume'] ?? 100);
                $this->logger->log_user_action(self::LOG_NAME, 'default_height_applied', ['material' => $newData['Material'] ?? 2, 'volume' => $newData['Volume'] ?? 100, 'height' => $newData['Height']], $user_id);
            }
            if (!isset($newData['TankType']))
            {
                $newData['TankType'] = 4;
                $this->logger->log_user_action(self::LOG_NAME, 'default_tank_type_applied', ['type' => 4], $user_id);
            }
        }

        if (empty($newData))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No technical data to update', $user_id);
            $debug['message'] = "Aucune donnée technique à mettre à jour.";
            $debug['success'] = true;
            return $debug;
        }

        $newData['userId'] = $user_id;
        $debug['datas to save'] = $newData;
        $this->logger->log_user_action(self::LOG_NAME, 'data_prepared_for_save', ['data' => $newData], $user_id);

        if ($exists)
        {
            $result = $wpdb->update($this->dimension_table, $newData, ['customerTankId' => $article_id]);
            $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'UPDATE', ['article_id' => $article_id, 'data' => $newData, 'result' => $result], $user_id);
            $debug['action'] = 'update';
        }
        else
        {
            $newData['customerTankId'] = $article_id;
            $result = $wpdb->insert($this->dimension_table, $newData);
            $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'INSERT', ['article_id' => $article_id, 'data' => $newData, 'result' => $result], $user_id);
            $debug['action'] = 'insert';
        }

        if ($wpdb->last_error)
        {
            $debug['sql_error'] = $wpdb->last_error;
            $debug['last_query'] = $wpdb->last_query;
            $this->logger->log(self::LOG_NAME, 'ERROR: SQL error - ' . $wpdb->last_error, $user_id, ['last_query' => $wpdb->last_query]);
            wp_send_json_error(['message' => 'Erreur SQL', 'debug' => $debug]);
        }

        $nb_welding = $data_received['nbWelding'] ?? 0;
        $welding_by_client = $newData['weldingByClient'] ?? 0;
        if($welding_by_client != 1){
            apply_filters('ispag_auto_welding_saver', '', $deal_id, $article_id, $nb_welding);
            $this->logger->log_user_action(self::LOG_NAME, 'welding_saver_filter_applied', ['deal_id' => $deal_id, 'article_id' => $article_id, 'nb_welding' => $nb_welding], $user_id);
        }
        else{
            do_action('ispag_delete_welding_article', $deal_id, $article_id);
        }

        apply_filters('ispag_auto_insulation_saver', '', $deal_id, $article_id, $newData['insulation'] ?? '', $newData['InsulationThickness'] ?? '', $newData['insulationCover'] ?? '');
        $this->logger->log_user_action(self::LOG_NAME, 'insulation_saver_filter_applied', ['deal_id' => $deal_id, 'article_id' => $article_id, 'insulation' => $newData['insulation'] ?? '', 'thickness' => $newData['InsulationThickness'] ?? '', 'cover' => $newData['insulationCover'] ?? ''], $user_id);

        $debug['success'] = true;
        $this->logger->log_user_action(self::LOG_NAME, 'save_tank_data_complete', [], $user_id);
        return $debug;
    }

    private function get_default_diameter($material = null, $volume = null, $type = null)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_default_diameter_start', ['material' => $material, 'volume' => $volume, 'type' => $type], $user_id);

        if (empty($volume))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty volume for diameter calculation', $user_id);
            return null;
        }

        if (empty($material))
        {
            if (in_array($type, [1, 6, 7, 9]))
            {
                $material = 2;
            }
            else
            {
                $material = 1;
            }
            $this->logger->log_user_action(self::LOG_NAME, 'material_defaulted', ['type' => $type, 'material' => $material], $user_id);
        }

        $filePath = __DIR__ . '/../assets/js/default_value.json';
        if (!file_exists($filePath) || !is_readable($filePath))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Default value file not found or not readable - ' . $filePath, $user_id);
            return null;
        }

        $jsonString = file_get_contents($filePath);
        $data = json_decode($jsonString, true);

        if ($data === null)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: JSON decode failed for default values', $user_id);
            return null;
        }

        if (isset($data[$material][$volume]['diameter']))
        {
            $diameter = $data[$material][$volume]['diameter'];
            $this->logger->log_user_action(self::LOG_NAME, 'diameter_found_in_defaults', ['material' => $material, 'volume' => $volume, 'diameter' => $diameter], $user_id);
            return $diameter;
        }

        $this->logger->log(self::LOG_NAME, 'ERROR: Diameter not found in defaults', $user_id);
        return null;
    }

    private function get_default_height($material = null, $volume = null, $type = null)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_default_height_start', ['material' => $material, 'volume' => $volume, 'type' => $type], $user_id);

        if (empty($volume))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty volume for height calculation', $user_id);
            return null;
        }

        if (empty($material))
        {
            if (in_array($type, [1, 6, 7, 9]))
            {
                $material = 2;
            }
            else
            {
                $material = 1;
            }
            $this->logger->log_user_action(self::LOG_NAME, 'material_defaulted', ['type' => $type, 'material' => $material], $user_id);
        }

        $filePath = __DIR__ . '/../assets/js/default_value.json';
        if (!file_exists($filePath) || !is_readable($filePath))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Default value file not found or not readable - ' . $filePath, $user_id);
            return null;
        }

        $jsonString = file_get_contents($filePath);
        $data = json_decode($jsonString, true);

        if ($data === null)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: JSON decode failed for default values', $user_id);
            return null;
        }

        if (isset($data[$material][$volume]['height']))
        {
            $height = $data[$material][$volume]['height'];
            $this->logger->log_user_action(self::LOG_NAME, 'height_found_in_defaults', ['material' => $material, 'volume' => $volume, 'height' => $height], $user_id);
            return $height;
        }

        $this->logger->log(self::LOG_NAME, 'ERROR: Height not found in defaults', $user_id);
        return null;
    }

    private function calculate_tipping($tank_diameter, $tank_height)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'calculate_tipping_start', ['diameter' => $tank_diameter, 'height' => $tank_height], $user_id);

        $radius = $tank_diameter / 2;
        $Height = $tank_height;
        $tipping = round(sqrt(pow($radius, 2) + pow($Height, 2)));

        $this->logger->log_user_action(self::LOG_NAME, 'tipping_calculated', ['radius' => $radius, 'height' => $Height, 'tipping' => $tipping], $user_id);
        return $tipping;
    }

    private function get_tank_finition($material = null, $type = null)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_finition_start', ['material' => $material, 'type' => $type], $user_id);

        if (in_array($material, [1, 3]))
        {
            $finition = __("Internally and externally stained and passivated", "creation-reservoir");
            $this->logger->log_user_action(self::LOG_NAME, 'finition_resolved', ['material' => $material, 'finition' => $finition], $user_id);
            return $finition;
        }

        if (in_array($material, [2]) && in_array($type, [4, 9]))
        {
            $finition = __("Raw inside / outside painted anti-rust", "creation-reservoir");
            $this->logger->log_user_action(self::LOG_NAME, 'finition_resolved', ['material' => $material, 'type' => $type, 'finition' => $finition], $user_id);
            return $finition;
        }

        if (in_array($material, [2]) && in_array($type, [6, 7]))
        {
            $finition = __("Raw inside / zinc dust primed outside", "creation-reservoir");
            $this->logger->log_user_action(self::LOG_NAME, 'finition_resolved', ['material' => $material, 'type' => $type, 'finition' => $finition], $user_id);
            return $finition;
        }

        $this->logger->log(self::LOG_NAME, 'ERROR: No finition found for material/type combination', $user_id);
        return '';
    }

    public function get_tank_id_by_article_id($article_id)
    {
        $user_id = get_current_user_id();

        if (empty($article_id) || !is_numeric($article_id))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid article_id - ' . $article_id, $user_id);
            return null;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_id_by_article_id_start', ['article_id' => $article_id], $user_id);

        $tank_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->dimension_table} WHERE customerTankId = %d",
            $article_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_TANK_ID', ['article_id' => $article_id, 'tank_id' => $tank_id], $user_id);
        return $tank_id;
    }

    public function duplicate_tank_data($old_article_id, $new_article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'duplicate_tank_data_start', ['old_article_id' => $old_article_id, 'new_article_id' => $new_article_id], $user_id);

        $original = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->dimension_table} WHERE customerTankId = %d",
                $old_article_id
            ),
            ARRAY_A
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'FETCH_ORIGINAL', ['old_article_id' => $old_article_id, 'result' => !empty($original)], $user_id);

        if (!$original)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank dimensions found for article ' . $old_article_id, $user_id);
            return;
        }

        $old_tank_id = $original['Id'];
        unset($original['Id']);

        $original['customerTankId'] = $new_article_id;
        $this->logger->log_user_action(self::LOG_NAME, 'data_prepared_for_duplicate', ['old_tank_id' => $old_tank_id, 'new_article_id' => $new_article_id], $user_id);

        $inserted = $this->wpdb->insert($this->dimension_table, $original);
        $this->logger->log_db_change(self::LOG_NAME, $this->dimension_table, 'INSERT_DUPLICATE', ['old_article_id' => $old_article_id, 'new_article_id' => $new_article_id, 'result' => $inserted], $user_id);

        if (!$inserted)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Failed to duplicate tank dimensions for article ' . $new_article_id, $user_id);
            return;
        }

        $new_tank_id = $this->wpdb->insert_id;
        $this->logger->log_user_action(self::LOG_NAME, 'duplicate_inserted', ['new_tank_id' => $new_tank_id], $user_id);

        $connection_table = $this->wpdb->prefix . 'achats_tank_connection';
        $connections = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM $connection_table WHERE TankId = %d", $old_tank_id),
            ARRAY_A
        );

        $this->logger->log_db_change(self::LOG_NAME, $connection_table, 'FETCH_CONNECTIONS', ['old_tank_id' => $old_tank_id, 'count' => count($connections)], $user_id);

        foreach ($connections as $conn)
        {
            unset($conn['Id']);
            $conn['TankId'] = $new_tank_id;
            $result = $this->wpdb->insert($connection_table, $conn);
            $this->logger->log_db_change(self::LOG_NAME, $connection_table, 'INSERT_DUPLICATE_CONNECTION', ['old_tank_id' => $old_tank_id, 'new_tank_id' => $new_tank_id, 'result' => $result], $user_id);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'duplicate_tank_data_complete', ['old_article_id' => $old_article_id, 'new_article_id' => $new_article_id, 'old_tank_id' => $old_tank_id, 'new_tank_id' => $new_tank_id], $user_id);
    }

    /**
     * Récupère l'ID du réservoir dans la base de données
     * en fonction du customerTankId.
     */
    function get_tank_created_by_id($current_user_id, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_created_by_id_start', ['article_id' => $article_id], $user_id);

        $table_name = $this->wpdb->prefix . 'achats_tank_dimensions';
        $query = $this->wpdb->prepare(
            "SELECT userId FROM $table_name WHERE customerTankId = %d",
            $article_id
        );

        $result = $this->wpdb->get_var($query);
        $this->logger->log_db_change(self::LOG_NAME, $table_name, 'FETCH_USER_ID', ['article_id' => $article_id, 'userId' => $result], $user_id);

        if ($result !== null)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'user_id_found', ['userId' => $result], $user_id);
            return (int) $result;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'no_user_id_found_default_to_current', ['current_user_id' => $current_user_id], $user_id);
        return (int) $current_user_id;
    }

    public static function save_tank_unit_price()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'save_tank_unit_price_start', [], $user_id);

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $discount = isset($_POST['discount']) ? floatval($_POST['discount']) : 0;
        $log_details = isset($_POST['log_details']) ? stripslashes($_POST['log_details']) : 'Aucun détail de calcul fourni.';

        $logger->log_user_action(self::LOG_NAME, 'price_data_received', ['article_id' => $article_id, 'price' => $price, 'discount' => $discount], $user_id);

        if (!$article_id || $price <= 0)
        {
            $logger->log(self::LOG_NAME, 'ERROR: Invalid article_id or price', $user_id);
            wp_send_json_error(['message' => 'Données invalides : ID ou Prix manquant']);
            wp_die();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';

        $data_to_update = [
            'UnitPrice' => $price,
            'discount' => $discount
        ];

        $logger->log_user_action(self::LOG_NAME, 'data_prepared_for_update', ['data' => $data_to_update], $user_id);

        $updated = $wpdb->update(
            $table,
            $data_to_update,
            array('Id' => $article_id)
        );

        $logger->log_db_change(self::LOG_NAME, $table, 'UPDATE_PRICE', ['article_id' => $article_id, 'data' => $data_to_update, 'result' => $updated], $user_id);

        if ($updated !== false)
        {
            try
            {
                $upload_dir = wp_upload_dir();
                $debug_dir = $upload_dir['basedir'] . '/ispag_debug_pricing';

                if (!file_exists($debug_dir))
                {
                    wp_mkdir_p($debug_dir);
                    file_put_contents($debug_dir . '/index.php', '<?php // Silence is golden');
                    $logger->log_user_action(self::LOG_NAME, 'debug_dir_created', ['dir' => $debug_dir], $user_id);
                }

                $current_user = wp_get_current_user();
                $file_content = "====================================================\n";
                $file_content .= "RAPPORT DE CALCUL - ARTICLE ID: " . $article_id . "\n";
                $file_content .= "GÉNÉRÉ PAR : " . $current_user->display_name . " (ID: " . $current_user->ID . ")\n";
                $file_content .= "DATE : " . date('d/m/Y H:i:s') . "\n";
                $file_content .= "====================================================\n\n";
                $file_content .= $log_details;

                $filename = $debug_dir . '/article_' . $article_id . '_debug.txt';
                file_put_contents($filename, $file_content);

                $logger->log_user_action(self::LOG_NAME, 'debug_file_created', ['filename' => $filename], $user_id);

                wp_send_json_success([
                    'message' => 'Prix, remise et log de debug mis à jour',
                    'debug_file' => 'article_' . $article_id . '_debug.txt'
                ]);
            }
            catch (Exception $e)
            {
                $logger->log(self::LOG_NAME, 'ERROR: Failed to create debug file - ' . $e->getMessage(), $user_id);
                wp_send_json_success([
                    'message' => 'Prix mis à jour en DB, mais erreur lors de la création du log.',
                    'error_log' => $e->getMessage()
                ]);
            }
        }
        else
        {
            $error = $wpdb->last_error;
            $logger->log(self::LOG_NAME, 'ERROR: DB update failed - ' . $error, $user_id);
            wp_send_json_error(['message' => 'Erreur DB : ' . $error]);
        }

        wp_die();
    }
}