-- Site en trois langues (français, anglais, espagnol)
-- À exécuter une fois dans phpMyAdmin (base dockerDB), après sql/2026-10-stock.sql.
--
-- 1. Colonnes de traduction (_en, _es) pour le contenu saisi dans l'administration.
--    Une traduction vide (NULL) affiche le texte français.
-- 2. Langue des clients et des commandes (e-mails dans la langue du client).
-- 3. Traductions du contenu actuel. Chaque traduction n'est appliquée que si le texte
--    français n'a pas changé et si aucune traduction n'a encore été saisie.

-- ---------------------------------------------------------------------------
-- 1. Colonnes de traduction
-- ---------------------------------------------------------------------------

ALTER TABLE `textes`
  ADD COLUMN `contenu_en` text NULL DEFAULT NULL AFTER `contenu`,
  ADD COLUMN `contenu_es` text NULL DEFAULT NULL AFTER `contenu_en`;

ALTER TABLE `categories`
  ADD COLUMN `nom_en` varchar(100) NULL DEFAULT NULL AFTER `nom`,
  ADD COLUMN `nom_es` varchar(100) NULL DEFAULT NULL AFTER `nom_en`;

ALTER TABLE `sous_categories`
  ADD COLUMN `nom_en` varchar(100) NULL DEFAULT NULL AFTER `nom`,
  ADD COLUMN `nom_es` varchar(100) NULL DEFAULT NULL AFTER `nom_en`;

ALTER TABLE `pieces_detachees`
  ADD COLUMN `nom_en` varchar(150) NULL DEFAULT NULL AFTER `nom`,
  ADD COLUMN `nom_es` varchar(150) NULL DEFAULT NULL AFTER `nom_en`,
  ADD COLUMN `description_en` text NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `description_es` text NULL DEFAULT NULL AFTER `description_en`;

ALTER TABLE `modeles_emblematiques`
  ADD COLUMN `type_moto_en` varchar(100) NULL DEFAULT NULL AFTER `type_moto`,
  ADD COLUMN `type_moto_es` varchar(100) NULL DEFAULT NULL AFTER `type_moto_en`,
  ADD COLUMN `description_en` text NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `description_es` text NULL DEFAULT NULL AFTER `description_en`,
  ADD COLUMN `caracteristiques_en` text NULL DEFAULT NULL AFTER `caracteristiques`,
  ADD COLUMN `caracteristiques_es` text NULL DEFAULT NULL AFTER `caracteristiques_en`;

ALTER TABLE `pilotes_emblematiques`
  ADD COLUMN `date_naissance_en` varchar(50) NULL DEFAULT NULL AFTER `date_naissance`,
  ADD COLUMN `date_naissance_es` varchar(50) NULL DEFAULT NULL AFTER `date_naissance_en`,
  ADD COLUMN `date_deces_en` varchar(50) NULL DEFAULT NULL AFTER `date_deces`,
  ADD COLUMN `date_deces_es` varchar(50) NULL DEFAULT NULL AFTER `date_deces_en`,
  ADD COLUMN `nationalite_en` varchar(50) NULL DEFAULT NULL AFTER `nationalite`,
  ADD COLUMN `nationalite_es` varchar(50) NULL DEFAULT NULL AFTER `nationalite_en`,
  ADD COLUMN `description_en` text NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `description_es` text NULL DEFAULT NULL AFTER `description_en`,
  ADD COLUMN `palmares_en` text NULL DEFAULT NULL AFTER `palmares`,
  ADD COLUMN `palmares_es` text NULL DEFAULT NULL AFTER `palmares_en`;

-- pressbook : documents de presse et logos
ALTER TABLE `pressbook`
  ADD COLUMN `titre_en` varchar(200) NULL DEFAULT NULL AFTER `titre`,
  ADD COLUMN `titre_es` varchar(200) NULL DEFAULT NULL AFTER `titre_en`,
  ADD COLUMN `date_publication_en` varchar(50) NULL DEFAULT NULL AFTER `date_publication`,
  ADD COLUMN `date_publication_es` varchar(50) NULL DEFAULT NULL AFTER `date_publication_en`,
  ADD COLUMN `description_en` text NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `description_es` text NULL DEFAULT NULL AFTER `description_en`;

