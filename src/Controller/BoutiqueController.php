<?php

namespace App\Controller;

use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class BoutiqueController extends AbstractController
{
    #[Route('/boutique/pieces', name: 'app_boutique_pieces')]
    public function pieces(Contenu $contenu, Connection $connection, TranslatorInterface $translator): Response
    {
        $categories = $contenu->traduireTout($connection
            ->executeQuery('SELECT * FROM categories ORDER BY ordre ASC')
            ->fetchAllAssociative(), ['nom']);

        $sousCategories = $contenu->traduireTout($connection
            ->executeQuery('SELECT * FROM sous_categories ORDER BY ordre ASC')
            ->fetchAllAssociative(), ['nom']);

        $pieces = $connection
            ->executeQuery('
                SELECT p.*,
                       sc.nom AS sous_categorie, sc.nom_en AS sous_categorie_en, sc.nom_es AS sous_categorie_es,
                       c.nom AS categorie, c.nom_en AS categorie_en, c.nom_es AS categorie_es
                FROM pieces_detachees p
                INNER JOIN sous_categories sc ON p.sous_categorie_id = sc.id
                INNER JOIN categories c ON sc.categorie_id = c.id
                ORDER BY p.nom ASC
            ')
            ->fetchAllAssociative();
        $pieces = $contenu->traduireTout($pieces, ['nom', 'description', 'sous_categorie', 'categorie']);
        // tri alphabétique dans la langue de la page (les noms traduits n'ont pas le même ordre)
        $collator = new \Collator($contenu->langue());
        usort($pieces, fn (array $a, array $b) => $collator->compare($a['nom'], $b['nom']));

        // Construction de l'arborescence catégorie > sous-catégorie > pièces
        $piecesParSousCategorie = [];
        foreach ($pieces as $piece) {
            $piecesParSousCategorie[$piece['sous_categorie_id']][] = $piece;
        }

        $sousCategoriesParCategorie = [];
        foreach ($sousCategories as $sousCategorie) {
            $sousCategorie['pieces'] = $piecesParSousCategorie[$sousCategorie['id']] ?? [];
            $sousCategoriesParCategorie[$sousCategorie['categorie_id']][] = $sousCategorie;
        }

        foreach ($categories as &$categorie) {
            $categorie['sous_categories'] = $sousCategoriesParCategorie[$categorie['id']] ?? [];
            $categorie['nb_pieces'] = array_sum(array_map(
                fn (array $sousCategorie) => count($sousCategorie['pieces']),
                $categorie['sous_categories']
            ));
        }
        unset($categorie);

        return $this->render('boutique/pieces.html.twig', [
            'texte_presentation' => $contenu->texte('pieces_detachees', $translator->trans('boutique.texte_defaut')),
            'categories' => $categories,
            'pieces' => $pieces,
        ]);
    }
}
