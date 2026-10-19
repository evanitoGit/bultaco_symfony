<?php

namespace App\Controller;

use App\Boutique\AlerteStock;
use App\Boutique\Catalogue;
use App\Entity\Client;
use App\I18n\Contenu;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Prévenez-moi quand elle revient » : inscription depuis la page d'une pièce en rupture,
 * annulation depuis le lien de l'e-mail.
 */
class AlerteStockController extends AbstractController
{
    public function __construct(
        private readonly AlerteStock $alertes,
        private readonly Catalogue $catalogue,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/boutique/pieces/{id}/alerte', name: 'app_alerte_stock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function inscrire(int $id, Request $request, Contenu $contenu, ValidatorInterface $validator, RateLimiterFactoryInterface $alerteStockLimiter): Response
    {
        $piece = $this->catalogue->piece($id);
        if ($piece === null) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }
        $retour = $this->redirect($piece['url'] . '#alerte');

        if (!$this->isCsrfTokenValid('alerte_stock', $request->getPayload()->getString('_token'))) {
            $this->addFlash('alerte', ['erreur', $this->translator->trans('commun.session_expiree')]);

            return $retour;
        }

        // client connecté : son adresse ; sinon l'adresse saisie
        $client = $this->getUser();
        $email = $client instanceof Client ? $client->getEmail() : trim($request->getPayload()->getString('email'));
        if ($email === '' || count($validator->validate($email, new Email())) > 0) {
            $this->addFlash('alerte', ['erreur', $this->translator->trans('alerte.email_invalide')]);

            return $retour;
        }

        if (!$alerteStockLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('alerte', ['erreur', $this->translator->trans('alerte.trop_de_demandes')]);

            return $retour;
        }

        $this->addFlash('alerte', $this->alertes->inscrire($piece, $email, $contenu->langue())
            ? ['ok', $this->translator->trans('alerte.inscrit', ['email' => mb_strtolower($email)])]
            : ['ok', $this->translator->trans('alerte.deja_disponible')]);

        return $retour;
    }

    /**
     * Lien « Ne plus être prévenu » de l'e-mail. La page demande une confirmation :
     * les logiciels qui ouvrent les liens des e-mails à l'avance n'annulent donc rien.
     */
    #[Route('/alerte/annuler/{jeton}', name: 'app_alerte_annuler', methods: ['GET', 'POST'])]
    public function annuler(string $jeton, Request $request): Response
    {
        $alerte = $this->alertes->alerte($jeton);
        $piece = $alerte ? $this->catalogue->piece((int) $alerte['piece_id']) : null;
        $annulee = false;

        if ($alerte && $request->isMethod('POST') && $this->isCsrfTokenValid('alerte_annuler', $request->getPayload()->getString('_token'))) {
            $this->alertes->annuler($alerte);
            $annulee = true;
        }

        return $this->render('boutique/alerte_annuler.html.twig', [
            'alerte' => $alerte,
            'piece' => $piece,
            'annulee' => $annulee,
            'jeton' => $jeton,
        ]);
    }
}
