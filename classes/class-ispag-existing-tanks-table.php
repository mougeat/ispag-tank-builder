<?php
defined('ABSPATH') || exit;

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
        if ( ! current_user_can( 'display_sales_prices' ) ) {
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i> 
                        <strong>' . esc_html__( 'Restricted access', 'ispag-crm' ) . ' :</strong> ' . 
                         esc_html__( 'You do not have the necessary rights to view this order.', 'ispag-crm' ) . '<br/>
                        <a href ="'. wp_login_url( get_permalink() ) . '">' . esc_html__( 'To login page', 'ispag-crm' ) . '</a>
                    </div>';
        }

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
                border-radius: var(--ispag-btn-border-radius);
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
        </style>

        <div class="ispag-tanks-container">
            <div class="ispag-toolbar">
                <form method="get" action="" style="display:contents;">
                    <?php if(!is_admin()): ?>
                        <input type="hidden" name="page_id" value="<?php echo get_the_ID(); ?>">
                    <?php else: ?>
                        <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                    <?php endif; ?>

                    <input type="search" name="search" class="ispag-search-field" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search for a project...', 'creation-reservoir'); ?>" />

                    <span class="ispag-kanban-filter-wrapper">
                        <select name="tank_type">
                            <option value="0"><?php esc_html_e('All types', 'creation-reservoir'); ?></option>
                            <?php $this->render_conception_options('typ', $filters['tank_type']); ?>
                        </select>
                    </span>

                    <span class="ispag-kanban-filter-wrapper">
                        <select name="material">
                            <option value="0"><?php esc_html_e('All materials', 'creation-reservoir'); ?></option>
                            <?php $this->render_conception_options('material', $filters['material']); ?>
                        </select>
                    </span>

                    <input type="number" name="volume" value="<?php echo esc_attr($filters['volume']); ?>" placeholder="<?php esc_attr_e('Volume L', 'creation-reservoir'); ?>" style="width: 100px;" />
                    <input type="number" name="pressure" value="<?php echo esc_attr($filters['pressure']); ?>" placeholder="<?php esc_attr_e('Pressure', 'creation-reservoir'); ?>" style="width: 100px;" />

                    <button type="submit" class="ispag-btn ispag-btn-grey"><?php esc_html_e('Filter / Search', 'creation-reservoir'); ?></button>
                    <a href="<?php echo esc_url(get_permalink()); ?>" class="ispag-btn ispag-btn-secondary-outlined"><?php esc_html_e('Reset filters', 'creation-reservoir'); ?></a>

                    <span style="margin-left: auto;">
                        <strong><?php echo (int) $total_items; ?></strong> <?php esc_html_e('tanks found', 'creation-reservoir'); ?>
                    </span>
                </form>
            </div>

            <div class="ispag-table-wrapper ispag-card">
                <table class="ispag-project-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Project / Article', 'creation-reservoir'); ?></th>
                            <th><?php esc_html_e('Date', 'creation-reservoir'); ?></th>
                            <th><?php esc_html_e('Design', 'creation-reservoir'); ?></th>
                            <th><?php esc_html_e('Dimensions', 'creation-reservoir'); ?></th>
                            <th><?php esc_html_e('Operating pressure', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Gross unit price', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($results) : foreach ($results as $row) : 
                            $link = !empty($row->hubspot_deal_id) 
                                ? trailingslashit(get_site_url()) . 'project-detail/' . esc_attr($row->hubspot_deal_id) 
                                : "#";
                            $price = floatval($row->sales_price);
                            if ($price <= 0 && has_filter('ispag_calculate_total_sales_price')) {
                                $price = apply_filters('ispag_calculate_total_sales_price', $row->article_id);
                            }
                            ?>
                            <tr class="project-row-item">
                                <td data-label="<?php esc_attr_e('Project / Article', 'creation-reservoir'); ?>" class="td-title">
                                    <strong><a href="<?php echo esc_url($link); ?>" class="project-link"><?php echo esc_html($row->ObjetCommande ?: $row->Article); ?></a></strong><br>
                                    <small class="project-number">#<?php echo (int) $row->article_id; ?></small>
                                    <?php if (!empty($row->Article)) : ?> | <small class="creator-name"><?php echo esc_html($row->Article); ?></small><?php endif; ?>
                                </td>
                                <td data-label="<?php esc_attr_e('Date', 'creation-reservoir'); ?>"><?php echo esc_html(date('d.m.Y', strtotime($row->creation_date))); ?></td>
                                <td data-label="<?php esc_attr_e('Design', 'creation-reservoir'); ?>" class="td-contact">
                                    <span class="company-name"><?php echo esc_html($row->tank_type_label); ?></span><br>
                                    <small class="creator-name"><?php echo esc_html($row->material_label); ?></small>
                                </td>
                                <td data-label="<?php esc_attr_e('Dimensions', 'creation-reservoir'); ?>" class="td-contact">
                                    <span class="company-name"><?php echo esc_html($row->Volume); ?> L</span><br>
                                    <small class="creator-name">Ø <?php echo esc_html($row->Diameter); ?> x H <?php echo esc_html($row->Height); ?> mm</small>
                                </td>
                                <td data-label="<?php esc_attr_e('Operating pressure', 'creation-reservoir'); ?>" class="td-step"><span class="ispag-next-step-badge step-badge" style="color:#8c8f94; border:1px solid #8c8f94;"><?php echo esc_html($row->MaxPressure); ?> bar</span></td>
                                <td data-label="<?php esc_attr_e('Gross unit price', 'creation-reservoir'); ?>" style="text-align: right;">
                                    <strong><?php echo $price > 0 ? esc_html(number_format($price, 2, '.', "'")) . ' CHF' : '—'; ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; else : ?>
                            <tr><td colspan="6" style="text-align:center; padding: 40px;">No result found for your filters.</td></tr>
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
                        'prev_text' => __('&laquo; Previous'),
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