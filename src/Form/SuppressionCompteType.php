<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

class SuppressionCompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motDePasse', PasswordType::class, [
                'label' => 'form.mot_de_passe',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Ce champ est obligatoire.'),
                    new UserPassword(message: 'Le mot de passe est incorrect.'),
                ],
            ])
            ->add('confirmation', CheckboxType::class, [
                'label' => 'form.suppression_confirmation',
                'row_attr' => ['class' => 'commande-accord'],
                'constraints' => [new Assert\IsTrue(message: 'Cochez cette case pour confirmer.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'csrf_token_id' => 'suppression_compte']);
    }
}
