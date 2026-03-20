<?php

class ISPAG_Existing_Tanks_Table {

    private $wpdb;
    private $table_dimensions;
    private $table_details;
    private $table_conception;
    private $table_orders; 
    private $per_page = 50;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_dimensions = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_details    = $wpdb->prefix . 'achats_details_commande';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_orders     = $wpdb->prefix . 'achats_liste_commande';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_shortcode('ispag_tanks_table', [self::$instance, 'ispag_render_tanks_table']);
    }

    public function ispag_render_tanks_table($atts) {
        $paged = get_query_var('paged') ? get_query_var('paged') : (isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        if ($paged < 1) $paged = 1;
        
        $search   = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $filters = [
            'tank_type' => isset($_GET['tank_type']) ? intval($_GET['tank_type']) : 0,
            'material'  => isset($_GET['material']) ? intval($_GET['material']) : 0,
            'volume'    => isset($_GET['volume']) ? sanitize_text_field($_GET['volume']) : '',
            'pressure'  => isset($_GET['pressure']) ? sanitize_text_field($_GET['pressure']) : '',
        ];

        $results = $this->get_data($paged, $search, $filters);
        $total_items = $this->get_total_count($search, $filters);
        $total_pages = ceil($total_items / $this->per_page);

        ob_start();
        ?>
        <style>
            /* Style de la pagination type boutons numérotés */
            .ispag-pagination {
                margin: 30px 0;
                display: flex;
                justify-content: center;
                gap: 5px;
            }
            .ispag-pagination .page-numbers {
                padding: 8px 14px;
                border: 1px solid #ddd;
                background: #fff;
                color: #2271b1;
                text-decoration: none;
                border-radius: 4px;
                font-weight: 500;
                transition: all 0.2s ease;
            }
            .ispag-pagination .page-numbers:hover {
                background: #f0f0f0;
                border-color: #2271b1;
            }
            .ispag-pagination .page-numbers.current {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
                cursor: default;
            }
            .ispag-pagination .dots {
                padding: 8px;
                color: #777;
            }
            
            /* Amélioration visuelle du tableau */
            .ispag-tanks-table-wrapper { overflow-x: auto; }
            .ispag-tanks-table-wrapper table { width: 100%; border-collapse: collapse; }
            .ispag-tanks-table-wrapper th { background: #f8f9fa; text-align: left; padding: 12px; border-bottom: 2px solid #e2e4e7; }
            .ispag-tanks-table-wrapper td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
            .project-title { color: #2271b1; font-weight: bold; text-decoration: none; font-size: 1.05em; }
            .project-id { font-size: 0.85em; color: #888; margin-top: 3px; }
            .badge-info { font-size: 0.85em; background: #e7f3ff; color: #0056b3; padding: 2px 6px; border-radius: 3px; display: inline-block; margin-top: 4px; }
        </style>

        <div class="ispag-tanks-container">
            <div class="ispag-toolbar" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <form method="get" action="">
                    <?php if(!is_admin()): ?>
                        <input type="hidden" name="page_id" value="<?php echo get_the_ID(); ?>">
                    <?php else: ?>
                        <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                    <?php endif; ?>

                    <div class="filter-group" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                        <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="Rechercher un projet..." style="min-width: 200px; padding: 6px 10px; border-radius: 4px; border: 1px solid #8c8f94;" />
                        
                        <select name="tank_type" style="padding: 6px; border-radius: 4px;">
                            <option value="0">Tous les types</option>
                            <?php $this->render_conception_options('typ', $filters['tank_type']); ?>
                        </select>

                        <select name="material" style="padding: 6px; border-radius: 4px;">
                            <option value="0">Tous les matériaux</option>
                            <?php $this->render_conception_options('material', $filters['material']); ?>
                        </select>

                        <input type="number" name="volume" value="<?php echo esc_attr($filters['volume']); ?>" placeholder="Volume L" style="width: 90px; padding: 6px; border-radius: 4px; border: 1px solid #8c8f94;"/>
                        <input type="number" name="pressure" value="<?php echo esc_attr($filters['pressure']); ?>" placeholder="Pression" style="width: 80px; padding: 6px; border-radius: 4px; border: 1px solid #8c8f94;"/>

                        <button type="submit" class="button button-primary" style="padding: 0 20px; height: 36px;"><?php _e('Filtrer'); ?></button>
                        <a href="<?php echo get_permalink(); ?>" class="button" style="height: 36px; line-height: 34px;">Reset</a>
                        
                        <span style="margin-left: auto; color: #666; font-size: 0.9em;">
                            <strong><?php echo $total_items; ?></strong> réservoirs trouvés
                        </span>
                    </div>
                </form>
            </div>

            <div class="ispag-tanks-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Date</th>
                            <th>Projet / Article</th>
                            <th>Conception</th>
                            <th>Dimensions</th>
                            <th>Pression</th>
                            <th style="text-align: right;">Prix Brut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($results) : foreach ($results as $row) : 
                            $link = !empty($row->hubspot_deal_id) 
                                ? "https://app.ispag-asp.ch/details-du-projet/?deal_id=" . esc_attr($row->hubspot_deal_id) 
                                : "#";
                            $price = floatval($row->sales_price);
                            if ($price <= 0 && has_filter('ispag_calculate_total_sales_price')) {
                                $price = apply_filters('ispag_calculate_total_sales_price', $row->article_id);
                            }
                            ?>
                            <tr>
                                <td><?php echo date('d.m.Y', strtotime($row->creation_date)); ?></td>
                                <td>
                                    <a href="<?php echo $link; ?>" class="project-title"><?php echo esc_html($row->ObjetCommande ?: $row->Article); ?></a>
                                    <div class="project-id">Article: <?php echo $row->Article; ?> | ID: <?php echo $row->article_id; ?></div>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($row->tank_type_label); ?></strong><br>
                                    <span class="badge-info"><?php echo esc_html($row->material_label); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo $row->Volume; ?> L</strong><br>
                                    <small>Ø <?php echo $row->Diameter; ?> x H <?php echo $row->Height; ?> mm</small>
                                </td>
                                <td><span style="background: #fff8e5; padding: 3px 7px; border-radius: 4px; font-weight: bold;"><?php echo $row->MaxPressure; ?> bar</span></td>
                                <td style="text-align: right;">
                                    <strong style="color: #2c3338; font-size: 1.1em;"><?php echo $price > 0 ? number_format($price, 2, '.', "'") . ' CHF' : '—'; ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; else : ?>
                            <tr><td colspan="6" style="text-align:center; padding: 40px;">Aucun résultat trouvé pour vos filtres.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1) : ?>
                <div class="ispag-pagination">
                    <?php
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%', remove_query_arg('paged')),
                        'format'    => '',
                        'prev_text' => __('&laquo; Précédent'),
                        'next_text' => __('Suivant &raquo;'),
                        'total'     => $total_pages,
                        'current'   => $paged,
                        'add_args'  => array_filter([
                            'search'    => $search,
                            'tank_type' => $filters['tank_type'],
                            'material'  => $filters['material'],
                            'volume'    => $filters['volume'],
                            'pressure'  => $filters['pressure'],
                        ])
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_data($paged, $search, $filters) {
        $limit  = (int) $this->per_page;
        $offset = (int) (($paged - 1) * $limit);
        $where = $this->build_where_clause($search, $filters);

        $sql = $this->wpdb->prepare("
            SELECT 
                d.*, 
                art.Article, art.sales_price, art.hubspot_deal_id, art.Id as article_id,
                ord.ObjetCommande,
                tc_type.Value as tank_type_label,
                tc_mat.Value as material_label
            FROM {$this->table_dimensions} d
            INNER JOIN {$this->table_details} art ON d.customerTankId = art.Id
            LEFT JOIN {$this->table_orders} ord ON art.hubspot_deal_id = ord.hubspot_deal_id
            LEFT JOIN {$this->table_conception} tc_type ON d.TankType = tc_type.Id
            LEFT JOIN {$this->table_conception} tc_mat ON d.Material = tc_mat.Id
            WHERE $where
            ORDER BY d.creation_date DESC
            LIMIT %d OFFSET %d
        ", $limit, $offset);

        return $this->wpdb->get_results($sql);
    }

    private function get_total_count($search, $filters) {
        $where = $this->build_where_clause($search, $filters);
        return $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_dimensions} d 
            INNER JOIN {$this->table_details} art ON d.customerTankId = art.Id 
            LEFT JOIN {$this->table_orders} ord ON art.hubspot_deal_id = ord.hubspot_deal_id
            WHERE $where");
    }

    private function build_where_clause($search, $filters) {
        $where = "1=1";
        if (!empty($search)) {
            $where .= $this->wpdb->prepare(" AND (art.Article LIKE %s OR ord.ObjetCommande LIKE %s OR art.Id LIKE %s)", '%' . $search . '%', '%' . $search . '%', $search);
        }
        if ($filters['tank_type'] > 0) $where .= $this->wpdb->prepare(" AND d.TankType = %d", $filters['tank_type']);
        if ($filters['material'] > 0) $where .= $this->wpdb->prepare(" AND d.Material = %d", $filters['material']);
        if (!empty($filters['volume'])) $where .= $this->wpdb->prepare(" AND d.Volume = %d", $filters['volume']);
        if (!empty($filters['pressure'])) $where .= $this->wpdb->prepare(" AND d.MaxPressure = %f", $filters['pressure']);
        return $where;
    }

    private function render_conception_options($type, $selected_id) {
        $options = $this->wpdb->get_results($this->wpdb->prepare("SELECT Id, Value FROM {$this->table_conception} WHERE SelectType = %s ORDER BY Value ASC", $type));
        foreach ($options as $opt) {
            echo '<option value="'.$opt->Id.'" '.selected($selected_id, $opt->Id, false).'>'.esc_html($opt->Value).'</option>';
        }
    }
}