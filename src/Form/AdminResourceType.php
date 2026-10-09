<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire construit à partir de la description d'une ressource (voir ResourceRegistry).
 * Les données sont un simple tableau « colonne => valeur ».
 */
class AdminResourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($options['fields'] as $name => $field) {
            $required = $field['required'] ?? false;

            // Champ uniquement modifiable à la création (ex. section d'un texte)
            if (($field['create_only'] ?? false) && !$options['is_new']) {
                continue;
            }

            $common = [
                'label' => $field['label'],
                'required' => $required,
                'help' => $field['help'] ?? null,
            ];

            $constraints = $required ? [new Assert\NotBlank(message: 'Ce champ est obligatoire.')] : [];
            if (isset($field['max'])) {
                $constraints[] = new Assert\Length(max: $field['max']);
            }

            switch ($field['type']) {
                case 'textarea':
                    $builder->add($name, TextareaType::class, $common + [
                        'constraints' => $constraints,
                        'attr' => ['rows' => $field['rows'] ?? 5],
                    ]);
                    break;

                case 'integer':
                    if (isset($field['min'])) {
                        $constraints[] = new Assert\GreaterThanOrEqual(value: $field['min'], message: 'La valeur doit être supérieure ou égale à {{ compared_value }}.');
                    }
                    if (isset($field['max_value'])) {
                        $constraints[] = new Assert\LessThanOrEqual(value: $field['max_value']);
                    }
                    $builder->add($name, IntegerType::class, $common + [
                        'constraints' => $constraints,
                        'attr' => array_filter(['min' => $field['min'] ?? null], fn ($v) => $v !== null),
                        'empty_data' => (string) ($field['default'] ?? ''),
                    ]);
                    break;

                case 'money':
                    $builder->add($name, MoneyType::class, $common + [
                        'currency' => 'EUR',
                        'scale' => 2,
                        'constraints' => array_merge($constraints, [new Assert\PositiveOrZero()]),
                    ]);
                    break;

                case 'choice':
                case 'relation':
                    $choices = is_array($field['choices']) ? $field['choices'] : ($options['choices'][$name] ?? []);
                    $builder->add($name, ChoiceType::class, $common + [
                        'choices' => $choices,
                        'expanded' => $field['expanded'] ?? false,
                        'placeholder' => ($field['expanded'] ?? false) ? false : '— Choisir —',
                        'constraints' => $constraints,
                    ]);
                    break;

                case 'url':
                    $builder->add($name, UrlType::class, $common + [
                        'default_protocol' => 'https',
                        'constraints' => array_merge($constraints, [new Assert\Url(message: 'Cette adresse web n\'est pas valide.', requireTld: true)]),
                        'attr' => ['placeholder' => 'https://…'],
                    ]);
                    break;

                case 'image':
                    $hasImage = !empty($options['data'][$name] ?? null);
                    $builder->add($name, FileType::class, [
                        'label' => $field['label'],
                        'mapped' => false,
                        // obligatoire seulement s'il n'y a pas encore d'image
                        'required' => $required && !$hasImage,
                        'help' => $hasImage ? 'Laisser vide pour conserver l\'image actuelle.' : 'Formats acceptés : JPG, PNG, WEBP, GIF (10 Mo maximum).',
                        'attr' => ['accept' => 'image/*'],
                        'constraints' => array_merge(
                            $required && !$hasImage ? [new Assert\NotBlank(message: 'Une image est obligatoire.')] : [],
                            [new Assert\Image(maxSize: '10M', mimeTypesMessage: 'Le fichier doit être une image (JPG, PNG, WEBP, GIF).')]
                        ),
                    ]);
                    if ($hasImage && !$required) {
                        $builder->add($name . '__remove', CheckboxType::class, [
                            'label' => 'Supprimer l\'image actuelle',
                            'mapped' => false,
                            'required' => false,
                        ]);
                    }
                    break;

                case 'password':
                    $builder->add($name, RepeatedType::class, [
                        'type' => PasswordType::class,
                        'mapped' => false,
                        'required' => $options['is_new'],
                        'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                        'first_options' => [
                            'label' => $options['is_new'] ? $field['label'] : 'Nouveau mot de passe',
                            'help' => $options['is_new'] ? '8 caractères minimum.' : 'Laisser vide pour conserver le mot de passe actuel.',
                            'attr' => ['autocomplete' => 'new-password'],
                        ],
                        'second_options' => [
                            'label' => 'Confirmer le mot de passe',
                            'attr' => ['autocomplete' => 'new-password'],
                        ],
                        'constraints' => array_merge(
                            $options['is_new'] ? [new Assert\NotBlank(message: 'Le mot de passe est obligatoire.')] : [],
                            [new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.', max: 4096)]
                        ),
                    ]);
                    break;

                default: // text
                    $builder->add($name, TextType::class, $common + ['constraints' => $constraints]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'is_new' => true,
            'choices' => [],
            'csrf_token_id' => 'admin_resource',
        ]);
        $resolver->setRequired('fields');
        $resolver->setAllowedTypes('fields', 'array');
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('choices', 'array');
    }
}
