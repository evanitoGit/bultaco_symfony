<?php

namespace App\Admin;

use App\Commande\CommandeService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Description des contenus modifiables depuis l'administration.
 *
 * Chaque ressource correspond à une table de la base. Le contrôleur et le
 * formulaire d'administration sont génériques : pour rendre un nouveau champ
 * modifiable, il suffit de l'ajouter ici.
 *
 * Types de champs : text, textarea, integer, money, choice, relation, url, image, password.
 */
final class ResourceRegistry
{
    /** Pour la contrainte de route (doit lister toutes les clés de RESOURCES). */
    public const ROUTE_REQUIREMENT = 'textes|modeles|pilotes|restaurations|logos|pressbook|categories|sous-categories|pieces|commandes|clients|admins';

    /** Libellés lisibles des sections de la table `textes`, avec la page qui les affiche. */
    public const TEXT_SECTIONS = [
        'presentation' => ['label' => 'Accueil — présentation', 'route' => 'app_home'],
        'album_photos' => ['label' => 'Modèles — liste', 'route' => 'app_modeles_liste'],
        'restauration' => ['label' => 'Restaurations', 'route' => 'app_modeles_restaurations'],
        'pilotes_legende' => ['label' => 'Pilotes de légende', 'route' => 'app_modeles_pilotes'],
        'logos' => ['label' => 'Logos', 'route' => 'app_doc_logos'],
        'pressbook' => ['label' => 'Pressbook', 'route' => 'app_doc_pressbook'],
        'pieces_detachees' => ['label' => 'Pièces détachées', 'route' => 'app_boutique_pieces'],
    ];

    public const PRESS_TYPES = [
        'Article de presse' => 'article',
        'Magazine' => 'magazine',
        'Photographie' => 'photo',
        'Illustration' => 'illustration',
    ];

