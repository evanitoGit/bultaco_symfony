-- « Prévenez-moi quand elle revient » : alertes de retour en stock
-- À exécuter une fois dans phpMyAdmin (base dockerDB), après sql/2026-10-fiche-piece.sql.

CREATE TABLE `alertes_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `piece_id` int(11) NOT NULL,
  `email` varchar(180) NOT NULL,
  `langue` char(2) NOT NULL DEFAULT 'fr' COMMENT 'langue de l''e-mail envoyé',
  `jeton` char(32) NOT NULL COMMENT 'lien « Ne plus être prévenu »',
  `date_demande` datetime NOT NULL DEFAULT current_timestamp(),
  `date_envoi` datetime DEFAULT NULL COMMENT 'NULL = en attente',
  PRIMARY KEY (`id`),
  UNIQUE KEY `piece_email` (`piece_id`, `email`),
  UNIQUE KEY `jeton` (`jeton`),
  KEY `email` (`email`),
  CONSTRAINT `alertes_stock_ibfk_1` FOREIGN KEY (`piece_id`) REFERENCES `pieces_detachees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
