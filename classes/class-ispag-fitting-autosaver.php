<?php
/**
 * Class Ispag_Fitting_Autosaver
 *
 * Gère l'enregistrement automatique des raccords (fittings) d'un réservoir
 * dans la table wor9711_achats_tank_connection.
 *
 * Résolution des clés étrangères :
 *   - fitting[diameter]    → DN dans achats_flange_dimensions   → Pouces (Id)
 *   - fitting[accessories] → Value dans achats_tank_conception  → Type + Accessories (Id)
 *   - fitting[madeFor]     → stocké tel quel                    → madeFor
 *
 * Stratégie d'écriture : suppression complète des anciens raccords du réservoir
 * puis réinsertion (plus simple que le diff ligne par ligne).
 *
 * Stratégie de résolution :
 *   1. Alias map statique  (termes Mistral/FR → valeur exacte en base)
 *   2. Correspondance exacte sur la valeur normalisée
 *   3. Correspondance partielle stricte (longueur minimale pour éviter les faux positifs)
 */
class Ispag_Fitting_Autosaver {

    // ------------------------------------------------------------------ //
    // Noms des tables (sans préfixe — le préfixe est ajouté via $wpdb)
    // ------------------------------------------------------------------ //
    private const TABLE_CONNECTION = 'achats_tank_connection';
    private const TABLE_FLANGE     = 'achats_flange_dimensions';
    private const TABLE_CONCEPTION = 'achats_tank_conception';

    // Id de la ligne 'threaded fitting' dans achats_tank_conception
    // utilisé comme Type par défaut quand l'accessoire est de SelectType 'accessories'
    private const DEFAULT_TYPE_ID  = 12;

    // Longueur minimale d'un token pour autoriser la correspondance partielle.
    private const PARTIAL_MIN_LEN  = 5;

    // ------------------------------------------------------------------ //
    // Alias map — Diamètres
    // ------------------------------------------------------------------ //
    private const DIAMETER_ALIASES = [
        '2\'\''       => 'rp2"',
        '2"'          => 'rp2"',
        '1\'\'1/2'    => 'rp1½"',
        '1"1/2'       => 'rp1½"',
        '1 1/2"'      => 'rp1½"',
        '1\'\'1/4'    => 'rp1¼"',
        '1"1/4'       => 'rp1¼"',
        '1 1/4"'      => 'rp1¼"',
        '1\''         => 'rp1"',
        '1"'          => 'rp1"',
        '3/4\''       => 'rp¾"',
        '3/4"'        => 'rp¾"',
        '1/2\''       => 'rp½"',
        '1/2"'        => 'rp½"',
        '1\'\'1/2'    => 'rp1½"',
        '1/4\''       => 'rp¼"',
        '1/4"'        => 'rp¼"',
        'dn500/580'   => 'ø500/580',
        'dn400/480'   => 'ø400/480',
        'dn300/380'   => 'ø300/380',
        'dn220/290'   => 'ø220/290',
        'dn200/280'   => 'ø200/280',
        'dn120/180'   => 'ø120/180',
    ];

