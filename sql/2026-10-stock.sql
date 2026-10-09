-- Gestion du stock des pièces détachées
-- À exécuter une fois dans phpMyAdmin (base dockerDB).

-- NULL = stock non suivi : la pièce reste toujours disponible
ALTER TABLE `pieces_detachees`
  ADD COLUMN `stock` int(10) UNSIGNED NULL DEFAULT NULL AFTER `prix`;

-- 1 quand les pièces d'une commande annulée ont été remises en stock
ALTER TABLE `commandes`
  ADD COLUMN `stock_restitue` tinyint(1) NOT NULL DEFAULT 0 AFTER `statut`;
