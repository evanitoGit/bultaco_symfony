<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BoutiqueController extends AbstractController
{
    #[Route('/boutique/pieces', name: 'app_boutique_pieces')]
    public function pieces(TexteRepository $texteRepository, Connection $connection): Response
    {
        $texte = $texteRepository->findOneBy(['section' => 'pieces_detachees']);

        $categories = $connection
            ->executeQuery('SELECT * FROM categories ORDER BY ordre ASC')
            ->fetchAllAssociative();

        $sousCategories = $connection
            ->executeQuery('SELECT * FROM sous_categories ORDER BY ordre ASC')
            ->fetchAllAssociative();

        $pieces = $connection
            ->executeQuery('
                SELECT p.*, sc.nom AS sous_categorie, c.nom AS categorie
                FROM pieces_detachees p
                INNER JOIN sous_categories sc ON p.sous_categorie_id = sc.id
                INNER JOIN categories c ON sc.categorie_id = c.id
                ORDER BY p.nom ASC
            ')
            ->fetchAllAssociative();

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
            'texte_presentation' => $texte ? $texte->getContenu() : 'Retrouvez notre catalogue de pièces détachées pour motos Bultaco.',
            'categories' => $categories,
            'pieces' => $pieces,
        ]);
    }
}
