-- Réglages du site modifiables depuis l'administration (clé / valeur)
-- dont le mode de la boutique : vente en ligne ou vitrine (consultation seulement)
CREATE TABLE reglages (
    cle VARCHAR(50) NOT NULL PRIMARY KEY,
    valeur TEXT NULL,
    date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reglages (cle, valeur) VALUES
    ('boutique_mode', 'vente'),   -- 'vente' ou 'vitrine'
    ('vitrine_prix', '1');        -- en vitrine : 1 = prix affichés, 0 = masqués
