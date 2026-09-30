<?php
/**
 * Schéma de base de données — ISPAG Tank Builder
 *
 * Généré à partir de la structure de production (export phpMyAdmin du 2026-09-29).
 * Chaque entrée est un CREATE TABLE IF NOT EXISTS : exécuté sans risque sur un site existant
 * (rien n'est modifié si la table existe déjà). {prefix} = $wpdb->prefix, {charset} = $wpdb->get_charset_collate().
 * Les tables sont classées pour que les clés étrangères pointent vers des tables déjà créées.
 */
defined('ABSPATH') || exit;

return [
    'achats_flange_dimensions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_flange_dimensions` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `DN` text NOT NULL,
  `InternalDiamter` decimal(10,0) NOT NULL,
  `ExternalDiameter` decimal(10,0) NOT NULL,
  `Thickness` decimal(10,0) NOT NULL,
  `Drilling` decimal(10,0) NOT NULL,
  `NbDrilling` decimal(10,0) NOT NULL,
  `Typ` text NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_plate_exchanger_datas' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_plate_exchanger_datas` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL COMMENT 'Lien vers wor9711_achats_details_commande',
  `type` varchar(50) DEFAULT 'brazed',
  `power` decimal(10,2) DEFAULT NULL COMMENT 'Puissance en kW',
  `primary_temp_in` decimal(5,2) DEFAULT NULL,
  `primary_temp_out` decimal(5,2) DEFAULT NULL,
  `primary_pressure_drop` decimal(10,2) DEFAULT NULL COMMENT 'Pertes de charge kPa',
  `primary_pressure` decimal(5,2) DEFAULT NULL COMMENT 'Pression primaire bar',
  `primary_fluid` varchar(100) DEFAULT 'water',
  `secondary_temp_in` decimal(5,2) DEFAULT NULL,
  `secondary_temp_out` decimal(5,2) DEFAULT NULL,
  `secondary_pressure_drop` decimal(10,2) DEFAULT NULL COMMENT 'Pertes de charge kPa',
  `secondary_pressure` decimal(5,2) DEFAULT NULL COMMENT 'Pression secondaire bar',
  `secondary_fluid` varchar(100) DEFAULT 'water',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  KEY `article_id` (`article_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_tank_rules' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_tank_rules` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) NOT NULL COMMENT 'typ, material ou insulation',
  `scope_id` int NOT NULL COMMENT 'Id dans achats_tank_conception',
  `kind` varchar(10) NOT NULL COMMENT 'allowed ou default',
  `field` varchar(30) NOT NULL,
  `value` varchar(100) NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`Id`),
  KEY `scope_lookup` (`scope`,`scope_id`,`kind`,`field`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_tank_conception' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_tank_conception` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `SelectType` text NOT NULL,
  `articleId` int NOT NULL,
  `Value` text NOT NULL,
  `is_supplier_delivery` tinyint(1) NOT NULL,
  `matiere` text NOT NULL,
  `image` int NOT NULL,
  `sort` int NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_tank_connection' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_tank_connection` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `TankId` int NOT NULL,
  `Type` int NOT NULL,
  `Pouces` text,
  `Height` int NOT NULL,
  `heightApproved` tinyint(1) NOT NULL DEFAULT '0',
  `Angle` int NOT NULL,
  `Accessories` int NOT NULL,
  `madeFor` text NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_tank_dimensions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_tank_dimensions` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` int NOT NULL,
  `customerTankId` int DEFAULT NULL,
  `TankType` int NOT NULL DEFAULT '1',
  `Material` int NOT NULL DEFAULT '1',
  `Volume` int NOT NULL DEFAULT '1',
  `Diameter` int NOT NULL DEFAULT '1',
  `Height` int NOT NULL DEFAULT '1',
  `FeetHeight` int DEFAULT '200',
  `GroundClearance` int NOT NULL,
  `BottomHeight` int NOT NULL,
  `weldingByClient` int DEFAULT NULL,
  `welding_infos` tinyint(1) DEFAULT '0',
  `MaxPressure` decimal(10,2) DEFAULT NULL,
  `TestPressure` decimal(10,2) DEFAULT NULL,
  `usingTemperature` text NOT NULL,
  `Support` int DEFAULT NULL,
  `InsulationThickness` int NOT NULL,
  `insulation` int DEFAULT NULL,
  `insulationCover` int DEFAULT NULL,
  `openComment` text NOT NULL,
  `userId` int DEFAULT NULL,
  `creation_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_tank_heat_exchanger' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_tank_heat_exchanger` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `tank_id` int NOT NULL,
  `heatExchangerSurface` decimal(10,2) NOT NULL,
  `coilDetails` json NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_valves' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_valves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `deal_id` bigint NOT NULL,
  `page` int DEFAULT NULL,
  `titre` varchar(255) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT 'M.P. Welding SA',
  `type` varchar(255) DEFAULT NULL,
  `marque` varchar(255) DEFAULT NULL,
  `technical_data` text,
  `quantity` int DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `deal_id_index` (`deal_id`),
  KEY `supplier_index` (`supplier`),
  KEY `type_index` (`type`),
  KEY `marque_index` (`marque`)
) ENGINE=InnoDB {charset}
SQL
    ,
];
