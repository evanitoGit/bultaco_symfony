<?php

namespace App\Twig;

use App\I18n\Contenu;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Changement de langue (drapeaux) et formats propres à chaque langue.
 */
class LangueExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Contenu $contenu,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('langues', fn () => Contenu::LANGUES),
            new TwigFunction('url_langue', $this->urlLangue(...)),
            new TwigFunction('route_courante', $this->routeCourante(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            // 8990 (centimes) -> « 89,90 € » / « €89.90 »
            new TwigFilter('prix', fn (int $centimes) => $this->euros($centimes / 100)),
            // « 89.90 » (valeur en base) -> « 89,90 € » / « €89.90 »
            new TwigFilter('euros', fn ($montant) => $this->euros((float) $montant)),
            // ligne de commande|traduit('nom_piece') : nom de la pièce dans la langue en cours (page ou e-mail)
            new TwigFilter('traduit', fn (array $ligne, string $champ) => $this->contenu->traduire($ligne, [$champ])[$champ] ?? ''),
        ];
    }

    /**
     * Nom de la route sans le suffixe de langue (app_home.en -> app_home).
     */
    public function routeCourante(): ?string
    {
        $attributs = $this->requestStack->getMainRequest()?->attributes;

        return $attributs?->get('_canonical_route') ?? $attributs?->get('_route');
    }

    /**
     * La page affichée, dans une autre langue (mêmes paramètres et même recherche).
     */
    public function urlLangue(string $langue): string
    {
        $requete = $this->requestStack->getMainRequest();
        $route = $this->routeCourante();
        // page sans version traduite (connexion administrateur, erreur…) : accueil dans la langue choisie
        if ($requete === null || $route === null || !$requete->attributes->has('_locale')) {
            return $this->urlGenerator->generate('app_home', ['_locale' => $langue]);
        }

        $parametres = ['_locale' => $langue] + $requete->attributes->get('_route_params', []) + $requete->query->all();
        try {
            return $this->urlGenerator->generate($route, $parametres);
        } catch (\Throwable) {
            return $this->urlGenerator->generate('app_home', ['_locale' => $langue]);
        }
    }

    private function euros(float $montant): string
    {
        return match ($this->contenu->langue()) {
            'en' => '€' . number_format($montant, 2, '.', ','),
            'es' => number_format($montant, 2, ',', '.') . ' €',
            default => number_format($montant, 2, ',', ' ') . ' €',
        };
    }
}
