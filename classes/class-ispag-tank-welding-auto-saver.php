<?php
/**
 * Class ISPAG_Tank_Welding_Auto_Saver
 * Gère l'ajout automatique des articles de soudure pour les réservoirs ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_tank_welding_auto_saver.log.
 */
class ISPAG_Tank_Welding_Auto_Saver
{
    private $wpdb;
    protected static $instance = null;
    private const LOG_NAME = 'tank_welding_auto_saver';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
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

        add_filter('ispag_auto_welding_saver', [self::$instance, 'maybe_add_welding_article'], 10, 4);
        // $logger->log_user_action(self::LOG_NAME, 'filter_registered', ['filter' => 'ispag_auto_welding_saver'], $user_id);

        add_action('ispag_delete_welding_article', [self::$instance, 'delete_welding_article'], 10, 2);
        // $logger->log_user_action(self::LOG_NAME, 'filter_registered', ['filter' => 'ispag_delete_welding_article'], $user_id);
    }

    public function maybe_add_welding_article($html, $deal_id, $article_id, $nb_welding)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'maybe_add_welding_article_start', ['deal_id' => $deal_id, 'article_id' => $article_id, 'nb_welding' => $nb_welding], $user_id);

        if (empty($nb_welding) || $nb_welding == 0)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_welding_to_add', [], $user_id);
            return;
        }

        ob_start();
        $tank = apply_filters('ispag_get_tank_datas', null, $article_id);
        $this->logger->log_db_change(self::LOG_NAME, 'tank_datas', 'FETCH', ['article_id' => $article_id], $user_id);

        if (!$tank)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank data found for article ' . $article_id, $user_id);
            return ob_get_clean();
        }

        $matching_article = $this->find_matching_welding_article(
            floatval($tank['dimensions']->Diameter),
            $tank['conception']->Material,
            intval($nb_welding)
        );

        $this->logger->log_user_action(self::LOG_NAME, 'matching_article_searched', ['diameter' => $tank['dimensions']->Diameter, 'material' => $tank['conception']->Material, 'nb_welding' => $nb_welding], $user_id);

        if ($matching_article)
        {
            $this->logger->log_db_change(self::LOG_NAME, 'achats_articles', 'MATCHING_ARTICLE_FOUND', ['article_id' => $matching_article->Id, 'title' => $matching_article->TitreArticle], $user_id);
            $result = $this->insert_welding_article($deal_id, $article_id, $matching_article);
            $this->logger->log_user_action(self::LOG_NAME, 'welding_article_inserted', ['result' => $result], $user_id);
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No matching welding article found', $user_id);
        }

        $sync_result = $this->sync_welding_connections_by_article($article_id, $nb_welding);
        $this->logger->log_user_action(self::LOG_NAME, 'welding_connections_synced', ['result' => $sync_result], $user_id);

        return ob_get_clean();
    }

    private function find_matching_welding_article($diameter, $material, $nb_welding)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'find_matching_welding_article_start', ['diameter' => $diameter, 'material' => $material, 'nb_welding' => $nb_welding], $user_id);

        $articles = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 3"
        );

        $this->logger->log_db_change(self::LOG_NAME, 'achats_articles', 'FETCH_WELDING_ARTICLES', ['count' => count($articles)], $user_id);

        $nb_welding = $nb_welding + 1;

        foreach ($articles as $article)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'checking_article', ['article_id' => $article->Id], $user_id);

            $data = json_decode($article->conception);
            if (!isset($data->welding))
            {
                $this->logger->log_user_action(self::LOG_NAME, 'no_welding_field', ['article_id' => $article->Id], $user_id);
                continue;
            }

            $w = $data->welding;
            $match_nb = intval($w->nb_welding) === $nb_welding;
            $match_material = strtolower(trim($w->tank_material)) === strtolower(trim($material));
            $match_diameter = abs(floatval($w->tank_diameter) - $diameter) < 5;

            $this->logger->log_user_action(self::LOG_NAME, 'welding_match_check', [
                'article_id' => $article->Id,
                'match_nb' => $match_nb,
                'match_material' => $match_material,
                'match_diameter' => $match_diameter,
                'w_nb_welding' => $w->nb_welding,
                'w_material' => $w->tank_material,
                'w_diameter' => $w->tank_diameter
            ], $user_id);

            if ($match_nb && $match_material && $match_diameter)
            {
                $this->logger->log_db_change(self::LOG_NAME, 'achats_articles', 'MATCH_FOUND', ['article_id' => $article->Id], $user_id);
                return $article;
            }
        }

        $this->logger->log(self::LOG_NAME, 'ERROR: No matching welding article found', $user_id);
        return null;
    }

    private function insert_welding_article($deal_id, $tank_id, $article)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'insert_welding_article_start', ['deal_id' => $deal_id, 'tank_id' => $tank_id, 'article_id' => $article->Id], $user_id);

        $title = apply_filters('ispag_get_welding_title', '', $article->Id);
        $description = apply_filters('ispag_get_welding_description', '', $article->Id);
        $default_supplier = 25;

        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_id);

        if (!$tank)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank found for tank_id ' . $tank_id, $user_id);
            return ['success' => false, 'error' => 'No tank found'];
        }

        $this->logger->log_db_change(self::LOG_NAME, 'articles', 'FETCH_TANK', ['tank_id' => $tank_id], $user_id);

        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->wpdb->prefix}achats_details_commande
            WHERE hubspot_deal_id = %d AND Groupe = %s AND Type = 3 LIMIT 1",
            $deal_id,
            $tank->Groupe
        ));

        $this->logger->log_db_change(self::LOG_NAME, 'achats_details_commande', 'CHECK_EXISTING', ['deal_id' => $deal_id, 'groupe' => $tank->Groupe, 'existing_id' => $existing_id], $user_id);

        $data = [
            'linked_tank' => $tank_id,
            'IdArticleStandard' => $article->Id,
            'Article' => $title,
            'Description' => $description,
            // 'sales_price' => $article->sales_price,
            'Qty' => $tank->Qty,
            'IdFournisseur' => $default_supplier,
        ];

        $this->logger->log_user_action(self::LOG_NAME, 'welding_article_data_prepared', ['data' => $data], $user_id);

        if ($existing_id)
        {
            $result = $this->wpdb->update("{$this->wpdb->prefix}achats_details_commande", $data, ['Id' => $existing_id]);
            $this->logger->log_db_change(self::LOG_NAME, 'achats_details_commande', 'UPDATE', ['existing_id' => $existing_id, 'result' => $result], $user_id);
            return ['success' => true, 'action' => 'updated', 'row_id' => $existing_id];
        }
        else
        {
            $data['hubspot_deal_id'] = $deal_id;
            $data['Groupe'] = $tank->Groupe;
            $data['Type'] = 3;

            $result = $this->wpdb->insert("{$this->wpdb->prefix}achats_details_commande", $data);
            $this->logger->log_db_change(self::LOG_NAME, 'achats_details_commande', 'INSERT', ['data' => $data, 'result' => $result], $user_id);
            return ['success' => true, 'action' => 'inserted', 'insert_id' => $this->wpdb->insert_id];
        }
    }

    /**
     * Supprime l'article de soudure pour un réservoir donné.
     *
     * @param int $deal_id ID du deal.
     * @param int $article_id ID de l'article du réservoir.
     * @return array Résultat de la suppression.
     */
    public function delete_welding_article($deal_id, $article_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'delete_welding_article_start', ['deal_id' => $deal_id, 'article_id' => $article_id], $user_id);

        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $article_id);

        if (!$tank)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank found for article_id ' . $article_id, $user_id);
            return ['success' => false, 'error' => 'No tank found'];
        }

        $this->logger->log_db_change(self::LOG_NAME, 'articles', 'FETCH_TANK', ['article_id' => $article_id], $user_id);

        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->wpdb->prefix}achats_details_commande
            WHERE hubspot_deal_id = %d AND Groupe = %s AND Type = 3 LIMIT 1",
            $deal_id,
            $tank->Groupe
        ));

        $this->logger->log_db_change(self::LOG_NAME, 'achats_details_commande', 'FETCH_WELDING_ARTICLE', ['deal_id' => $deal_id, 'groupe' => $tank->Groupe, 'existing_id' => $existing_id], $user_id);

        if (!$existing_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No welding article found to delete', $user_id);
            return ['success' => false, 'error' => 'No welding article found'];
        }

        $result = $this->wpdb->delete("{$this->wpdb->prefix}achats_details_commande", ['Id' => $existing_id]);
        $this->logger->log_db_change(self::LOG_NAME, 'achats_details_commande', 'DELETE_WELDING_ARTICLE', ['existing_id' => $existing_id, 'result' => $result], $user_id);

        if ($result === false)
        {
            $error = $this->wpdb->last_error;
            $this->logger->log(self::LOG_NAME, 'ERROR: Failed to delete welding article - ' . $error, $user_id);
            return ['success' => false, 'error' => $error];
        }

        $this->logger->log_user_action(self::LOG_NAME, 'welding_article_deleted', ['existing_id' => $existing_id], $user_id);
        return ['success' => true, 'message' => 'Welding article deleted successfully'];
    }

    public function sync_welding_connections_by_article($article_id, $nb_welding)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'sync_welding_connections_by_article_start', ['article_id' => $article_id, 'nb_welding' => $nb_welding], $user_id);

        global $wpdb;
        $article_id = (int) $article_id;
        $nb_welding = (int) $nb_welding;

        $dim_table = $wpdb->prefix . 'achats_tank_dimensions';
        $conn_table = $wpdb->prefix . 'achats_tank_connection';

        $tank_id = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM $dim_table WHERE customerTankId = %d",
            $article_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $dim_table, 'FETCH_TANK_ID', ['article_id' => $article_id, 'tank_id' => $tank_id], $user_id);

        if (!$tank_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank_id found for article ' . $article_id, $user_id);
            return "[SYNC] Aucun tank_id trouvé pour l'article $article_id.";
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count, MIN(heightApproved) as heightApproved FROM $conn_table WHERE TankId = %d AND Type = 23",
            $tank_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $conn_table, 'FETCH_WELDING_CONNECTIONS', ['tank_id' => $tank_id, 'count' => $row->count, 'heightApproved' => $row->heightApproved], $user_id);

        $current_count = (int) $row->count;
        $height_approved = (int) $row->heightApproved;

        if ($current_count === $nb_welding && $height_approved != 0)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'sync_not_needed', ['current_count' => $current_count, 'nb_welding' => $nb_welding], $user_id);
            return "[SYNC] Rien à faire, nombre exact ($nb_welding) et hauteur validée.";
        }

        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        $tank_height = $tank_datas['dimensions']->Height ?? 0;
        $tank_ground_clearance = $tank_datas['dimensions']->GroundClearance ?? 0;
        $body_height = $tank_height - $tank_ground_clearance;
        $sections_height = $nb_welding > 0 ? ceil($body_height / ($nb_welding + 1)) : 0;

        $this->logger->log_user_action(self::LOG_NAME, 'height_calculations', [
            'tank_height' => $tank_height,
            'ground_clearance' => $tank_ground_clearance,
            'body_height' => $body_height,
            'sections_height' => $sections_height
        ], $user_id);

        if ($current_count < $nb_welding)
        {
            $to_add = $nb_welding - $current_count;
            $this->logger->log_user_action(self::LOG_NAME, 'adding_welding_connections', ['to_add' => $to_add], $user_id);

            for ($i = 0; $i < $to_add; $i++)
            {
                $weld_nb = $current_count + $i + 1;
                $weld_position = $weld_nb * $sections_height;

                $result = $wpdb->insert($conn_table, [
                    'TankId' => $tank_id,
                    'Type' => 23,
                    'Pouces' => 23,
                    'Height' => $weld_position,
                    'heightApproved' => 0,
                ]);

                $this->logger->log_db_change(self::LOG_NAME, $conn_table, 'INSERT_WELDING_CONNECTION', ['weld_nb' => $weld_nb, 'weld_position' => $weld_position, 'result' => $result], $user_id);
            }

            $this->logger->log_user_action(self::LOG_NAME, 'welding_connections_added', ['added_count' => $to_add, 'tank_id' => $tank_id], $user_id);
            return "[SYNC] $to_add ligne(s) ajoutée(s) pour TankId $tank_id.";
        }
        elseif ($current_count > $nb_welding)
        {
            $to_delete = $current_count - $nb_welding;
            $this->logger->log_user_action(self::LOG_NAME, 'deleting_welding_connections', ['to_delete' => $to_delete], $user_id);

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT Id FROM $conn_table WHERE TankId = %d AND Type = 23 ORDER BY Id DESC LIMIT %d",
                $tank_id,
                $to_delete
            ));

            $this->logger->log_db_change(self::LOG_NAME, $conn_table, 'FETCH_IDS_TO_DELETE', ['count' => count($ids)], $user_id);

            foreach ($ids as $id)
            {
                $result = $wpdb->delete($conn_table, ['Id' => $id]);
                $this->logger->log_db_change(self::LOG_NAME, $conn_table, 'DELETE_WELDING_CONNECTION', ['id' => $id, 'result' => $result], $user_id);
            }
        }

        $connections = $wpdb->get_results($wpdb->prepare(
            "SELECT Id FROM $conn_table WHERE TankId = %d AND Type = 23 ORDER BY Id ASC",
            $tank_id
        ));

        $this->logger->log_db_change(self::LOG_NAME, $conn_table, 'FETCH_ALL_CONNECTIONS', ['tank_id' => $tank_id, 'count' => count($connections)], $user_id);

        foreach ($connections as $index => $conn)
        {
            if ($row && intval($row->heightApproved) !== 1)
            {
                $new_height = ($index + 1) * $sections_height;
                $result = $wpdb->update($conn_table, ['Height' => $new_height, 'heightApproved' => 0], ['Id' => $conn->Id]);
                $this->logger->log_db_change(self::LOG_NAME, $conn_table, 'UPDATE_WELDING_HEIGHT', ['id' => $conn->Id, 'new_height' => $new_height, 'result' => $result], $user_id);
            }
        }

        $this->logger->log_user_action(self::LOG_NAME, 'sync_welding_connections_complete', [], $user_id);
    }
}