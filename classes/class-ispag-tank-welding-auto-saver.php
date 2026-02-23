<?php

class ISPAG_Tank_Welding_Auto_Saver {
    private $wpdb;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_auto_welding_saver', [self::$instance, 'maybe_add_welding_article'], 10, 4);
    }

    public function maybe_add_welding_article($html, $deal_id, $article_id, $nb_welding) {
        
        if(empty($nb_welding) OR $nb_welding == 0) return;
        ob_start();
        $tank = apply_filters('ispag_get_tank_datas', null, $article_id);
        

        // echo "[DEBUG] article_id: $article_id\n";
        // echo "[DEBUG] deal_id: $deal_id\n";
        // echo "[DEBUG] selected_type: $selected_type | selected_thickness: $selected_thickness\n";
        // echo "[DEBUG] tank data:\n";
        // var_dump($tank);

        if (!$tank) {
            // echo "[DEBUG] Pas de données de cuve.\n";
            return ob_get_clean();
        }

        $matching_article = $this->find_matching_welding_article(
            floatval($tank['dimensions']->Diameter),
            $tank['conception']->Material,
            intval($nb_welding)
        );

        if ($matching_article) {
            // echo "[DEBUG] Article soudure trouvé : ID {$matching_article->Id} | Titre : {$matching_article->TitreArticle}\n";
            $result = $this->insert_welding_article($deal_id, $article_id, $matching_article);
            var_dump($result);
        } else {
            // echo "[DEBUG] Aucun article de soudure correspondant.\n";
        }

        $this->sync_welding_connections_by_article($article_id, $nb_welding);

        return ob_get_clean();
    }

    private function find_matching_welding_article($diameter, $material, $nb_welding) {
        $articles = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 3"
        );
        $nb_welding = $nb_welding+1;

        // // Correspondance code → libellé
        // $material = match (intval($material_code)) {
        //     1, 3 => 'Inox',
        //     2 => 'AcierNoir',
        //     default => 'Inconnu',
        // };

        // echo "[DEBUG] Recherche soudure : diameter={$diameter}, material_code={$material_code} => material={$material}, nb_welding={$nb_welding}\n";

        foreach ($articles as $article) {
            // echo "[DEBUG] Test article ID {$article->Id}\n";

            $data = json_decode($article->conception);
            if (!isset($data->welding)) {
                echo "  → Pas de champ 'welding'\n";
                continue;
            }

            $w = $data->welding;
            $match_nb = intval($w->nb_welding) === $nb_welding;
            $match_material = strtolower(trim($w->tank_material)) === strtolower(trim($material));
            $match_diameter = abs(floatval($w->tank_diameter) - $diameter) < 5;

            // echo "    nb_welding: {$w->nb_welding} (match: " . ($match_nb ? '✔' : '✘') . ")\n";
            // echo "    material: {$w->tank_material} (match: " . ($match_material ? '✔' : '✘') . ")\n";
            // echo "    diameter: {$w->tank_diameter} (match: " . ($match_diameter ? '✔' : '✘') . ")\n";

            if ($match_nb && $match_material && $match_diameter) {
                // echo "  → MATCH trouvé !\n";
                return $article;
            }
        }

        // echo "[DEBUG] Aucun article de soudure correspondant trouvé.\n";
        return null;
    }


    private function insert_welding_article($deal_id, $tank_id, $article) {
        $title = apply_filters('ispag_get_welding_title', '', $article->Id);
        $description = apply_filters('ispag_get_welding_description', '', $article->Id);

        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_id);

        if (!$tank) {
            return ['success' => false, 'error' => 'No tank found'];
        }

        $demande_achat = $article->sales_price != 0;

        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->wpdb->prefix}achats_details_commande
            WHERE hubspot_deal_id = %d AND Groupe = %s AND Type = 3 LIMIT 1",
            $deal_id,
            $tank->Groupe
        ));

        $data = [
            'linked_tank' => $tank_id,
            'IdArticleStandard' => $article->Id,
            'Article' => $title,
            'Description' => $description,
            'sales_price' => $article->sales_price,
            'Qty' => $tank->Qty,
            'DemandeAchatOk' => $demande_achat,
        ];

        if ($existing_id) {
            $this->wpdb->update("{$this->wpdb->prefix}achats_details_commande", $data, ['Id' => $existing_id]);
            return ['success' => true, 'action' => 'updated', 'row_id' => $existing_id];
        } else {
            $data['hubspot_deal_id'] = $deal_id;
            $data['Groupe'] = $tank->Groupe;
            $data['Type'] = 3;

            $this->wpdb->insert("{$this->wpdb->prefix}achats_details_commande", $data);
            return ['success' => true, 'action' => 'inserted', 'insert_id' => $this->wpdb->insert_id];
        }
    }

    public function sync_welding_connections_by_article($article_id, $nb_welding) {
        global $wpdb;

        $article_id = (int) $article_id;
        $nb_welding = (int) $nb_welding;

        $dim_table = $wpdb->prefix . 'achats_tank_dimensions';
        $conn_table = $wpdb->prefix . 'achats_tank_connection';

        // 1. Récupérer le TankId à partir de l'article
        $tank_id = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM $dim_table WHERE customerTankId = %d",
            $article_id
        ));

        if (!$tank_id) {
            return "[SYNC] Aucun tank_id trouvé pour l'article $article_id.";
        }

        

        // 2. Compter les connexions existantes de type 23
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count, MIN(heightApproved) as heightApproved FROM $conn_table WHERE TankId = %d AND Type = 23",
            $tank_id
        ));
        $current_count = (int) $row->count;
        $height_approved = (int) $row->heightApproved;
        


        if ($current_count === $nb_welding AND $height_approved != 0) {
            return "[SYNC] Rien à faire, nombre exact ($nb_welding) et hauteur validée.";
        }

        
        // On récupère les dimensions de la cuve pour recalcul des positions
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);
        $tank_height = $tank_datas['dimensions']->Height ?? 0;
        $tank_ground_clearance = $tank_datas['dimensions']->GroundClearance ?? 0;
        $body_height = $tank_height - $tank_ground_clearance;
        $sections_height = $nb_welding > 0 ? ceil($body_height / ($nb_welding + 1)) : 0;

        if ($current_count < $nb_welding) {
            $to_add = $nb_welding - $current_count;
            for ($i = 0; $i < $to_add; $i++) {

                $weld_nb = $current_count + $i + 1;
                $weld_position = $weld_nb * $sections_height;

                $wpdb->insert($conn_table, [
                    'TankId' => $tank_id,
                    'Type' => 23,
                    'Pouces' => 23,
                    'Height' => $weld_position,
                    'heightApproved' => 0,
                ]);
            }
            return "[SYNC] $to_add ligne(s) ajoutée(s) pour TankId $tank_id.";
        } elseif ($current_count > $nb_welding) {
            $to_delete = $current_count - $nb_welding;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT Id FROM $conn_table WHERE TankId = %d AND Type = 23 ORDER BY Id DESC LIMIT %d",
                $tank_id,
                $to_delete
            ));
            foreach ($ids as $id) {
                $wpdb->delete($conn_table, ['Id' => $id]);
            }
        }

        // Recalculer la hauteur de toutes les soudures (ajustement après ajout ou suppression)
        $connections = $wpdb->get_results($wpdb->prepare(
            "SELECT Id FROM $conn_table WHERE TankId = %d AND Type = 23 ORDER BY Id ASC",
            $tank_id
        ));
        
        foreach ($connections as $index => $conn) {
            if ($row && intval($row->heightApproved) !== 1) {
                $new_height = ($index + 1) * $sections_height;
                $wpdb->update($conn_table, ['Height' => $new_height, 'heightApproved' => 0], ['Id' => $conn->Id]);
            }
        }
    }


}
