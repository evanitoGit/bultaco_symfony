-- Pages des pièces, compatibilité pièce / modèle de moto et photos supplémentaires
-- À exécuter une fois dans phpMyAdmin (base dockerDB), après sql/2026-10-traductions.sql.

-- ---------------------------------------------------------------------------
-- Modèles de motos (liste de référence pour la compatibilité des pièces)
-- Séparée de l'album : on y met toutes les variantes (Sherpa T 250, 350…).
-- ---------------------------------------------------------------------------
CREATE TABLE `modeles_moto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `cylindree` varchar(50) DEFAULT NULL,
  `annees` varchar(50) DEFAULT NULL,
  `modele_album_id` int(11) DEFAULT NULL COMMENT 'fiche du même modèle dans l''album (facultatif)',
  `ordre` int(11) NOT NULL DEFAULT 0,
  `date_ajout` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`),
  KEY `modele_album_id` (`modele_album_id`),
  CONSTRAINT `modeles_moto_ibfk_1` FOREIGN KEY (`modele_album_id`) REFERENCES `modeles_emblematiques` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pièce <-> modèles compatibles
CREATE TABLE `piece_modeles` (
  `piece_id` int(11) NOT NULL,
  `modele_id` int(11) NOT NULL,
  PRIMARY KEY (`piece_id`, `modele_id`),
  KEY `modele_id` (`modele_id`),
  CONSTRAINT `piece_modeles_ibfk_1` FOREIGN KEY (`piece_id`) REFERENCES `pieces_detachees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `piece_modeles_ibfk_2` FOREIGN KEY (`modele_id`) REFERENCES `modeles_moto` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Photos supplémentaires d'une pièce (la photo principale reste pieces_detachees.image_path)
CREATE TABLE `piece_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `piece_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `ordre` int(11) NOT NULL DEFAULT 0,
  `date_ajout` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `piece_id` (`piece_id`),
  CONSTRAINT `piece_photos_ibfk_1` FOREIGN KEY (`piece_id`) REFERENCES `pieces_detachees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Nouveaux champs des pièces
-- ---------------------------------------------------------------------------
ALTER TABLE `pieces_detachees`
  ADD COLUMN `reference_origine` varchar(50) NULL DEFAULT NULL COMMENT 'numéro de pièce Bultaco' AFTER `nom_es`,
  ADD COLUMN `universelle` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = compatible avec toutes les motos' AFTER `stock`,
  ADD COLUMN `description_longue` text NULL DEFAULT NULL AFTER `description_es`,
  ADD COLUMN `description_longue_en` text NULL DEFAULT NULL AFTER `description_longue`,
  ADD COLUMN `description_longue_es` text NULL DEFAULT NULL AFTER `description_longue_en`;

-- ---------------------------------------------------------------------------
-- Premiers modèles (à compléter dans l'administration : cylindrée, années…)
-- ---------------------------------------------------------------------------
INSERT INTO `modeles_moto` (`nom`, `ordre`) VALUES
  ('Sherpa T 250', 10),
  ('Sherpa T 325', 11),
  ('Sherpa T 350', 12),
  ('Pursang 125', 20),
  ('Pursang 250', 21),
  ('Pursang 360', 22),
  ('Matador 250', 30),
  ('Frontera 250', 40),
  ('Frontera 370', 41),
  ('Alpina 250', 50),
  ('Alpina 350', 51),
  ('Metralla 250', 60),
  ('Mercurio 155', 70),
  ('Lobito 125', 80);

-- Les modèles de l'album, reliés à leur fiche (cylindrée et années reprises de l'album)
INSERT IGNORE INTO `modeles_moto` (`nom`, `cylindree`, `annees`, `modele_album_id`, `ordre`)
  SELECT `nom`, `cylindree`, NULLIF(CONCAT_WS('–', NULLIF(`annee_debut`, ''), NULLIF(`annee_fin`, '')), ''), `id`, 100 + `ordre`
  FROM `modeles_emblematiques`;
