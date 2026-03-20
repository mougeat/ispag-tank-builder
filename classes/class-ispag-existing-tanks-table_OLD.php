<?php

class ISPAG_Existing_Tanks_table_OLD {

    private $conception_table;
    private $dimension_table;
    private $projects_table;
    private $projects_article_table;
    private $wpdb;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->conception_table = $wpdb->prefix . 'achats_tank_conception';
        $this->dimension_table = $wpdb->prefix . 'achats_tank_dimensions';
        $this->projects_table = $wpdb->prefix . 'achats_liste_commande';
        $this->projects_article_table = $wpdb->prefix . 'achats_details_commande';

    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_shortcode('ispag_tanks_table', [self::$instance, 'ispag_render_tanks_table']);

        
    }

    public function ispag_render_tanks_table() {
        $dimension_table = $this->dimension_table;
        $conception_table = $this->conception_table;
        $projects_article_table = $this->projects_article_table;
        $projects_table = $this->projects_table;

        // Récupère les cuves
        $results = $this->wpdb->get_results("
            SELECT 
                d.customerTankId,
                d.Diameter,
                d.Volume,
                d.Material,
                d.MaxPressure,
                c.Value AS MaterialLabel,
                a.hubspot_deal_id
            FROM $dimension_table d
            LEFT JOIN $conception_table c ON c.Id = d.Material
            LEFT JOIN $projects_article_table a ON a.Id = d.customerTankId
            LEFT JOIN $projects_table pt ON pt.hubspot_deal_id = a.hubspot_deal_id
            WHERE FROM_UNIXTIME(pt.TimestampDateCommande) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND CURDATE()
            ORDER BY d.Id DESC
            LIMIT 400
        ");

        // Récupère tous les IDs
        $deal_ids = array_filter(array_unique(array_map(fn($r) => (int)$r->hubspot_deal_id, $results)));
        $articles_ids = array_filter(array_unique(array_map(fn($r) => (int)$r->customerTankId, $results)));

        // Précharge projets et articles
        $projects = apply_filters('ispag_get_projects_by_deal_ids', [], $deal_ids);
        $articles = apply_filters('ispag_get_articles_by_ids', [], $articles_ids);

        // echo'<pre>';
        // var_dump($articles);
        // echo'</pre>';

        // Injection
        foreach ($results as &$row) {
            $row->project = $projects[(int)$row->hubspot_deal_id] ?? null;
            $row->article = $articles[(int)$row->customerTankId] ?? null;
        }

        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/table-existing-tanks.php';
        return ob_get_clean();
    }

}