<?php

namespace App\Form;

use App\Commande\CommandeService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CommandeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $obligatoire = fn (int $max) => [new Assert\NotBlank(message: 'Ce champ est obligatoire.'), new Assert\Length(max: $max)];

        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'Nom', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'family-name']])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'constraints' => [...$obligatoire(180), new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')],
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'constraints' => [new Assert\Length(max: 30)],
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'constraints' => $obligatoire(255), 'attr' => ['autocomplete' => 'street-address']])
            ->add('code_postal', TextType::class, ['label' => 'Code postal', 'constraints' => $obligatoire(10), 'attr' => ['autocomplete' => 'postal-code']])
            ->add('ville', TextType::class, ['label' => 'Ville', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'address-level2']])
            ->add('pays', TextType::class, ['label' => 'Pays', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'country-name']])
            ->add('mode_paiement', ChoiceType::class, [
                'label' => 'Mode de paiement',
                'choices' => CommandeService::PAIEMENTS,
                'expanded' => true,
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un mode de paiement.')],
            ])
            ->add('commentaire', TextareaType::class, [
                'label' => 'Commentaire (facultatif)',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Précision sur la livraison, référence de votre moto…'],
            ])
            ->add('accord', CheckboxType::class, [
                'label' => 'J\'ai vérifié ma commande et j\'accepte d\'être contacté par le club pour la finaliser.',
                'mapped' => false,
                'row_attr' => ['class' => 'commande-accord'],
                'constraints' => [new Assert\IsTrue(message: 'Cette case doit être cochée pour valider la commande.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
