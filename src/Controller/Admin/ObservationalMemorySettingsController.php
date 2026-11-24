<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Admin\ObservationalMemorySettingsType;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Model\MemoryReasoningLevel;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ObservationalMemorySettingsController extends AbstractController
{
    public const string ROUTE = DashboardController::ROUTE_PREFIX.self::ROUTE_SUFFIX;
    public const string CSRF_ID = 'observational_memory_settings';
    private const string ROUTE_SUFFIX = 'operations_om_settings';

    public function __construct(
        private readonly MemoryModelSelectionService $selections,
    ) {
    }

    #[AdminRoute('/operations/om-settings', self::ROUTE_SUFFIX, options: ['methods' => [Request::METHOD_GET, Request::METHOD_POST]])]
    public function index(Request $request): Response
    {
        $current = $this->selections->currentPinned();
        $form = $this->createForm(ObservationalMemorySettingsType::class, [
            'presetId' => $current->presetId,
            'reasoningLevel' => $current->reasoningLevel,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{presetId: string, reasoningLevel: MemoryReasoningLevel} $data */
            $data = $form->getData();
            try {
                $saved = $this->selections->save($data['presetId'], $data['reasoningLevel']);
                $this->addFlash(
                    'success',
                    \sprintf('Saved OM model %s at %s.', $saved->model, $saved->reasoningLevel->label()),
                );

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/operations/om_settings.html.twig', [
            'form' => $form,
            'current' => $current,
            'hasSelectableReasoning' => $this->selections->hasSelectableReasoning($current),
        ])->setPrivate();
    }
}
