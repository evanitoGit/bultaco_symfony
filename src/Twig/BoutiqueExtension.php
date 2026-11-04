<?php

namespace App\Twig;

use App\Boutique\Reglages;
use App\Paiement\StripeClient;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Mode de la boutique dans les templates : {% if vente_ouverte() %} … {% endif %}
 */
class BoutiqueExtension extends AbstractExtension
{
    public function __construct(
        private readonly Reglages $reglages,
        private readonly StripeClient $stripe,
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vente_ouverte', $this->reglages->venteOuverte(...)),
            new TwigFunction('prix_visibles', $this->reglages->prixVisibles(...)),
            new TwigFunction('reglage', $this->reglages->get(...)),
            // liens vers le tableau de bord Stripe (mode test ou réel)
            new TwigFunction('stripe_mode_test', $this->stripe->modeTest(...)),
        ];
    }
}
