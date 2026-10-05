<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocumentationController extends AbstractController
{
    #[Route('/documentation/logos', name: 'app_doc_logos')]
    public function logos(TexteRepository $texteRepository, Connection $connection): Response
    {
        $texte = $texteRepository->findOneBy(['section' => 'logos']);

        $logos = $connection
            ->executeQuery("SELECT * FROM pressbook WHERE type_contenu = 'logo' ORDER BY date_publication DESC")
            ->fetchAllAssociative();

        return $this->render('documentation/logos.html.twig', [
            'texte_presentation' => $texte ? $texte->getContenu() : "Découvrez l'évolution des logos Bultaco à travers les décennies. De l'emblème original aux variations modernes, chaque logo raconte une partie de l'histoire de la marque.",
            'logos' => $logos,
        ]);
    }

    #[Route('/documentation/pressbook', name: 'app_doc_pressbook')]
    public function pressbook(TexteRepository $texteRepository, Connection $connection): Response
    {
        $texte = $texteRepository->findOneBy(['section' => 'pressbook']);

        $items = $connection
            ->executeQuery("SELECT * FROM pressbook WHERE type_contenu != 'logo' ORDER BY date_publication DESC")
            ->fetchAllAssociative();

        return $this->render('documentation/pressbook.html.twig', [
            'texte_presentation' => $texte ? $texte->getContenu() : 'Articles de presse, magazines, photographies et illustrations : retrouvez toute la mémoire de Bultaco.',
            'items' => $items,
            'types' => array_values(array_unique(array_column($items, 'type_contenu'))),
        ]);
    }
}
