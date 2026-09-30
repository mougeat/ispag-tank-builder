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
        add_action('wp_ajax_ispag_get_insulation_catalog', [self::$instance, 'ajax_insulation_catalog']);
        add_filter('ispag_insulation_last_status', [self::$instance, 'last_status']);
        add_action('ispag_fittings_changed', [self::$instance, 'resync_manhole_covers']);
        
    }

    public function maybe_add_insulation_article($html, $deal_id, $article_id, $selected_type, $selected_thickness, $selected_cover) {
        
        
        $this->log = [];
        $this->status = ['status' => 'skipped', 'message' => ''];
        $this->debug("start deal=$deal_id article=$article_id type=$selected_type thickness=$selected_thickness cover=$selected_cover");
        $tank = apply_filters('ispag_get_tank_datas', null, $article_id);
// \1('maybe_add_insulation_article article ' . $article_id .' : ' . print_r($tank, true));

        ob_start(); // <-- pour capturer les logs
        // echo "[DEBUG] article_id: $article_id\n";
        // echo "[DEBUG] deal_id: $deal_id\n";
        // echo "[DEBUG] selected_type: $selected_type | selected_thickness: $selected_thickness\n";
        // echo "[DEBUG] tank data:\n";
        // var_dump($tank);
        
 
        if (!$tank || empty($tank['dimensions'])) {
            $this->debug('aucune donnée de réservoir (ispag_get_tank_datas)');
            if (intval($selected_type) > 0 && intval($selected_thickness) > 0) {
                $this->status = ['status' => 'error', 'message' => 'The insulation could not be checked: tank data not found.'];
            }
            ob_end_clean();
            return implode("\n", $this->log);
        }

        $this->debug('tank volume=' . ($tank['dimensions']->Volume ?? 'null') . ' height=' . ($tank['dimensions']->Height ?? 'null'));
        $matching_article = $this->find_matching_insulation_article(
            floatval($tank['dimensions']->Volume),
            floatval($tank['dimensions']->Height),
            intval($selected_type),
            intval($selected_thickness),
            intval($selected_cover)
        );

        $requested = intval($selected_type) > 0 && intval($selected_thickness) > 0;

        if ($matching_article) {
            // echo "[DEBUG] Article trouvé : ID {$matching_article->Id} | Titre : {$matching_article->TitreArticle}\n";
            $result_save = $this->insert_insulation_article($deal_id, $article_id, $matching_article);
            $this->debug('article ' . $matching_article->Id . ' -> ' . wp_json_encode($result_save) . ' | db error: ' . $this->wpdb->last_error);
            // update sans changement renvoie false sans être une erreur : on ne signale que les erreurs SQL
            $this->status = (empty($result_save['success']) && ($this->wpdb->last_error || !empty($result_save['error'])))
                ? ['status' => 'error', 'message' => 'The insulation could not be saved' . ($this->wpdb->last_error ? ' : ' . $this->wpdb->last_error : (!empty($result_save['error']) ? ' : ' . $result_save['error'] : '.'))]
                : ['status' => 'ok', 'message' => ''];
        } else {
            $this->debug('aucun article correspondant');
            if ($requested) {
                $this->status = ['status' => 'missing', 'message' => sprintf(
                    'No insulation article matches this tank (volume %s L, height %s mm). Change the insulation options, or choose none.',
                    floatval($tank['dimensions']->Volume), floatval($tank['dimensions']->Height)
                )];
            }
            // Isolation retirée ou sans article correspondant : on enlève la ligne existante
            $this->delete_insulation_article($deal_id, $article_id);
        }

        // Les capots ne doivent jamais empêcher l'enregistrement de l'isolation
        try {
            $this->sync_manhole_covers($deal_id, $article_id, (bool) $matching_article);
        } catch (\Throwable $e) {
            $this->debug('capots de trou d\'homme : ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }

        ob_end_clean();
        return implode("\n", $this->log); // Journal de cet enregistrement (visible dans la réponse AJAX)
    }

    /**
     * Le libellé de hauteur de l'article ("over"/"under", éventuellement traduit) indique
     * s'il vaut pour les cuves au-dessus ou en dessous de la limite tankHeightLimit.
     */
    private function is_over_height_label($label) {
        $label = strtolower(trim((string) $label));
        return (bool) preg_match('/^(over|above|greater|higher|more|plus|sup|au[- ]?dessus|über|ueber|hoch|>)/u', $label);
    }

    private $log = [];
    private $status = ['status' => '', 'message' => ''];

    /** Résultat du dernier enregistrement : skipped (aucune isolation demandée), ok, missing (aucun article) ou error. */
    public function last_status($default = '') {
        return $this->status;
    }


    private function debug($msg) {
        $this->log[] = $msg;
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log('[ISPAG insulation] ' . $msg);
    }

    private function accepted_keys($id, $kind) {
        $keys = [intval($id)];
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Value FROM {$this->table_conception} WHERE Id = %d", $id
        ));
        if ($value !== null) {
            if ($kind === 'thickness') {
                $keys[] = intval($value); // "130" => 130 mm
            } elseif ($kind === 'cover') {
                // "with ..." => 1, "without ..." => 0
                $keys[] = preg_match('/^without/i', $value) ? 0 : 1;
            }
        }
        // Correspondance explicite éventuelle Id conception => valeur article
        $keys = apply_filters('ispag_insulation_article_keys', $keys, $id, $kind);
        return array_map('intval', array_unique($keys));
    }

    /**
     * Combinaisons réellement disponibles au catalogue : pour chaque type d'isolation (Id de conception),
     * les épaisseurs et revêtements (Id de conception) pour lesquels un article d'isolation existe.
     */
    public function ajax_insulation_catalog() {
        $options = function ($select_type) {
            return (array) $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT Id FROM {$this->table_conception} WHERE SelectType = %s", $select_type
            ));
        };
        $types = $options('insulationType');
        $thicknesses = $options('insulationThickness');
        $covers = $options('insulationCover');

        // Clés acceptées calculées une seule fois par valeur (évite une requête par article)
        $keys = ['type' => [], 'thickness' => [], 'cover' => []];
        foreach ($types as $id) $keys['type'][$id] = $this->accepted_keys($id, 'type');
        foreach ($thicknesses as $id) $keys['thickness'][$id] = $this->accepted_keys($id, 'thickness');
        foreach ($covers as $id) $keys['cover'][$id] = $this->accepted_keys($id, 'cover');

        $articles = $this->wpdb->get_results("SELECT conception FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 2");
        $out = [];
        foreach ($articles as $article) {
            $data = json_decode($article->conception);
            if (empty($data->insulation)) continue;
            $i = $data->insulation;

            foreach ($types as $type_id) {
                if (!in_array(intval($i->insulationType ?? 0), $keys['type'][$type_id], true)) continue;
                foreach ($thicknesses as $th_id) {
                    if (in_array(intval($i->insulationThickness ?? 0), $keys['thickness'][$th_id], true)) {
                        $out[$type_id]['thickness'][$th_id] = intval($th_id);
                    }
                }
                foreach ($covers as $cv_id) {
                    if (in_array(intval($i->insulationCover ?? 0), $keys['cover'][$cv_id], true)) {
                        $out[$type_id]['cover'][$cv_id] = intval($cv_id);
                    }
                }
            }
        }
        foreach ($out as $type_id => $lists) {
            $out[$type_id] = [
                'thickness' => array_values($lists['thickness'] ?? []),
                'cover'     => array_values($lists['cover'] ?? []),
            ];
        }
        wp_send_json_success(['catalog' => (object) $out]);
    }

    private function find_matching_insulation_article($volume, $height, $type, $thickness, $cover) {
        if ($type <= 0 || $thickness <= 0) return null;

        // Le formulaire envoie des Id de achats_tank_conception, alors que les articles
        // stockent leurs propres valeurs (ex. épaisseur en mm, revêtement 1/0) : on accepte les deux.
        $type_keys      = $this->accepted_keys($type, 'type');
        $thickness_keys = $this->accepted_keys($thickness, 'thickness');
        $cover_keys     = $this->accepted_keys($cover, 'cover');

        $articles = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 2"
        );

        $best = null;
        $best_vol = PHP_INT_MAX;

        foreach ($articles as $article) {
            $data = json_decode($article->conception);
            if (empty($data->insulation)) continue;
            $i = $data->insulation;

            if (!in_array(intval($i->insulationType ?? 0), $type_keys, true)) continue;
            if (!in_array(intval($i->insulationThickness ?? 0), $thickness_keys, true)) continue;
            if (!in_array(intval($i->insulationCover ?? 0), $cover_keys, true)) continue;

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


    /** Piquages modifiés : recalcule les capots si le réservoir a une ligne d'isolation. */
    public function resync_manhole_covers($tank_article_id) {
        $tank_article_id = intval($tank_article_id);
        if ($tank_article_id <= 0) return;

        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_article_id);
        if (!$tank) return;

        $has_insulation = (bool) $this->find_insulation_row($tank->hubspot_deal_id, $tank_article_id, $tank->Groupe);
        $this->sync_manhole_covers($tank->hubspot_deal_id, $tank_article_id, $has_insulation);
    }

    /** Id de l'article standard « capot de trou d'homme » (option, filtre, sinon recherche par titre dans les accessoires d'isolation). */
    private function manhole_cover_article_id() {
        $id = intval(apply_filters('ispag_manhole_cover_article_id', get_option('ispag_manhole_cover_article_id', 0)));
        if ($id > 0) return $id;

        $id = $this->wpdb->get_var(
            "SELECT Id FROM {$this->wpdb->prefix}achats_articles
             WHERE TypeArticle = 200 AND (TitreArticle LIKE '%capot%trou%homme%' OR TitreArticle LIKE '%manhole%cover%' OR TitreArticle LIKE '%capot%manhole%')
             ORDER BY Id ASC LIMIT 1"
        );
        return intval($id);
    }

    /** Nombre de trous d'homme (piquages de type « revision flange ») du réservoir. */
    private function count_manholes($tank_article_id) {
        $type_id = intval($this->wpdb->get_var(
            "SELECT Id FROM {$this->table_conception} WHERE SelectType = 'connection' AND Value = 'revision flange' LIMIT 1"
        )) ?: 24;

        return intval($this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_connections} c
             INNER JOIN {$this->wpdb->prefix}achats_tank_dimensions d ON c.TankId = d.Id
             WHERE d.customerTankId = %d AND c.Type = %d",
            $tank_article_id, $type_id
        )));
    }

    /**
     * Accessoire de l'isolation : un capot par trou d'homme. La ligne est liée au réservoir (linked_tank)
     * et disparaît quand il n'y a plus d'isolation ou plus de trou d'homme.
     */
    private function sync_manhole_covers($deal_id, $tank_id, $has_insulation) {
        $cover_id = $this->manhole_cover_article_id();
        if (!$cover_id) {
            $this->debug('capot de trou d\'homme : aucun article standard trouvé (option ispag_manhole_cover_article_id)');
            return;
        }

        $table = "{$this->wpdb->prefix}achats_details_commande";
        $count = $has_insulation ? $this->count_manholes($tank_id) : 0;
        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$table} WHERE linked_tank = %d AND IdArticleStandard = %d LIMIT 1",
            $tank_id, $cover_id
        ));

        if ($count <= 0) {
            if ($existing_id) $this->wpdb->delete($table, ['Id' => $existing_id]);
            return;
        }

        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_id);
        $standard = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}achats_articles WHERE Id = %d", $cover_id
        ));
        if (!$tank || !$standard) return;

        $data = [
            'linked_tank'       => $tank_id,
            'IdArticleStandard' => $cover_id,
            'Article'           => $standard->TitreArticle,
            'Description'       => $standard->description_ispag,
            'Qty'               => $count,
        ];
        if (!empty($standard->IdFournisseur)) $data['IdFournisseur'] = intval($standard->IdFournisseur);

        if ($existing_id) {
            $data['Groupe'] = $tank->Groupe;
            $this->wpdb->update($table, $data, ['Id' => $existing_id]);
        } else {
            $data['hubspot_deal_id'] = $deal_id;
            $data['Groupe'] = $tank->Groupe;
            $data['Type'] = 2;
            $data['sales_price'] = floatval($standard->sales_price);
            $this->wpdb->insert($table, $data);
        }
        $this->debug("capots de trou d'homme : qty=$count article=$cover_id -> " . ($this->wpdb->last_error ?: 'ok'));
    }

    /**
     * Ligne d'isolation d'un réservoir : d'abord celle qui lui est liée (linked_tank), quel que soit son groupe,
     * sinon celle du même groupe. Sans cela, renseigner le groupe après coup créait un doublon.
     */
    private function find_insulation_row($deal_id, $tank_article_id, $groupe) {
        $table = "{$this->wpdb->prefix}achats_details_commande";
        $is_insulation = "IdArticleStandard IN (SELECT Id FROM {$this->wpdb->prefix}achats_articles WHERE TypeArticle = 2)";
        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$table} WHERE linked_tank = %d AND Type = 2 AND {$is_insulation} LIMIT 1", $tank_article_id
        ));
        if ($id) return $id;
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$table} WHERE hubspot_deal_id = %d AND Groupe = %s AND Type = 2 AND {$is_insulation} LIMIT 1", $deal_id, $groupe
        ));
    }

    private function delete_insulation_article($deal_id, $tank_id) {
        ISPAG_Article_Repository::ini();
        $tank = apply_filters('ispag_get_article_by_id', null, $tank_id);
        if (!$tank) return false;

        $existing_id = $this->find_insulation_row($deal_id, $tank_id, $tank->Groupe);
        if (!$existing_id) return false;

        return $this->wpdb->delete("{$this->wpdb->prefix}achats_details_commande", ['Id' => $existing_id]) !== false;
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
        $existing_id = $this->find_insulation_row($deal_id, $tank_id, $tank->Groupe);


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
            $data['Groupe'] = $tank->Groupe; // le groupe a pu être renseigné après la création de la ligne
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