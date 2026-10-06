<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** Changement de l'adresse e-mail : mot de passe demandé, nouvelle adresse à vérifier. */
class ChangementEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Nouvelle adresse e-mail',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indiquez votre nouvelle adresse e-mail.'),
                    new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Ce champ est obligatoire.'),
                    new UserPassword(message: 'Le mot de passe est incorrect.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'csrf_token_id' => 'changement_email']);
    }
}
