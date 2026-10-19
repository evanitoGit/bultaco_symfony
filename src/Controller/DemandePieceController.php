<?php

namespace App\Controller;

use App\Boutique\Catalogue;
use App\Boutique\DemandePiece;
use App\Entity\Client;
use App\Form\DemandePieceType;
use App\I18n\Contenu;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Vous ne trouvez pas votre pièce ? » : formulaire de demande au club.
 */
class DemandePieceController extends AbstractController
{
    private const SESSION_DEMANDE = 'derniere_demande_piece';

    #[Route('/boutique/demande', name: 'app_demande_piece', methods: ['GET', 'POST'])]
    public function demande(
        Request $request,
        Catalogue $catalogue,
        DemandePiece $demandes,
        Contenu $contenu,
        TranslatorInterface $translator,
        RateLimiterFactoryInterface $demandePieceLimiter,
    ): Response {
        $modeles = array_column($catalogue->modelesMoto(), 'id', 'nom');

        // client connecté : coordonnées déjà remplies ; ?moto=12 : modèle déjà choisi
        $client = $this->getUser() instanceof Client ? $this->getUser() : null;
        $donnees = [
            'nom' => $client?->getNomComplet(),
            'email' => $client?->getEmail(),
            'telephone' => $client?->getTelephone(),
            'modele' => in_array($request->query->getInt('moto'), $modeles) ? (string) $request->query->getInt('moto') : null,
        ];

        $form = $this->createForm(DemandePieceType::class, $donnees, ['modeles' => array_map('strval', $modeles)]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->get('modele')->getData() === 'autre' && trim((string) $form->get('modele_autre')->getData()) === '') {
                $form->get('modele_autre')->addError(new FormError($translator->trans('demande.modele_autre_obligatoire')));
            }
            if ($form->isValid() && !$demandePieceLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError($translator->trans('demande.trop_de_demandes')));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // champ piège rempli : c'est un robot, on fait comme si de rien n'était
            if (trim((string) $form->get('site_web')->getData()) === '') {
                $id = $demandes->enregistrer($form->getData(), $form->get('photos')->getData() ?? [], $client, $contenu->langue());
                $request->getSession()->set(self::SESSION_DEMANDE, $id);
            }

            return $this->redirectToRoute('app_demande_piece_merci');
        }

        return $this->render('boutique/demande.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/boutique/demande/merci', name: 'app_demande_piece_merci', methods: ['GET'])]
    public function merci(Request $request, DemandePiece $demandes): Response
    {
        $id = $request->hasPreviousSession() ? $request->getSession()->get(self::SESSION_DEMANDE) : null;

        return $this->render('boutique/demande_merci.html.twig', ['demande' => $id ? $demandes->trouver($id) : null]);
    }
}
