<?php

namespace App\Controller;

use App\Boutique\Catalogue;
use App\I18n\Contenu;
use App\Modele\PageModele;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ModeleController extends AbstractController
{
    public function __construct(
        private readonly Contenu $contenu,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/modeles/liste', name: 'app_modeles_liste')]
    public function liste(Catalogue $catalogue, PageModele $pages): Response
    {
        // Pas encore d'entité pour cette table : requête SQL directe via DBAL
        $modeles = $this->connection
            ->executeQuery('SELECT * FROM modeles_emblematiques ORDER BY ordre ASC')
            ->fetchAllAssociative();

        // liens « Voir les pièces pour ce modèle » (modèles de motos reliés à la fiche)
        $motos = $catalogue->modelesParFicheAlbum();
        $modeles = array_map(fn (array $modele) => $modele + ['motos' => array_map(
            fn (array $moto) => ['nom' => $moto['nom'], 'url' => $this->generateUrl('app_boutique_pieces', ['moto' => $moto['id']])],
            $motos[(int) $modele['id']] ?? []
        )], $modeles);

        // la fiche ouverte dans la fenêtre mène à la page complète du modèle
        $modeles = array_map(fn (array $modele) => $modele + ['page' => $pages->url($modele)], $modeles);

        return $this->render('modele/liste.html.twig', [
            'texte_presentation' => $this->contenu->texte('album_photos', $this->translator->trans('modeles.texte_defaut')),
            'modeles' => $this->contenu->traduireTout($modeles, ['type_moto', 'description', 'caracteristiques']),
        ]);
    }

    /**
     * Page complète d'un modèle de l'album.
     * Seul le numéro compte : si le nom a changé, l'ancienne adresse redirige vers la nouvelle.
     */
    #[Route('/modeles/{id}-{slug}', name: 'app_modele', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]*'], methods: ['GET'])]
    public function fiche(int $id, string $slug, Request $request, PageModele $pages): Response
    {
        $modele = $pages->fiche($id);
        if ($modele === null) {
            throw $this->createNotFoundException('Modèle introuvable.');
        }
        if ($request->getBaseUrl() . $request->getPathInfo() !== $modele['url']) {
            return $this->redirect($modele['url'], Response::HTTP_MOVED_PERMANENTLY);
        }

        // drapeaux et balises hreflang : même page dans chaque langue
        $request->attributes->set('_urls_langues', array_combine(
            array_keys(Contenu::LANGUES),
            array_map(fn (string $langue) => $pages->url($modele, $langue), array_keys(Contenu::LANGUES))
        ));

        return $this->render('modele/fiche.html.twig', ['modele' => $modele]);
    }

    #[Route('/modeles/restaurations', name: 'app_modeles_restaurations')]
    public function restaurations(): Response
    {
        // Une restauration = une photo « avant » et une photo « après » (l'une des deux peut manquer)
        $restaurations = $this->connection
            ->executeQuery('SELECT * FROM restaurations WHERE photo_avant IS NOT NULL OR photo_apres IS NOT NULL ORDER BY ordre ASC, id ASC')
            ->fetchAllAssociative();

        return $this->render('modele/restaurations.html.twig', [
            'texte_presentation' => $this->contenu->texte('restauration', $this->translator->trans('restaurations.texte_defaut')),
            'restaurations' => $this->contenu->traduireTout($restaurations, ['titre']),
        ]);
    }

    #[Route('/modeles/pilotes', name: 'app_modeles_pilotes')]
    public function pilotes(): Response
    {
        $pilotes = $this->connection
            ->executeQuery('SELECT * FROM pilotes_emblematiques ORDER BY ordre ASC')
            ->fetchAllAssociative();

        return $this->render('modele/pilotes.html.twig', [
            'texte_presentation' => $this->contenu->texte('pilotes_legende', $this->translator->trans('pilotes.texte_defaut')),
            'pilotes' => $this->contenu->traduireTout($pilotes, ['nationalite', 'date_naissance', 'date_deces', 'description', 'palmares']),
        ]);
    }
}