    // ------------------------------------------------------------------ //
    // Alias map — Accessoires
    // ------------------------------------------------------------------ //
    private const ACCESSORY_ALIASES = [
        'tube plongeant'                          => 'bend pipe',
        'tube plongeur'                           => 'bend pipe',
        'tube plongeant avec diffuseur conique'   => 'bend pipe with conical flow diffuser',
        'tube plongeant conique'                  => 'bend pipe with conical flow diffuser',
        'tôle de déflection'                      => 'baffle plate',
        'tole de deflection'                      => 'baffle plate',
        'tôle de deflection'                      => 'baffle plate',
        'tole de déflection'                      => 'baffle plate',
        'déflecteur'                              => 'baffle plate',
        'deflecteur'                              => 'baffle plate',
        'tube de chargement'                      => 'loading spray tube',
        'spray tube'                              => 'loading spray tube',
        'thermomètre'                             => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'thermometre'                             => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'doigt de gant thermomètre'               => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'doigt de gant thermometre'               => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'sonde de température'                    => 'pour sonde(s) sans ces dernière(s)',
        'sonde de temperature'                    => 'pour sonde(s) sans ces dernière(s)',
        'sonde température'                       => 'pour sonde(s) sans ces dernière(s)',
        'sonde temperature'                       => 'pour sonde(s) sans ces dernière(s)',
        'sonde'                                   => 'pour sonde(s) sans ces dernière(s)',
        'doigt de gant sonde'                     => 'pour sonde(s) sans ces dernière(s)',
        'bride de révision'                       => 'revision flange',
        'bride de revision'                       => 'revision flange',
        'bride révision'                          => 'revision flange',
        'bride revision'                          => 'revision flange',
        'bride de révision avec tube plongeant'   => 'revision flange',
        'bride de revision avec tube plongeant'   => 'revision flange',
        'bride de révision avec tube plongeur'    => 'revision flange',
        'bride de revision avec tube plongeur'    => 'revision flange',
        'bride avec tube plongeant'               => 'flange',
        'bride avec tube plongeur'                => 'flange',
        'bride'                                   => 'flange',
        'échangeur de chaleur'                    => 'heat exchanger',
        'echangeur de chaleur'                    => 'heat exchanger',
        'registre'                                => 'heat exchanger',
        'plaque perforée'                         => 'drilled plate (35%)',
        'plaque perforee'                         => 'drilled plate (35%)',
        'soudure'                                 => 'welding',
        'raccord fileté'                          => 'threaded fitting',
        'raccord filete'                          => 'threaded fitting',
        'raccord vissé'                           => 'threaded fitting',
        'raccord visse'                           => 'threaded fitting',
    ];

    /** @var wpdb */
    private wpdb $wpdb;

    /** @var ISPAG_Logger */
    private ISPAG_Logger $logger;

    /** @var array<string, int>  DN normalisé → Id */
    private array $flange_map = [];

    /** @var array<string, array{id: int, select_type: string}>  Value normalisée → entry */
    private array $conception_map = [];

