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
 * (L'adresse e-mail se change avec ChangementEmailType : elle doit être revérifiée.)
 */
class ProfilType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'form.prenom', 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'form.nom', 'attr' => ['autocomplete' => 'family-name']])
            ->add('telephone', TelType::class, ['label' => 'form.telephone', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('adresse', TextType::class, ['label' => 'form.adresse', 'required' => false, 'attr' => ['autocomplete' => 'street-address']])
            ->add('codePostal', TextType::class, ['label' => 'form.code_postal', 'required' => false, 'attr' => ['autocomplete' => 'postal-code']])
            ->add('ville', TextType::class, ['label' => 'form.ville', 'required' => false, 'attr' => ['autocomplete' => 'address-level2']])
            ->add('pays', TextType::class, ['label' => 'form.pays', 'required' => false, 'attr' => ['autocomplete' => 'country-name']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Client::class]);
    }
}
