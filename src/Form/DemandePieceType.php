<?php

namespace App\Form;

use App\Boutique\DemandePiece;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Vous ne trouvez pas votre pièce ? »
 */
class DemandePieceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $obligatoire = new Assert\NotBlank(message: 'Ce champ est obligatoire.');

        $builder
            ->add('nom', TextType::class, [
                'label' => 'demande.form.nom',
                'attr' => ['autocomplete' => 'name'],
                'constraints' => [$obligatoire, new Assert\Length(max: 150)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'form.email',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [$obligatoire, new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'), new Assert\Length(max: 180)],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'demande.form.telephone',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
                'constraints' => [new Assert\Length(max: 30)],
            ])
            ->add('modele', ChoiceType::class, [
                'label' => 'demande.form.modele',
                // modèles de motos + « Autre / je ne sais pas »
                'choices' => $options['modeles'] + ['demande.form.autre' => 'autre'],
                'choice_translation_domain' => 'messages',
                'placeholder' => 'demande.form.choisir',
                'constraints' => [new Assert\NotBlank(message: 'Indiquez le modèle de votre moto.')],
            ])
            ->add('modele_autre', TextType::class, [
                'label' => 'demande.form.modele_autre',
                'required' => false,
                'attr' => ['placeholder' => 'demande.form.modele_autre_aide'],
                'constraints' => [new Assert\Length(max: 100)],
            ])
            ->add('annee', TextType::class, [
                'label' => 'demande.form.annee',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'placeholder' => '1975'],
                'constraints' => [new Assert\Length(max: 20)],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'demande.form.description',
                'attr' => ['rows' => 5, 'placeholder' => 'demande.form.description_aide'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Décrivez la pièce recherchée.'),
                    new Assert\Length(min: 10, max: 3000, minMessage: 'Décrivez la pièce recherchée (10 caractères minimum).'),
                ],
            ])
            ->add('photos', FileType::class, [
                'label' => 'demande.form.photos',
                'help' => 'demande.form.photos_aide',
                'help_translation_parameters' => ['max' => DemandePiece::PHOTOS_MAX],
                'required' => false,
                'multiple' => true,
                'attr' => ['accept' => 'image/*'],
                'constraints' => [
                    new Assert\Count(max: DemandePiece::PHOTOS_MAX, maxMessage: 'Trois photos au maximum.'),
                    new Assert\All([new Assert\Image(maxSize: '5M', mimeTypesMessage: 'Chaque photo doit être une image (JPG, PNG…).', maxSizeMessage: 'Chaque photo doit faire 5 Mo au maximum.')]),
                ],
            ])
            ->add('accord', CheckboxType::class, [
                'label' => 'demande.form.accord',
                'mapped' => false,
                'row_attr' => ['class' => 'commande-accord'],
                'constraints' => [new Assert\IsTrue(message: 'Cette case doit être cochée pour envoyer la demande.')],
            ])
            // piège à robots : champ invisible pour les visiteurs, rempli par les robots
            ->add('site_web', TextType::class, [
                'label' => 'Site web',
                'required' => false,
                'mapped' => false,
                'row_attr' => ['class' => 'champ-piege', 'aria-hidden' => 'true'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'modeles' => []]);
        $resolver->setAllowedTypes('modeles', 'array');
    }
}
