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

        add_filter('ispag_auto_welding_saver', [self::$instance, 'maybe_add_welding_article'], 10, 5);
        // $logger->log_user_action(self::LOG_NAME, 'filter_registered', ['filter' => 'ispag_auto_welding_saver'], $user_id);

        add_action('ispag_delete_welding_article', [self::$instance, 'delete_welding_article'], 10, 2);
        // $logger->log_user_action(self::LOG_NAME, 'filter_registered', ['filter' => 'ispag_delete_welding_article'], $user_id);
    }

    /**
     * @param mixed $nb_welding  Nombre de soudures saisi ; null ou '' = champ absent, on ne touche à rien.
     * @param bool  $by_client   Soudure réalisée par le client : le nombre de soudures est enregistré (piquages),
     *                           mais aucun article de soudure n'est créé (et celui qui existe est retiré).
     */
    public function maybe_add_welding_article($html, $deal_id, $article_id, $nb_welding, $by_client = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'maybe_add_welding_article_start', ['deal_id' => $deal_id, 'article_id' => $article_id, 'nb_welding' => $nb_welding, 'by_client' => $by_client], $user_id);

        if ($nb_welding === null || $nb_welding === '')
        {
            $this->logger->log_user_action(self::LOG_NAME, 'welding_field_absent', [], $user_id);
            return;
        }
        $nb_welding = max(0, (int) $nb_welding);

        ob_start();
        $tank = apply_filters('ispag_get_tank_datas', null, $article_id);
        $this->logger->log_db_change(self::LOG_NAME, 'tank_datas', 'FETCH', ['article_id' => $article_id], $user_id);

        if (!$tank)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank data found for article ' . $article_id, $user_id);
            return ob_get_clean();
        }

        if ($by_client || $nb_welding === 0)
        {
            // Pas d'article de soudure : soudure faite par le client, ou aucune soudure
            $this->delete_welding_article($deal_id, $article_id);
            $this->logger->log_user_action(self::LOG_NAME, 'welding_article_not_needed', ['by_client' => $by_client, 'nb_welding' => $nb_welding], $user_id);
        }
        else
        {
            $matching_article = $this->find_matching_welding_article(
                floatval($tank['dimensions']->Diameter),
                $tank['conception']->Material,
                $nb_welding
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
        }

        // Le nombre de soudures est toujours enregistré (piquages de type soudure), même si la soudure est faite par le client
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

        $matching_candidates = [];

        foreach ($articles as $article)
        {
            $data = json_decode($article->conception);
            if (!isset($data->welding))
            {
                continue;
            }

            $w = $data->welding;
            
            // Correspondance exacte sur le nombre de soudures (ex: 2 ou 3) + 1 pour correspondre au nombre de tronçons
            $match_nb = intval($w->nb_welding) === intval($nb_welding);
            $match_material = strtolower(trim($w->tank_material)) === strtolower(trim($material));
            
            // Le diamètre de l'article doit être supérieur ou égal à celui du réservoir
            $w_diameter = floatval($w->tank_diameter);
            $match_diameter = $w_diameter >= floatval($diameter);

            if ($match_nb && $match_material && $match_diameter)
            {
                $matching_candidates[] = [
                    'article' => $article,
                    'diameter' => $w_diameter
                ];
            }
        }

        if (!empty($matching_candidates))
        {
            // Tri par diamètre croissant pour récupérer le plus petit des diamètres supérieurs (celui juste au-dessus)
            usort($matching_candidates, function($a, $b) {
                return $a['diameter'] <=> $b['diameter'];
            });

            $best_match = $matching_candidates[0]['article'];

            $this->logger->log_db_change(self::LOG_NAME, 'achats_articles', 'MATCH_FOUND_GREATER_DIAMETER', [
                'article_id' => $best_match->Id,
                'matched_diameter' => $matching_candidates[0]['diameter'],
                'tank_diameter' => $diameter,
                'nb_welding' => $nb_welding
            ], $user_id);

            return $best_match;
        }

        $this->logger->log(self::LOG_NAME, 'ERROR: No matching welding article found for nb_welding ' . $nb_welding, $user_id);
        return null;
    }

    /**
     * Ligne de soudure d'un réservoir : d'abord celle qui lui est liée (linked_tank), quel que soit son groupe,
     * sinon celle du même groupe. Sans cela, renseigner le groupe après coup créait un doublon.
     */
    private function find_welding_row($deal_id, $tank_article_id, $groupe)
    {
        $table = "{$this->wpdb->prefix}achats_details_commande";
        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$table} WHERE linked_tank = %d AND Type = 3 LIMIT 1", $tank_article_id
        ));
        if ($id) return $id;
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$table} WHERE hubspot_deal_id = %d AND Groupe = %s AND Type = 3 LIMIT 1", $deal_id, $groupe
        ));
    }

    private function insert_welding_article($deal_id, $tank_id, $article)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'insert_welding_article_start', ['deal_id' => $deal_id, 'tank_id' => $tank_id, 'article_id' => $article->Id, 'Article' => $article->TitreArticle, 'Description' => $article->description_ispag], $user_id);

        $title = apply_filters('ispag_get_welding_title', $article->TitreArticle, $article->Id);
        $description = apply_filters('ispag_get_welding_description', $article->description_ispag, $article->Id);
        $default_supplier = 25; // ID fournisseur = wor9711_ispag_companies.id : à remapper (voir ispag-crm/migrations)

        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_id);

        if (!$tank)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No tank found for tank_id ' . $tank_id, $user_id);
            return ['success' => false, 'error' => 'No tank found'];
        }

        $this->logger->log_db_change(self::LOG_NAME, 'articles', 'FETCH_TANK', ['tank_id' => $tank_id], $user_id);

        $existing_id = $this->find_welding_row($deal_id, $tank_id, $tank->Groupe);

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
            $data['Groupe'] = $tank->Groupe; // le groupe a pu être renseigné après la création de la ligne
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

        $existing_id = $this->find_welding_row($deal_id, $article_id, $tank->Groupe);

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
            return "[SYNC] No tank_id found for article $article_id.";
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
            return "[SYNC] Nothing to do, exact count ($nb_welding) and height validated.";
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
            return "[SYNC] $to_add row(s) added for TankId $tank_id.";
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