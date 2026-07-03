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
    // Évite que "dn40" matche "DN400/480" ou que "bend" matche "bend pipe with..."
    private const PARTIAL_MIN_LEN  = 5;

    // ------------------------------------------------------------------ //
    // Alias map — Diamètres
    //
    // Clé   : ce que Mistral peut envoyer (normalisé en minuscule, trim)
    // Valeur : le DN exact tel qu'il existe dans achats_flange_dimensions (colonne DN),
    //          normalisé en minuscule pour la comparaison avec $this->flange_map
    // ------------------------------------------------------------------ //
    private const DIAMETER_ALIASES = [
        // Pouces en notation typographique double-apostrophe → Rp correspondant en base
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
        // Mistral envoie parfois "DN400/480" au lieu de "Ø400/480"
        'dn500/580'   => 'ø500/580',
        'dn400/480'   => 'ø400/480',
        'dn300/380'   => 'ø300/380',
        'dn220/290'   => 'ø220/290',
        'dn200/280'   => 'ø200/280',
        'dn120/180'   => 'ø120/180',
    ];

    // ------------------------------------------------------------------ //
    // Alias map — Accessoires
    //
    // Clé   : libellé FR/Mistral (normalisé en minuscule, trim)
    // Valeur : Value exacte dans achats_tank_conception (normalisée en minuscule)
    // ------------------------------------------------------------------ //
    private const ACCESSORY_ALIASES = [
        // Tubes plongeants
        'tube plongeant'                          => 'bend pipe',
        'tube plongeur'                           => 'bend pipe',
        'tube plongeant avec diffuseur conique'   => 'bend pipe with conical flow diffuser',
        'tube plongeant conique'                  => 'bend pipe with conical flow diffuser',
        // Déflecteur / Tôle de déflection
        'tôle de déflection'                      => 'baffle plate',
        'tole de deflection'                      => 'baffle plate',
        'tôle de deflection'                      => 'baffle plate',
        'tole de déflection'                      => 'baffle plate',
        'déflecteur'                              => 'baffle plate',
        'deflecteur'                              => 'baffle plate',
        // Tube de chargement
        'tube de chargement'                      => 'loading spray tube',
        'spray tube'                              => 'loading spray tube',
        // Thermomètre → doigt de gant sans thermomètre
        'thermomètre'                             => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'thermometre'                             => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'doigt de gant thermomètre'               => 'pour thermomètre(s) sans ce(s) dernier(s)',
        'doigt de gant thermometre'               => 'pour thermomètre(s) sans ce(s) dernier(s)',
        // Sonde → doigt de gant sans sonde
        'sonde de température'                    => 'pour sonde(s) sans ces dernière(s)',
        'sonde de temperature'                    => 'pour sonde(s) sans ces dernière(s)',
        'sonde température'                       => 'pour sonde(s) sans ces dernière(s)',
        'sonde temperature'                       => 'pour sonde(s) sans ces dernière(s)',
        'sonde'                                   => 'pour sonde(s) sans ces dernière(s)',
        'doigt de gant sonde'                     => 'pour sonde(s) sans ces dernière(s)',
        // Brides de révision (SelectType = 'connection')
        'bride de révision'                       => 'revision flange',
        'bride de revision'                       => 'revision flange',
        'bride révision'                          => 'revision flange',
        'bride revision'                          => 'revision flange',
        // Bride de révision avec tube plongeant → on mappe sur la connection 'revision flange'
        // (le tube plongeant est un accessoire implicite géré séparément par ISPAG)
        'bride de révision avec tube plongeant'   => 'revision flange',
        'bride de revision avec tube plongeant'   => 'revision flange',
        'bride de révision avec tube plongeur'    => 'revision flange',
        'bride de revision avec tube plongeur'    => 'revision flange',
        // Bride standard avec tube plongeant → flange
        'bride avec tube plongeant'               => 'flange',
        'bride avec tube plongeur'                => 'flange',
        // Bride générique → flange
        'bride'                                   => 'flange',
        // Échangeur / registre
        'échangeur de chaleur'                    => 'heat exchanger',
        'echangeur de chaleur'                    => 'heat exchanger',
        'registre'                                => 'heat exchanger',
        // Plaque perforée
        'plaque perforée'                         => 'drilled plate (35%)',
        'plaque perforee'                         => 'drilled plate (35%)',
        // Soudure
        'soudure'                                 => 'welding',
        // Raccord fileté
        'raccord fileté'                          => 'threaded fitting',
        'raccord filete'                          => 'threaded fitting',
        'raccord vissé'                           => 'threaded fitting',
        'raccord visse'                           => 'threaded fitting',
    ];

    /** @var wpdb */
    private wpdb $wpdb;

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
    }

    // ------------------------------------------------------------------ //
    // Point d'entrée public
    // ------------------------------------------------------------------ //

    /**
     * Enregistre les raccords issus du tableau $datas.
     *
     * Structure attendue (identique à ce que reçoit ispag_auto_saver_tank_data) :
     *   $datas = [
     *       'article_id' => 123,          // customerTankId dans achats_tank_dimensions
     *       'tank'       => [
     *           'fittings' => [ ... ],    // ← les raccords sont ici
     *           'volume'   => 2000,
     *           ...
     *       ],
     *       'deal_id'    => 456,
     *       ...
     *   ]
     *
     * @param  array $datas
     * @return array{success: bool, inserted: int, total: int, errors: string[], message?: string}
     */
    public function save( array $datas ): array {

        // -- 1. Résolution du TankId (Id PK de achats_tank_dimensions) ----
        $article_id = $this->resolve_article_id( $datas );
        if ( ! $article_id ) {
            return $this->result( false, 0, 0, [], 'Article ID manquant ou introuvable dans achats_tank_dimensions' );
        }

        // -- 2. Validation des fittings -----------------------------------
        // Les fittings sont dans $datas['tank']['fittings'] (structure auto_save_extracted_data)
        // Fallback sur $datas['fittings'] pour les autres contextes d'appel (formulaire manuel…)
        $fittings = $datas['tank']['fittings'] ?? $datas['fittings'] ?? [];
        if ( empty( $fittings ) || ! is_array( $fittings ) ) {
            return $this->result( true, 0, 0, [], 'Aucun raccord à enregistrer' );
        }

        // -- 3. Chargement des référentiels en mémoire --------------------
        $this->load_flange_map();
        $this->load_conception_map( $article_id );

        // -- 4. Remplacement complet des raccords existants ---------------
        $this->delete_existing_fittings( $article_id );

        // -- 5. Insertion de chaque raccord --------------------------------
        $errors   = [];
        $inserted = 0;

        foreach ( $fittings as $index => $fitting ) {
            $row    = $this->build_row( $article_id, $index, $fitting, $errors );
            $result = $this->insert_row( $row );

            if ( $result === false ) {
                $errors[] = "Fitting #{$index} : erreur SQL – " . $this->wpdb->last_error;
            } else {
                $inserted++;
            }
        }

        return $this->result(
            $inserted > 0 || empty( $errors ),
            $inserted,
            count( $fittings ),
            $errors
        );
    }

    // ------------------------------------------------------------------ //
    // Résolution de l'article_id
    // ------------------------------------------------------------------ //

    /**
     * Le $datas['article_id'] correspond au customerTankId dans achats_tank_dimensions.
     * On récupère le Id (clé primaire) de cette table, qui est le TankId
     * utilisé comme clé étrangère dans achats_tank_connection.
     */
    private function resolve_article_id( array $datas ): int {
        $customer_tank_id = ! empty( $datas['article_id'] ) ? intval( $datas['article_id'] ) : 0;

        if ( ! $customer_tank_id ) {
            return 0;
        }

        $tank_id = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT Id
               FROM {$this->wpdb->prefix}achats_tank_dimensions
              WHERE customerTankId = %d
              LIMIT 1",
            $customer_tank_id
        ) );

        return $tank_id ? intval( $tank_id ) : 0;
    }

    // ------------------------------------------------------------------ //
    // Chargement des référentiels
    // ------------------------------------------------------------------ //

    /**
     * Charge toutes les brides en mémoire.
     * Clé = DN normalisé en minuscule, valeur = Id.
     */
    private function load_flange_map(): void {
        $rows = $this->wpdb->get_results(
            "SELECT Id, DN FROM {$this->wpdb->prefix}" . self::TABLE_FLANGE,
            ARRAY_A
        );

        $this->flange_map = [];
        foreach ( $rows as $row ) {
            $this->flange_map[ strtolower( trim( $row['DN'] ) ) ] = intval( $row['Id'] );
        }
    }

    /**
     * Charge les entrées de conception utiles (connection + accessories).
     * Les entrées spécifiques à l'article ont la priorité sur les génériques (articleId = 0).
     * Clé = Value normalisée en minuscule.
     */
    private function load_conception_map( int $article_id ): void {
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
        foreach ( $rows as $row ) {
            $key = strtolower( trim( $row['Value'] ) );
            // Une entrée spécifique à l'article écrase l'entrée générique
            if ( ! isset( $this->conception_map[ $key ] )
                || intval( $row['articleId'] ) === $article_id ) {
                $this->conception_map[ $key ] = [
                    'id'          => intval( $row['Id'] ),
                    'select_type' => $row['SelectType'],
                ];
            }
        }
    }

    // ------------------------------------------------------------------ //
    // Construction de la ligne à insérer
    // ------------------------------------------------------------------ //

    /**
     * Construit le tableau de données pour un fitting.
     * Les erreurs de résolution sont ajoutées dans $errors (passage par référence).
     */
    private function build_row( int $article_id, $index, array $fitting, array &$errors ): array {
        $diameter_raw  = trim( $fitting['diameter']    ?? '' );
        $accessory_raw = trim( $fitting['accessories'] ?? '' );
        $made_for      = trim( $fitting['madeFor']     ?? '' );

        $pouces                       = $this->resolve_diameter( $index, $diameter_raw, $errors );
        [ $type_id, $accessories_id ] = $this->resolve_accessory( $index, $accessory_raw, $errors );

        return [
            'TankId'         => $article_id,
            'Type'           => $type_id,
            'Pouces'         => $pouces,
            'Height'         => 0,   // à compléter via l'UI
            'heightApproved' => 0,
            'Angle'          => 0,   // à compléter via l'UI
            'Accessories'    => $accessories_id,
            'madeFor'        => $made_for,
        ];
    }

    // ------------------------------------------------------------------ //
    // Résolution du diamètre
    // ------------------------------------------------------------------ //

    /**
     * Résout le texte du diamètre vers l'Id de achats_flange_dimensions.
     *
     * Ordre de résolution :
     *   1. DIAMETER_ALIASES  : normalise les notations Mistral/FR vers la valeur en base
     *   2. Correspondance exacte dans flange_map
     *   3. Correspondance partielle stricte (token ≥ PARTIAL_MIN_LEN des deux côtés)
     *
     * @return string|null  Id sous forme de chaîne, ou null si non résolu
     */
    private function resolve_diameter( $index, string $diameter_raw, array &$errors ): ?string {
        if ( $diameter_raw === '' ) {
            $errors[] = "Fitting #{$index} : diamètre vide.";
            return null;
        }

        $key = strtolower( trim( $diameter_raw ) );

        // -- 1. Alias map -------------------------------------------------
        $resolved_key = self::DIAMETER_ALIASES[ $key ] ?? $key;

        // -- 2. Correspondance exacte -------------------------------------
        if ( isset( $this->flange_map[ $resolved_key ] ) ) {
            return (string) $this->flange_map[ $resolved_key ];
        }

        // -- 3. Correspondance partielle stricte --------------------------
        // Les deux tokens doivent être ≥ PARTIAL_MIN_LEN pour éviter les faux positifs
        if ( strlen( $resolved_key ) >= self::PARTIAL_MIN_LEN ) {
            foreach ( $this->flange_map as $dn => $id ) {
                if ( strlen( $dn ) < self::PARTIAL_MIN_LEN ) {
                    continue; // ignore les DN trop courts (ex: 'dn40')
                }
                if ( str_contains( $dn, $resolved_key ) || str_contains( $resolved_key, $dn ) ) {
                    $errors[] = "Fitting #{$index} : diamètre '{$diameter_raw}' résolu partiellement sur '{$dn}'.";
                    return (string) $id;
                }
            }
        }

        $errors[] = "Fitting #{$index} : diamètre '{$diameter_raw}' introuvable dans achats_flange_dimensions.";
        return null;
    }

    // ------------------------------------------------------------------ //
    // Résolution de l'accessoire → [Type, Accessories]
    // ------------------------------------------------------------------ //

    /**
     * Résout le texte de l'accessoire vers le couple [Type, Accessories].
     *
     * Ordre de résolution :
     *   1. ACCESSORY_ALIASES : normalise les libellés Mistral/FR vers la valeur en base
     *   2. Correspondance exacte dans conception_map
     *   3. Correspondance partielle stricte (token ≥ PARTIAL_MIN_LEN des deux côtés)
     *
     * Règle métier :
     *   - SelectType = 'connection'  → Type = Id de la ligne,  Accessories = 0
     *   - SelectType = 'accessories' → Type = DEFAULT_TYPE_ID, Accessories = Id de la ligne
     *
     * @return array{int, int}  [ $type_id, $accessories_id ]
     */
    private function resolve_accessory( $index, string $accessory_raw, array &$errors ): array {
        if ( $accessory_raw === '' ) {
            $errors[] = "Fitting #{$index} : accessoire vide.";
            return [ 0, 0 ];
        }

        $key = strtolower( trim( $accessory_raw ) );

        // -- 1. Alias map -------------------------------------------------
        $resolved_key = self::ACCESSORY_ALIASES[ $key ] ?? $key;

        // -- 2. Correspondance exacte -------------------------------------
        $entry = $this->conception_map[ $resolved_key ] ?? null;

        // -- 3. Correspondance partielle stricte --------------------------
        if ( $entry === null && strlen( $resolved_key ) >= self::PARTIAL_MIN_LEN ) {
            foreach ( $this->conception_map as $val => $candidate ) {
                if ( strlen( $val ) < self::PARTIAL_MIN_LEN ) {
                    continue;
                }
                if ( str_contains( $resolved_key, $val ) || str_contains( $val, $resolved_key ) ) {
                    $entry    = $candidate;
                    $errors[] = "Fitting #{$index} : accessoire '{$accessory_raw}' résolu partiellement sur '{$val}'.";
                    break;
                }
            }
        }

        if ( $entry === null ) {
            $errors[] = "Fitting #{$index} : accessoire '{$accessory_raw}' introuvable dans achats_tank_conception.";
            return [ 0, 0 ];
        }

        if ( $entry['select_type'] === 'connection' ) {
            // L'Id de la ligne conception IS le Type ; pas d'accessoire complémentaire
            return [ $entry['id'], 0 ];
        }

        // 'accessories' : on rattache à la connexion filetée par défaut
        return [ self::DEFAULT_TYPE_ID, $entry['id'] ];
    }

    // ------------------------------------------------------------------ //
    // Opérations SQL
    // ------------------------------------------------------------------ //

    /**
     * Supprime tous les raccords existants du réservoir.
     */
    private function delete_existing_fittings( int $article_id ): void {
        $this->wpdb->delete(
            $this->wpdb->prefix . self::TABLE_CONNECTION,
            [ 'TankId' => $article_id ],
            [ '%d' ]
        );
    }

    /**
     * Insère une ligne dans achats_tank_connection.
     *
     * @return int|false
     */
    private function insert_row( array $row ) {
        return $this->wpdb->insert(
            $this->wpdb->prefix . self::TABLE_CONNECTION,
            $row,
            [ '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s' ]
        );
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    /**
     * Construit le tableau de retour standardisé.
     */
    private function result(
        bool   $success,
        int    $inserted,
        int    $total,
        array  $errors,
        string $message = ''
    ): array {
        $out = [
            'success'  => $success,
            'inserted' => $inserted,
            'total'    => $total,
            'errors'   => $errors,
        ];
        if ( $message !== '' ) {
            $out['message'] = $message;
        }
        return $out;
    }
}