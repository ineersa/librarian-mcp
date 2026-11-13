<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Form\Type\ProjectMemorySearchType;
use App\Index\IndexException;
use App\Index\ProjectIndexManager;
use App\Index\Search\HybridSearch;
use App\Index\Status\ProjectIndexStatus;
use App\Index\Status\ProjectIndexStatusFactory;
use App\Index\Status\ProjectStatusTopics;
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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * @extends AbstractCrudController<Project>
 */
#[AdminRoute(path: '/projects')]
class ProjectCrudController extends AbstractCrudController
{
    public const string CSRF_SCAN_SOURCES = 'project-scan-sources';
    public const string CSRF_CHECK_UPDATES = 'project-check-updates';

    private const string ACTION_SCAN_SOURCES = 'scanSources';
    private const string ACTION_CHECK_UPDATES = 'checkUpdates';
    private const string ACTION_SEARCH_MEMORY = 'searchMemory';

    public function __construct(
        private readonly ProjectManager $projectManager,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly IndexGenerationRepository $indexGenerationRepository,
        private readonly ProjectIndexStatusFactory $projectIndexStatusFactory,
        private readonly HybridSearch $hybridSearch,
        private readonly ClockInterface $clock,
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
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Project $project) => \sprintf('Edit Project: %s', $project->name))
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Project $project) => \sprintf('Project: %s', $project->name))
            ->setSearchFields(['name', 'rootPath', 'repositoryOrigin'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/detail', 'admin/project/detail.html.twig')
            ->overrideTemplate('crud/index', 'admin/project/index.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('name')
            ->setHelp('Must be unique. Renaming keeps the same project ID and does not merge data by name or origin.');

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
            ->formatValue(function (mixed $value, ?Project $entity): ?ProjectIndexStatus {
                if (!$entity instanceof Project || null === $entity->id) {
                    return null;
                }

                return $this->projectIndexStatusFactory->forProject($entity);
            })
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
            ->setHelp('Discovered source observations, not indexed memories.');

        yield IntegerField::new('reflectionCount', 'Reflections')
            ->hideOnForm()
            ->setHelp('Discovered source reflections, not indexed memories.');

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
            ->hideOnForm();

        yield DateTimeField::new('lastSuccessfulScanAt', 'Last successful scan')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('lastSuccessfulIndexAt', 'Last index')
            ->onlyOnDetail()
            ->formatValue(static function (mixed $value): string {
                if (!$value instanceof \DateTimeInterface) {
                    return 'Never indexed';
                }

                return $value->format(\DATE_ATOM);
            });
    }

    public function configureActions(Actions $actions): Actions
    {
        $scanSources = Action::new(self::ACTION_SCAN_SOURCES, 'Scan sources', 'fa fa-search')
            ->linkToCrudAction(self::ACTION_SCAN_SOURCES)
            ->setTemplatePath('admin/project/action/scan_sources.html.twig')
            ->addCssClass('btn btn-primary');

        $checkUpdates = Action::new(self::ACTION_CHECK_UPDATES, 'Check for updates', 'fa fa-rotate')
            ->linkToCrudAction(self::ACTION_CHECK_UPDATES)
            ->setTemplatePath('admin/project/action/check_updates.html.twig')
            ->addCssClass('btn btn-outline-secondary')
            ->displayIf(function (Project $project): bool {
                if (!$project->health->isReady() || null === $project->id) {
                    return false;
                }

                return !$this->projectIndexStatusFactory->forProject($project)->pending;
            });

        $searchMemory = Action::new(self::ACTION_SEARCH_MEMORY, 'Search memory', 'fa fa-magnifying-glass')
            ->linkToCrudAction(self::ACTION_SEARCH_MEMORY)
            ->addCssClass('btn btn-outline-primary');

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Register Project'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $scanSources)
            ->add(Crud::PAGE_INDEX, $checkUpdates)
            ->add(Crud::PAGE_DETAIL, $scanSources)
            ->add(Crud::PAGE_DETAIL, $checkUpdates)
            ->add(Crud::PAGE_DETAIL, $searchMemory)
            ->add(Crud::PAGE_EDIT, $scanSources)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action
                ->setLabel('Details')
                ->setIcon('fa fa-eye'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action) => $action
                ->setLabel('Edit')
                ->setIcon('fa fa-pen'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action
                ->setLabel('Delete')
                ->setIcon('fa fa-trash'))
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, self::ACTION_SCAN_SOURCES, self::ACTION_CHECK_UPDATES, Action::EDIT, Action::DELETE])
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
                foreach ($entities as $entity) {
                    $project = $entity instanceof EntityDto ? $entity->getInstance() : null;
                    if ($project instanceof Project) {
                        $projects[] = $project;
                    }
                }
                $this->projectIndexStatusFactory->warm($projects);
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

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Project);

        parent::updateEntity($entityManager, $entityInstance);
        $this->projectManager->queueScan($entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Project);

        // Registration removal must never delete source project files.
        $this->projectIndexManager->removeRegistration($entityInstance);
    }

    /**
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/scan-sources', name: 'scan-sources', options: ['methods' => [Request::METHOD_POST]])]
    public function scanSources(AdminContext $context, AdminUrlGenerator $urlGenerator): RedirectResponse
    {
        $request = $context->getRequest();
        $csrfToken = $request->request->has('token')
            ? (string) $request->request->get('token')
            : null;

        if (!$this->isCsrfTokenValid(self::CSRF_SCAN_SOURCES, $csrfToken)) {
            throw new InvalidCsrfTokenException('Invalid Scan sources CSRF token.');
        }

        /** @var Project $project */
        $project = $context->getEntity()->getInstance();

        $this->projectManager->queueScan($project);
        $this->addFlash('success', \sprintf('Project "%s" queued for source discovery.', $project->name));

        return $this->redirectToDetail($urlGenerator, $project);
    }

    /**
     * Optional incremental check. Automatic indexing already runs on a schedule.
     *
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/check-updates', name: 'check-updates', options: ['methods' => [Request::METHOD_POST]])]
    public function checkUpdates(AdminContext $context, AdminUrlGenerator $urlGenerator): RedirectResponse
    {
        $request = $context->getRequest();
        $csrfToken = $request->request->has('token')
            ? (string) $request->request->get('token')
            : null;

        if (!$this->isCsrfTokenValid(self::CSRF_CHECK_UPDATES, $csrfToken)) {
            throw new InvalidCsrfTokenException('Invalid Check for updates CSRF token.');
        }

        /** @var Project $project */
        $project = $context->getEntity()->getInstance();
        if (!$project->health->isReady()) {
            $this->addFlash('warning', 'Source discovery must finish before checking for index updates.');

            return $this->redirectToDetail($urlGenerator, $project);
        }

        $status = null !== $project->id ? $this->projectIndexStatusFactory->forProject($project) : null;
        if (null !== $status && $status->pending) {
            $this->addFlash('info', \sprintf('Project "%s" is already indexing. Wait for the live status to finish.', $project->name));

            return $this->redirectToDetail($urlGenerator, $project);
        }

        $this->projectIndexManager->queue($project);
        $this->addFlash('success', \sprintf('Queued an optional index check for "%s". Automatic indexing continues on its schedule.', $project->name));

        return $this->redirectToDetail($urlGenerator, $project);
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

        if ($form->isSubmitted() && $form->isValid()) {
            $searched = true;
            /** @var array{question: string, limit: int} $data */
            $data = $form->getData();
            if (null === $active || !$active->state->isUsable() || null === $active->id) {
                $searchError = 'No committed searchable index is active for this project yet.';
            } else {
                try {
                    $searchResult = $this->hybridSearch->search(
                        $project->id,
                        $active->id,
                        trim($data['question']),
                        (int) $data['limit'],
                    );
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
            'detailUrl' => $urlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
        ]);
    }

    private function redirectToDetail(AdminUrlGenerator $urlGenerator, Project $project): RedirectResponse
    {
        return new RedirectResponse(
            $urlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId((string) $project->id)
                ->generateUrl(),
        );
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
