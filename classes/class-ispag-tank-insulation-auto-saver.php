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
        
 
        if (!$tank || empty($tank['dimensions'])) {
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
        } else {
            // echo "[DEBUG] Aucun article d'isolation correspondant trouvé.\n";
        }

        return ob_get_clean(); // On renvoie les logs capturés
    }

    /**
     * Le libellé de hauteur de l'article ("over"/"under", éventuellement traduit) indique
     * s'il vaut pour les cuves au-dessus ou en dessous de la limite tankHeightLimit.
     */
    private function is_over_height_label($label) {
        $label = strtolower(trim((string) $label));
        return (bool) preg_match('/^(over|above|greater|higher|more|plus|sup|au[- ]?dessus|über|ueber|hoch|>)/u', $label);
    }

    private function find_matching_insulation_article($volume, $height, $type, $thickness, $cover) {
        if ($type <= 0 || $thickness <= 0) return null;

        $articles = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 2"
        );

        $best = null;
        $best_vol = PHP_INT_MAX;

        foreach ($articles as $article) {
            $data = json_decode($article->conception);
            if (empty($data->insulation)) continue;
            $i = $data->insulation;

            if (intval($i->insulationType ?? 0) !== $type) continue;
            if (intval($i->insulationThickness ?? 0) !== $thickness) continue;
            // Le revêtement n'est un critère que s'il est choisi (sinon on ne l'impose pas)
            if ($cover > 0 && intval($i->insulationCover ?? 0) !== $cover) continue;

            // Hauteur : limite propre à l'article (2500 mm par défaut)
            $limit = isset($i->tankHeightLimit) && floatval($i->tankHeightLimit) > 0 ? floatval($i->tankHeightLimit) : 2500;
            $is_over = $this->is_over_height_label($i->tankHeight ?? '');
            if ($height > 0) {
                if ($is_over && !($height > $limit)) continue;
                if (!$is_over && !($height <= $limit)) continue;
            }

            // Volume : plus petit volume d'article couvrant le volume de la cuve
            $i_volume = floatval($i->tankVolum ?? 0);
            if ($i_volume < $volume) continue;
            if ($i_volume < $best_vol) {
                $best = $article;
                $best_vol = $i_volume;
            }
        }

        return $best;
    }


    private function insert_insulation_article($deal_id, $tank_id, $article) {
        $title = apply_filters('ispag_get_insulation_title', '', $article->Id);
        $description = apply_filters('ispag_get_insulation_description', '', $article->Id);
        $default_supplier = 17; // ID fournisseur = wor9711_ispag_companies.id : à remapper (voir ispag-crm/migrations)
        
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