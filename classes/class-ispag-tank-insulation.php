<?php

class ISPAG_Tank_Insulation {
    private $wpdb;
    protected static $instance = null;
    private $table_article;
    private $table_conception;
    private $table_dimensions;
    private $table_project_article;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_article = $wpdb->prefix . 'achats_articles';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_dimensions = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_project_article = $wpdb->prefix . 'achats_details_commande';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_get_insulation_title', [self::$instance, 'get_insulation_title'], 10, 2 );
        add_filter('ispag_get_insulation_description', [self::$instance, 'get_insulation_description'], 10, 2 );
        add_filter('ispag_get_insulation_for_tank_description', [self::$instance, 'get_insulation_for_tank_description'], 10, 3 );
        add_filter('ispag_render_insulation_selector', [self::$instance, 'render_insulation_selector'], 10, 2);
        add_filter('ispag_get_related_insulation_information', [self::$instance, 'get_related_insulation_information'], 10, 2);
        
    }

    public function render_insulation_selector($html, $article_id) {
        $types = $this->get_insulation_options('insulationType');
        $covers = $this->get_insulation_options('insulationCover');
        $thicknesses = $this->get_insulation_options('insulationThickness');

        // Récupère les valeurs existantes
        $tank = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT insulation, InsulationThickness, insulationCover FROM {$this->wpdb->prefix}achats_tank_dimensions WHERE customerTankId = %d LIMIT 1",
                $article_id
            )
        );

        $selected_type = $tank->insulation ?? 0;
        $selected_thickness = $tank->InsulationThickness ?? 0;
        $selected_covering = $tank->insulationCover ?? '';

        // echo '<pre>';
        // var_dump($tank);
        // echo '</pre>';

        ob_start(); ?>
        <div class="ispag-insulation-selector" data-article-id="<?= esc_attr($article_id); ?>">
            <label for="insulation-type"><?php _e('Type of insulation', 'creation-reservoir'); ?></label>
            <select id="insulation-type" name="tank[insulation]" class="ispag-insulation-type">
                <option value="0"><?= __('– None –', 'creation-reservoir'); ?></option>
                <?php foreach ($types as $id => $label): ?>
                    <option value="<?= esc_attr($id); ?>" <?= selected((int)$selected_type, (int)$id, false); ?>>
                        <?= esc_html__($label, 'creation-reservoir'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="insulation-cover"><?php _e('Type of covering', 'creation-reservoir'); ?></label>
            <select id="insulation-cover" name="tank[insulationCover]" class="ispag-insulation-cover">
                <option value="53"><?= __('– None –', 'creation-reservoir'); ?></option>
                <?php foreach ($covers as $id => $label): ?>
                    <?php 
                    // On saute l'itération si l'ID est 53 car il est déjà affiché au-dessus
                    if ((int)$id === 53) continue; 
                    ?>
                    <option value="<?= esc_attr($id); ?>" <?= selected((int)$selected_covering, (int)$id, false); ?>>
                        <?= esc_html__($label, 'creation-reservoir'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="insulation-thickness"><?php _e('Thickness', 'creation-reservoir'); ?></label>
            <select id="insulation-thickness" name="tank[InsulationThickness]" class="ispag-insulation-thickness">
                <option value="0"><?= __('– None –', 'creation-reservoir'); ?></option>
                <?php foreach ($thicknesses as $id => $label): ?>
                    <option value="<?= esc_attr($id); ?>" <?= selected((int)$selected_thickness, (int)$id, false); ?>>
                        <?= esc_html($label); ?> mm
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php return ob_get_clean();
    }



    public function get_insulation_options($type) {
        $type = sanitize_text_field($type);

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT Id, Value FROM {$this->table_conception} WHERE SelectType = %s ORDER BY sort ASC",
                $type
            )
        );

        $options = [];
        foreach ($results as $row) {
            $options[$row->Id] = $row->Value;
        }

        return $options;
    }



    // Récupérer la valeur texte dans tank_conception via l'ID
    public function get_conception_value($id) {
        $id = intval($id);
        if ($id <= 0) return '';

        $sql = $this->wpdb->prepare("SELECT Value FROM {$this->table_conception} WHERE Id = %d LIMIT 1", $id);
        return $this->wpdb->get_var($sql) ?? '';
    }

    private function get_article_data( $article_id) {
        // Sécurise l'id
        $article_id = intval($article_id);

        $sql = $this->wpdb->prepare("
            SELECT * FROM {$this->table_article} 
            WHERE Id = %d AND TypeArticle = 2
            LIMIT 1
        ", $article_id);

        return $this->wpdb->get_row($sql);
    }

    public function get_insulation_title($title, $article_id) {
//         return $article_id;
        
        $article = $this->get_article_data($article_id);
        if (!$article) return $title;

        $data = json_decode($article->conception ?: $article->conception, true);
        // error_log('get_insulation_title ' . print_r($data, true));
        // error_log('get_insulation_title ARTICLE ' . print_r($article, true));
        if (!$data || !isset($data['insulation']) ) return $article->TitreArticle;

        $ins = $data['insulation'];

        // Exemple de composition multilingue (adapte __() selon ton système)
        $volume = $ins['tankVolum'] ?? '';
        $tank_height_text = $ins['tankHeight'] ?? '';
        $tank_height = $ins['tankHeightLimit'] ?? '';
        
        // Récupérer les textes via les IDs
        $thickness_text = $this->get_conception_value($ins['insulationThickness'] ?? 0);
        $type_text = $this->get_conception_value($ins['insulationType'] ?? 0);
        $cover_text = $this->get_conception_value($ins['insulationCover'] ?? 0);

        // Ex : "Insulation 130mm for 500L tank (height under 2500mm)"
        $title = sprintf(
            __('%dL tank %dmm %s %s (height %s %smm)', 'creation-reservoir'),
            $volume,
            $thickness_text,
            __($type_text, 'creation-reservoir') ,
            __($cover_text, 'creation-reservoir') ,
            
            __($tank_height_text, 'creation-reservoir') ,
            $tank_height
        ); 

 
        return $title;
    }

    public function get_insulation_description($description, $article_id, $target_locale = null) {
        $article = $this->get_article_data($article_id);

        if(!empty($target_locale)){
            $previous_locale = switch_to_locale($target_locale);
        }

        if (!$article) return $description;

        $data = json_decode($article->conception ?: $article->conception, true);
        if (!$data || !isset($data['insulation']) ) return $article->description_ispag;

        $ins = $data['insulation'];

        $volume = $ins['tankVolum'] ?? '';
        $tank_height_text = $ins['tankHeight'] ?? '';
        $tank_height = $ins['tankHeightLimit'] ?? '';
        
        // Récupérer les textes via les IDs
        $thickness_text = $this->get_conception_value($ins['insulationThickness'] ?? 0);
        $type_text = $this->get_conception_value($ins['insulationType'] ?? 0);
        $cover_text = $this->get_conception_value($ins['insulationCover'] ?? 0);

        $title = sprintf(
            __('%dmm %s for %dL tank (height %s %smm)', 'creation-reservoir'),
            $thickness_text,
            __($type_text, 'creation-reservoir') ,
            $volume,
            __($tank_height_text, 'creation-reservoir') ,
            $tank_height
        );

        $coat = sprintf(
            __('%s', 'creation-reservoir'),
            __($cover_text, 'creation-reservoir') ,
        );

        // $desc = $this->get_insulation_title('', $article_id);
        $desc = $title;
        $desc .= '<br />' . $coat;
        $desc .= '<br />' . __('Installation lead time: on request', 'creation-reservoir');
        $desc .= '<br />' . __('Notice period: 2 to 3 weeks after call', 'creation-reservoir');
        $desc .= '<br />' . __('Price includes on-site installation on non-connected tank.', 'creation-reservoir');
        $desc .= '<br />' . __('Surcharge if already connected: +10%.', 'creation-reservoir');

        if(!empty($target_locale)){
            restore_previous_locale();
        }
        
        return $desc;
    }

    private function is_supplier_delivery($article_id) {
        // Étape 1 : récupérer la valeur 'insulation' depuis achats_tank_dimensions
        $insulation_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT insulation FROM {$this->wpdb->prefix}achats_tank_dimensions WHERE customerTankId = %d",
            $article_id
        ));

        if (!$insulation_id) {
            return false; // pas d'isolation liée
        }

        // Étape 2 : vérifier dans achats_tank_conception si is_supplier_delivery = 1
        $is_supplier = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT is_supplier_delivery FROM {$this->wpdb->prefix}achats_tank_conception WHERE Id = %d",
            $insulation_id
        ));
        return intval($is_supplier) === 1;
    }

    public function get_insulation_for_tank_description($html, $article_id, $is_purchase){
        $article_id = intval($article_id);

        

        if($this->is_supplier_delivery($article_id)){
            
            // Récupère les IDs d'isolation depuis achats_tank_dimensions
            $row = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT insulation, InsulationThickness 
                FROM {$this->wpdb->prefix}achats_tank_dimensions 
                WHERE customerTankId = %d",
                $article_id
            ));

            if (!$row) return '';

            $insulation_id = intval($row->insulation);
            $thickness_id = intval($row->InsulationThickness);

            // Récupère les valeurs textuelles depuis achats_tank_conception
            $insulation_label = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT Value FROM {$this->wpdb->prefix}achats_tank_conception WHERE Id = %d",
                $insulation_id
            ));

            $thickness_label = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT Value FROM {$this->wpdb->prefix}achats_tank_conception WHERE Id = %d",
                $thickness_id
            ));

            // Construit la description
            $desc = trim(__('Insulation', 'creation-reservoir') . ' ' . $thickness_label . 'mm ' . __($insulation_label, 'creation-reservoir'));
            return $desc;
        }
        
    }

    public function get_related_insulation_information($html, $article_id){
        global $wpdb;

        $article = apply_filters('ispag_get_article_by_id',null, $article_id);
        // Cherche s’il existe une soudure liée à cette cuve (même hubspot_deal_id et même groupe)
        $sql = "
            SELECT Id FROM {$this->table_project_article}
            WHERE hubspot_deal_id = %d
            AND Type = 2
            AND Groupe = %s
            LIMIT 1
        ";

        $linked_tank_in_group = $wpdb->get_var($wpdb->prepare($sql, $article->hubspot_deal_id, $article->Groupe));

        if ($linked_tank_in_group) {
            // Récupère l'article
            $article = apply_filters('ispag_get_article_by_id',null, $linked_tank_in_group);
            return $article->Description;
        }

        // Cherche s’il existe une soudure liée à cette cuve (linked_tank)
        $sql = "
            SELECT Id FROM {$this->table_project_article}
            WHERE linked_tank = %d
            AND Type = 2
            LIMIT 1
        ";
        $linked_tank = $wpdb->get_var($wpdb->prepare($sql, $article_id));
        if ($linked_tank) {
            // Récupère l'article
            $article = apply_filters('ispag_get_article_by_id',null, $linked_tank);
            return $article->Description;
        }
    }
}


