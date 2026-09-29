<?php

namespace App\Controller;

use App\Repository\TexteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(TexteRepository $texteRepository): Response
    {
        $textePresentation = $texteRepository->findOneBy(['section' => 'presentation']);

        $quickLinks = [
            "Liste modèles" => 'app_modeles_liste',
            "Restaurations" => 'app_modeles_restaurations',
            "Pilotes de légende" => 'app_modeles_pilotes',
            "Logos" => 'app_doc_logos',
            "Pressbook" => 'app_doc_pressbook',
            "Pièces détachées" => 'app_boutique_pieces',
        ];

        return $this->render('home/index.html.twig', [
            'texte_presentation' => $textePresentation ? $textePresentation->getContenu() : '',
            'quick_links' => $quickLinks,
        ]);
    }
}
