<?php

class ISPAG_Tank_Insulation_Auto_Saver {
    private $wpdb;
    private $table_flange_dimension;
    private $table_conception;
    private $table_connections;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_auto_insulation_saver', [self::$instance, 'maybe_add_insulation_article'], 10, 6);
        
    }

    public function maybe_add_insulation_article($html, $deal_id, $article_id, $selected_type, $selected_thickness, $selected_cover) {
        
        
        $tank = apply_filters('ispag_get_tank_datas', null, $article_id);
// \1('maybe_add_insulation_article article ' . $article_id .' : ' . print_r($tank, true));

        ob_start(); // <-- pour capturer les logs
        // echo "[DEBUG] article_id: $article_id\n";
        // echo "[DEBUG] deal_id: $deal_id\n";
        // echo "[DEBUG] selected_type: $selected_type | selected_thickness: $selected_thickness\n";
        // echo "[DEBUG] tank data:\n";
        // var_dump($tank);
        
 
        if (!$tank) {
            // echo "[DEBUG] Pas de données de cuve. Abort.\n";
            return ob_get_clean();
        }

        $matching_article = $this->find_matching_insulation_article(
            floatval($tank['dimensions']->Volume),
            floatval($tank['dimensions']->Height),
            intval($selected_type),
            intval($selected_thickness),
            intval($selected_cover)
        );

        if ($matching_article) {
            // echo "[DEBUG] Article trouvé : ID {$matching_article->Id} | Titre : {$matching_article->TitreArticle}\n";
            $result_save = $this->insert_insulation_article($deal_id, $article_id, $matching_article);
            // echo "[DEBUG] result_save : \n";
            var_dump($result_save);
        } else {
            // echo "[DEBUG] Aucun article d'isolation correspondant trouvé.\n";
        }

        return ob_get_clean(); // On renvoie les logs capturés
    }

    private function find_matching_insulation_article($volume, $height, $type, $thickness, $cover) {
        $height_case = $height > 2500 ? 'over' : 'under';
        $articles = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 2"
        );

        $best = null;
        $min_surplus_vol = PHP_INT_MAX;

        foreach ($articles as $article) {
            $data = json_decode($article->conception);
            if (!isset($data->insulation)) continue;

            $i = $data->insulation;
           // On extrait et convertit les valeurs du JSON pour la comparaison
            $i_type      = isset($i->insulationType) ? intval($i->insulationType) : null;
            $i_thickness = isset($i->insulationThickness) ? intval($i->insulationThickness) : null;
            $i_cover     = isset($i->insulationCover) ? intval($i->insulationCover) : null;
            $i_height    = isset($i->tankHeight) ? $i->tankHeight : '';
            $i_volume    = isset($i->tankVolum) ? floatval($i->tankVolum) : 0;

            // 3. Vérification de tous les critères
            if (
                $i_type === intval($type) &&
                $i_thickness === intval($thickness) &&
                $i_cover === intval($cover) &&
                $i_height === $height_case &&
                $i_volume >= $volume
            ) {
                // Calcul du surplus pour trouver l'isolation la plus proche du volume réel
                $surplus = $i_volume - $volume;
                if ($surplus < $min_surplus_vol) {
                    $best = $article;
                    $min_surplus_vol = $surplus;
                }
            }
        }

        return $best;
    }


    private function insert_insulation_article($deal_id, $tank_id, $article) {
        $title = apply_filters('ispag_get_insulation_title', '', $article->Id);
        $description = apply_filters('ispag_get_insulation_description', '', $article->Id);
        $default_supplier = 17;
        
        ISPAG_Article_Repository::ini(); // assure que le filtre est dispo
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_id);

        if (!$tank) {
            return [
                'success' => false,
                'error' => 'No tank found',
            ];
        }

        // $demande_achat = $article->sales_price != 0 ? true : false;

        // Vérifie si une ligne existe déjà
        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->wpdb->prefix}achats_details_commande
            WHERE hubspot_deal_id = %d AND Groupe = %s AND Type = 2 LIMIT 1",
            $deal_id,
            $tank->Groupe
        ));


        $data = [
            'linked_tank'       => $tank_id,
            'IdArticleStandard' => $article->Id,
            'Article'           => $title,
            'Description'       => $description,
            // 'sales_price'       => $article->sales_price,
            'Qty'               => $tank->Qty,
            
            'IdFournisseur'     => $default_supplier,
        ];

        if ($existing_id) {
            $updated = $this->wpdb->update(
                "{$this->wpdb->prefix}achats_details_commande",
                $data,
                ['Id' => $existing_id]
            );
            return [
                'success' => (bool)$updated,
                'action' => 'updated',
                'row_id' => $existing_id,
            ];
        } else {
            $data['hubspot_deal_id'] = $deal_id;
            $data['Groupe'] = $tank->Groupe;
            $data['Type'] = 2;

            $inserted = $this->wpdb->insert("{$this->wpdb->prefix}achats_details_commande", $data);

            return [
                'success' => (bool)$inserted,
                'action' => 'inserted',
                'insert_id' => $this->wpdb->insert_id,
            ];
        }
    }





}