<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Admin\ObservationalMemorySettingsType;
use App\ObservationalMemory\Model\MemoryModelCatalog;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly MemoryModelCatalog $catalog,
    ) {
    }

    #[AdminRoute('/operations/om-settings', self::ROUTE_SUFFIX, options: ['methods' => [Request::METHOD_GET, Request::METHOD_POST]])]
    public function index(Request $request): Response
    {
        $current = $this->selections->currentPinned();
        $defaultContextWindow = $this->catalog->require($current->presetId)->defaultContextWindow;
        $form = $this->createForm(ObservationalMemorySettingsType::class, [
            'combinationId' => $this->catalog->combinationId($current->presetId, $current->reasoningLevel),
            'contextWindow' => $current->contextWindow === $defaultContextWindow ? null : $current->contextWindow,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{combinationId: string, contextWindow: int|null} $data */
            $data = $form->getData();
            try {
                $parsed = $this->catalog->parseCombinationId($data['combinationId']);
                $saved = $this->selections->save(
                    $parsed['presetId'],
                    $parsed['reasoningLevel'],
                    $data['contextWindow'],
                );
                $this->addFlash(
                    'success',
                    \sprintf(
                        'Saved OM model %s at %s with a %d-token context budget. Queued and in-flight jobs keep their original selection.',
                        $saved->model,
                        $saved->reasoningLevel->label(),
                        $saved->contextWindow,
                    ),
                );

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        $credentialStatus = [];
        foreach ($this->catalog->all() as $preset) {
            $credentialStatus[$preset->id] = $this->selections->hasCredential($preset);
        }

        return $this->render('admin/operations/om_settings.html.twig', [
            'form' => $form,
            'current' => $current,
            'presets' => $this->catalog->all(),
            'credentialStatus' => $credentialStatus,
        ])->setPrivate();
    }
}
