<?php

namespace App\Controller;

use App\I18n\Contenu;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Contenu $contenu): Response
    {
        // libellé (clé de traduction) => route
        $quickLinks = [
            'nav.liste_modeles' => 'app_modeles_liste',
            'nav.restaurations' => 'app_modeles_restaurations',
            'nav.pilotes_legende' => 'app_modeles_pilotes',
            'nav.logos' => 'app_doc_logos',
            'nav.pressbook' => 'app_doc_pressbook',
            'nav.pieces_detachees' => 'app_boutique_pieces',
        ];

        return $this->render('home/index.html.twig', [
            'texte_presentation' => $contenu->texte('presentation'),
            'quick_links' => $quickLinks,
        ]);
    }
}
