<?php

class ISPAG_Tank_Repository {

    /**
     * Récupère toutes les données du réservoir pour le moteur DXF
     */
    public function get_tank_details($customer_tank_id) {
        global $wpdb;

        // 1. Récupérer les dimensions et les noms des types/matériaux
        $tank = $wpdb->get_row($wpdb->prepare("
            SELECT
                td.*,
                tc.Value as tankTypeRead,
                tm.Value as tankMaterialRead,
                ts.Value as tankSupportRead,
                ti.Value as insulationThickness
            FROM {$wpdb->prefix}achats_tank_dimensions td
            LEFT JOIN {$wpdb->prefix}achats_tank_conception tc ON td.TankType = tc.Id
            LEFT JOIN {$wpdb->prefix}achats_tank_conception tm ON td.Material = tm.Id
            LEFT JOIN {$wpdb->prefix}achats_tank_conception ts ON td.Support = ts.Id
            LEFT JOIN {$wpdb->prefix}achats_tank_conception ti ON td.InsulationThickness = ti.Id
            WHERE td.customerTankId = %d
        ", $customer_tank_id), ARRAY_A);

        if (!$tank) return null;

        // 2. Récupérer la hauteur du fond bombé depuis le JSON
        $bottom_height = $this->get_bottom_height_from_json($tank['Material'], $tank['Diameter']);

        // 3. Récupérer les piquages avec les labels des raccords et accessoires
        $piquages = $wpdb->get_results($wpdb->prepare("
            SELECT 
                conn.Height as Elevation_mm,
                conn.Angle as Angle_degres,
                conn.madeFor as Usage_piquage,
                flange.Typ as Type_raccord,
                flange.DN as Diametre_Nominal,
                flange.ExternalDiameter as Bride_Ext_mm,
                flange.InternalDiamter as Bride_Int_mm,
                flange.Thickness as Epaisseur_Bride_mm,
                flange.Drilling AS DiamDrillings,
                flange.NbDrilling AS NbDrilling,
                type.Value as Type_raccord_label,
                accessories.Value as Accessories_label
            FROM {$wpdb->prefix}achats_tank_connection conn
            LEFT JOIN {$wpdb->prefix}achats_tank_conception type ON conn.Type = type.Id
            LEFT JOIN {$wpdb->prefix}achats_flange_dimensions flange ON conn.Pouces = flange.Id
            LEFT JOIN {$wpdb->prefix}achats_tank_conception accessories ON conn.Accessories = accessories.Id
            WHERE conn.TankId = %d
            ORDER BY conn.Height ASC
        ", $tank['Id']), ARRAY_A);

        // 4. Générer la description formatée pour le tableau DXF
        foreach ($piquages as &$p) {
            $p['Description_Complete'] = $this->generate_connection_label($p);
        }

        $data = [
            'tank_id'   => $tank['customerTankId'],
            'dimensions_principales' => [
                'Designed_by'           => $tank['userId'],
                'Volume_L'              => $tank['Volume'],
                'Diametre_mm'           => $tank['Diameter'],
                'Hauteur_mm'            => $tank['Height'],
                'Pression_Max_bar'      => $tank['MaxPressure'],
                'Pression_Test_bar'     => $tank['TestPressure'],
                'Temperature_Max'       => $tank['usingTemperature'],
                'Matiere_ID'            => $tank['Material'],
                'Matiere'               => $tank['tankMaterialRead'],
                'Ground_clearance'      => $tank['GroundClearance'],
                'Support_ID'            => $tank['Support'],
                'Support'               => $tank['tankSupportRead'],
                'Type_ID'               => $tank['TankType'],
                'Type'                  => $tank['tankTypeRead'],
                'Bottom_Height_mm'      => $bottom_height,
                'insulationThickness'   => !empty($tank['insulationThickness']) ? $tank['insulationThickness'] : 160
            ],
            'piquages_techniques' => $piquages
        ];

        return $this->utf8_encode_deep($data);
    }

    /**
     * Formate la description d'un piquage pour le tableau
     */
    private function generate_connection_label($p) {
        $type = !empty($p['Type_raccord_label']) ? $p['Type_raccord_label'] : 'Raccord';
        $dn   = !empty($p['Diametre_Nominal']) ? $p['Diametre_Nominal'] : '';
        
        $dn = str_replace('"', "''", $dn);
        
        $acc  = !empty($p['Accessories_label']) ? ' avec ' . __($p['Accessories_label'], 'creation-reservoir') : '';
        $for  = !empty($p['Usage_piquage']) ? ' pour ' . $p['Usage_piquage'] : '';

        $label = sprintf('%s %s%s%s', $type, $dn, $acc, $for);
        
        // Nettoyage des retours à la ligne invisibles qui cassent le JS
        return str_replace(array("\r", "\n"), ' ', $label);
    }

    /**
     * Lit le fichier JSON et trouve la hauteur du fond
     */
    private function get_bottom_height_from_json($material_id, $diameter) {
        $json_path = WP_PLUGIN_DIR . '/ispag-tank-builder/assets/js/tank_data.json';
        if (!file_exists($json_path)) return 280; // Par défaut

        $config = json_decode(file_get_contents($json_path), true);

        if (isset($config['arrayBottomHeight'][$material_id][$diameter])) {
            return $config['arrayBottomHeight'][$material_id][$diameter];
        }
        return 280;
    }

    /**
     * Nettoyage UTF-8 récursif
     */
    private function utf8_encode_deep($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->utf8_encode_deep($value);
            }
        } elseif (is_string($data)) {
            if (!mb_check_encoding($data, 'UTF-8')) {
                return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
            }
        }
        return $data;
    }
}