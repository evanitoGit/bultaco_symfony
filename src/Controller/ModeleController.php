<?php

namespace App\Controller;

use App\Boutique\Catalogue;
use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function liste(Catalogue $catalogue): Response
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

        return $this->render('modele/liste.html.twig', [
            'texte_presentation' => $this->contenu->texte('album_photos', $this->translator->trans('modeles.texte_defaut')),
            'modeles' => $this->contenu->traduireTout($modeles, ['type_moto', 'description', 'caracteristiques']),
        ]);
    }

    #[Route('/modeles/restaurations', name: 'app_modeles_restaurations')]
    public function restaurations(): Response
    {
        $photos = $this->connection
            ->executeQuery('SELECT * FROM restauration_photos ORDER BY ordre ASC')
            ->fetchAllAssociative();
        $photos = $this->contenu->traduireTout($photos, ['nom_modele']);

        // Répartition des photos dans les deux colonnes définies en base
        $colonnes = ['gauche' => [], 'droite' => []];
        foreach ($photos as $photo) {
            $colonnes[$photo['colonne'] === 'droite' ? 'droite' : 'gauche'][] = $photo;
        }

        return $this->render('modele/restaurations.html.twig', [
            'texte_presentation' => $this->contenu->texte('restauration', $this->translator->trans('restaurations.texte_defaut')),
            'colonnes' => $colonnes,
            'photos' => $photos,
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
