<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BoutiqueController extends AbstractController
{
    #[Route('/boutique/pieces', name: 'app_boutique_pieces')]
    public function pieces(): Response
    {
        return $this->render('boutique/pieces.html.twig');
    }
}
