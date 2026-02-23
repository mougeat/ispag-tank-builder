<?php

class ISPAG_Tank_Designer {
    private $conception_table;
    private $dimension_table;
    private $wpdb;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->conception_table = $wpdb->prefix . 'achats_tank_conception';
        $this->dimension_table = $wpdb->prefix . 'achats_tank_dimensions';

        // add_action('wp_ajax_save_tank_data', [$this, 'save_tank_data']);

        

    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('ispag_render_tank_form', [self::$instance, 'render_tank_form']);
        add_action('ispag_render_tank_dimensions_form', [self::$instance, 'render_dimensions_form']);
        add_action('wp_ajax_ispag_save_tank_data', [self::$instance, 'ajax_save_tank_data']);
        add_action('ispag_duplicate_tank_data', [self::$instance, 'duplicate_tank_data'], 10, 2);
        add_filter('ispag_get_tank_id_by_article_id', [self::$instance, 'get_tank_id_by_article_id'], 10, 1);
        add_filter('ispag_get_tank_datas', [self::$instance, 'get_tank_data'], 10, 2);
        add_filter('ispag_auto_saver_tank_data', [self::$instance, 'save_tank_data'], 10, 3);
        add_action('ispag_get_tank_created_by_id', [self::$instance, 'get_tank_created_by_id'],10, 2);

 
        // get_tank_id_by_article_id
    }
 
    /**
    * Affiche le formulaire de conception pour les articles de type 1
    */
    public function render_tank_form($article_id) {
        $data = $this->get_tank_data(null, $article_id);
        include plugin_dir_path(__FILE__) . 'templates/form-tank-fields.php';
    }



    /**
    * Affiche le formulaire de dimenions pour les articles de type 1
    */
    public function render_dimensions_form($article_id) {
        $data = $this->get_tank_data(null, $article_id);
        $display = $article_id != 0 ? "" : 'style="display:none;"';
        include plugin_dir_path(__FILE__) . 'templates/form-tank-dimensions-field.php';
         
    }

    private function safe_get($obj, $prop) {
        return (is_object($obj) && isset($obj->$prop)) ? $obj->$prop : null;
    }


    public function get_tank_data($html, $article_id) {
        if($article_id == 0 OR empty($article_id)){
            return [
                'conception' => '',
                'dimensions' => '',
                'insulation' => ''
            ];
        }
        $conception = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT d.TankType, d.Material, d.Support, c.Value AS material_text
            FROM {$this->dimension_table} d
            LEFT JOIN {$this->conception_table} c ON c.Id = d.Material
            WHERE d.customerTankId = %d", $article_id
        ));
        if ($conception) {
            $material = $conception->Material ?? null;
            $type = $conception->TankType ?? null;

            if ($material !== null && $type !== null) {
                $conception->Finition = $this->get_tank_finition($material, $type);
            } else {
                // error_log("Material ou TankType manquant pour le deal/conception.");
                $conception->Finition = null;
            }
        } else {
// \1("❌ Conception est null");
        }



        $dimensions = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT Volume, Diameter, Height, FeetHeight, GroundClearance, MaxPressure, TestPressure, usingTemperature FROM {$this->dimension_table} WHERE customerTankId = %d", $article_id
        ));

        if ($dimensions) {
            $diameter = $this->safe_get($dimensions, 'Diameter');
            $height = $this->safe_get($dimensions, 'Height');

            if ($diameter !== null && $height !== null) {
                $dimensions->TippingHeight = $this->calculate_tipping($diameter, $height);
            } else {
// \1("❌ Diameter ou Height manquant pour le calcul de basculement.");
            }
        } else {
// \1("❌ Dimensions est null. Impossible de calculer la cote de basculement.");
        }

        

        $insulation = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT it.Value AS InsulationThickness, i.Value AS insulation
                FROM {$this->dimension_table} dt
                LEFT JOIN {$this->conception_table} it
                    ON it.Id = dt.InsulationThickness
                LEFT JOIN {$this->conception_table} i
                    ON i.Id = dt.insulation
                WHERE dt.customerTankId = %d", $article_id
        ));

        return [
            'conception' => $conception,
            'dimensions' => $dimensions,
            'insulation' => $insulation
        ];
    }

    public function get_tank_text_data($conception_id) {
        if(empty($conception_id)){
            return;
        }
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Value FROM {$this->conception_table} WHERE Id = %d", $conception_id
        ));    

    }

    public function get_tank_types() {
        $results = $this->wpdb->get_results(
            "SELECT Id, Value FROM {$this->conception_table} WHERE SelectType = 'typ' ORDER BY sort ASC"
        );

        return $results; // tableau d’objets avec Id et Value
    }

    /**
     * Récupère les options pour les materiaux de réservoir depuis la base
     */
    public function get_tank_materials() {
        $results = $this->wpdb->get_results(
            "SELECT Id, Value FROM {$this->conception_table} WHERE SelectType = 'material' ORDER BY sort ASC"
        );

        return $results; // tableau d’objets avec Id et Value
    }

    /**
     * Récupère les options pour les supports de réservoir depuis la base
     */
    public function get_tank_support() {
        $results = $this->wpdb->get_results(
            "SELECT Id, Value FROM {$this->conception_table} WHERE SelectType = 'support' ORDER BY sort ASC"
        );

        return $results; // tableau d’objets avec Id et Value
    }
    public function ajax_save_tank_data() {
       wp_send_json_success(['debug' => $this->save_tank_data(null, $_POST)]);
    }
    public function save_tank_data($html, $datas, $autoUpdateFromGemini = false) {
        global $wpdb;
        $debug = [];
        $debug['start'] = "IN Tank save_tank_data : " . print_r($datas, true);

        $article_id = !empty($datas['article_id']) ? intval($datas['article_id']) : 0;
        $deal_id = !empty($datas['deal_id']) ? intval($datas['deal_id']) : 0;
        $data_received = $datas['tank'] ?? [];
        $is_purchase = !empty($datas['is_purchase']) && $datas['is_purchase'] === 'true';

        // Gestion de l'ID pour les achats
        if ($is_purchase) {
            $idCommandeClient = $wpdb->get_var($wpdb->prepare(
                "SELECT IdCommandeClient FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs WHERE Id = %d",
                $article_id
            ));
            if ($idCommandeClient) {
                $article_id = $idCommandeClient;
            }
        }

        if (empty($article_id)) {
            return ['success' => false, 'message' => 'Article ID manquant'];
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->dimension_table} WHERE customerTankId = %d", 
            $article_id
        ));

        $user_id = get_current_user_id();
        $newData = [];

        // --- ÉTAPE 1 : MAPPING DES CLÉS ---
        $mapping = [
            'TankType'            => 'type',
            'Material'            => 'materiau',
            'Support'             => 'support',
            'MaxPressure'         => 'max_pressure',
            'TestPressure'        => 'test_pressure',
            'Volume'              => 'volume',
            'GroundClearance'     => 'clearance',
            'usingTemperature'    => 'temperature',
            'insulation'          => 'insulation',
            'InsulationThickness' => 'InsulationThickness',
            'Diameter'            => 'diameter',
            'Height'              => 'height'
        ];

        // On parcourt le mapping pour construire le tableau final
        foreach ($mapping as $sqlKey => $inputKey) {
            if (isset($data_received[$inputKey]) && $data_received[$inputKey] !== '') {
                $newData[$sqlKey] = $data_received[$inputKey];
            } 
            // Si la clé est déjà au format SQL (ex: l'IA envoie directement 'Volume')
            elseif (isset($data_received[$sqlKey]) && $data_received[$sqlKey] !== '') {
                $newData[$sqlKey] = $data_received[$sqlKey];
            }
        }

        // --- ÉTAPE 2 : VALEURS PAR DÉFAUT (seulement si pas Gemini) ---
        if (!$autoUpdateFromGemini) {
            if (!isset($newData['Diameter'])) {
                $newData['Diameter'] = $this->get_default_diameter($newData['Material'] ?? 2, $newData['Volume'] ?? 100);
            }
            if (!isset($newData['Height'])) {
                $newData['Height'] = $this->get_default_height($newData['Material'] ?? 2, $newData['Volume'] ?? 100);
            }
            // Autres valeurs par défaut...
            if (!isset($newData['TankType'])) $newData['TankType'] = 4;
        }

        // --- ÉTAPE 3 : SÉCURITÉ ANTI-SQL-VIDE ---
        if (empty($newData)) {
            $debug['message'] = "Aucune donnée technique à mettre à jour.";
            $debug['success'] = true; // On retourne true car ce n'est pas une "erreur" fatale
            return $debug;
        }

        $newData['userId'] = $user_id;
        $debug['datas to save'] = $newData;

        // --- ÉTAPE 4 : EXÉCUTION ---
        if ($exists) {
            $wpdb->update($this->dimension_table, $newData, ['customerTankId' => $article_id]);
            $debug['action'] = 'update';
        } else {
            $newData['customerTankId'] = $article_id;
            $wpdb->insert($this->dimension_table, $newData);
            $debug['action'] = 'insert';
        }

        if ($wpdb->last_error) {
            $debug['sql_error'] = $wpdb->last_error;
            $debug['last_query'] = $wpdb->last_query;
            wp_send_json_error(['message' => 'Erreur SQL', 'debug' => $debug]);
        }

        // Filtres additionnels (Soudures / Isolation)
        $nb_welding = $data_received['nbWelding'] ?? 0;
        apply_filters('ispag_auto_welding_saver', '', $deal_id, $article_id, $nb_welding);
        apply_filters('ispag_auto_insulation_saver', '', $deal_id, $article_id, $newData['insulation'] ?? '', $newData['InsulationThickness'] ?? '');

        $debug['success'] = true;
        return $debug;
    }
    private function get_default_diameter($material = null, $volume = null, $type = null){
        if(empty($volume)){
            return null;
        }
        if(empty($material)){
            if (in_array($type, [1, 6, 7, 9])) {
                $material = 2;
            }
            else{
                $material = 1;
            }
        }
        $filePath = __DIR__ . '/../assets/js/default_value.json';

        // Vérifier si le fichier existe et est lisible
        if (!file_exists($filePath) || !is_readable($filePath)) {
            // Gérer l'erreur, par exemple, en enregistrant un message de log
// \1("❌ Fichier JSON non trouvé ou illisible: " . $filePath);
            return null;
        }

        $jsonString = file_get_contents($filePath);
        $data = json_decode($jsonString, true);

        // Vérifier si le décodage a réussi
        if ($data === null) {
            // Gérer l'erreur de décodage JSON
// \1("❌ Erreur lors du décodage du fichier JSON.");
            return null;
        }

        // Vérifier si les clés existent avant d'y accéder
        if (isset($data[$material][$volume]['diameter'])) {
            return $data[$material][$volume]['diameter'];
        }

        return null; // Retourner null si les données ne sont pas trouvées

    }
    private function get_default_height($material = null, $volume = null, $type = null){
        if(empty($volume)){
            return null;
        }
        if(empty($material)){
            if (in_array($type, [1, 6, 7, 9])) {
                $material = 2;
            }
            else{
                $material = 1;
            }
        }
        $filePath = __DIR__ . '/../assets/js/default_value.json';

        // Vérifier si le fichier existe et est lisible
        if (!file_exists($filePath) || !is_readable($filePath)) {
            // Gérer l'erreur, par exemple, en enregistrant un message de log
// \1("❌ Fichier JSON non trouvé ou illisible: " . $filePath);
            return null;
        }

        $jsonString = file_get_contents($filePath);
        $data = json_decode($jsonString, true);

        // Vérifier si le décodage a réussi
        if ($data === null) {
            // Gérer l'erreur de décodage JSON
// \1("❌ Erreur lors du décodage du fichier JSON.");
            return null;
        }

        // Vérifier si les clés existent avant d'y accéder
        if (isset($data[$material][$volume]['height'])) {
            return $data[$material][$volume]['height'];
        }

        return null; // Retourner null si les données ne sont pas trouvées

    }
    private function calculate_tipping($tank_diamter, $tank_height){
        $radius = $tank_diamter / 2;
        $Height = $tank_height;

        return round(sqrt(pow($radius, 2) + pow($Height, 2)));

    }

    private function get_tank_finition($material = null, $type = null) {
        if (in_array($material, [1, 3])) {
            return __("Internally and externally stained and passivated", "creation-reservoir");
        }

        if (in_array($material, [2]) && in_array($type, [4, 9])) {
            return __("Raw inside / outside painted anti-rust", "creation-reservoir");
        }

        if (in_array($material, [2]) && in_array($type, [6, 7])) {
            return __("Raw inside / zinc dust primed outside", "creation-reservoir");
        }

        return ''; // Par défaut, si aucun cas ne correspond
    }

    public function get_tank_id_by_article_id($article_id){
        if (empty($article_id) || !is_numeric($article_id)) {
            return null;
        }

        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->dimension_table} WHERE customerTankId = %d",
            $article_id
        ));
    }

    public function duplicate_tank_data($old_article_id, $new_article_id) {
        // 1. On récupère la ligne de dimensions liée à l'ancien article
        $original = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->dimension_table} WHERE customerTankId = %d",
                $old_article_id
            ),
            ARRAY_A
        );

        if (!$original) {
            // error_log("Aucune dimension de cuve trouvée pour article $old_article_id");
            return;
        }

        $old_tank_id = $original['Id'];
        unset($original['Id']);

        // 2. On modifie le customerTankId avec le nouveau
        $original['customerTankId'] = $new_article_id;

        // 3. On insère la nouvelle ligne dans achats_tank_dimensions
        $inserted = $this->wpdb->insert($this->dimension_table, $original);
        if (!$inserted) {
            // error_log("Erreur lors de la duplication des dimensions pour article $new_article_id");
            return;
        }

        $new_tank_id = $this->wpdb->insert_id;

        // 4. On copie les connexions de l'ancien tank vers le nouveau
        $connection_table = $this->wpdb->prefix . 'achats_tank_connection';
        $connections = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM $connection_table WHERE TankId = %d", $old_tank_id),
            ARRAY_A
        );

        foreach ($connections as $conn) {
            unset($conn['Id']);
            $conn['TankId'] = $new_tank_id;
            $this->wpdb->insert($connection_table, $conn);
        }

        // error_log("✔ Cuve dupliquée de article $old_article_id vers $new_article_id (TankId: $old_tank_id → $new_tank_id)");
    }

    /**
     * Récupère l'ID du réservoir dans la base de données
     * en fonction du customerTankId.
     *
     * @param int $current_user_id L'ID de l'utilisateur actuel. Non utilisé dans cette requête, mais gardé pour la signature de la fonction.
     * @param int $article_id Le customerTankId à rechercher.
     * @return int|null L'ID du réservoir s'il est trouvé, sinon null.
     */
    function get_tank_created_by_id($current_user_id, $article_id) {
        global $wpdb;

        // Nom de la table
        $table_name = $wpdb->prefix . 'achats_tank_dimensions';

        // Requête préparée pour éviter les injections SQL
        $query = $wpdb->prepare(
            "SELECT userId FROM $table_name WHERE customerTankId = %d",
            $article_id
        );

        // Exécution de la requête et récupération du résultat
        $result = $wpdb->get_var($query);

        // Retourne l'ID ou null si rien n'est trouvé
        if ($result !== null) {
            return (int) $result;
        }

        return (int) $current_user_id;
    }



}