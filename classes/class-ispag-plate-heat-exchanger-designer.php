<?php

class ISPAG_Plate_Heat_exchanger_Designer {
    private $exchanger_table;
    private $wpdb;
    protected static $instance = null;
    private $fluids;
    private $exchanger_types;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->exchanger_table = $wpdb->prefix.'achats_plate_exchanger_datas';

        // Liste des fluides traduisibles
        $this->fluids = [
            'water'      => __('Water', 'creation-reservoir'),
            'glycol_30'  => __('Mono-Propylene-Glycol 30%', 'creation-reservoir'),
            'glycol'     => __('Water/Glycol', 'creation-reservoir'),
            'sea_water'  => __('Sea water', 'creation-reservoir'),
            'meg_05'     => __('Mono-Ethylene-Glycol 05%', 'creation-reservoir'),
            'meg_10'     => __('Mono-Ethylene-Glycol 10%', 'creation-reservoir'),
            'meg_15'     => __('Mono-Ethylene-Glycol 15%', 'creation-reservoir'),
            'meg_20'     => __('Mono-Ethylene-Glycol 20%', 'creation-reservoir'),
            'meg_25'     => __('Mono-Ethylene-Glycol 25%', 'creation-reservoir'),
            'meg_30'     => __('Mono-Ethylene-Glycol 30%', 'creation-reservoir'),
            'meg_35'     => __('Mono-Ethylene-Glycol 35%', 'creation-reservoir'),
            'meg_40'     => __('Mono-Ethylene-Glycol 40%', 'creation-reservoir'),
            'meg_45'     => __('Mono-Ethylene-Glycol 45%', 'creation-reservoir'),
            'meg_50'     => __('Mono-Ethylene-Glycol 50%', 'creation-reservoir'),
            'mpg_10'     => __('Mono-Propylene-Glycol 10%', 'creation-reservoir'),
            'mpg_15'     => __('Mono-Propylene-Glycol 15%', 'creation-reservoir'),
            'mpg_20'     => __('Mono-Propylene-Glycol 20%', 'creation-reservoir'),
            'mpg_25'     => __('Mono-Propylene-Glycol 25%', 'creation-reservoir'),
            'mpg_30'     => __('Mono-Propylene-Glycol 30%', 'creation-reservoir'),
            'mpg_35'     => __('Mono-Propylene-Glycol 35%', 'creation-reservoir'),
            'mpg_40'     => __('Mono-Propylene-Glycol 40%', 'creation-reservoir'),
            'mpg_45'     => __('Mono-Propylene-Glycol 45%', 'creation-reservoir'),
            'mpg_50'     => __('Mono-Propylene-Glycol 50%', 'creation-reservoir'),
            'other'      => __('Other', 'creation-reservoir')
        ];

