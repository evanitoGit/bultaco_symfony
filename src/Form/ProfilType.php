<?php

namespace App\Form;

use App\Entity\Client;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Informations personnelles et adresse de livraison par défaut du client.
 * (L'adresse e-mail n'est pas modifiable ici : elle devra être revérifiée à l'étape 2.)
 */
class ProfilType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('adresse', TextType::class, ['label' => 'Adresse', 'required' => false, 'attr' => ['autocomplete' => 'street-address']])
            ->add('codePostal', TextType::class, ['label' => 'Code postal', 'required' => false, 'attr' => ['autocomplete' => 'postal-code']])
            ->add('ville', TextType::class, ['label' => 'Ville', 'required' => false, 'attr' => ['autocomplete' => 'address-level2']])
            ->add('pays', TextType::class, ['label' => 'Pays', 'required' => false, 'attr' => ['autocomplete' => 'country-name']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Client::class]);
    }
}
