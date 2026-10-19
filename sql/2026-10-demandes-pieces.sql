-- « Vous ne trouvez pas votre pièce ? » : demandes de pièces introuvables
-- À exécuter une fois dans phpMyAdmin (base dockerDB), après sql/2026-10-alerte-stock.sql.

CREATE TABLE `demandes_pieces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL COMMENT 'compte client, si la personne était connectée',
  `nom` varchar(150) NOT NULL,
  `email` varchar(180) NOT NULL,
  `telephone` varchar(30) DEFAULT NULL,
  `modele_id` int(11) DEFAULT NULL COMMENT 'modèle de moto choisi dans la liste',
  `modele_autre` varchar(100) DEFAULT NULL COMMENT 'modèle saisi à la main (« Autre »)',
  `annee` varchar(20) DEFAULT NULL,
  `description` text NOT NULL,
  `photos` text DEFAULT NULL COMMENT 'liste JSON des photos (dossier var/demandes/, non public)',
  `langue` char(2) NOT NULL DEFAULT 'fr',
  `statut` enum('nouvelle','en_cours','trouvee','introuvable') NOT NULL DEFAULT 'nouvelle',
  `reponse` text DEFAULT NULL COMMENT 'dernière réponse envoyée au client',
  `date_reponse` datetime DEFAULT NULL,
  `date_demande` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `email` (`email`),
  KEY `statut` (`statut`),
  CONSTRAINT `demandes_pieces_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demandes_pieces_ibfk_2` FOREIGN KEY (`modele_id`) REFERENCES `modeles_moto` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
