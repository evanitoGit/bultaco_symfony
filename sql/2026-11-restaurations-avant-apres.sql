-- Restaurations « avant / après » : une ligne par restauration, avec ses deux photos
-- (remplace l'association des photos de restauration_photos par leur numéro d'ordre)

CREATE TABLE restaurations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(150) NOT NULL,
    titre_en VARCHAR(150) NULL,
    titre_es VARCHAR(150) NULL,
    photo_avant VARCHAR(255) NULL,
    photo_apres VARCHAR(255) NULL,
    ordre INT NOT NULL DEFAULT 0,
    date_ajout TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversion des photos existantes : dans chaque numéro d'ordre, la 1re photo « gauche (avant) »
-- va avec la 1re photo « droite (après) », la 2e avec la 2e, etc.
-- Le titre reprend la légende sans « - Avant » / « - Après » (et leurs traductions).
-- Une photo sans partenaire devient une restauration avec une seule photo : à compléter dans l'administration.
INSERT INTO restaurations (titre, titre_en, titre_es, photo_avant, photo_apres, ordre)
SELECT
    TRIM(REGEXP_REPLACE(COALESCE(a.nom_modele, p.nom_modele), '(?i)[[:space:]]*[-–—:][[:space:]]*(avant|après|apres|before|after|antes|después|despues)[[:space:]]*$', '')),
    NULLIF(TRIM(REGEXP_REPLACE(COALESCE(a.nom_modele_en, p.nom_modele_en, ''), '(?i)[[:space:]]*[-–—:][[:space:]]*(avant|après|apres|before|after|antes|después|despues)[[:space:]]*$', '')), ''),
    NULLIF(TRIM(REGEXP_REPLACE(COALESCE(a.nom_modele_es, p.nom_modele_es, ''), '(?i)[[:space:]]*[-–—:][[:space:]]*(avant|après|apres|before|after|antes|después|despues)[[:space:]]*$', '')), ''),
    a.image_path,
    p.image_path,
    COALESCE(c.ordre, 0)
FROM (
    SELECT DISTINCT ordre, rang
    FROM (SELECT ordre, ROW_NUMBER() OVER (PARTITION BY ordre, colonne ORDER BY id) AS rang FROM restauration_photos) numerotees
) c
LEFT JOIN (SELECT rp.*, ROW_NUMBER() OVER (PARTITION BY ordre, colonne ORDER BY id) AS rang FROM restauration_photos rp) a
    ON a.colonne = 'gauche' AND a.ordre <=> c.ordre AND a.rang = c.rang
LEFT JOIN (SELECT rp.*, ROW_NUMBER() OVER (PARTITION BY ordre, colonne ORDER BY id) AS rang FROM restauration_photos rp) p
    ON p.colonne = 'droite' AND p.ordre <=> c.ordre AND p.rang = c.rang
ORDER BY c.ordre, c.rang;

-- Vérifiez le résultat :
--   SELECT * FROM restaurations ORDER BY ordre, id;
-- L'ancienne table n'est plus utilisée par le site. Une fois le résultat vérifié, vous pouvez la supprimer
-- (les fichiers des photos sont conservés, ils servent à la nouvelle table) :
--   DROP TABLE restauration_photos;
