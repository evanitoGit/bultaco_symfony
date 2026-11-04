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
            ->add('prenom', TextType::class, ['label' => 'form.prenom', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'form.nom', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'family-name']])
            ->add('email', EmailType::class, [
                'label' => 'form.email',
                'constraints' => [...$obligatoire(180), new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')],
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'form.telephone',
                'required' => false,
                'constraints' => [new Assert\Length(max: 30)],
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('adresse', TextType::class, ['label' => 'form.adresse', 'constraints' => $obligatoire(255), 'attr' => ['autocomplete' => 'street-address']])
            ->add('code_postal', TextType::class, ['label' => 'form.code_postal', 'constraints' => $obligatoire(10), 'attr' => ['autocomplete' => 'postal-code']])
            ->add('ville', TextType::class, ['label' => 'form.ville', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'address-level2']])
            ->add('pays', TextType::class, ['label' => 'form.pays', 'constraints' => $obligatoire(100), 'attr' => ['autocomplete' => 'country-name']])
            ->add('mode_paiement', ChoiceType::class, [
                'label' => 'form.mode_paiement',
                'choices' => $options['paiements'],
                'choice_label' => fn (string $mode) => 'commande.mode.' . $mode,
                'expanded' => true,
                'constraints' => [new Assert\NotBlank(message: 'Choisissez un mode de paiement.')],
            ])
            ->add('commentaire', TextareaType::class, [
                'label' => 'form.commentaire',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'form.commentaire_aide'],
            ])
            ->add('accord', CheckboxType::class, [
                'label' => 'form.accord',
                'mapped' => false,
                'row_attr' => ['class' => 'commande-accord'],
                'constraints' => [new Assert\IsTrue(message: 'Cette case doit être cochée pour valider la commande.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'paiements' => CommandeService::PAIEMENTS, // modes proposés (la carte s'y ajoute si Stripe est configuré)
        ]);
        $resolver->setAllowedTypes('paiements', 'array');
    }
}
