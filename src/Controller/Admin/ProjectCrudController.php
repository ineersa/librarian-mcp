<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Form\Type\ProjectMemorySearchType;
use App\Index\IndexException;
use App\Index\ProjectIndexManager;
use App\Index\Search\HybridSearch;
use App\Index\Source\CanonicalSourceReader;
use App\Index\Source\MemoryKind;
use App\Index\Status\ProjectIndexStatusFactory;
use App\Index\Status\ProjectStatusTopics;
use App\Memory\MemoryRecall;
use App\Messenger\Monitor\MessengerStatsProvider;
use App\ObservationalMemory\Ingest\SessionBackfill;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\ObservationRepository;
use App\ObservationalMemory\Storage\ObserverCoverageReader;
use App\ObservationalMemory\Support\OmIdentity;
use App\Project\ProjectManager;
use App\Repository\IndexGenerationRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * @extends AbstractCrudController<Project>
 */
#[AdminRoute(path: '/projects')]
class ProjectCrudController extends AbstractCrudController
{
    public const string DETAIL_ROUTE = DashboardController::ROUTE_PREFIX.'project_detail';
    public const string RETURN_TO_QUERY = 'returnTo';
    public const string RETURN_INDEX = 'index';
    public const string RETURN_SEARCH = 'search';
    public const string MEMORY_ID_QUERY = 'memoryId';
    public const string BACKFILL_SESSIONS_CSRF_ID = 'project_backfill_sessions';

    /** @var array<string, string> */
    public const array RELEVANCE_BADGE_COLORS = [
        OmIdentity::RELEVANCE_LOW => 'secondary',
        OmIdentity::RELEVANCE_MEDIUM => 'info',
        OmIdentity::RELEVANCE_HIGH => 'warning',
        OmIdentity::RELEVANCE_CRITICAL => 'danger',
    ];

    private const string ACTION_SEARCH_MEMORY = 'searchMemory';
    private const string ACTION_OM_INDEX = 'omIndex';
    private const string ACTION_RECALL_MEMORY = 'recallMemory';
    private const string ACTION_BACKFILL_SESSIONS = 'backfillSessions';
    private const string SESSION_FILTER_FORM = 'memory_filter';
    private const string SESSION_FILTER_FIELD = 'session';

    public function __construct(
        private readonly ProjectManager $projectManager,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly IndexGenerationRepository $indexGenerationRepository,
        private readonly ProjectIndexStatusFactory $projectIndexStatusFactory,
        private readonly HybridSearch $hybridSearch,
        private readonly CanonicalSourceReader $canonicalSourceReader,
        private readonly ObservationRepository $observations,
        private readonly MemoryGenerationRepository $generations,
        private readonly MemoryRecall $memoryRecall,
        private readonly ClockInterface $clock,
        private readonly ObserverCoverageReader $coverage,
        private readonly MessengerStatsProvider $messengerStats,
        private readonly RequestStack $requests,
        private readonly SessionBackfill $sessionBackfill,
    ) {
    }

    public function createEntity(string $entityFqcn): Project
    {
        return new Project($this->clock->now());
    }

