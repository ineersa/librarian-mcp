<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Controller\Admin\ObservationalMemorySettingsController;
use App\ObservationalMemory\Model\MemoryModelCatalog;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Model\MemoryReasoningLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Operations form for the shared Observer/Reflector/Dropper model selection.
 */
final class ObservationalMemorySettingsType extends AbstractType
{
    public function __construct(
        private readonly MemoryModelCatalog $catalog,
        private readonly MemoryModelSelectionService $selections,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('presetId', ChoiceType::class, [
                'label' => 'Model',
                'choices' => $this->catalog->groupedChoices(),
                'choice_label' => function (string $presetId): string {
                    $preset = $this->catalog->require($presetId);

                    return $this->selections->hasCredential($preset) ? $preset->label : $preset->label.' (API key missing)';
                },
                'choice_attr' => function (string $presetId): array {
                    $preset = $this->catalog->require($presetId);
                    $levels = [];
                    foreach ($preset->reasoningLevels as $level) {
                        $levels[$level->value] = $level->label();
                    }
                    $attr = [
                        'data-reasoning-choices' => json_encode($levels, \JSON_THROW_ON_ERROR),
                        'data-default-reasoning' => $preset->defaultReasoningLevel->value,
                    ];
                    if (!$this->selections->hasCredential($preset)) {
                        $attr['disabled'] = 'disabled';
                    }

                    return $attr;
                },
                'attr' => [
                    'data-om-model-target' => 'model',
                    'data-action' => 'change->om-model#changeModel',
                ],
                'help' => 'Cloud models send observational-memory prompts and tool payloads to the selected provider.',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('reasoningLevel', ChoiceType::class, [
                'label' => 'Reasoning',
                'choices' => MemoryReasoningLevel::cases(),
                'choice_label' => static fn (MemoryReasoningLevel $level): string => $level->label(),
                'choice_value' => static fn (?MemoryReasoningLevel $level): ?string => $level?->value,
                'attr' => ['data-om-model-target' => 'reasoning'],
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => ObservationalMemorySettingsController::CSRF_ID,
            'attr' => ['data-controller' => 'om-model'],
        ]);
    }
}