    private const RESOURCES = [
        'textes' => [
            'table' => 'textes',
            'group' => 'Contenu',
            'label' => 'Textes des pages',
            'singular' => 'un texte',
            'icon' => '📝',
            'description' => "Les textes d'introduction affichés en haut de chaque page.",
            'order' => 'id ASC',
            'title' => ['section'],
            'list' => [
                'section' => ['label' => 'Page', 'format' => 'section'],
                'contenu' => ['label' => 'Texte', 'format' => 'excerpt'],
                'date_modification' => ['label' => 'Modifié le', 'format' => 'date'],
            ],
            'fields' => [
                'section' => ['type' => 'choice', 'label' => 'Page concernée', 'required' => true, 'create_only' => true, 'choices' => 'text_sections'],
                'contenu' => ['type' => 'textarea', 'label' => 'Texte', 'required' => true, 'rows' => 8],
            ],
            'touch' => 'date_modification',
        ],
        'modeles' => [
            'table' => 'modeles_emblematiques',
            'group' => 'Modèles',
            'label' => 'Liste des modèles',
            'singular' => 'un modèle',
            'icon' => '🏍️',
            'public_route' => 'app_modeles_liste',
            'order' => 'ordre ASC, id ASC',
            'title' => ['nom'],
            'list' => [
                'image_path' => ['label' => 'Photo', 'format' => 'image'],
                'nom' => ['label' => 'Nom'],
                'type_moto' => ['label' => 'Type'],
                'annee_debut' => ['label' => 'Début'],
                'annee_fin' => ['label' => 'Fin'],
                'ordre' => ['label' => 'Ordre'],
            ],
            'fields' => [
                'nom' => ['type' => 'text', 'label' => 'Nom du modèle', 'required' => true, 'max' => 100],
                'type_moto' => ['type' => 'text', 'label' => 'Type (trial, cross, route…)', 'max' => 100],
                'cylindree' => ['type' => 'text', 'label' => 'Cylindrée', 'max' => 50, 'help' => 'Ex. : 250cc / 350cc'],
                'annee_debut' => ['type' => 'text', 'label' => 'Année de début', 'max' => 20],
                'annee_fin' => ['type' => 'text', 'label' => 'Année de fin', 'max' => 20],
                'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 5],
                'caracteristiques' => ['type' => 'textarea', 'label' => 'Caractéristiques techniques', 'rows' => 5, 'help' => 'Une caractéristique par ligne.'],
                'image_path' => ['type' => 'image', 'label' => 'Photo', 'dir' => 'modeles'],
                'ordre' => ['type' => 'integer', 'label' => "Ordre d'affichage", 'default' => 0, 'help' => 'Les plus petits nombres apparaissent en premier.'],
            ],
        ],
        'pilotes' => [
            'table' => 'pilotes_emblematiques',
            'group' => 'Modèles',
            'label' => 'Pilotes de légende',
            'singular' => 'un pilote',
            'icon' => '🏆',
            'public_route' => 'app_modeles_pilotes',
            'order' => 'ordre ASC, id ASC',
            'title' => ['prenom', 'nom'],
            'list' => [
                'image_path' => ['label' => 'Photo', 'format' => 'image'],
                'prenom' => ['label' => 'Prénom'],
                'nom' => ['label' => 'Nom'],
                'nationalite' => ['label' => 'Nationalité'],
                'ordre' => ['label' => 'Ordre'],
            ],
            'fields' => [
                'prenom' => ['type' => 'text', 'label' => 'Prénom', 'required' => true, 'max' => 100],
                'nom' => ['type' => 'text', 'label' => 'Nom', 'required' => true, 'max' => 100],
                'nationalite' => ['type' => 'text', 'label' => 'Nationalité', 'max' => 50],
                'date_naissance' => ['type' => 'text', 'label' => 'Date de naissance', 'max' => 50, 'help' => 'Texte libre, ex. : 24 mars 1930'],
                'date_deces' => ['type' => 'text', 'label' => 'Date de décès', 'max' => 50],
                'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 5],
                'palmares' => ['type' => 'textarea', 'label' => 'Palmarès', 'rows' => 5, 'help' => 'Un titre par ligne.'],
                'image_path' => ['type' => 'image', 'label' => 'Photo', 'dir' => 'pilotes'],
                'ordre' => ['type' => 'integer', 'label' => "Ordre d'affichage", 'default' => 0],
            ],
        ],
        'restaurations' => [
            'table' => 'restauration_photos',
            'group' => 'Modèles',
            'label' => 'Restaurations',
            'singular' => 'une photo',
            'icon' => '🔧',
            'public_route' => 'app_modeles_restaurations',
            'order' => 'ordre ASC, colonne ASC, id ASC',
            'title' => ['nom_modele'],
            'list' => [
                'image_path' => ['label' => 'Photo', 'format' => 'image'],
                'nom_modele' => ['label' => 'Légende'],
                'colonne' => ['label' => 'Colonne', 'format' => 'choice'],
                'ordre' => ['label' => 'Ordre'],
            ],
            'fields' => [
                'nom_modele' => ['type' => 'text', 'label' => 'Légende', 'required' => true, 'max' => 100, 'help' => 'Ex. : Bultaco Sherpa T10 - Avant'],
                'image_path' => ['type' => 'image', 'label' => 'Photo', 'dir' => 'restaurations', 'required' => true],
                'colonne' => ['type' => 'choice', 'label' => 'Colonne', 'required' => true, 'choices' => ['Gauche (avant)' => 'gauche', 'Droite (après)' => 'droite'], 'expanded' => true, 'default' => 'gauche'],
                'ordre' => ['type' => 'integer', 'label' => "Ordre d'affichage", 'default' => 0, 'help' => 'Une photo « avant » et sa photo « après » doivent avoir le même ordre.'],
            ],
        ],
        'logos' => [
            'table' => 'pressbook',
            'group' => 'Documentation',
            'label' => 'Logos',
            'singular' => 'un logo',
            'icon' => '🎨',
            'public_route' => 'app_doc_logos',
            'where' => "type_contenu = 'logo'",
            'fixed' => ['type_contenu' => 'logo'],
            'order' => 'date_publication DESC, id DESC',
            'title' => ['titre'],
            'list' => [
                'image_path' => ['label' => 'Image', 'format' => 'image'],
                'titre' => ['label' => 'Titre'],
                'date_publication' => ['label' => 'Période'],
                'source' => ['label' => 'Source'],
            ],
            'fields' => [
                'titre' => ['type' => 'text', 'label' => 'Titre', 'required' => true, 'max' => 200],
                'date_publication' => ['type' => 'text', 'label' => 'Période / date', 'max' => 50, 'help' => 'Ex. : 1958, années 70…'],
                'source' => ['type' => 'text', 'label' => 'Source', 'max' => 150],
                'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 4],
                'image_path' => ['type' => 'image', 'label' => 'Image du logo', 'dir' => 'logos', 'required' => true],
                'lien_externe' => ['type' => 'url', 'label' => 'Lien externe', 'max' => 255],
            ],
        ],
        'pressbook' => [
            'table' => 'pressbook',
            'group' => 'Documentation',
            'label' => 'Pressbook',
            'singular' => 'un document',
            'icon' => '📰',
            'public_route' => 'app_doc_pressbook',
            'where' => "type_contenu <> 'logo'",
            'order' => 'date_publication DESC, id DESC',
            'title' => ['titre'],
            'list' => [
                'image_path' => ['label' => 'Image', 'format' => 'image'],
                'titre' => ['label' => 'Titre'],
                'type_contenu' => ['label' => 'Type', 'format' => 'choice'],
                'date_publication' => ['label' => 'Date'],
                'source' => ['label' => 'Source'],
            ],
            'fields' => [
                'titre' => ['type' => 'text', 'label' => 'Titre', 'required' => true, 'max' => 200],
                'type_contenu' => ['type' => 'choice', 'label' => 'Type de document', 'required' => true, 'choices' => self::PRESS_TYPES, 'default' => 'article'],
                'date_publication' => ['type' => 'text', 'label' => 'Date de publication', 'max' => 50],
                'source' => ['type' => 'text', 'label' => 'Source', 'max' => 150, 'help' => 'Ex. : Moto Verte, Trial Classic…'],
                'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 4],
                'image_path' => ['type' => 'image', 'label' => 'Image', 'dir' => 'pressbook', 'required' => true],
                'lien_externe' => ['type' => 'url', 'label' => 'Lien vers la source originale', 'max' => 255],
            ],
        ],
        'categories' => [
            'table' => 'categories',
            'group' => 'Boutique',
            'label' => 'Catégories',
            'singular' => 'une catégorie',
            'icon' => '🗂️',
            'public_route' => 'app_boutique_pieces',
            'order' => 'ordre ASC, id ASC',
            'title' => ['nom'],
            'list_sql' => 'SELECT c.*, (SELECT COUNT(*) FROM sous_categories sc WHERE sc.categorie_id = c.id) AS nb_sous_categories FROM categories c ORDER BY c.ordre ASC, c.id ASC',
            'list' => [
                'nom' => ['label' => 'Nom'],
                'nb_sous_categories' => ['label' => 'Sous-catégories'],
                'ordre' => ['label' => 'Ordre'],
            ],
            'fields' => [
                'nom' => ['type' => 'text', 'label' => 'Nom', 'required' => true, 'max' => 100],
                'ordre' => ['type' => 'integer', 'label' => "Ordre d'affichage", 'default' => 0],
            ],
            'delete_warning' => 'Ses sous-catégories et toutes leurs pièces seront aussi supprimées.',
            // Images des pièces supprimées en cascade par la base
            'cascade_images' => 'SELECT p.image_path FROM pieces_detachees p INNER JOIN sous_categories sc ON sc.id = p.sous_categorie_id WHERE sc.categorie_id = :id',
            'cascade_sql' => [
                'DELETE FROM pieces_detachees WHERE sous_categorie_id IN (SELECT id FROM sous_categories WHERE categorie_id = :id)',
                'DELETE FROM sous_categories WHERE categorie_id = :id',
            ],
        ],
        'sous-categories' => [
            'table' => 'sous_categories',
            'group' => 'Boutique',
            'label' => 'Sous-catégories',
            'singular' => 'une sous-catégorie',
            'icon' => '📂',
            'public_route' => 'app_boutique_pieces',
            'order' => 'ordre ASC, id ASC',
            'title' => ['nom'],
            'list_sql' => 'SELECT sc.*, c.nom AS categorie_nom, (SELECT COUNT(*) FROM pieces_detachees p WHERE p.sous_categorie_id = sc.id) AS nb_pieces FROM sous_categories sc LEFT JOIN categories c ON c.id = sc.categorie_id ORDER BY c.ordre ASC, sc.ordre ASC, sc.id ASC',
            'list' => [
                'nom' => ['label' => 'Nom'],
                'categorie_nom' => ['label' => 'Catégorie'],
                'nb_pieces' => ['label' => 'Pièces'],
                'ordre' => ['label' => 'Ordre'],
            ],
            'fields' => [
                'nom' => ['type' => 'text', 'label' => 'Nom', 'required' => true, 'max' => 100],
                'categorie_id' => ['type' => 'relation', 'label' => 'Catégorie', 'required' => true, 'choices' => 'categories'],
                'ordre' => ['type' => 'integer', 'label' => "Ordre d'affichage", 'default' => 0],
            ],
            'delete_warning' => 'Toutes ses pièces seront aussi supprimées.',
            'cascade_images' => 'SELECT image_path FROM pieces_detachees WHERE sous_categorie_id = :id',
            'cascade_sql' => [
                'DELETE FROM pieces_detachees WHERE sous_categorie_id = :id',
            ],
        ],
        'pieces' => [
            'table' => 'pieces_detachees',
            'group' => 'Boutique',
            'label' => 'Pièces détachées',
            'singular' => 'une pièce',
            'icon' => '⚙️',
            'public_route' => 'app_boutique_pieces',
            'order' => 'nom ASC',
            'title' => ['nom'],
            'list_sql' => 'SELECT p.*, sc.nom AS sous_categorie_nom, c.nom AS categorie_nom FROM pieces_detachees p LEFT JOIN sous_categories sc ON sc.id = p.sous_categorie_id LEFT JOIN categories c ON c.id = sc.categorie_id ORDER BY c.ordre ASC, sc.ordre ASC, p.nom ASC',
            'list' => [
                'image_path' => ['label' => 'Photo', 'format' => 'image'],
                'nom' => ['label' => 'Nom'],
                'categorie_nom' => ['label' => 'Catégorie'],
                'sous_categorie_nom' => ['label' => 'Sous-catégorie'],
                'prix' => ['label' => 'Prix', 'format' => 'money'],
            ],
            'fields' => [
                'nom' => ['type' => 'text', 'label' => 'Nom de la pièce', 'required' => true, 'max' => 150],
                'sous_categorie_id' => ['type' => 'relation', 'label' => 'Catégorie', 'required' => true, 'choices' => 'sous_categories'],
                'prix' => ['type' => 'money', 'label' => 'Prix', 'required' => true],
                'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 4],
                'image_path' => ['type' => 'image', 'label' => 'Photo', 'dir' => 'pieces'],
            ],
        ],
        'commandes' => [
            'table' => 'commandes',
            'group' => 'Boutique',
            'label' => 'Commandes',
            'singular' => 'une commande',
            'icon' => '🧾',
            'can_create' => false, // les commandes viennent du site, pas de l'administration
            'description' => 'Commandes passées sur la boutique. Ouvrez une commande pour voir le client et changer son statut.',
            'order' => 'date_commande DESC',
            'title' => ['reference'],
            'list_sql' => "SELECT c.*, CONCAT(c.prenom, ' ', c.nom) AS client FROM commandes c ORDER BY c.date_commande DESC, c.id DESC",
            'list' => [
                'reference' => ['label' => 'Référence'],
                'date_commande' => ['label' => 'Date', 'format' => 'date'],
                'client' => ['label' => 'Client'],
                'total' => ['label' => 'Total', 'format' => 'money'],
                'statut' => ['label' => 'Statut', 'format' => 'choice'],
            ],
            'fields' => [
                'statut' => ['type' => 'choice', 'label' => 'Statut de la commande', 'required' => true, 'choices' => CommandeService::STATUTS],
            ],
            'detail_template' => 'admin/commandes/_detail.html.twig',
            'detail_sql' => 'SELECT * FROM commande_lignes WHERE commande_id = :id ORDER BY id',
            'cascade_sql' => ['DELETE FROM commande_lignes WHERE commande_id = :id'],
        ],
        'clients' => [
            'table' => 'clients',
            'group' => 'Boutique',
            'label' => 'Clients',
            'singular' => 'un client',
            'icon' => '👥',
            'can_create' => false, // les comptes sont créés par les clients eux-mêmes
            'description' => 'Comptes créés sur la boutique. Supprimer un client conserve ses commandes (sans compte).',
            'order' => 'date_creation DESC',
            'title' => ['prenom', 'nom'],
            'list_sql' => 'SELECT c.*, (SELECT COUNT(*) FROM commandes o WHERE o.client_id = c.id) AS nb_commandes FROM clients c ORDER BY c.date_creation DESC, c.id DESC',
            'list' => [
                'email' => ['label' => 'E-mail'],
                'prenom' => ['label' => 'Prénom'],
                'nom' => ['label' => 'Nom'],
                'ville' => ['label' => 'Ville'],
                'nb_commandes' => ['label' => 'Commandes'],
                'date_creation' => ['label' => 'Inscrit le', 'format' => 'date'],
            ],
            'fields' => [
                'prenom' => ['type' => 'text', 'label' => 'Prénom', 'required' => true, 'max' => 100],
                'nom' => ['type' => 'text', 'label' => 'Nom', 'required' => true, 'max' => 100],
                'telephone' => ['type' => 'text', 'label' => 'Téléphone', 'max' => 30],
                'adresse' => ['type' => 'text', 'label' => 'Adresse', 'max' => 255],
                'code_postal' => ['type' => 'text', 'label' => 'Code postal', 'max' => 10],
                'ville' => ['type' => 'text', 'label' => 'Ville', 'max' => 100],
                'pays' => ['type' => 'text', 'label' => 'Pays', 'max' => 100],
            ],
            'delete_warning' => 'Ses commandes sont conservées, mais ne seront plus rattachées à un compte.',
            'cascade_sql' => ['UPDATE commandes SET client_id = NULL WHERE client_id = :id'],
        ],
        'admins' => [
            'table' => 'admins',
            'group' => 'Réglages',
            'label' => 'Administrateurs',
            'singular' => 'un administrateur',
            'icon' => '🔑',
            'order' => 'username ASC',
            'title' => ['username'],
            'list' => [
                'username' => ['label' => 'Identifiant'],
            ],
            'fields' => [
                'username' => ['type' => 'text', 'label' => 'Identifiant', 'required' => true, 'max' => 50, 'unique' => true],
                'password' => ['type' => 'password', 'label' => 'Mot de passe'],
            ],
        ],
    ];

    /**
     * @return array<string, array> toutes les ressources, indexées par leur clé d'URL
     */
    public function all(): array
    {
        return self::RESOURCES;
    }

    /**
     * @return array<string, array<string, array>> ressources regroupées pour le menu
     */
    public function grouped(): array
    {
        $groups = [];
        foreach (self::RESOURCES as $key => $resource) {
            $groups[$resource['group']][$key] = $resource;
        }

        return $groups;
    }

    public function get(string $key): array
    {
        if (!isset(self::RESOURCES[$key])) {
            throw new NotFoundHttpException(sprintf('Ressource d\'administration inconnue : "%s".', $key));
        }

        return self::RESOURCES[$key] + ['key' => $key];
    }
}
