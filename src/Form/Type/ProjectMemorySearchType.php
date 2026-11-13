<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Bounded project-scoped memory search inputs for the admin GET view.
 */
final class ProjectMemorySearchType extends AbstractType
{
    public const int MAX_QUESTION_LENGTH = 500;
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 20;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', SearchType::class, [
                'label' => 'Question',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Enter a search question.'),
                    new Length(max: self::MAX_QUESTION_LENGTH),
                ],
                'attr' => [
                    'maxlength' => self::MAX_QUESTION_LENGTH,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('from', DateType::class, [
                'label' => 'From date (UTC, inclusive)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
                'model_timezone' => 'UTC',
                'view_timezone' => 'UTC',
                'help' => 'Optional. Canonical memory dates on or after this UTC day are included.',
            ])
            ->add('until', DateType::class, [
                'label' => 'Until date (UTC, exclusive)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
                'model_timezone' => 'UTC',
                'view_timezone' => 'UTC',
                'help' => 'Optional. Canonical memory dates on or after this UTC day are excluded.',
            ])
            ->add('limit', IntegerType::class, [
                'label' => 'Result limit',
                'required' => true,
                'data' => self::DEFAULT_LIMIT,
                'constraints' => [
                    new NotBlank(),
                    new Positive(),
                    new Range(min: 1, max: self::MAX_LIMIT),
                ],
                'attr' => [
                    'min' => 1,
                    'max' => self::MAX_LIMIT,
                ],
            ])
            ->add('search', SubmitType::class, [
                'label' => 'Search',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
