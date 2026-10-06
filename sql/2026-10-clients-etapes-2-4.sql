-- Comptes clients, étapes 2 à 4 (à exécuter une fois, après sql/2026-10-clients.sql)

-- Demandes de réinitialisation du mot de passe (« mot de passe oublié »).
-- Seule une empreinte du jeton est stockée : le lien envoyé par e-mail ne peut pas
-- être reconstitué à partir de la base.
CREATE TABLE `client_reinitialisations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `selecteur` varchar(32) NOT NULL,
  `verificateur_hash` varchar(128) NOT NULL,
  `date_demande` datetime NOT NULL,
  `date_expiration` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selecteur` (`selecteur`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `client_reinitialisations_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Panier sauvegardé des clients connectés (retrouvé d'un appareil à l'autre)
CREATE TABLE `client_panier` (
  `client_id` int(11) NOT NULL,
  `piece_id` int(11) NOT NULL,
  `quantite` int(11) NOT NULL,
  `date_ajout` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`, `piece_id`),
  KEY `piece_id` (`piece_id`),
  CONSTRAINT `client_panier_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_panier_ibfk_2` FOREIGN KEY (`piece_id`) REFERENCES `pieces_detachees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