    // ------------------------------------------------------------------ //
    // Construction
    // ------------------------------------------------------------------ //
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
    }

    // ------------------------------------------------------------------ //
    // Point d'entrée public
    // ------------------------------------------------------------------ //
    /**
     * Enregistre les raccords issus du tableau $datas.
     *
     * @param  array $datas
     * @return array{success: bool, inserted: int, total: int, errors: string[], message?: string}
     */
    public function save(array $datas): array {
        $user_id = get_current_user_id();
        $this->logger->log_user_action('fitting_autosaver', 'save_start', ['article_id' => $datas['article_id'] ?? null], $user_id);

        // -- 1. Résolution du TankId
        $article_id = $this->resolve_article_id($datas);
        if (!$article_id) {
            $this->logger->log('fitting_autosaver', 'ERROR: Article ID manquant ou introuvable', $user_id);
            return $this->result(false, 0, 0, [], 'Article ID manquant ou introuvable dans achats_tank_dimensions');
        }
        $this->logger->log_db_change('fitting_autosaver', 'achats_tank_dimensions', 'RESOLVE', ['customerTankId' => $datas['article_id'], 'resolvedTankId' => $article_id], $user_id);

        // -- 2. Validation des fittings
        $fittings = $datas['tank']['fittings'] ?? $datas['fittings'] ?? [];
        if (empty($fittings) || !is_array($fittings)) {
            $this->logger->log_user_action('fitting_autosaver', 'no_fittings_to_save', [], $user_id);
            return $this->result(true, 0, 0, [], 'Aucun raccord à enregistrer');
        }
        $this->logger->log_user_action('fitting_autosaver', 'fittings_count', ['count' => count($fittings)], $user_id);

        // -- 3. Chargement des référentiels
        $this->load_flange_map();
        $this->load_conception_map($article_id);
        $this->logger->log_db_change('fitting_autosaver', 'achats_flange_dimensions', 'LOAD_MAP', [], $user_id);
        $this->logger->log_db_change('fitting_autosaver', 'achats_tank_conception', 'LOAD_MAP', ['article_id' => $article_id], $user_id);

        // -- 4. Suppression des anciens raccords
        $this->delete_existing_fittings($article_id);
        $this->logger->log_db_change('fitting_autosaver', self::TABLE_CONNECTION, 'DELETE_ALL', ['TankId' => $article_id], $user_id);

        // -- 5. Insertion des nouveaux raccords
        $errors = [];
        $inserted = 0;
        foreach ($fittings as $index => $fitting) {
            $row = $this->build_row($article_id, $index, $fitting, $errors);
            $result = $this->insert_row($row);

            if ($result === false) {
                $this->logger->log('fitting_autosaver', "ERROR: Insertion échouée pour fitting #$index - " . $this->wpdb->last_error, $user_id);
                $errors[] = "Fitting #{$index} : erreur SQL – " . $this->wpdb->last_error;
            } else {
                $inserted++;
                $this->logger->log_db_change('fitting_autosaver', self::TABLE_CONNECTION, 'INSERT', $row, $user_id);
            }
        }

        $this->logger->log_user_action('fitting_autosaver', 'save_complete', ['inserted' => $inserted, 'total' => count($fittings), 'errors' => $errors], $user_id);
        return $this->result($inserted > 0 || empty($errors), $inserted, count($fittings), $errors);
    }

    // ------------------------------------------------------------------ //
    // Résolution de l'article_id
    // ------------------------------------------------------------------ //
    private function resolve_article_id(array $datas): int {
        $customer_tank_id = !empty($datas['article_id']) ? intval($datas['article_id']) : 0;
        if (!$customer_tank_id) {
            return 0;
        }

        $tank_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT Id FROM {$this->wpdb->prefix}achats_tank_dimensions WHERE customerTankId = %d LIMIT 1",
            $customer_tank_id
        ));

        return $tank_id ? intval($tank_id) : 0;
    }

    // ------------------------------------------------------------------ //
    // Chargement des référentiels
    // ------------------------------------------------------------------ //
    private function load_flange_map(): void {
        $rows = $this->wpdb->get_results(
            "SELECT Id, DN FROM {$this->wpdb->prefix}" . self::TABLE_FLANGE,
            ARRAY_A
        );

        $this->flange_map = [];
        foreach ($rows as $row) {
            $this->flange_map[strtolower(trim($row['DN']))] = intval($row['Id']);
        }
    }

    private function load_conception_map(int $article_id): void {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT Id, SelectType, Value, articleId
                   FROM {$this->wpdb->prefix}" . self::TABLE_CONCEPTION . "
                  WHERE SelectType IN ('connection', 'accessories')
                    AND (articleId = 0 OR articleId = %d)",
                $article_id
            ),
            ARRAY_A
        );

        $this->conception_map = [];
        foreach ($rows as $row) {
            $key = strtolower(trim($row['Value']));
            if (!isset($this->conception_map[$key]) || intval($row['articleId']) === $article_id) {
                $this->conception_map[$key] = [
                    'id' => intval($row['Id']),
                    'select_type' => $row['SelectType'],
                ];
            }
        }
    }

    // ------------------------------------------------------------------ //
    // Construction de la ligne à insérer
    // ------------------------------------------------------------------ //
    private function build_row(int $article_id, $index, array $fitting, array &$errors): array {
        $diameter_raw  = trim($fitting['diameter'] ?? '');
        $accessory_raw = trim($fitting['accessories'] ?? '');
        $made_for      = trim($fitting['madeFor'] ?? '');

        $pouces = $this->resolve_diameter($index, $diameter_raw, $errors);
        [$type_id, $accessories_id] = $this->resolve_accessory($index, $accessory_raw, $errors);

        return [
            'TankId' => $article_id,
            'Type' => $type_id,
            'Pouces' => $pouces,
            'Height' => 0,
            'heightApproved' => 0,
            'Angle' => 0,
            'Accessories' => $accessories_id,
            'madeFor' => $made_for,
        ];
    }

    // ------------------------------------------------------------------ //
    // Résolution du diamètre
    // ------------------------------------------------------------------ //
    private function resolve_diameter($index, string $diameter_raw, array &$errors): ?string {
        if ($diameter_raw === '') {
            $errors[] = "Fitting #{$index} : diamètre vide.";
            return null;
        }

        $key = strtolower(trim($diameter_raw));
        $resolved_key = self::DIAMETER_ALIASES[$key] ?? $key;

        if (isset($this->flange_map[$resolved_key])) {
            return (string) $this->flange_map[$resolved_key];
        }

        if (strlen($resolved_key) >= self::PARTIAL_MIN_LEN) {
            foreach ($this->flange_map as $dn => $id) {
                if (strlen($dn) < self::PARTIAL_MIN_LEN) {
                    continue;
                }
                if (str_contains($dn, $resolved_key) || str_contains($resolved_key, $dn)) {
                    $errors[] = "Fitting #{$index} : diamètre '{$diameter_raw}' résolu partiellement sur '{$dn}'.";
                    return (string) $id;
                }
            }
        }

        $errors[] = "Fitting #{$index} : diamètre '{$diameter_raw}' introuvable dans achats_flange_dimensions.";
        return null;
    }

    // ------------------------------------------------------------------ //
    // Résolution de l'accessoire
    // ------------------------------------------------------------------ //
    private function resolve_accessory($index, string $accessory_raw, array &$errors): array {
        if ($accessory_raw === '') {
            $errors[] = "Fitting #{$index} : accessoire vide.";
            return [0, 0];
        }

        $key = strtolower(trim($accessory_raw));
        $resolved_key = self::ACCESSORY_ALIASES[$key] ?? $key;

        $entry = $this->conception_map[$resolved_key] ?? null;

        if ($entry === null && strlen($resolved_key) >= self::PARTIAL_MIN_LEN) {
            foreach ($this->conception_map as $val => $candidate) {
                if (strlen($val) < self::PARTIAL_MIN_LEN) {
                    continue;
                }
                if (str_contains($resolved_key, $val) || str_contains($val, $resolved_key)) {
                    $entry = $candidate;
                    $errors[] = "Fitting #{$index} : accessoire '{$accessory_raw}' résolu partiellement sur '{$val}'.";
                    break;
                }
            }
        }

        if ($entry === null) {
            $errors[] = "Fitting #{$index} : accessoire '{$accessory_raw}' introuvable dans achats_tank_conception.";
            return [0, 0];
        }

        if ($entry['select_type'] === 'connection') {
            return [$entry['id'], 0];
        }

        return [self::DEFAULT_TYPE_ID, $entry['id']];
    }

    // ------------------------------------------------------------------ //
    // Opérations SQL
    // ------------------------------------------------------------------ //
    private function delete_existing_fittings(int $article_id): void {
        $this->wpdb->delete(
            $this->wpdb->prefix . self::TABLE_CONNECTION,
            ['TankId' => $article_id],
            ['%d']
        );
    }

    private function insert_row(array $row) {
        return $this->wpdb->insert(
            $this->wpdb->prefix . self::TABLE_CONNECTION,
            $row,
            ['%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s']
        );
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //
    private function result(bool $success, int $inserted, int $total, array $errors, string $message = ''): array {
        $out = [
            'success' => $success,
            'inserted' => $inserted,
            'total' => $total,
            'errors' => $errors,
        ];
        if ($message !== '') {
            $out['message'] = $message;
        }
        return $out;
    }
}