    public static function getEntityFqcn(): string
    {
        return Project::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Project')
            ->setEntityLabelInPlural('Projects')
            ->setPageTitle(Crud::PAGE_INDEX, 'Projects')
            ->setPageTitle(Crud::PAGE_NEW, 'Register Project')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Project $project) => \sprintf('Project: %s', $project->name))
            ->setSearchFields(['name', 'rootPath', 'repositoryOrigin'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/detail', 'admin/project/detail.html.twig')
            ->overrideTemplate('crud/index', 'admin/project/index.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('name')
            ->setHelp('Must be unique.');

        yield TextField::new('rootPath')
            ->setLabel('Project directory')
            ->setFormTypeOptions([
                'attr' => ['placeholder' => '/projects/my-project', 'autocomplete' => 'off'],
            ])
            ->setHelp('Absolute directory path inside the container, e.g. /projects/my-project.');

        yield TextField::new('repositoryOrigin', 'Repository origin')
            ->hideOnForm()
            ->setHelp('Discovered Git origin. Must be unique across registered projects.');

        yield ChoiceField::new('health')
            ->setChoices($this->healthChoices())
            ->renderAsBadges([
                ProjectHealth::Pending->value => 'secondary',
                ProjectHealth::Scanning->value => 'info',
                ProjectHealth::Ready->value => 'success',
                ProjectHealth::Error->value => 'danger',
            ])
            ->formatValue(static fn (?ProjectHealth $health): string => $health?->label() ?? '')
            ->setTemplatePath('admin/project/field/health.html.twig')
            ->hideOnForm();

        yield TextField::new('indexStatusSummary', 'Indexing')
            ->onlyOnIndex()
            ->setVirtual(true)
            ->setTemplatePath('admin/project/field/index_status.html.twig');

        yield TextareaField::new('lastError')
            ->hideOnForm()
            ->hideOnIndex()
            ->formatValue(static fn (?string $error): string => $error ?? '');

        yield TextField::new('databasePath', 'OM database')
            ->hideOnForm()
            ->hideOnIndex();

        yield TextField::new('sessionsPath', 'Sessions path')
            ->hideOnForm()
            ->hideOnIndex();

        yield TextField::new('schemaVersion', 'Schema version')
            ->hideOnForm()
            ->hideOnIndex();

        yield IntegerField::new('observationCount', 'Observations')
            ->hideOnForm()
            ->hideOnIndex()
            ->setHelp('Legacy discovery counter; live status uses active observational-memory counts.');

        yield IntegerField::new('reflectionCount', 'Reflections')
            ->hideOnForm()
            ->hideOnIndex()
            ->setHelp('Legacy discovery counter; live status uses active observational-memory counts.');

        yield IntegerField::new('sessionCount', 'Sessions')
            ->hideOnForm()
            ->hideOnIndex();

        yield IntegerField::new('sourceRevision', 'Source revision')
            ->hideOnForm()
            ->hideOnIndex();

        yield IntegerField::new('sourceChangeCount', 'Source change entries')
            ->hideOnForm()
            ->hideOnIndex()
            ->setHelp('Source journal entries from Hatfield OM, not a Librarian index backlog.');

        yield CodeEditorField::new('sourceDetails', 'Source details')
            ->onlyOnDetail()
            ->setLanguage('javascript')
            ->formatValue(static function (mixed $value): string {
                if (!\is_array($value) || [] === $value) {
                    return '{}';
                }

                return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            });

        yield DateTimeField::new('lastScanAt', 'Last scan')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('lastSuccessfulScanAt', 'Last successful scan')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('lastSuccessfulIndexAt', 'Last index')
            ->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $searchMemory = Action::new(self::ACTION_SEARCH_MEMORY, 'Search memory', 'fa fa-magnifying-glass')
            ->linkToCrudAction(self::ACTION_SEARCH_MEMORY)
            ->addCssClass('btn btn-outline-primary');
        $omIndex = Action::new(self::ACTION_OM_INDEX, 'OM index details', 'fa fa-book-open')
            ->linkToCrudAction(self::ACTION_OM_INDEX)
            ->addCssClass('btn btn-outline-secondary');
        $backfillSessions = Action::new(self::ACTION_BACKFILL_SESSIONS, 'Backfill sessions', 'fa fa-clock-rotate-left')
            ->linkToCrudAction(self::ACTION_BACKFILL_SESSIONS)
            // POST form with a CSRF token; EasyAdmin's renderAsForm() renders no token.
            ->setTemplatePath('admin/project/_backfill_sessions_action.html.twig')
            ->addCssClass('btn btn-outline-secondary')
            ->displayIf(fn (Project $project): bool => $this->sessionBackfill->isAvailable($project));

        return $actions
            ->disable(Action::EDIT)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Register Project'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // EasyAdmin resolves row navigation from configured entity actions.
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action
                ->addCssClass('d-none'))
            ->add(Crud::PAGE_INDEX, $searchMemory)
            ->add(Crud::PAGE_INDEX, $omIndex)
            ->add(Crud::PAGE_INDEX, $backfillSessions)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action
                ->setLabel('Delete')
                ->setIcon('fa fa-trash'))
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, self::ACTION_OM_INDEX, self::ACTION_SEARCH_MEMORY, self::ACTION_BACKFILL_SESSIONS, Action::DELETE])
            ->setPermission(Action::DELETE, 'ROLE_ADMIN');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('name')
            ->add('health');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $pageName = $responseParameters->get('pageName');
        if (Crud::PAGE_INDEX === $pageName) {
            $entities = $responseParameters->get('entities');
            if (is_iterable($entities)) {
                $projects = [];
                $projectEntities = [];
                foreach ($entities as $entity) {
                    if (!$entity instanceof EntityDto) {
                        continue;
                    }
                    $project = $entity->getInstance();
                    if ($project instanceof Project && null !== $project->id) {
                        $projects[] = $project;
                        $projectEntities[$project->id] = $entity;
                    }
                }
                $statuses = $this->projectIndexStatusFactory->forProjects($projects);
                foreach ($projectEntities as $projectId => $entity) {
                    // EasyAdmin includes field templates without the response context.
                    // Pass the bulk-built statuses through its already-formatted DTOs.
                    $entity->getFields()->getByProperty('indexStatusSummary')?->setFormattedValue($statuses[$projectId]);
                }
            }

            $responseParameters->set('statusTopics', [
                'list' => ProjectStatusTopics::LIST_STATUS,
            ]);

            return $responseParameters;
        }

        if (Crud::PAGE_DETAIL !== $pageName) {
            return $responseParameters;
        }

        $entity = $responseParameters->get('entity');
        $project = $entity instanceof EntityDto ? $entity->getInstance() : null;
        if (!$project instanceof Project || null === $project->id) {
            return $responseParameters;
        }

        $status = $this->projectIndexStatusFactory->forProject($project);
        $responseParameters->set('indexStatus', $status);
        $query = $this->requests->getCurrentRequest()?->query;
        $session = $query?->getInt(ObserverCoverageReader::SESSION_QUERY) ?? 0;
        $before = $query?->getInt(ObserverCoverageReader::BEFORE_QUERY) ?? 0;
        $failureId = $query?->getInt(ObserverCoverageReader::FAILURE_QUERY) ?? 0;
        $coverage = $this->coverage->forProject($project->id, $session, $before);
        // Scope the session to the project before looking up the single linked job.
        $target = $failureId > 0 && $session > 0 && isset($coverage['sessions'][$session])
            ? $this->messengerStats->observerTargetForFailure($failureId, $session)
            : null;
        $responseParameters->set('coverage', $coverage);
        $responseParameters->set('coverageFailure', null === $target ? null : ['id' => $failureId, 'target' => $target]);
        $responseParameters->set('coverageFocused', $session > 0 || $before > 0);
        $responseParameters->set('statusTopics', [
            'list' => ProjectStatusTopics::LIST_STATUS,
            'project' => ProjectStatusTopics::forProject($project->id),
        ]);

        return $responseParameters;
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Project);

        parent::persistEntity($entityManager, $entityInstance);
        $this->projectManager->queueScan($entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Project);

        // Registration removal must never delete source project files.
        $this->projectIndexManager->removeRegistration($entityInstance);
    }

    /**
     * Queues observe signals for the project's existing Claude transcripts and Hatfield journals;
     * never runs models.
     *
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/backfill-sessions', name: 'backfill-sessions', options: ['methods' => [Request::METHOD_POST]])]
    #[IsCsrfTokenValid(self::BACKFILL_SESSIONS_CSRF_ID)]
    public function backfillSessions(AdminContext $context, AdminUrlGenerator $urlGenerator): RedirectResponse
    {
        /** @var Project $project */
        $project = $context->getEntity()->getInstance();
        if (null === $project->id || !$this->sessionBackfill->isAvailable($project)) {
            throw $this->createNotFoundException('Session sources for this project are not available.');
        }

        foreach ($this->sessionBackfill->queue($project) as $result) {
            $this->addFlash('success', \sprintf(
                '%s: queued %d observe job(s) for %d session(s); %d session(s) already covered.',
                match ($result->sourceKind) {
                    MemorySourceKind::ClaudeCode => 'Claude Code',
                    MemorySourceKind::Hatfield => 'Hatfield',
                },
                $result->signals,
                $result->queuedSessions,
                $result->skippedSessions,
            ));
        }

        return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl());
    }

    /**
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/search-memory', name: 'search-memory', options: ['methods' => [Request::METHOD_GET]])]
    public function searchMemory(AdminContext $context, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        /** @var Project $project */
        $project = $context->getEntity()->getInstance();
        if (null === $project->id) {
            throw $this->createNotFoundException('Project registration is missing.');
        }

        $form = $this->createForm(ProjectMemorySearchType::class, null, [
            'action' => $urlGenerator
                ->setController(self::class)
                ->setAction(self::ACTION_SEARCH_MEMORY)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
        ]);
        $form->handleRequest($request);

        $active = $this->indexGenerationRepository->findActiveForProject($project->id);
        $searchError = null;
        $searchResult = null;
        $searched = false;
        $question = '';
        $limit = ProjectMemorySearchType::DEFAULT_LIMIT;

        if ($form->isSubmitted() && $form->isValid()) {
            $searched = true;
            /** @var array{question: string, limit: int} $data */
            $data = $form->getData();
            $question = trim($data['question']);
            $limit = (int) $data['limit'];
            if (null === $active || !$active->state->isUsable() || null === $active->id) {
                $searchError = 'No committed searchable index is active for this project yet.';
            } else {
                try {
                    $searchResult = $this->hybridSearch->search(
                        $project->id,
                        $active->id,
                        $question,
                        $limit,
                    );
                    $searchResult = $this->withStoredRelevance($project->id, $searchResult);
                } catch (IndexException $exception) {
                    $searchError = $exception->getMessage();
                } catch (\InvalidArgumentException $exception) {
                    $searchError = $exception->getMessage();
                }
            }
        }

        return $this->render('admin/project/search_memory.html.twig', [
            'project' => $project,
            'form' => $form,
            'activeGeneration' => $active,
            'searched' => $searched,
            'searchError' => $searchError,
            'searchResult' => $searchResult,
            'searchQuestion' => $question,
            'searchLimit' => $limit,
            'detailUrl' => $urlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
            'omIndexUrl' => $urlGenerator
                ->setController(self::class)
                ->setAction(self::ACTION_OM_INDEX)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
            'recallAction' => self::ACTION_RECALL_MEMORY,
            'returnToSearch' => self::RETURN_SEARCH,
        ]);
    }

    /**
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/om-index', name: 'om-index', options: ['methods' => [Request::METHOD_GET]])]
    public function omIndex(AdminContext $context, Request $request, AdminUrlGenerator $urlGenerator, FormFactoryInterface $forms): Response
    {
        /** @var Project $project */
        $project = $context->getEntity()->getInstance();
        if (null === $project->id) {
            throw $this->createNotFoundException('Project registration is missing.');
        }

        $filter = $forms->createNamedBuilder(self::SESSION_FILTER_FORM, FormType::class, options: [
            'method' => Request::METHOD_GET,
            'csrf_protection' => false,
        ])->add(self::SESSION_FILTER_FIELD, TextType::class, [
            'label' => 'Session ID',
            'required' => false,
        ])->getForm()->handleRequest($request);

        $sessionId = null;
        if ($filter->isSubmitted() && $filter->isValid()) {
            /** @var string|null $sessionId */
            $sessionId = $filter->get(self::SESSION_FILTER_FIELD)->getData();
            $sessionId = '' === $sessionId ? null : $sessionId;
        }
        $filterParams = null === $sessionId ? [] : [self::SESSION_FILTER_FORM => [self::SESSION_FILTER_FIELD => $sessionId]];

        $observations = $this->observations->pageForProject(
            $project->id,
            $request->query->getInt(ObservationRepository::PAGE_QUERY, 1),
            $sessionId,
        );
        $reflections = $this->generations->pageForProject(
            $project->id,
            $request->query->getInt(MemoryGenerationRepository::PAGE_QUERY, 1),
            $sessionId,
        );

        return $this->render('admin/project/om_index.html.twig', [
            'project' => $project,
            'sessionFilter' => $filter->createView(),
            'filterParams' => $filterParams,
            'indexStatus' => $this->projectIndexStatusFactory->forProject($project),
            'observations' => $observations,
            'reflections' => $reflections,
            'observationsPageQuery' => ObservationRepository::PAGE_QUERY,
            'reflectionsPageQuery' => MemoryGenerationRepository::PAGE_QUERY,
            'detailUrl' => $urlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
            'searchUrl' => $urlGenerator
                ->setController(self::class)
                ->setAction(self::ACTION_SEARCH_MEMORY)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
            'recallAction' => self::ACTION_RECALL_MEMORY,
            'returnToIndex' => self::RETURN_INDEX,
        ]);
    }

    /**
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/recall-memory', name: 'recall-memory', options: ['methods' => [Request::METHOD_GET]])]
    public function recallMemory(AdminContext $context, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        /** @var Project $project */
        $project = $context->getEntity()->getInstance();
        if (null === $project->id) {
            throw $this->createNotFoundException('Project registration is missing.');
        }

        $memoryId = strtolower(trim($request->query->getString(self::MEMORY_ID_QUERY)));
        if ('' === $memoryId) {
            throw $this->createNotFoundException('Memory id is required.');
        }

        $returnTo = $request->query->getString(self::RETURN_TO_QUERY, self::RETURN_INDEX);
        $backUrl = match ($returnTo) {
            self::RETURN_SEARCH => $this->searchReturnUrl(
                $urlGenerator,
                $project->id,
                $request->query->getString('question'),
                $request->query->getInt('limit', ProjectMemorySearchType::DEFAULT_LIMIT),
            ),
            default => $this->indexReturnUrl(
                $urlGenerator,
                $project->id,
                $request->query->getInt(ObservationRepository::PAGE_QUERY, 1),
                $request->query->getInt(MemoryGenerationRepository::PAGE_QUERY, 1),
            ),
        };
        $backLabel = self::RETURN_SEARCH === $returnTo ? 'Back to search results' : 'Back to OM index details';

        return $this->render('admin/project/recall_memory.html.twig', [
            'project' => $project,
            'memoryId' => $memoryId,
            'recall' => $this->memoryRecall->recall($project, $memoryId),
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
            'detailUrl' => $urlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
        ]);
    }

    /**
     * @return array{results: list<array<string, mixed>>, truncated: bool, candidateTruncated: bool, resultTruncated: bool}
     */
    private function withStoredRelevance(int $projectId, \App\Index\Search\SearchResult $searchResult): array
    {
        $observationIds = [];
        foreach ($searchResult->results as $hit) {
            if (MemoryKind::Observation->value !== $hit['kind']) {
                continue;
            }
            $observationIds[$hit['source_id']] = $hit['source_id'];
        }

        $relevanceById = [];
        $ids = array_values($observationIds);
        if ([] !== $ids) {
            foreach ($this->canonicalSourceReader->fetchByIds($projectId, MemoryKind::Observation, $ids) as $memory) {
                $relevanceById[$memory->sourceId] = $memory->relevance;
            }
        }

        $results = [];
        foreach ($searchResult->results as $hit) {
            if (MemoryKind::Observation->value === $hit['kind']) {
                $hit['relevance'] = $relevanceById[$hit['source_id']] ?? null;
            }
            $results[] = $hit;
        }

        return [
            'results' => $results,
            'truncated' => $searchResult->truncated,
            'candidateTruncated' => $searchResult->candidateTruncated,
            'resultTruncated' => $searchResult->resultTruncated,
        ];
    }

    private function indexReturnUrl(AdminUrlGenerator $urlGenerator, int $projectId, int $observationsPage, int $reflectionsPage): string
    {
        $generator = $urlGenerator
            ->setController(self::class)
            ->setAction(self::ACTION_OM_INDEX)
            ->setEntityId((string) $projectId);
        if ($observationsPage > 1) {
            $generator->set(ObservationRepository::PAGE_QUERY, $observationsPage);
        }
        if ($reflectionsPage > 1) {
            $generator->set(MemoryGenerationRepository::PAGE_QUERY, $reflectionsPage);
        }

        return $generator->generateUrl();
    }

    private function searchReturnUrl(AdminUrlGenerator $urlGenerator, int $projectId, string $question, int $limit): string
    {
        $generator = $urlGenerator
            ->setController(self::class)
            ->setAction(self::ACTION_SEARCH_MEMORY)
            ->setEntityId((string) $projectId);
        if ('' === $question) {
            return $generator->generateUrl();
        }

        return $generator
            ->set(ProjectMemorySearchType::FORM_NAME, [
                'question' => $question,
                'limit' => max(1, $limit),
                'search' => '',
            ])
            ->generateUrl();
    }

    /**
     * @return array<string, ProjectHealth>
     */
    private function healthChoices(): array
    {
        $choices = [];
        foreach (ProjectHealth::cases() as $health) {
            $choices[$health->label()] = $health;
        }

        return $choices;
    }
}
