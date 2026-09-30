<?php

namespace App\Controller;

use App\Entity\Texte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ModeleController extends AbstractController
{
    #[Route('/modeles/liste', name: 'app_modeles_liste')]
    public function liste(EntityManagerInterface $entityManager): Response
    {
        // 1. Récupération du texte dynamique (équivalent de la table textes)
        $texteRepository = $entityManager->getRepository(Texte::class);
        $texteObj = $texteRepository->findOneBy(['section' => 'album_photos']);

        $texteContenu = $texteObj ? $texteObj->getContenu() : 'Découvrez notre collection de motos Bultaco emblématiques à travers des photos et fiches techniques détaillées.';

        // 2. Récupération des modèles depuis la base de données
        // (Si vous n'avez pas encore d'entité ModeleEmblematique, on utilise directement le PDO de Doctrine)
        $connection = $entityManager->getConnection();
        $sql = 'SELECT * FROM modeles_emblematiques ORDER BY ordre ASC';
        $modeles = $connection->executeQuery($sql)->fetchAllAssociative();

        return $this->render('modele/liste.html.twig', [
            'texte_presentation' => $texteContenu,
            'modeles' => $modeles,
        ]);
    }



    #[Route('/modeles/restaurations', name: 'app_modeles_restaurations')]
    public function restaurations(): Response
    {
        return $this->render('modele/restaurations.html.twig');
    }

    #[Route('/modeles/pilotes', name: 'app_modeles_pilotes')]
    public function pilotes(): Response
    {
        return $this->render('modele/pilotes.html.twig');
    }

}
