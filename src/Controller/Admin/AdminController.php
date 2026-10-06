<?php

namespace App\Controller\Admin;

use App\Admin\ImageUploader;
use App\Admin\ResourceRegistry;
use App\Entity\Admin;
use App\Form\AdminResourceType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration générique des contenus du site (voir App\Admin\ResourceRegistry).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly Connection $connection,
        private readonly ImageUploader $uploader,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function dashboard(): Response
    {
        $counts = [];
        foreach ($this->registry->all() as $key => $resource) {
            $sql = 'SELECT COUNT(*) FROM ' . $resource['table'] . (isset($resource['where']) ? ' WHERE ' . $resource['where'] : '');
            $counts[$key] = (int) $this->connection->fetchOne($sql);
        }

        return $this->render('admin/dashboard.html.twig', [
            'groups' => $this->registry->grouped(),
            'counts' => $counts,
        ]);
    }

    #[Route('/{resource}', name: 'app_admin_list', requirements: ['resource' => ResourceRegistry::ROUTE_REQUIREMENT], methods: ['GET'])]
    public function list(string $resource): Response
    {
        $config = $this->registry->get($resource);

        $sql = $config['list_sql'] ?? sprintf(
            'SELECT * FROM %s%s ORDER BY %s',
            $config['table'],
            isset($config['where']) ? ' WHERE ' . $config['where'] : '',
            $config['order']
        );

        return $this->render('admin/list.html.twig', [
            'resource' => $config,
            'rows' => $this->connection->fetchAllAssociative($sql),
            'groups' => $this->registry->grouped(),
            'choice_labels' => $this->choiceLabels($config),
        ]);
    }

    #[Route('/{resource}/nouveau', name: 'app_admin_new', requirements: ['resource' => ResourceRegistry::ROUTE_REQUIREMENT], methods: ['GET', 'POST'])]
    public function new(string $resource, Request $request): Response
    {
        $config = $this->registry->get($resource);

        if (($config['can_create'] ?? true) === false) {
            throw $this->createNotFoundException();
        }

        $data = [];
        foreach ($config['fields'] as $name => $field) {
            if (array_key_exists('default', $field)) {
                $data[$name] = $field['default'];
            }
        }

        return $this->handleForm($config, $request, $data, null);
    }

    #[Route('/{resource}/{id}/modifier', name: 'app_admin_edit', requirements: ['resource' => ResourceRegistry::ROUTE_REQUIREMENT, 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $resource, int $id, Request $request): Response
    {
        $config = $this->registry->get($resource);

        return $this->handleForm($config, $request, $this->findRow($config, $id), $id);
    }

    #[Route('/{resource}/{id}/supprimer', name: 'app_admin_delete', requirements: ['resource' => ResourceRegistry::ROUTE_REQUIREMENT, 'id' => '\d+'], methods: ['POST'])]
    public function delete(string $resource, int $id, Request $request): Response
    {
        $config = $this->registry->get($resource);
        $row = $this->findRow($config, $id);

        if (!$this->isCsrfTokenValid('delete-' . $resource . '-' . $id, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'La suppression a expiré, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_list', ['resource' => $resource]);
        }

        if ($resource === 'admins') {
            $user = $this->getUser();
            if ($user instanceof Admin && $user->getId() === $id) {
                $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

                return $this->redirectToRoute('app_admin_list', ['resource' => $resource]);
            }
        }

        // Images liées : celle de la ligne + celles des lignes supprimées en cascade
        $images = [];
        foreach ($config['fields'] as $name => $field) {
            if ($field['type'] === 'image' && !empty($row[$name])) {
                $images[] = $row[$name];
            }
        }
        if (isset($config['cascade_images'])) {
            $images = array_merge($images, $this->connection->fetchFirstColumn($config['cascade_images'], ['id' => $id]));
        }

        $this->connection->transactional(function (Connection $connection) use ($config, $id): void {
            foreach ($config['cascade_sql'] ?? [] as $sql) {
                $connection->executeStatement($sql, ['id' => $id]);
            }
            $connection->delete($config['table'], ['id' => $id]);
        });

        foreach ($images as $image) {
            $this->uploader->remove($image);
        }

        $this->addFlash('success', sprintf('« %s » a été supprimé.', $this->rowTitle($config, $row)));

        return $this->redirectToRoute('app_admin_list', ['resource' => $resource]);
    }

    private function handleForm(array $config, Request $request, array $row, ?int $id): Response
    {
        $isNew = $id === null;
        $fields = $config['fields'];

        // Valeurs typées pour les champs numériques
        foreach ($fields as $name => $field) {
            if (isset($row[$name]) && $row[$name] !== '') {
                $row[$name] = match ($field['type']) {
                    'integer', 'relation' => (int) $row[$name],
                    'money' => (float) $row[$name],
                    default => $row[$name],
                };
            }
        }

        $form = $this->createForm(AdminResourceType::class, $row, [
            'fields' => $this->fieldsWithChoices($fields, $isNew),
            'is_new' => $isNew,
            'choices' => $this->relationChoices($fields),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateUnique($config, $form, $id);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $values = $this->extractValues($config, $form, $row, $isNew);

            if ($isNew) {
                $this->connection->insert($config['table'], $values + ($config['fixed'] ?? []));
            } else {
                $this->connection->update($config['table'], $values, ['id' => $id]);
            }

            $title = $this->rowTitle($config, $values + $row);
            $this->addFlash('success', $isNew ? sprintf('« %s » a été ajouté.', $title) : sprintf('« %s » a été enregistré.', $title));

            return $this->redirectToRoute('app_admin_list', ['resource' => $config['key']]);
        }

        return $this->render('admin/form.html.twig', [
            'resource' => $config,
            'form' => $form,
            'row' => $row,
            'is_new' => $isNew,
            'groups' => $this->registry->grouped(),
            'details' => (!$isNew && isset($config['detail_sql']))
                ? $this->connection->fetchAllAssociative($config['detail_sql'], ['id' => $id])
                : [],
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Construit les valeurs à enregistrer à partir du formulaire validé.
     */
    private function extractValues(array $config, FormInterface $form, array $row, bool $isNew): array
    {
        $data = $form->getData();
        $values = [];

        foreach ($config['fields'] as $name => $field) {
            if (!$form->has($name)) {
                continue;
            }

            switch ($field['type']) {
                case 'image':
                    $file = $form->get($name)->getData();
                    $old = $row[$name] ?? null;
                    if ($file) {
                        $values[$name] = $this->uploader->upload($file, $field['dir']);
                        $this->uploader->remove($old);
                    } elseif ($form->has($name . '__remove') && $form->get($name . '__remove')->getData()) {
                        $values[$name] = null;
                        $this->uploader->remove($old);
                    }
                    break;

                case 'password':
                    $plain = $form->get($name)->getData();
                    if ($plain) {
                        $values[$name] = $this->passwordHasher->hashPassword(new Admin(), $plain);
                    }
                    break;

                case 'integer':
                    $values[$name] = $data[$name] ?? ($field['default'] ?? null);
                    break;

                default:
                    $value = $data[$name] ?? null;
                    $values[$name] = is_string($value) ? trim($value) : $value;
                    if ($values[$name] === '') {
                        $values[$name] = null;
                    }
            }
        }

        // Date de modification des textes de pages
        if (isset($config['touch'])) {
            $values[$config['touch']] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        return $values;
    }

    private function validateUnique(array $config, FormInterface $form, ?int $id): void
    {
        foreach ($config['fields'] as $name => $field) {
            if (!($field['unique'] ?? false) || !$form->has($name)) {
                continue;
            }
            $value = trim((string) $form->get($name)->getData());
            $existing = $this->connection->fetchOne(
                sprintf('SELECT id FROM %s WHERE %s = :value', $config['table'], $name),
                ['value' => $value]
            );
            if ($existing !== false && (int) $existing !== $id) {
                $form->get($name)->addError(new FormError('Cette valeur est déjà utilisée.'));
            }
        }
    }

    /**
     * Remplace les listes de choix dynamiques (ex. sections de textes encore libres).
     */
    private function fieldsWithChoices(array $fields, bool $isNew): array
    {
        foreach ($fields as $name => $field) {
            if (($field['choices'] ?? null) === 'text_sections') {
                $used = $this->connection->fetchFirstColumn('SELECT section FROM textes');
                $choices = [];
                foreach (ResourceRegistry::TEXT_SECTIONS as $section => $info) {
                    if (!$isNew || !in_array($section, $used, true)) {
                        $choices[$info['label']] = $section;
                    }
                }
                $fields[$name]['choices'] = $choices;
            }
        }

        return $fields;
    }

    /**
     * Listes de choix venant d'autres tables (catégories, sous-catégories).
     */
    private function relationChoices(array $fields): array
    {
        $choices = [];
        foreach ($fields as $name => $field) {
            if ($field['type'] !== 'relation') {
                continue;
            }
            $choices[$name] = match ($field['choices']) {
                'categories' => array_column(
                    $this->connection->fetchAllAssociative('SELECT id, nom FROM categories ORDER BY ordre ASC, nom ASC'),
                    'id',
                    'nom'
                ),
                'sous_categories' => $this->groupedSubCategories(),
                default => [],
            };
        }

        return $choices;
    }

    /**
     * @return array<string, array<string, int>> sous-catégories regroupées par catégorie
     */
    private function groupedSubCategories(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT sc.id, sc.nom, c.nom AS categorie FROM sous_categories sc INNER JOIN categories c ON c.id = sc.categorie_id ORDER BY c.ordre ASC, sc.ordre ASC, sc.nom ASC'
        );
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['categorie']][$r['nom']] = (int) $r['id'];
        }

        return $grouped;
    }

    /**
     * Libellés à afficher dans la liste pour les colonnes à choix (ex. « droite » -> « Droite (après) »).
     */
    private function choiceLabels(array $config): array
    {
        $labels = [];
        foreach ($config['fields'] as $name => $field) {
            if ($field['type'] === 'choice' && is_array($field['choices'])) {
                $labels[$name] = array_flip($field['choices']);
            }
        }

        return $labels;
    }

    private function findRow(array $config, int $id): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = :id%s', $config['table'], isset($config['where']) ? ' AND ' . $config['where'] : '');
        $row = $this->connection->fetchAssociative($sql, ['id' => $id]);

        if ($row === false) {
            throw new NotFoundHttpException('Élément introuvable.');
        }

        return $row;
    }

    private function rowTitle(array $config, array $row): string
    {
        $parts = array_filter(array_map(fn (string $column) => $row[$column] ?? null, $config['title']));
        $title = trim(implode(' ', $parts));

        if ($config['key'] === 'textes') {
            $title = ResourceRegistry::TEXT_SECTIONS[$title]['label'] ?? $title;
        }

        return $title !== '' ? $title : 'Élément';
    }
}