ALTER TABLE `restauration_photos`
  ADD COLUMN `nom_modele_en` varchar(100) NULL DEFAULT NULL AFTER `nom_modele`,
  ADD COLUMN `nom_modele_es` varchar(100) NULL DEFAULT NULL AFTER `nom_modele_en`;

-- ---------------------------------------------------------------------------
-- 2. Langue des clients et des commandes
-- ---------------------------------------------------------------------------

ALTER TABLE `clients`
  ADD COLUMN `langue` char(2) NOT NULL DEFAULT 'fr' AFTER `pays`;

ALTER TABLE `commandes`
  ADD COLUMN `langue` char(2) NOT NULL DEFAULT 'fr' AFTER `commentaire`;

-- ---------------------------------------------------------------------------
-- 3. Traductions du contenu actuel
-- ---------------------------------------------------------------------------

-- Textes des pages
UPDATE `textes` SET
  `contenu_en` = 'Welcome to the Club Bultaco Trial Classic website! Come and discover our restorations, spare parts, photos and press book. M. Escuyer will finalise this introduction to make visitors want to explore the site.',
  `contenu_es` = '¡Bienvenido a la web del Club Bultaco Trial Classic! Descubra nuestras restauraciones, recambios, fotos y pressbook. El Sr. Escuyer completará este texto de presentación para animar a los visitantes a explorar el sitio.'
  WHERE `section` = 'presentation' AND `contenu_en` IS NULL
    AND `contenu` = 'Bienvenue sur le site du Club Bultaco Trial Classic ! Venez découvrir nos restaurations, pièces détachées, photos, et pressbook et M. Escuyer finalisera ce texte de présentation pour donner envie aux gens d\'explorer ce site.';

UPDATE `textes` SET
  `contenu_en` = 'Discover our Bultaco motorcycle restorations. Each project is carried out with passion and precision to bring these legendary machines back to life.',
  `contenu_es` = 'Descubra nuestras restauraciones de motos Bultaco. Cada proyecto se realiza con pasión y precisión para devolver la vida a estas máquinas legendarias.'
  WHERE `section` = 'restauration' AND `contenu_en` IS NULL
    AND TRIM(`contenu`) = 'Découvrez nos restaurations de motos Bultaco. Chaque projet est réalisé avec passion et précision pour redonner vie à ces machines légendaires.';

UPDATE `textes` SET
  `contenu_en` = 'Find all the original spare parts for your Bultaco motorcycles. Our stock is regularly updated with authentic, quality parts.',
  `contenu_es` = 'Encuentre todos los recambios originales para sus motos Bultaco. Nuestro stock se actualiza con regularidad con piezas auténticas y de calidad.'
  WHERE `section` = 'pieces_detachees' AND `contenu_en` IS NULL
    AND `contenu` = 'Retrouvez toutes les pièces détachées d\'origine pour vos motos Bultaco. Notre stock est régulièrement mis à jour avec des pièces authentiques et de qualité.';

UPDATE `textes` SET
  `contenu_en` = 'Discover the legendary riders and iconic models that made Bultaco famous all over the world.',
  `contenu_es` = 'Descubra los pilotos legendarios y los modelos míticos que dieron fama a Bultaco en todo el mundo.'
  WHERE `section` = 'emblemes' AND `contenu_en` IS NULL
    AND `contenu` = 'Découvrez les pilotes légendaires et les modèles mythiques qui ont fait la renommée de Bultaco dans le monde entier.';

