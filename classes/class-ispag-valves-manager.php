<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ISPAG_Valves_Manager {
    private $wpdb;
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'achats_valves';

        // Création de la table à l'initialisation
        $this->create_table();

        // Ajout du sous-menu pour les statistiques des vannes
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    /**
     * Crée la table pour les vannes si elle n'existe pas
     */
    private function create_table() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            deal_id BIGINT(20) NOT NULL,
            page INT,
            titre VARCHAR(255),
            supplier VARCHAR(255) DEFAULT 'M.P. Welding SA',
            type VARCHAR(255),
            marque VARCHAR(255),
            technical_data TEXT,
            quantity INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX deal_id_index (deal_id),
            INDEX supplier_index (supplier),
            INDEX type_index (type),
            INDEX marque_index (marque)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Ajoute le sous-menu pour les statistiques des vannes
     */
    public function add_admin_menu() {
        add_submenu_page(
            'ispag-entreprises', // Menu parent
            __('Valves Statistics', 'creation-reservoir'),
            __('Valves Stats', 'creation-reservoir'),
            'manage_options',
            'ispag-valves-stats',
            [$this, 'render_statistics_page']
        );
    }

    /**
     * Charge les styles CSS pour l'admin
     */
    public function enqueue_admin_styles() {
        wp_add_inline_style('wp-admin', '
            .ispag-stats-overview {
                background: #f9f9f9;
                padding: 15px;
                border-radius: var(--ispag-btn-border-radius);
                margin-bottom: 20px;
            }
            .ispag-stats-overview p {
                margin: 5px 0;
            }
            .wp-list-table th, .wp-list-table td {
                padding: 12px;
            }
            .ispag-valves-stats h2 {
                margin-top: 30px;
            }
        ');
    }

    /**
     * Affiche la page des statistiques des vannes dans l'admin
     */
    public function render_statistics_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'creation-reservoir'));
        }

        $stats = $this->get_valves_statistics();

        $translations = [
            'title' => __('Valves Statistics', 'creation-reservoir'),
            'total_valves' => __('Total Valves:', 'creation-reservoir'),
            'total_quantity' => __('Total Quantity:', 'creation-reservoir'),
            'unique_brands' => __('Unique Brands:', 'creation-reservoir'),
            'unique_types' => __('Unique Types:', 'creation-reservoir'),
            'top_brands' => __('Top 5 Brands', 'creation-reservoir'),
            'top_types' => __('Top 5 Types', 'creation-reservoir'),
            'brand' => __('Brand', 'creation-reservoir'),
            'type' => __('Type', 'creation-reservoir'),
            'count' => __('Count', 'creation-reservoir'),
            'quantity' => __('Quantity', 'creation-reservoir'),
        ];

        echo '<div class="wrap ispag-valves-stats">';
        echo '<h1>' . esc_html($translations['title']) . '</h1>';

        // Statistiques générales
        echo '<div class="ispag-stats-overview">';
        echo '<p><strong>' . esc_html($translations['total_valves']) . '</strong> ' . esc_html($stats['general']->total_valves ?? 0) . '</p>';
        echo '<p><strong>' . esc_html($translations['total_quantity']) . '</strong> ' . esc_html($stats['general']->total_quantity ?? 0) . '</p>';
        echo '<p><strong>' . esc_html($translations['unique_brands']) . '</strong> ' . esc_html($stats['general']->unique_brands ?? 0) . '</p>';
        echo '<p><strong>' . esc_html($translations['unique_types']) . '</strong> ' . esc_html($stats['general']->unique_types ?? 0) . '</p>';
        echo '</div>';

        // Top 5 des marques
        echo '<h2>' . esc_html($translations['top_brands']) . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html($translations['brand']) . '</th>';
        echo '<th>' . esc_html($translations['count']) . '</th>';
        echo '<th>' . esc_html($translations['quantity']) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        if (!empty($stats['brands'])) {
            foreach (array_slice($stats['brands'], 0, 5) as $brand) {
                echo '<tr>';
                echo '<td>' . esc_html($brand->marque) . '</td>';
                echo '<td>' . esc_html($brand->count) . '</td>';
                echo '<td>' . esc_html($brand->total_quantity) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">' . __('No data available.', 'creation-reservoir') . '</td></tr>';
        }
        echo '</tbody></table>';

        // Top 5 des types
        echo '<h2>' . esc_html($translations['top_types']) . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html($translations['type']) . '</th>';
        echo '<th>' . esc_html($translations['count']) . '</th>';
        echo '<th>' . esc_html($translations['quantity']) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        if (!empty($stats['types'])) {
            foreach (array_slice($stats['types'], 0, 5) as $type) {
                echo '<tr>';
                echo '<td>' . esc_html($type->type) . '</td>';
                echo '<td>' . esc_html($type->count) . '</td>';
                echo '<td>' . esc_html($type->total_quantity) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">' . __('No data available.', 'creation-reservoir') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }

    /**
     * Sauvegarde une vanne en base de données
     */
    public function save_valve($valve_data, $deal_id) {
        if (empty($valve_data) || empty($deal_id)) {
            return false;
        }

        $data = [
            'deal_id' => $deal_id,
            'page' => $valve_data['page'] ?? 0,
            'titre' => sanitize_text_field($valve_data['titre'] ?? ''),
            'supplier' => sanitize_text_field($valve_data['supplier'] ?? 'M.P. Welding SA'),
            'type' => sanitize_text_field($valve_data['type'] ?? ''),
            'marque' => sanitize_text_field($valve_data['marque'] ?? ''),
            'technical_data' => maybe_serialize($valve_data['technical_data'] ?? []),
            'quantity' => intval($valve_data['quantity'] ?? 1),
        ];

        $format = ['%d', '%d', '%s', '%s', '%s', '%s', '%d'];

        $this->wpdb->insert($this->table_name, $data, $format);
        return $this->wpdb->insert_id;
    }

    /**
     * Récupère les vannes pour un deal donné
     */
    public function get_valves_by_deal($deal_id) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE deal_id = %d ORDER BY created_at DESC",
                $deal_id
            )
        );
    }

    /**
     * Récupère des statistiques sur les vannes (marques, types, etc.)
     */
    public function get_valves_statistics() {
        $results = $this->wpdb->get_results(
            "SELECT
                COUNT(*) as total_valves,
                COUNT(DISTINCT marque) as unique_brands,
                COUNT(DISTINCT type) as unique_types,
                SUM(quantity) as total_quantity
            FROM {$this->table_name}"
        );

        $brand_stats = $this->wpdb->get_results(
            "SELECT marque, COUNT(*) as count, SUM(quantity) as total_quantity
            FROM {$this->table_name}
            WHERE marque != ''
            GROUP BY marque
            ORDER BY count DESC"
        );

        $type_stats = $this->wpdb->get_results(
            "SELECT type, COUNT(*) as count, SUM(quantity) as total_quantity
            FROM {$this->table_name}
            WHERE type != ''
            GROUP BY type
            ORDER BY count DESC"
        );

        return [
            'general' => $results[0] ?? (object) [
                'total_valves' => 0,
                'unique_brands' => 0,
                'unique_types' => 0,
                'total_quantity' => 0,
            ],
            'brands' => $brand_stats,
            'types' => $type_stats,
        ];
    }

    /**
     * Met à jour une vanne existante
     */
    public function update_valve($valve_id, $valve_data) {
        if (empty($valve_id) || empty($valve_data)) {
            return false;
        }

        $data = [];
        if (isset($valve_data['page'])) $data['page'] = $valve_data['page'];
        if (isset($valve_data['titre'])) $data['titre'] = sanitize_text_field($valve_data['titre']);
        if (isset($valve_data['supplier'])) $data['supplier'] = sanitize_text_field($valve_data['supplier']);
        if (isset($valve_data['type'])) $data['type'] = sanitize_text_field($valve_data['type']);
        if (isset($valve_data['marque'])) $data['marque'] = sanitize_text_field($valve_data['marque']);
        if (isset($valve_data['technical_data'])) $data['technical_data'] = maybe_serialize($valve_data['technical_data']);
        if (isset($valve_data['quantity'])) $data['quantity'] = intval($valve_data['quantity']);

        if (empty($data)) {
            return false;
        }

        $this->wpdb->update($this->table_name, $data, ['id' => $valve_id]);
        return true;
    }

    /**
     * Supprime une vanne
     */
    public function delete_valve($valve_id) {
        return $this->wpdb->delete($this->table_name, ['id' => $valve_id]);
    }

    /**
     * Supprime toutes les vannes liées à un deal
     */
    public function delete_valves_by_deal($deal_id) {
        return $this->wpdb->delete($this->table_name, ['deal_id' => $deal_id]);
    }
}
