<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ModeleController extends AbstractController
{
    #[Route('/modeles/liste', name: 'app_modeles_liste')]
    public function liste(TexteRepository $texteRepository, Connection $connection): Response
    {
        $texte = $texteRepository->findOneBy(['section' => 'album_photos']);

        // Pas encore d'entité pour cette table : requête SQL directe via DBAL
        $modeles = $connection
            ->executeQuery('SELECT * FROM modeles_emblematiques ORDER BY ordre ASC')
            ->fetchAllAssociative();

        return $this->render('modele/liste.html.twig', [
            'texte_presentation' => $texte ? $texte->getContenu() : 'Découvrez notre collection de motos Bultaco emblématiques à travers des photos et fiches techniques détaillées.',
            'modeles' => $modeles,
        ]);
    }

    #[Route('/modeles/restaurations', name: 'app_modeles_restaurations')]
    public function restaurations(TexteRepository $texteRepository, Connection $connection): Response
    {
        $texte = $texteRepository->findOneBy(['section' => 'restauration']);

        $photos = $connection
            ->executeQuery('SELECT * FROM restauration_photos ORDER BY ordre ASC')
            ->fetchAllAssociative();

        // Répartition des photos dans les deux colonnes définies en base
        $colonnes = ['gauche' => [], 'droite' => []];
        foreach ($photos as $photo) {
            $colonnes[$photo['colonne'] === 'droite' ? 'droite' : 'gauche'][] = $photo;
        }

        return $this->render('modele/restaurations.html.twig', [
            'texte_presentation' => $texte ? $texte->getContenu() : 'Découvrez quelques exemples de restaurations de motos Bultaco réalisées par les membres du club.',
            'colonnes' => $colonnes,
            'photos' => $photos,
        ]);
    }

    #[Route('/modeles/pilotes', name: 'app_modeles_pilotes')]
    public function pilotes(TexteRepository $texteRepository, Connection $connection): Response
    {
        $texte = $texteRepository->findOneBy(['section' => 'pilotes_legende']);

        $pilotes = $connection
            ->executeQuery('SELECT * FROM pilotes_emblematiques ORDER BY ordre ASC')
            ->fetchAllAssociative();

        return $this->render('modele/pilotes.html.twig', [
            'texte_presentation' => $texte ? $texte->getContenu() : "Découvrez les pilotes légendaires qui ont marqué l'histoire de Bultaco et écrit les plus belles pages du trial et du motocross.",
            'pilotes' => $pilotes,
        ]);
    }
}
