-- « Mon garage » (fonctionnalité en test) : les motos enregistrées par les clients dans leur compte,
-- et les e-mails « nouvelle pièce pour votre moto » déjà envoyés (un seul par client et par pièce).
-- À exécuter une fois dans phpMyAdmin (base dockerDB).

CREATE TABLE `client_motos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `modele_id` int(11) NOT NULL COMMENT 'modèle du menu « Ma moto » (modeles_moto)',
  `annee` smallint(5) unsigned DEFAULT NULL,
  `surnom` varchar(60) DEFAULT NULL COMMENT 'ex. « la rouge », « celle de papa »',
  `alertes` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'e-mail quand une pièce compatible est ajoutée',
  `date_ajout` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `modele_id` (`modele_id`),
  CONSTRAINT `client_motos_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_motos_ibfk_2` FOREIGN KEY (`modele_id`) REFERENCES `modeles_moto` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `garage_notifications` (
  `client_id` int(11) NOT NULL,
  `piece_id` int(11) NOT NULL,
  `date_envoi` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`, `piece_id`),
  KEY `piece_id` (`piece_id`),
  CONSTRAINT `garage_notifications_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `garage_notifications_ibfk_2` FOREIGN KEY (`piece_id`) REFERENCES `pieces_detachees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
