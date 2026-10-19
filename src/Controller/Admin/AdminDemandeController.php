<?php

namespace App\Controller\Admin;

use App\Boutique\DemandePiece;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Photos jointes aux demandes de pièces : rangées hors du dossier public,
 * elles ne sont visibles que par les administrateurs.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminDemandeController extends AbstractController
{
    #[Route('/admin/demandes-pieces/{id}/photo/{numero}', name: 'app_admin_demande_photo', requirements: ['id' => '\d+', 'numero' => '\d+'], methods: ['GET'])]
    public function photo(int $id, int $numero, DemandePiece $demandes): BinaryFileResponse
    {
        $chemin = $demandes->cheminPhoto($id, $numero);
        if ($chemin === null) {
            throw $this->createNotFoundException();
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, sprintf('demande-%d-photo-%d.%s', $id, $numero, pathinfo($chemin, PATHINFO_EXTENSION)));
        $reponse->setPrivate();
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');

        return $reponse;
    }
}
