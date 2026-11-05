<?php

namespace App\Controller;

use App\Boutique\Catalogue;
use App\I18n\Contenu;
use App\Modele\PageModele;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Plan du site pour les moteurs de recherche (toutes les pages, dans les trois langues)
 * et robots.txt qui l'annonce. Adresses à la racine du site, sans préfixe de langue.
 */
class SitemapController extends AbstractController
{
    /** Pages fixes du site */
    private const ROUTES = ['app_home', 'app_modeles_liste', 'app_modeles_restaurations', 'app_modeles_pilotes', 'app_doc_logos', 'app_doc_pressbook', 'app_boutique_pieces'];

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(Catalogue $catalogue, Connection $connection, PageModele $modeles): Response
    {
        $langues = array_keys(Contenu::LANGUES);
        $pages = [];

        foreach (self::ROUTES as $route) {
            $pages[] = array_combine($langues, array_map(
                fn (string $langue) => $this->generateUrl($route, ['_locale' => $langue], UrlGeneratorInterface::ABSOLUTE_URL),
                $langues
            ));
        }

        foreach ($connection->fetchAllAssociative('SELECT id, nom, nom_en, nom_es FROM pieces_detachees ORDER BY id') as $piece) {
            $pages[] = array_combine($langues, array_map(fn (string $langue) => $catalogue->url($piece, $langue, true), $langues));
        }

        // pages des modèles de l'album
        foreach ($modeles->modeles() as $modele) {
            $pages[] = array_combine($langues, array_map(fn (string $langue) => $modeles->url($modele, $langue, true), $langues));
        }

        $response = $this->render('sitemap.xml.twig', ['pages' => $pages]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        return new Response(
            "User-agent: *\nDisallow: /admin\nDisallow: /login\nDisallow: /panier\nDisallow: /commande\nDisallow: /compte\n"
            . 'Sitemap: ' . $this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL) . "\n",
            headers: ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