        // Liste des types d'échangeurs traduisibles
        $this->exchanger_types = [
            'brazed'   => __('Brazed', 'creation-reservoir'),
            'gasketed' => __('Gasketed / Screwed', 'creation-reservoir')
        ];
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_render_plate_heat_exchanger_form', [self::$instance, 'render_dimensions_form'], 10, 2);
        add_action('wp_ajax_ispag_save_exchanger_data', [self::$instance, 'ispag_save_exchanger_data']);
        add_filter('ispag_get_plate_exchanger_title', [self::$instance, 'generate_title_exchanger'], 10, 2);
        add_filter('ispag_get_plate_exchanger_description', [self::$instance, 'generate_description_exchanger'], 10, 2);
        add_action('ispag_delete_exchanger_data', [self::$instance, 'delete_exchanger_data'], 10, 2);
    }

    public function generate_title_exchanger($title, $article_id) {
        $exchanger = $this->get_exchanger_data($article_id);

        if (!$exchanger || empty($exchanger->power)) {
            return $title;
        }

        $type_label = $this->exchanger_types[$exchanger->type] ?? '';

        $regime = sprintf(
            '(%s/%s°C → %s/%s°C)',
            $exchanger->primary_temp_in ?? '?',
            $exchanger->primary_temp_out ?? '?',
            $exchanger->secondary_temp_in ?? '?',
            $exchanger->secondary_temp_out ?? '?'
        );

        return sprintf(
            '%s (%s) - %s kW %s',
            __('Plate Heat Exchanger', 'creation-reservoir'),
            $type_label,
            $exchanger->power,
            $regime
        );
    }

    public function generate_description_exchanger($title, $article_id) {
        $exchanger = $this->get_exchanger_data($article_id);

        if (!$exchanger || empty($exchanger->power)) {
            return '';
        }

        $primary_fluid   = $this->fluids[$exchanger->primary_fluid] ?? $exchanger->primary_fluid;
        $secondary_fluid = $this->fluids[$exchanger->secondary_fluid] ?? $exchanger->secondary_fluid;
        $type_label      = $this->exchanger_types[$exchanger->type] ?? $exchanger->type;

        $desc = [];

        // Ligne Type d'échangeur
        $desc[] = sprintf("<strong>%s :</strong> %s", __('Type', 'creation-reservoir'), $type_label);

        // Ligne Puissance
        $desc[] = sprintf("<strong>%s :</strong> %s kW", __('Thermal Power', 'creation-reservoir'), $exchanger->power);

        // Bloc Primaire
        $desc[] = sprintf(
            "<strong>%s :</strong> %s/%s °C | %s : %s | %s : %s kPa",
            __('Primary', 'creation-reservoir'),
            $exchanger->primary_temp_in,
            $exchanger->primary_temp_out,
            __('Fluid', 'creation-reservoir'),
            $primary_fluid,
            __('ΔP', 'creation-reservoir'),
            $exchanger->primary_pressure_drop
        );

        // Bloc Secondaire
        $desc[] = sprintf(
            "<strong>%s :</strong> %s/%s °C | %s : %s | %s : %s kPa",
            __('Secondary', 'creation-reservoir'),
            $exchanger->secondary_temp_in,
            $exchanger->secondary_temp_out,
            __('Fluid', 'creation-reservoir'),
            $secondary_fluid,
            __('ΔP', 'creation-reservoir'),
            $exchanger->secondary_pressure_drop
        );

        $desc[] = ""; 
        $desc[] = "<em>" . __('Technical data according to attached data sheets.', 'creation-reservoir') . "</em>";

        return implode('<br>', $desc);
    }

    public function render_dimensions_form($article_id, $source = 'project') {
        $data['exchanger'] = $this->get_exchanger_data($article_id);
        $fluids = $this->fluids;

        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/form-plate-exchanger-field.php';
        return ob_get_clean();
    }

    public function ispag_save_exchanger_data() {
       wp_send_json_success(['debug' => $this->save_exchanger_data(null, $_POST)]);
    }

    public function save_exchanger_data($html, $datas) {
        global $wpdb;
        $article_id = !empty($datas['article_id']) ? intval($datas['article_id']) : 0;
        $data_received = $datas['exchanger'] ?? []; 
        $is_purchase = !empty($datas['is_purchase']) && $datas['is_purchase'] === 'true';

        if ($is_purchase) {
            $idCommandeClient = $wpdb->get_var($wpdb->prepare(
                "SELECT IdCommandeClient FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs WHERE Id = %d",
                $article_id
            ));
            if ($idCommandeClient) { $article_id = $idCommandeClient; }
        }

        if (empty($article_id)) return ['success' => false];

        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->exchanger_table} WHERE article_id = %d", $article_id));

        $newData = [];
        $mapping = [
            'type'                    => 'type',
            'power'                   => 'power',
            'primary_temp_in'         => 'primary_temp_in',
            'primary_temp_out'        => 'primary_temp_out',
            'primary_pressure_drop'   => 'primary_pressure_drop',
            'primary_fluid'           => 'primary_fluid',
            'secondary_temp_in'       => 'secondary_temp_in',
            'secondary_temp_out'      => 'secondary_temp_out',
            'secondary_pressure_drop' => 'secondary_pressure_drop',
            'secondary_fluid'         => 'secondary_fluid'
        ];

        foreach ($mapping as $sqlKey => $inputKey) {
            if (isset($data_received[$inputKey])) {
                $newData[$sqlKey] = $data_received[$inputKey];
            }
        }

        if ($exists) {
            $wpdb->update($this->exchanger_table, $newData, ['article_id' => $article_id]);
        } else {
            $newData['article_id'] = $article_id;
            $wpdb->insert($this->exchanger_table, $newData);
        }

        return ['success' => true];
    }

    public function get_exchanger_data($article_id) {
        if (empty($article_id)) return null;

        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->exchanger_table} WHERE article_id = %d", $article_id));

        if (!$row) {
            return (object) [
                'type' => 'brazed',
                'power' => '',
                'primary_temp_in' => '', 'primary_temp_out' => '', 'primary_pressure_drop' => '', 'primary_fluid' => 'water',
                'secondary_temp_in' => '', 'secondary_temp_out' => '', 'secondary_pressure_drop' => '', 'secondary_fluid' => 'water'
            ];
        }

        // Formatage numérique
        $numeric_fields = ['power', 'primary_temp_in', 'primary_temp_out', 'primary_pressure_drop', 'secondary_temp_in', 'secondary_temp_out', 'secondary_pressure_drop'];
        foreach ($numeric_fields as $field) {
            if (isset($row->$field) && is_numeric($row->$field)) {
                $row->$field = number_format((float)$row->$field, 1, '.', '');
            }
        }

        return $row;
    }

    public function delete_exchanger_data($html, $article_id) {
        if (empty($article_id)) return false;
        return $this->wpdb->delete($this->exchanger_table, ['article_id' => $article_id], ['%d']) !== false;
    }
}