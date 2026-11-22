<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Controller\Admin\ObservationalMemorySettingsController;
use App\ObservationalMemory\Model\MemoryModelCatalog;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            ->add('combinationId', ChoiceType::class, [
                'label' => 'Model and reasoning',
                'choices' => $this->catalog->groupedCombinationChoices(),
                'choice_attr' => function (string $combinationId): array {
                    $parsed = $this->catalog->parseCombinationId($combinationId);
                    $preset = $this->catalog->require($parsed['presetId']);
                    $attr = [
                        'data-default-context-window' => (string) $preset->defaultContextWindow,
                        'data-max-context-window' => (string) $preset->maxContextWindow,
                    ];
                    if ($preset->leavesHost()) {
                        $attr['data-leaves-host'] = '1';
                    }
                    if (!$this->selections->hasCredential($preset)) {
                        $attr['disabled'] = 'disabled';
                        $attr['title'] = \sprintf('Requires %s in the environment', $preset->apiKeyEnv ?? 'an API key');
                    }

                    return $attr;
                },
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('contextWindow', IntegerType::class, [
                'label' => 'OM context budget override',
                'required' => false,
                'help' => 'Leave blank to use the selected preset default (local configured window, cloud 200,000).',
                'constraints' => [
                    new Assert\Positive(),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => ObservationalMemorySettingsController::CSRF_ID,
        ]);
    }
}
