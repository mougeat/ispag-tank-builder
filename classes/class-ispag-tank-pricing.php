<?php
class ISPAG_Tank_Pricing {
    protected static $instance = null;
    private $tank_pricing_data;
    private $fittings_pricing_data;
    private $supplier;
    private $errors = [];
    private const LOG_NAME = 'tank_pricing';

    /**
     * Initialise les hooks WordPress
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        // Enregistrer les actions AJAX
        add_action('wp_ajax_calculate_tank_price', [self::$instance, 'handle_ajax_request']);
        add_action('wp_ajax_nopriv_calculate_tank_price', [self::$instance, 'handle_ajax_request']);
        add_action('wp_ajax_generate_tank_report', [self::$instance, 'generate_report_ajax']);
        add_action('wp_ajax_nopriv_generate_tank_report', [self::$instance, 'generate_report_ajax']);

        // Charger les scripts uniquement dans l'admin WordPress
        add_action('wp_enqueue_scripts', [self::$instance, 'enqueue_scripts']);
    }

    /**
     * Enregistre et localise les scripts JavaScript
     */
    public function enqueue_scripts() {
        wp_enqueue_script('ispag-tank-pricing', plugin_dir_url(__FILE__) . '../assets/js/tank-pricing.js', ['jquery'], false, true);

        // Localisation pour tank-pricing.js
        wp_localize_script('ispag-tank-pricing', 'ispag_tank_pricing_vars', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'plugin_url'    => ISPAG_PLUGIN_URL . 'price/',
            'nonce'         => wp_create_nonce('ispag_tank_pricing_nonce'),
        ]);

        // ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'enqueue_scripts', [], get_current_user_id());
    }

    /**
     * Charge les données de tarification depuis les fichiers JSON en fonction du fournisseur
     */
    public function load_pricing_data($supplier = 'Diem-Werke GmbH') {
        $this->supplier = $supplier;
        $base_path = WP_PLUGIN_DIR . '/ispag-tank-builder/price/';
        $user_id = get_current_user_id();

        // Remplacer les espaces par des underscores pour correspondre aux noms de fichiers JSON
        $formatted_supplier = str_replace(' ', '_', $supplier);

        // Charger les données de la cuve
        $tank_json_path = $base_path . $formatted_supplier . '.json';
        if (file_exists($tank_json_path)) {
            $this->tank_pricing_data = json_decode(file_get_contents($tank_json_path), true);
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Chargement réussi du fichier JSON cuve pour : $supplier (fichier: $formatted_supplier.json)", $user_id);
        } else {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Fichier JSON cuve introuvable pour le fournisseur : $supplier", ['path' => $tank_json_path], $user_id);
        }

        // Charger les données des accessoires
        $fittings_json_path = $base_path . $formatted_supplier . '_accessories.json';
        if (file_exists($fittings_json_path)) {
            $this->fittings_pricing_data = json_decode(file_get_contents($fittings_json_path), true);
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Chargement réussi du fichier JSON accessoires pour : $supplier (fichier: {$formatted_supplier}_accessories.json)", $user_id);
        } else {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Fichier JSON accessoires introuvable pour le fournisseur : $supplier", ['path' => $fittings_json_path], $user_id);
        }
    }

    /**
     * Ajoute une erreur à la liste
     */
    private function add_error($error_message) {
        $this->errors[] = $error_message;
    }

    /**
     * Récupère les erreurs accumulées
     */
    private function get_errors() {
        return $this->errors;
    }

    /**
     * Calcule le prix de la cuve en fonction des paramètres
     */
    public function calculate_tank_price($diameter, $height, $pressure, $supplier = 'Diem-Werke GmbH') {
        $user_id = get_current_user_id();

        // Recharger les données si le fournisseur a changé
        if ($this->supplier !== $supplier) {
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Changement de fournisseur détecté ($this->supplier -> $supplier), rechargement des données.", $user_id);
            $this->load_pricing_data($supplier);
        }

        if (!isset($this->tank_pricing_data['grille_tarifaire'])) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Grille tarifaire cuve manquante ou non chargée pour le fournisseur : $supplier", [], $user_id);
            return [
                'total_price' => 0,
                'details' => []
            ];
        }

        $grille = $this->tank_pricing_data['grille_tarifaire'];

        // Trouver le diamètre le plus proche (supérieur ou égal)
        $available_diameters = array_keys($grille);
        $target_diameter = null;
        foreach ($available_diameters as $d) {
            if ($d >= $diameter) {
                $target_diameter = $d;
                break;
            }
        }

        if (!$target_diameter) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Diamètre cible introuvable pour la valeur : $diameter mm", ['available' => $available_diameters], $user_id);
            return [
                'total_price' => 0,
                'details' => []
            ];
        }

        // Trouver la hauteur la plus proche (supérieure ou égale)
        $available_heights = array_keys($grille[$target_diameter]);
        $target_height = null;
        foreach ($available_heights as $h) {
            if ($h >= $height) {
                $target_height = $h;
                break;
            }
        }

        if (!$target_height) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Hauteur cible introuvable pour la valeur : $height mm (Diamètre: $target_diameter)", ['available' => $available_heights], $user_id);
            return [
                'total_price' => 0,
                'details' => []
            ];
        }

        // Déterminer la clé de pression (3bar ou 6bar)
        $pressure_key = ($pressure <= 3) ? '3bar' : '6bar';

        // Récupérer le prix de base
        if (isset($grille[$target_diameter][$target_height][$pressure_key])) {
            $price = $grille[$target_diameter][$target_height][$pressure_key];
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Prix cuve calculé avec succès : $price € (Diamètre: $target_diameter, Hauteur: $target_height, Pression: $pressure_key)", $user_id);
            
            return [
                'total_price' => $price,
                'details' => [
                    'fournisseur' => $supplier,
                    'diametre_demande' => $diameter,
                    'diametre_retenu' => $target_diameter,
                    'hauteur_demandee' => $height,
                    'hauteur retenue' => $target_height,
                    'pression' => $pressure_key,
                    'prix' => $price
                ]
            ];
        }

        ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Aucun tarif trouvé dans la grille pour D:$target_diameter, H:$target_height, P:$pressure_key", [], $user_id);
        return [
            'total_price' => 0,
            'details' => []
        ];
    }

    /**
     * Calcule le prix des piquages et accessoires
     */
    public function calculate_fittings_price($fittings, $tank_params = []) {
        $user_id = get_current_user_id();
        $this->errors = []; // Réinitialiser les erreurs
        

        // Loguer les données brutes reçues pour analyse
        ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Données reçues pour le calcul des piquages (fittings) : " . json_encode($fittings), $user_id);

        if (!isset($this->fittings_pricing_data)) {
            $this->add_error("Données de tarification des accessoires non chargées.");
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Données de tarification des accessoires non chargées.", [], $user_id);
            return [
                'total_price' => 0,
                'details' => [] 
            ];
        }

        if (empty($fittings) || !is_array($fittings)) {
            $this->add_error("Le tableau des piquages (fittings) est vide ou invalide.");
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Le tableau des piquages (fittings) est vide ou invalide.", $user_id);
            return [
                'total_price' => 0,
                'details' => []
            ];
        }

        // Récupérer les raccords inclus et le nombre maximal selon le type de réservoir
        $included_fittings = $this->fittings_pricing_data['logic']['included_fittings'] ?? [];
        $max_included_fittings = 0;

        // Déterminer le nombre maximal de raccords inclus en fonction du type
        if (isset($tank_params['type'])) {
            if ($tank_params['type'] == 6 || $tank_params['type'] == 7) {
                $max_included_fittings = $this->fittings_pricing_data['logic']['included_fittings_combi'] ?? 0;
            } else {
                $max_included_fittings = $this->fittings_pricing_data['logic']['included_fittings_energy'] ?? 0;
            }
        } else {
            // Par défaut, utiliser included_fittings_energy
            $max_included_fittings = $this->fittings_pricing_data['logic']['included_fittings_energy'] ?? 0;
        }

        ISPAG_Logger::get_instance()->log(
            self::LOG_NAME,
            "Nombre maximal de raccords inclus : $max_included_fittings | Types inclus : " . implode(', ', $included_fittings),
            $user_id
        );

        $total_price = 0;
        $included_fittings_count = 0; // Compteur pour les raccords inclus
        $calculation_details = [];

        foreach ($fittings as $index => $fitting) {
            $fitting_pouce = $fitting['Pouces'] ?? '';
            $fitting_type = $fitting['Type'] ?? '';
            $pressure = $fitting['MaxPressure'] ?? 6;

            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Traitement du piquage index [$index] -> ID Pouce : '$fitting_pouce' Type : '$fitting_type', Pression : '$pressure'", $user_id);

            
            if($fitting_type == 0 && $fitting_pouce != 0){
                // Vérifier si le raccord est inclus dans le prix de base
                $is_included = false;
                if ($max_included_fittings > 0 && in_array($fitting_pouce, $included_fittings)) {
                    if ($included_fittings_count < $max_included_fittings) {
                        $is_included = true;
                        $included_fittings_count++;
                        ISPAG_Logger::get_instance()->log(
                            self::LOG_NAME,
                            "Piquage [$fitting_pouce] inclus dans le prix de base (compteur : $included_fittings_count/$max_included_fittings).",
                            $user_id
                        );
                    }
                }

                $standard_price = 0;
                // Prix du raccord standard (uniquement si non inclus)
                if (!$is_included) {
                    if (isset($this->fittings_pricing_data['tarifs_raccords_standards'][$fitting_pouce])) {
                        $price_key = ($pressure <= 6) ? 'prix_pn6' : 'prix_pn16';
                        $standard_price = $this->fittings_pricing_data['tarifs_raccords_standards'][$fitting_pouce][$price_key] ?? 0;
                        $total_price += $standard_price;
                        ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Piquage standard [$fitting_pouce] trouvé (clé: $price_key). Ajout : +$standard_price €", $user_id);
                    }
                    else {
                        $this->add_error("Piquage standard introuvable dans le JSON pour l'ID : '$fitting_pouce'");
                        ISPAG_Logger::get_instance()->log_error(
                            self::LOG_NAME,
                            "Piquage standard introuvable dans le JSON pour l'ID : '$fitting_pouce'",
                            ['keys_disponibles' => array_keys($this->fittings_pricing_data['tarifs_raccords_standards'] ?? [])],
                            $user_id
                        );
                    }
                } else {
                    ISPAG_Logger::get_instance()->log(
                        self::LOG_NAME,
                        "Piquage [$fitting_pouce] NON facturé (inclus dans le prix de base).",
                        $user_id
                    );
                }

                $accessory_price = 0;
                $accessory_id = null;
                // Prix des accessoires complexes (toujours facturés, même si le raccord est inclus)
                if (isset($fitting['Accessories']) && !empty($fitting['Accessories'])) {
                    $accessory_id = $fitting['Accessories'];
                    if (isset($this->fittings_pricing_data['tarifs_accessoires_complexes'][$accessory_id])) {
                        $accessory_price = $this->fittings_pricing_data['tarifs_accessoires_complexes'][$accessory_id][$fitting_pouce] ?? 0;
                        $total_price += $accessory_price;
                        ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Accessoire complexe [$accessory_id] pour [$fitting_pouce] ajouté : +$accessory_price €", $user_id);
                    } else {
                        $this->add_error("Accessoire complexe introuvable dans le JSON pour le nom : '$accessory_id'");
                        ISPAG_Logger::get_instance()->log_error(
                            self::LOG_NAME,
                            "Accessoire complexe introuvable dans le JSON pour le nom : '$accessory_id'",
                            [],
                            $user_id
                        );
                    }
                }
            }
            // Prix des accessoires complexes (toujours facturés, même si le raccord est inclus)
            if (isset($fitting_type) && $fitting_type != 0) {
                $accessory_id = $fitting_type;

                // Cas spécial pour lochblech_fix (prix dépend du diamètre de la cuve)
                if ($accessory_id == '22' && isset($this->fittings_pricing_data['tarifs_accessoires_complexes']['22'])) {
                    $diameter = $tank_params['diameter'] ?? 0;
                    $lochblech_prices = $this->fittings_pricing_data['tarifs_accessoires_complexes']['22'];

                    // Trouver le prix correspondant au diamètre de la cuve
                    $accessory_price = 0;
                    if (isset($lochblech_prices[$diameter])) {
                        $accessory_price = $lochblech_prices[$diameter];
                    } else {
                        // Si le diamètre exact n'est pas trouvé, chercher le diamètre le plus proche (supérieur ou égal)
                        $available_diameters = array_keys($lochblech_prices);
                        foreach ($available_diameters as $available_diameter) {
                            if ($available_diameter >= $diameter) {
                                $accessory_price = $lochblech_prices[$available_diameter];
                                break;
                            }
                        }
                    }

                    if ($accessory_price > 0) {
                        $total_price += $accessory_price;
                        ISPAG_Logger::get_instance()->log(
                            self::LOG_NAME,
                            "Accessoire lochblech_fix pour diamètre $diameter : +$accessory_price €",
                            $user_id
                        );
                    } else {
                        $this->add_error("Prix introuvable pour lochblech_fix avec diamètre : $diameter");
                        ISPAG_Logger::get_instance()->log_error(
                            self::LOG_NAME,
                            "Prix introuvable pour lochblech_fix avec diamètre : $diameter",
                            [],
                            $user_id
                        );
                    }
                }
                // Cas général pour les autres accessoires complexes
                elseif (isset($this->fittings_pricing_data['tarifs_accessoires_complexes'][$accessory_id])) {
                    $accessory_price = $this->fittings_pricing_data['tarifs_accessoires_complexes'][$accessory_id][$fitting_pouce] ?? 0;
                    $total_price += $accessory_price;
                    ISPAG_Logger::get_instance()->log(
                        self::LOG_NAME,
                        "Accessoire complexe [$accessory_id] pour [$fitting_pouce] ajouté : +$accessory_price €",
                        $user_id
                    );
                } else {
                    $this->add_error("Accessoire complexe introuvable dans le JSON pour le nom : '$accessory_id'");
                    ISPAG_Logger::get_instance()->log_error(
                        self::LOG_NAME,
                        "Accessoire complexe introuvable dans le JSON pour le nom : '$accessory_id'",
                        [],
                        $user_id
                    );
                }
            }

            // Construction du détail pour ce raccord
            $detail_key = "raccords" . ($index + 1);
            $calculation_details[$detail_key] = [
                'type' => $fitting_pouce,
                'prix' => $is_included ? ' ' . __('included', 'creation-reservoir') : $standard_price,
                'accessoires' => $accessory_id ? $accessory_id : __('none', 'creation-reservoir'),
                'accessoires_prix' => $accessory_price
            ];
        }

        ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Prix total des piquages calculé : $total_price €", $user_id);

        return [
            'total_price' => $total_price,
            'details' => $calculation_details
        ];
    }

    /**
     * Calcule les majorations (soudure sur place, traitement de surface, etc.)
     */
    private function calculate_surcharges($tank_params, $tank_details) {
        $user_id = get_current_user_id();
        $surcharges = [];
        $total_surcharge = 0;

        // Soudure sur place (20% du prix de base)
        if (
            isset($this->tank_pricing_data['logic']['surcharge_soudure_sur_place']) &&
            isset($tank_params['welding']) &&
            $tank_params['welding'] > 0 &&
            $tank_params['welding'] != 'NaN'
        ) {
            $welding_surcharge_percent = $this->tank_pricing_data['logic']['surcharge_soudure_sur_place'];
            $tank_price_details = $this->calculate_tank_price(
                $tank_params['diameter'],
                $tank_params['height'],
                $tank_params['pressure'],
                $tank_params['supplier'] ?? 'Diem-Werke GmbH'
            );
            $base_price = $tank_price_details['total_price'];

            $surcharge_amount = ($base_price * $welding_surcharge_percent) / 100;
            $surcharges[] = "Majorations soudure sur place ({$welding_surcharge_percent}% de {$base_price}€) : +{$surcharge_amount}€";
            $total_surcharge += $surcharge_amount;
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Surcharge soudure calculée : +{$surcharge_amount}€", $user_id);
        }

        // Garde au sol
        if (isset($tank_params['ground_clearance']) && $tank_params['ground_clearance'] > ($this->tank_pricing_data['accessoires']['ground_clearance']['base_mm'] ?? 0)) {
            $ground_clearance = $tank_params['ground_clearance'];
            $diameter = $tank_params['diameter'];

            // Récupérer le prix de la garde au sol depuis les données
            $ground_clearance_price = 0;
            if (isset($this->tank_pricing_data['accessoires']['ground_clearance']['plus_values'])) {
                foreach ($this->tank_pricing_data['accessoires']['ground_clearance']['plus_values'] as $rule) {
                    if ($diameter <= $rule['diametre_max_mm'] && $ground_clearance <= $rule['hauteur_max_mm']) {
                        $ground_clearance_price = $rule['prix'];
                        break;
                    }
                }
            }
            if ($ground_clearance_price > 0) {
                $surcharges[] = "Plus-value Garde au sol ({$ground_clearance}mm) : +{$ground_clearance_price}€";
                $total_surcharge += $ground_clearance_price;
                ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Surcharge garde au sol calculée : +{$ground_clearance_price}€", $user_id);
            }
        }

        // Pieds
        if (isset($tank_params['support']) && $tank_params['support'] !== 2) {
            $volume = $tank_params['volume'];
            $pieds_price = 0;

            // Récupérer le prix des pieds
            if (isset($this->tank_pricing_data['accessoires']['pieds'])) {
                $pieds_rules = $this->tank_pricing_data['accessoires']['pieds'];
                if ($volume <= 1500) {
                    $pieds_price = $pieds_rules['rohrfüße'][0]['prix'];
                } elseif ($volume <= 3000) {
                    $pieds_price = $pieds_rules['rohrfüße'][1]['prix'];
                } elseif ($volume <= 14999) {
                    $pieds_price = $pieds_rules['rohrfüße'][2]['prix'];
                } elseif ($volume <= 20000) {
                    $pieds_price = $pieds_rules['unp_füße'][1]['prix'];
                } elseif ($volume <= 40000) {
                    $pieds_price = $pieds_rules['unp_füße'][2]['prix'];
                }
            }

            if ($pieds_price > 0) {
                $surcharges[] = "Pieds (3x Rohr) : +{$pieds_price}€";
                $total_surcharge += $pieds_price;
                ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Surcharge pieds calculée : +{$pieds_price}€", $user_id);
            }
        }

        // Traitement de surface
        if (isset($tank_params['material']) && $tank_params['material'] == 2 && ($tank_params['type'] == 6 || $tank_params['type'] == 7)) {
            $diameter = $tank_params['diameter'];

            // Récupérer la hauteur du fond depuis tank_data.json
            $bottom_height = 0;
            $tank_data_json_path = WP_PLUGIN_DIR . '/ispag-tank-builder/assets/json/tank_data.json';

            if (file_exists($tank_data_json_path)) {
                $tank_data = json_decode(file_get_contents($tank_data_json_path), true);
                if (isset($tank_data['arrayBottomHeight'][$tank_params['material']][$diameter])) {
                    $bottom_height = $tank_data['arrayBottomHeight'][$tank_params['material']][$diameter];
                } else {
                    // Si le diamètre exact n'est pas trouvé, chercher le diamètre le plus proche (supérieur ou égal)
                    $available_diameters = array_keys($tank_data['arrayBottomHeight'][$tank_params['material']] ?? []);
                    foreach ($available_diameters as $available_diameter) {
                        if ($available_diameter >= $diameter) {
                            $bottom_height = $tank_data['arrayBottomHeight'][$tank_params['material']][$available_diameter];
                            break;
                        }
                    }
                }
            }

            // Calculer la hauteur du manteau (hauteur totale - hauteur du fond)
            $height = $tank_params['height'] - $bottom_height;

            // Calculer la surface en m² : ((diamètre² * 2) + (diamètre * 4 * hauteur)) / 1 000 000
            $surface_area = (($diameter * $diameter * 2) + ($diameter * 4 * $height)) / 1000000;

            // Arrondir la surface à l'entier supérieur
            $surface_area_rounded = ceil($surface_area);

            // Prix du traitement de surface (ex: Zinc)
            $surface_price = 0;
            if (isset($this->tank_pricing_data['accessoires']['traitement_surface']['options']['exterieur_zinc_1K'])) {
                $surface_price = $surface_area_rounded * $this->tank_pricing_data['accessoires']['traitement_surface']['options']['exterieur_zinc_1K']['prix_m2'];
            }

            if ($surface_price > 0) {
                $surcharges[] = "Option Zinc : (+" . $surface_area_rounded . " m²) +" . round($surface_price, 2) . "€";
                $total_surcharge += $surface_price;
                ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Surcharge traitement de surface calculée : +{$surface_price}€ (Surface arrondie : {$surface_area_rounded} m²)", $user_id);
            }
        }

        return [
            'surcharges' => $surcharges,
            'total_surcharge' => $total_surcharge,
        ];
    }

    /**
     * Génère un rapport de calcul et l'enregistre dans un fichier
     */
    private function generate_report(
        $article_id,
        $tank_params,
        $tank_details,
        $fittings_price,
        $fittings_details,
        $net_price,
        $surcharges,
        $sales_price = 0,
        $tank_price = 0, // Ajout du prix de la cuve en paramètre
        $total_surcharge = 0 // Ajout du total des majorations en paramètre
    ) {
        $user = wp_get_current_user();
        $user_name = $user->display_name;
        $user_id = $user->ID;
        $date = current_time('d/m/Y H:i:s');

        // Récupérer le rabais
        $discount = $this->tank_pricing_data['discount_defaut'] ?? 0;

        // Prix total cuve + majorations + piquages
        $gross_price = $tank_price + $fittings_price + $total_surcharge;

        // Créer le contenu du rapport (multilingue avec le domaine 'creation-reservoir')
        $report_content = "====================================================\n";
        $report_content .= sprintf(__("CALCULATION REPORT - ARTICLE ID: %s", 'creation-reservoir'), $article_id) . "\n";
        $report_content .= sprintf(__("GENERATED BY : %s (ID: %s)", 'creation-reservoir'), $user_name, $user_id) . "\n";
        $report_content .= sprintf(__("DATE : %s", 'creation-reservoir'), $date) . "\n";
        $mode_text = (isset($tank_params['is_project_or_purchase']) && $tank_params['is_project_or_purchase'] === 'purchase')
            ? __("MODE : Purchase", 'creation-reservoir')
            : __("MODE : Project", 'creation-reservoir');

        $report_content .= $mode_text . "\n\n";
        $report_content .= "====================================================\n\n";

        $report_content .= sprintf(
            __("Tank base price (%dx%d - %dbar) : %.2f €", 'creation-reservoir'),
            $tank_params['diameter'],
            $tank_params['height'],
            $tank_params['pressure'],
            $tank_price
        ) . "\n";

        $report_content .= implode("\n", $surcharges) . "\n";
        $report_content .= sprintf(__("Total surcharges : %.2f €", 'creation-reservoir'), $total_surcharge) . "\n";

        $report_content .= "====================================================\n\n";
        $report_content .= "--- " . __("LOG FITTINGS & ACCESSORIES", 'creation-reservoir') . " ---\n";
        foreach ($fittings_details as $fitting_key => $fitting_data) {
            // Récupérer le libellé du type de raccord (ex: ID 13 -> DN...)
            $fitting_size_text = "";
            if (!empty($fitting_data['type'])) {
                $type_results = ISPAG_Tank_Fittings::get_fitting_type($fitting_data['type']);
                if (!empty($type_results) && isset($type_results[0]->DN)) {
                    $fitting_size_text = $type_results[0]->DN;
                } else {
                    $fitting_size_text = $fitting_data['type'];
                }
            }

            // Gestion du prix (peut être un nombre ou la chaîne "included")
            $price_display = is_numeric($fitting_data['prix'])
                ? sprintf("%.2f €", $fitting_data['prix'])
                : __("included", 'creation-reservoir');

            $report_content .= sprintf(__("Fittings %s : %s", 'creation-reservoir'), $fitting_size_text, $price_display) . "\n";

            // Gestion de l'accessoire si présent et différent de "aucune"
            if (!empty($fitting_data['accessoires']) && $fitting_data['accessoires'] !== 'aucune') {
                $accessories_text = "";
                $acc_results = ISPAG_Tank_Fittings::get_accessories_type($fitting_data['accessoires']);
                if (!empty($acc_results) && isset($acc_results[0]->Value)) {
                    $accessories_text = $acc_results[0]->Value;
                } else {
                    $accessories_text = $fitting_data['accessoires'];
                }

                $report_content .= sprintf(
                    __("--> Accessories %s : %.2f €", 'creation-reservoir'),
                    __($accessories_text, 'creation-reservoir'),
                    $fitting_data['accessoires_prix']
                ) . "\n";
            }
        }

        $report_content .= "\n\n";
        $report_content .= sprintf(__("Fittings price : %.2f €", 'creation-reservoir'), $fittings_price) . "\n";

        $report_content .= "====================================================\n\n";
        $report_content .= sprintf(__("Total gross price (Tank + Fittings + Surcharges) : %.2f €", 'creation-reservoir'), $gross_price) . "\n";
        $report_content .= sprintf(__("Supplier discount : %s%%", 'creation-reservoir'), $discount) . "\n";
        $report_content .= sprintf(__("Net price : %.2f €", 'creation-reservoir'), $net_price) . "\n";

        // Ajouter le prix de vente si on est en mode "project"
        if ($tank_params['is_project_or_purchase'] !== 'purchase') {
            $report_content .= sprintf(__("Sales price : %.2f €", 'creation-reservoir'), $sales_price) . "\n";
        }

        // Créer le dossier de destination si nécessaire
        $upload_dir = wp_upload_dir();
        $report_dir = $upload_dir['basedir'] . '/ispag_pricing/';
        if (!file_exists($report_dir)) {
            wp_mkdir_p($report_dir);
        }

        // Enregistrer le fichier
        $filename = $report_dir . 'article_' . $article_id . '_' . $tank_params['is_project_or_purchase'] . '.txt';
        file_put_contents($filename, $report_content);

        ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Rapport de calcul généré avec succès : {$filename}", get_current_user_id());

        return $filename;
    }

    /**
     * Calcule le prix total (cuve + piquages + majorations)
     */
    public function calculate_total_price($tank_params, $fittings) {
        $user_id = get_current_user_id();
        ISPAG_Logger::get_instance()->log(self::LOG_NAME, "Début du calcul global du prix.", $user_id);
        $this->errors = []; // Réinitialiser les erreurs

        // Récupérer le rabais du fournisseur
        $discount = $this->tank_pricing_data['discount_defaut'] ?? 0;

        $tank_price_details = $this->calculate_tank_price(
            $tank_params['diameter'],
            $tank_params['height'],
            $tank_params['pressure'],
            $tank_params['supplier'] ?? 'Diem-Werke GmbH'
        );
        $tank_price = $tank_price_details['total_price'];

        $fittings_details = $this->calculate_fittings_price($fittings, $tank_params);
        $fittings_price = $fittings_details['total_price'];

        // Calculer les majorations
        $tank_details = ISPAG_Tank_Repository::get_tank_details($tank_params['article_id']);
        $surcharges = $this->calculate_surcharges($tank_params, $tank_details);
        $total_surcharge = $surcharges['total_surcharge'];

        // Prix brut total (cuve + piquages + majorations)
        $gross_price = $tank_price + $fittings_price + $total_surcharge;

        // Prix net après application du rabais
        $net_price = $gross_price * (1 - ($discount / 100));

        // Calculer le prix de vente si on est en mode "project"
        $sales_price = 0;
        if (isset($tank_params['is_project_or_purchase']) && $tank_params['is_project_or_purchase'] !== 'purchase' && class_exists('ISPAG_Article_Pricing')) {
            $pricing = new ISPAG_Article_Pricing();
            $sales_price = $pricing->calculate_sales_price_from_data(
                $net_price, // Prix d'achat net
                $tank_params['volume'] ?? 0 // Volume en litres
            );
        }

        ISPAG_Logger::get_instance()->log(
            self::LOG_NAME,
            "Fin du calcul global -> Cuve: $tank_price € | Piquages: $fittings_price € | Majorations: $total_surcharge € | Rabais: $discount% | Prix brut: $gross_price € | Prix net: $net_price € | Prix de vente: $sales_price €",
            $user_id
        );

        return [
            'tank_price' => $tank_price,
            'fittings_price' => $fittings_price,
            'fittings_details' => $fittings_details['details'],
            'surcharges' => $surcharges['surcharges'],
            'total_surcharge' => $total_surcharge,
            'gross_price' => $gross_price,
            'discount' => $discount,
            'net_price' => $net_price,
            'sales_price' => $sales_price, // Ajouter le prix de vente
            'errors' => $this->get_errors(),
        ];
    }
    
    /**
     * Méthode pour gérer les requêtes AJAX de calcul de prix
     */
    public function handle_ajax_request() {
        $user_id = get_current_user_id();
        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'handle_ajax_request_start', $_POST, $user_id);

        check_ajax_referer('ispag_tank_pricing_nonce', 'nonce');

        if (!current_user_can('manage_order')) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Tentative d'accès non autorisée (Permissions insuffisantes)", ['user_id' => $user_id], $user_id);
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $tank_params = $_POST['tank_params'] ?? [];
        $article_id = $tank_params['article_id'] ?? 0;
        $supplier = $tank_params['supplier'] ?? 'Diem-Werke GmbH';

        if (empty($article_id)) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Article ID manquant dans la requête AJAX.", ['tank_params' => $tank_params], $user_id);
            wp_send_json_error(['message' => __('Article ID missing', 'creation-reservoir')]);
        }

        // Vérifier si les fichiers JSON du fournisseur existent
        $formatted_supplier = str_replace(' ', '_', $supplier);
        $base_path = WP_PLUGIN_DIR . '/ispag-tank-builder/price/';
        $tank_json_path = $base_path . $formatted_supplier . '.json';
        $fittings_json_path = $base_path . $formatted_supplier . '_accessories.json';

        $json_files_exist = file_exists($tank_json_path) && file_exists($fittings_json_path);

        // Si achat, on cherche l'article du projet
        if ($tank_params['is_project_or_purchase'] == 'purchase' && class_exists('ISPAG_Achat_Article_Repository')) {
            $achat_article_repo = new ISPAG_Achat_Article_Repository();
            $article = $achat_article_repo->get_article_by_id(null, $article_id);
            $article_id = $article->IdCommandeClient;
        }

        $tank_datas = ISPAG_Tank_Repository::get_tank_details($article_id);
        if (!$tank_datas) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Impossible de récupérer les détails de la cuve depuis le repository pour l'article ID : $article_id", [], $user_id);
            wp_send_json_error(['message' => __('Tank details not found', 'creation-reservoir')]);
        }

        // Recharger les données si le fournisseur a changé
        if (isset($tank_params['supplier'])) {
            $this->load_pricing_data($tank_params['supplier']);
        }

        // Calculer le prix total et les majorations
        $result = $this->calculate_total_price($tank_params, $tank_datas['piquages_techniques'] ?? []);

        // Ajouter une information sur l'existence des fichiers JSON
        $result['json_files_exist'] = $json_files_exist;

        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'handle_ajax_request_success', $result, $user_id);
        wp_send_json_success($result);
    }

    /**
     * Génère le rapport de calcul sur demande
     */
    public function generate_report_ajax() {
        $user_id = get_current_user_id();
        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'generate_report_ajax', $_POST, $user_id);

        check_ajax_referer('ispag_tank_pricing_nonce', 'nonce');

        if (!current_user_can('manage_order')) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Tentative d'accès non autorisée (Permissions insuffisantes)", ['user_id' => $user_id], $user_id);
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $tank_params = $_POST['tank_params'] ?? [];
        $article_id = $tank_params['article_id'] ?? 0;

        if (empty($article_id)) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Article ID manquant dans la requête AJAX.", ['tank_params' => $tank_params], $user_id);
            wp_send_json_error(['message' => __('Article ID missing', 'creation-reservoir')]);
        }

        // Si achat, on cherche l'article du projet
        if ($tank_params['is_project_or_purchase'] == 'purchase' && class_exists('ISPAG_Achat_Article_Repository')) {
            $achat_article_repo = new ISPAG_Achat_Article_Repository();
            $article = $achat_article_repo->get_article_by_id(null, $article_id);
            $article_id = $article->IdCommandeClient;
        }

        $tank_datas = ISPAG_Tank_Repository::get_tank_details($article_id);
        if (!$tank_datas) {
            ISPAG_Logger::get_instance()->log_error(self::LOG_NAME, "Impossible de récupérer les détails de la cuve depuis le repository pour l'article ID : $article_id", [], $user_id);
            wp_send_json_error(['message' => __('Tank details not found', 'creation-reservoir')]);
        }

        // Recharger les données si le fournisseur a changé
        if (isset($tank_params['supplier'])) {
            $this->load_pricing_data($tank_params['supplier']);
        }

        // Calculer le prix total et les majorations
        $result = $this->calculate_total_price($tank_params, $tank_datas['piquages_techniques'] ?? []);

        // Le prix de vente est déjà calculé dans $result si on est en mode "project"
        $sales_price = $result['sales_price'];

        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'generate_report_ajax CALCULATION PRICE', $result, $user_id);

        // Générer le rapport
        $report_path = $this->generate_report(
            $article_id,
            $tank_params,
            $tank_datas,
            $result['fittings_price'],
            $result['fittings_details'],
            $result['net_price'],
            $result['surcharges'],
            $sales_price,
            $result['tank_price'], // Prix de la cuve
            $result['total_surcharge'] // Total des majorations
        );

        wp_send_json_success([
            'report_path'   => $report_path,
            'gross_price'   => $result['gross_price'],
            'discount'      => $result['discount'],
            'net_price'     => $result['net_price'],
            'sales_price'   => $sales_price
        ]);
    }
}

ISPAG_Tank_Pricing::init();