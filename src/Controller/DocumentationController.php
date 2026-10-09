<?php

namespace App\Controller;

use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class DocumentationController extends AbstractController
{
    private const CHAMPS_TRADUITS = ['titre', 'date_publication', 'description'];

    public function __construct(
        private readonly Contenu $contenu,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/documentation/logos', name: 'app_doc_logos')]
    public function logos(): Response
    {
        $logos = $this->connection
            ->executeQuery("SELECT * FROM pressbook WHERE type_contenu = 'logo' ORDER BY date_publication DESC")
            ->fetchAllAssociative();

        return $this->render('documentation/logos.html.twig', [
            'texte_presentation' => $this->contenu->texte('logos', $this->translator->trans('logos.texte_defaut')),
            'logos' => $this->contenu->traduireTout($logos, self::CHAMPS_TRADUITS),
        ]);
    }

    #[Route('/documentation/pressbook', name: 'app_doc_pressbook')]
    public function pressbook(): Response
    {
        $items = $this->connection
            ->executeQuery("SELECT * FROM pressbook WHERE type_contenu != 'logo' ORDER BY date_publication DESC")
            ->fetchAllAssociative();

        return $this->render('documentation/pressbook.html.twig', [
            'texte_presentation' => $this->contenu->texte('pressbook', $this->translator->trans('pressbook.texte_defaut')),
            'items' => $this->contenu->traduireTout($items, self::CHAMPS_TRADUITS),
            'types' => array_values(array_unique(array_column($items, 'type_contenu'))),
        ]);
    }
}
