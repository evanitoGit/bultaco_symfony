<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocumentationController extends AbstractController
{
    #[Route('/documentation/logos', name: 'app_doc_logos')]
    public function logos(): Response
    {
        return $this->render('documentation/logos.html.twig');
    }

    #[Route('/documentation/pressbook', name: 'app_doc_pressbook')]
    public function pressbook(): Response
    {
        return $this->render('documentation/pressbook.html.twig');
    }
}