UPDATE `textes` SET
  `contenu_en` = 'Discover Bultaco through the years: press articles, magazine covers, historic photos and illustrations that have shaped the history of the brand!',
  `contenu_es` = '¡Descubra Bultaco a lo largo de los años: artículos de prensa, portadas de revistas, fotos históricas e ilustraciones que han marcado la historia de la marca!'
  WHERE `section` = 'pressbook' AND `contenu_en` IS NULL
    AND `contenu` = 'Découvrez Bultaco à travers les années : articles de presse, couvertures de magazines, photos historiques et illustrations qui ont marqué l\'histoire de la marque !';

UPDATE `textes` SET
  `contenu_en` = 'Discover our collection of iconic Bultaco motorcycles through photos and detailed technical sheets.',
  `contenu_es` = 'Descubra nuestra colección de motos Bultaco emblemáticas a través de fotos y fichas técnicas detalladas.'
  WHERE `section` = 'album_photos' AND `contenu_en` IS NULL
    AND `contenu` = 'Découvrez notre collection de motos Bultaco emblématiques à travers des photos et fiches techniques détaillées.';

UPDATE `textes` SET
  `contenu_en` = 'Discover the legendary riders who shaped Bultaco history and wrote the finest pages of trial and motocross.',
  `contenu_es` = 'Descubra los pilotos legendarios que marcaron la historia de Bultaco y escribieron las páginas más bellas del trial y del motocross.'
  WHERE `section` = 'pilotes_legende' AND `contenu_en` IS NULL
    AND `contenu` = 'Découvrez les pilotes légendaires qui ont marqué l\'histoire de Bultaco et écrit les plus belles pages du trial et du motocross.';

UPDATE `textes` SET
  `contenu_en` = 'Discover how the Bultaco logos have evolved over the decades. From the original emblem to modern variations, each logo tells part of the brand\'s story.',
  `contenu_es` = 'Descubra la evolución de los logotipos de Bultaco a lo largo de las décadas. Del emblema original a las variantes modernas, cada logotipo cuenta una parte de la historia de la marca.'
  WHERE `section` = 'logos' AND `contenu_en` IS NULL
    AND `contenu` = 'Découvrez l\'évolution des logos Bultaco à travers les décennies. De l\'emblème original aux variations modernes, chaque logo raconte une partie de l\'histoire de la marque.';

UPDATE `textes` SET
  `contenu_en` = 'Discover the legendary riders who shaped Bultaco history.',
  `contenu_es` = 'Descubra los pilotos de leyenda que marcaron la historia de Bultaco.'
  WHERE `section` = 'pilotes' AND `contenu_en` IS NULL
    AND `contenu` = 'Découvrez les pilotes de légende qui ont marqué l\'histoire de Bultaco.';

-- Catégories et sous-catégories de pièces
UPDATE `categories` SET `nom_en` = 'Engine', `nom_es` = 'Motor' WHERE `nom` = 'Moteur' AND `nom_en` IS NULL;
UPDATE `categories` SET `nom_en` = 'Transmission', `nom_es` = 'Transmisión' WHERE `nom` = 'Transmission' AND `nom_en` IS NULL;
UPDATE `categories` SET `nom_en` = 'Brakes', `nom_es` = 'Frenos' WHERE `nom` = 'Freinage' AND `nom_en` IS NULL;
UPDATE `categories` SET `nom_en` = 'Electrics', `nom_es` = 'Electricidad' WHERE `nom` = 'Électricité' AND `nom_en` IS NULL;
UPDATE `categories` SET `nom_en` = 'Chassis', `nom_es` = 'Parte ciclo' WHERE `nom` = 'Partie-cycle' AND `nom_en` IS NULL;

