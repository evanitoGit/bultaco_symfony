<?php

namespace App\Controller;

use App\Recherche\RechercheGlobale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche sur tout le site : page de résultats et suggestions de la fenêtre de recherche (loupe de l'en-tête).
 */
class RechercheController extends AbstractController
{
    public function __construct(private readonly RechercheGlobale $recherche)
    {
    }

    #[Route('/recherche', name: 'app_recherche', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $texte = trim($request->query->getString('q'));

        $reponse = $this->render('recherche/index.html.twig', [
            'q' => $texte,
            'groupes' => $this->recherche->rechercher($texte, 50),
        ]);
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    /** Résultats affichés pendant la frappe (5 par rubrique au plus) */
    #[Route('/recherche/suggestions', name: 'app_recherche_suggestions', methods: ['GET'])]
    public function suggestions(Request $request): Response
    {
        $texte = trim($request->query->getString('q'));
        $groupes = $this->recherche->rechercher($texte);

        $reponse = $this->render('recherche/_suggestions.html.twig', [
            'q' => $texte,
            'groupes' => $groupes,
            'total' => array_sum(array_column($groupes, 'total')),
            'complet' => array_sum(array_column($groupes, 'total')) === array_sum(array_map(fn (array $g) => count($g['resultats']), $groupes)),
        ]);
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }
}
