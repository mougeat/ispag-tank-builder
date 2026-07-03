<?php

class ISPAG_Tank_Welding {
    private $wpdb;
    private $table_flange_dimension;
    private $table_conception;
    private $table_article;
    private $table_project_article;
    private $table_connections;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_article = $wpdb->prefix . 'achats_articles';
        $this->table_project_article = $wpdb->prefix . 'achats_details_commande';
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';
        
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_get_welding_title', [self::$instance, 'get_welding_title'], 10, 2 );
        add_filter('ispag_get_welding_description', [self::$instance, 'get_welding_description'], 10, 2 );
        add_filter('ispag_get_welding_text', [self::$instance, 'get_welding_text'], 10, 3 );
        add_filter('ispag_render_welding_selector', [self::$instance, 'render_welding_selector'], 10, 2);
        add_filter('ispag_get_warranty_information', [self::$instance, 'get_warranty_information'], 10, 2);
        add_filter('ispag_get_tank_on_site_welded', [self::$instance, 'get_tank_on_site_welded'], 10, 2);
        add_filter('ispag_get_all_welding_drilled_plate', [self::$instance, 'get_all_welding_drilled_plate'], 10, 3);
        // add_action('wp_ajax_ispag_generate_welding_certificat_pdf', [self::$instance, 'ispag_ajax_generate_welding_certificat']);
        // add_filter('ispag_get_welding_certificat_btn', [self::$instance, 'get_welding_certificat_btn'], 10, 3);
        
    }

    

    

    public function get_all_welding_drilled_plate($null, $article_id, $is_description = false){

        if (empty($article_id) || !is_numeric($article_id)) {
            return [];
        }
 
        $group_by = $is_description ? "GROUP BY c.Type, c.Pouces, c.Accessories, c.madeFor" : '';
        $count = $is_description ? ", COUNT(*) as qty" : '';

        $tank_designer = new ISPAG_Tank_Designer();
        $tank_id = $tank_designer->get_tank_id_by_article_id($article_id);

        $weldings = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT c.Id AS fitting_id, c.Type AS type_id, tc.Value AS Type, c.Pouces AS Pouces, c.Height $count
            FROM $this->table_connections c
            
            LEFT JOIN $this->table_conception tc ON tc.Id = c.Type
            
            WHERE c.TankId = %d
            AND c.Type IN (22, 23)
            $group_by
            ORDER BY tc.sort",
            $tank_id
        ));

        return $weldings;

    
    }

    public function get_tank_on_site_welded($html, $article_id) {
        global $wpdb;

        $table_dimensions = $wpdb->prefix . 'achats_tank_dimensions';
        $table_connections = $wpdb->prefix . 'achats_tank_connection';

        // 1. Récupérer le TankId depuis achats_tank_dimensions
        $tank = $wpdb->get_row(
            $wpdb->prepare("SELECT Id FROM $table_dimensions WHERE customerTankId = %d", $article_id)
        );
        // error_log("article_id: " . $article_id . ",var get_tank_on_site_welded : " . var_export($tank, true));

        if (!$tank) return false;

        $tank_id = intval($tank->Id);
        

        // 2. Vérifier s'il existe au moins une ligne avec Type = 23 pour ce TankId
        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table_connections WHERE TankId = %d AND Type = 23", $tank_id)
        );
        // error_log("get_tank_on_site_welded COUNT : " . var_export($count, true));

        return $count > 0;

    }

    public function get_welding_title($title, $article_id) {
//         return $article_id;
        $article = $this->get_article_data($article_id);
        if (!$article) return $title;

        // error_log('get_welding_title ---------------> ' . print_r($article, true));

        $data = json_decode($article->conception ?: $article->conception, true);
        if (!$data || !isset($data['welding'])) return $article->TitreArticle;

        $ins = $data['welding'];

        // Exemple de composition multilingue (adapte __() selon ton système)
        $nb_welding = $ins['nb_welding'] ?? '';
        $tank_diameter = $ins['tank_diameter'] ?? '';
        // $tank_material = $ins['tank_material'] ?? '';

        $tank_material = $this->get_conception_value($ins['tank_material'] ?? 0 );
       
        // Ex : "On-site welding of a black steel tank in diameter 1100mm delivered in 2 pieces"
        $title = sprintf(
            __('On-site welding of a %s tank in diameter %smm delivered in %s pieces', 'creation-reservoir'),
            $tank_material,
            $tank_diameter,
            $nb_welding
        );


        return $title;
    }

    public function get_welding_description($description, $article_id) {
        $article = $this->get_article_data($article_id);
        if (!$article) return $description;

        // error_log('get_welding_description ---------------> ' . print_r($article, true));

        $data = json_decode($article->conception ?: $article->conception, true);
        if (!$data || !isset($data['welding'])) return $article->TitreArticle;

        $ins = $data['welding'];

        // Exemple de composition multilingue (adapte __() selon ton système)
        $nb_welding = $ins['nb_welding'] ?? '';
        $tank_diameter = $ins['tank_diameter'] ?? '';
        // $tank_material = $ins['tank_material'] ?? '';

        $tank_material = $this->get_conception_value($ins['tank_material'] ?? 0 );

        $title = sprintf(
            __('On-site welding of a %s tank in diameter %smm delivered in %s pieces', 'creation-reservoir'),
            __($tank_material, 'creation-reservoir'),
            $tank_diameter,
            $nb_welding
        );

        // $desc = $this->get_insulation_title('', $article_id);
        $desc = $title;
        $desc .= '<br />' . __('Site setup.', 'creation-reservoir');
        $desc .= '<br />' . __('The transport of the (cut) tank from the truck to its final location will be carried out by the installer.', 'creation-reservoir');
        $desc .= '<br />' . sprintf(__('Assembly, preparation, and tack welding of the water heater in %s parts.', 'creation-reservoir'), $nb_welding);
        $desc .= '<br />' . __('Execution of the welding.', 'creation-reservoir');
        $desc .= '<br />' . __('Weld inspection by dye penetrant testing.', 'creation-reservoir');
        $desc .= '<br />' . __('Site cleaning and dismantling.', 'creation-reservoir');


        return $desc;
    }

    private function get_article_data( $article_id) {
        // Sécurise l'id
        $article_id = intval($article_id);

        $sql = $this->wpdb->prepare("
            SELECT * FROM {$this->table_article} 
            WHERE Id = %d AND TypeArticle = 3
            LIMIT 1
        ", $article_id);

        return $this->wpdb->get_row($sql);
    }

    // Récupérer la valeur texte dans tank_conception via l'ID
    private function get_conception_value($id) {
        $id = intval($id);
        if ($id <= 0) return '';

        $sql = $this->wpdb->prepare("SELECT Value FROM {$this->table_conception} WHERE Id = %d LIMIT 1", $id);
        return $this->wpdb->get_var($sql) ?? '';
    }

    public function render_welding_selector($html, $article_id) {
        // Récupération du nombre de tronçons depuis la base
        $nb_welding = $this->count_nb_welding_in_tank($article_id) > 0 ? intval($this->count_nb_welding_in_tank($article_id)) : '';

        ob_start(); ?>
        <div class="ispag-welding-selector" data-article-id="<?= esc_attr($article_id); ?>">
            <label for="welding-nb"><?php _e('Number of welding', 'creation-reservoir'); ?></label>
            <input type="number" 
                id="welding-nb" 
                name="tank[nbWelding]" 
                value="<?= esc_attr($nb_welding); ?>" 
                min="0" 
                step="1"
                class="ispag-welding-nb" /> 
        </div>
        <?php
        return ob_get_clean();
    }


    public function count_nb_welding_in_tank($article_id){
        $tank_designer = new ISPAG_Tank_Designer();
        $tank_id = intval($tank_designer->get_tank_id_by_article_id(intval($article_id)));
        
        return $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(Id) 
            FROM {$this->table_connections}
            WHERE Type = %d AND TankId = %d",
            23,
            $tank_id
        ) );
    }

    public function get_welding_text($html, $article_id, $is_description = false){

        $nb = $this->count_nb_welding_in_tank($article_id);
        $sections = !$is_description ? __("The sections can be pointed to facilitate transport.","creation-reservoir") : '';
        $icon = !$is_description ? '<span class="dashicons dashicons-warning"></span> ' : '';

        if ($nb > 0) {
            return  $icon . str_replace('%NB_PIECES%', $nb + 1, __("Tank delivered to site in %NB_PIECES% pieces", "creation-reservoir")) . '. ' . $sections;
        }
        return '';
    }

    public function get_warranty_information($html, $article_id){
        global $wpdb;

        $positiv_warranty_text = __('Welding covered under standard ISPAG tank warranty.', 'creation-reservoir');
        $negativ_warranty_text = __('No warranty can be granted for this tank, as the welding is not carried out by us.', 'creation-reservoir');

        // Récupère l'article
        $article = apply_filters('ispag_get_article_by_id',null, $article_id);
        $welding_description = apply_filters('ispag_get_welding_text', null, $article_id, false);

        // Cherche s’il existe une soudure liée à cette cuve (même hubspot_deal_id et même groupe)
        $sql = "
            SELECT Id FROM {$this->table_project_article}
            WHERE hubspot_deal_id = %d
            AND Type = 3
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
            AND Type = 3
            LIMIT 1
        ";
        $linked_tank = $wpdb->get_var($wpdb->prepare($sql, $article_id));
        if ($linked_tank) {
            // Récupère l'article
            $article = apply_filters('ispag_get_article_by_id',null, $linked_tank);
            return $article->Description;
        }
        
        return $welding_description . ' ' .$negativ_warranty_text;
    }

}