UPDATE `sous_categories` SET `nom_en` = 'Cylinder & piston', `nom_es` = 'Cilindro y pistón' WHERE `nom` = 'Cylindre et piston' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Crankshaft', `nom_es` = 'Cigüeñal' WHERE `nom` = 'Vilebrequin' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Carburettor', `nom_es` = 'Carburador' WHERE `nom` = 'Carburateur' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Clutch', `nom_es` = 'Embrague' WHERE `nom` = 'Embrayage' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Gearbox', `nom_es` = 'Caja de cambios' WHERE `nom` = 'Boîte de vitesses' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Chain', `nom_es` = 'Cadena' WHERE `nom` = 'Chaîne' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Brake discs', `nom_es` = 'Discos de freno' WHERE `nom` = 'Disques de frein' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Brake pads', `nom_es` = 'Pastillas de freno' WHERE `nom` = 'Plaquettes' AND `nom_en` IS NULL;
UPDATE `sous_categories` SET `nom_en` = 'Cables', `nom_es` = 'Cables' WHERE `nom` = 'Câbles' AND `nom_en` IS NULL;

-- Pièces détachées
UPDATE `pieces_detachees` SET
  `nom_en` = 'Sherpa T 50mm piston', `nom_es` = 'Pistón 50 mm Sherpa T',
  `description_en` = 'Original piston for the Bultaco Sherpa T 350cc', `description_es` = 'Pistón original para Bultaco Sherpa T 350 cc'
  WHERE `nom` = 'Piston 50mm Sherpa T' AND `nom_en` IS NULL;
UPDATE `pieces_detachees` SET
  `nom_en` = 'Pursang cylinder head gasket', `nom_es` = 'Junta de culata Pursang',
  `description_en` = 'High-quality cylinder head gasket for the Pursang', `description_es` = 'Junta de culata de alta calidad para Pursang'
  WHERE `nom` = 'Joint de culasse Pursang' AND `nom_en` IS NULL;
UPDATE `pieces_detachees` SET
  `nom_en` = 'Bul crankshaft', `nom_es` = 'Cigüeñal Bul',
  `description_en` = 'Bul crankshaft', `description_es` = 'Cigüeñal Bul'
  WHERE `nom` = 'Vilbrequin Bul' AND `nom_en` IS NULL;

-- Modèles
UPDATE `modeles_emblematiques` SET
  `type_moto_en` = 'Trial', `type_moto_es` = 'Trial',
  `description_en` = 'I don\'t know its history, but it must be fascinating!',
  `description_es` = 'No conozco su historia, ¡pero seguro que es apasionante!',
  `caracteristiques_en` = 'Weight: 90 kg\r\nPower: 25 hp',
  `caracteristiques_es` = 'Peso: 90 kg\r\nPotencia: 25 CV'
  WHERE `nom` = 'Matador MK3' AND `description_en` IS NULL;

-- Pilotes
UPDATE `pilotes_emblematiques` SET
  `date_naissance_en` = '24 March 1930', `date_naissance_es` = '24 de marzo de 1930',
  `date_deces_en` = '7 November 1980', `date_deces_es` = '7 de noviembre de 1980',
  `nationalite_en` = 'American', `nationalite_es` = 'Estadounidense',
  `description_en` = 'American actor, passionate motorcyclist and rider',
  `description_es` = 'Actor estadounidense, apasionado de las motos y piloto'
  WHERE `nom` = 'McQueen' AND `prenom` = 'Steve' AND `description_en` IS NULL;

-- Pressbook et logos
UPDATE `pressbook` SET `description_en` = 'A handsome one', `description_es` = 'Todo un galán'
  WHERE `titre` = 'Don Paco' AND `description` = 'Un beau gosse' AND `description_en` IS NULL;

-- Restaurations : « Avant » / « Après » dans les légendes
UPDATE `restauration_photos` SET
  `nom_modele_en` = REPLACE(REPLACE(`nom_modele`, ' - Avant', ' - Before'), ' - Après', ' - After'),
  `nom_modele_es` = REPLACE(REPLACE(`nom_modele`, ' - Avant', ' - Antes'), ' - Après', ' - Después')
  WHERE `nom_modele_en` IS NULL AND (`nom_modele` LIKE '% - Avant' OR `nom_modele` LIKE '% - Après');
