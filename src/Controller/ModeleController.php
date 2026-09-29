<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ModeleController extends AbstractController
{
    #[Route('/modeles/liste', name: 'app_modeles_liste')]
    public function liste(): Response
    {
        return $this->render('modele/liste.html.twig');
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
