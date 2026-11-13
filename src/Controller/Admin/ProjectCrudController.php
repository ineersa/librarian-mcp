<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IndexGeneration;
use App\Entity\IndexState;
use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Form\Type\ProjectDirectoryType;
use App\Form\Type\ProjectMemorySearchType;
use App\Index\IndexException;
use App\Index\ProjectIndexManager;
use App\Index\Search\HybridSearch;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * @extends AbstractCrudController<Project>
 */
#[AdminRoute(path: '/projects')]
class ProjectCrudController extends AbstractCrudController
{
    public const string CSRF_SCAN_SOURCES = 'project-scan-sources';
    public const string CSRF_INDEX_NOW = 'project-index-now';

    public function __construct(
        private readonly ProjectManager $projectManager,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly IndexGenerationRepository $indexGenerationRepository,
        private readonly HybridSearch $hybridSearch,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            ->setFormType(ProjectDirectoryType::class)
            ->setFormTypeOptions([
                'browse_url' => $this->urlGenerator->generate('admin_project_directories'),
            ])
            ->addFormTheme('admin/project/form_theme.html.twig')
            ->setHelp('Choose a service-visible directory. Changing the path rebinds this project ID; it does not merge by name or Git origin. Host directories require a deliberate Docker mount and PROJECT_BROWSE_ROOT.');

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
            ->hideOnForm();

        yield IntegerField::new('reflectionCount', 'Reflections')
            ->hideOnForm();

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
        $scanSources = Action::new('scanSources', 'Scan sources', 'fa fa-search')
            ->linkToCrudAction('scanSources')
            ->setTemplatePath('admin/project/action/scan_sources.html.twig')
            ->addCssClass('btn btn-primary');

        $indexNow = Action::new('indexNow', 'Index now', 'fa fa-database')
            ->linkToCrudAction('indexNow')
            ->setTemplatePath('admin/project/action/index_now.html.twig')
            ->addCssClass('btn btn-secondary');

        $searchMemory = Action::new('searchMemory', 'Search memory', 'fa fa-magnifying-glass')
            ->linkToCrudAction('searchMemory')
            ->addCssClass('btn btn-outline-primary');

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Register Project'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $scanSources)
            ->add(Crud::PAGE_INDEX, $indexNow)
            ->add(Crud::PAGE_DETAIL, $scanSources)
            ->add(Crud::PAGE_DETAIL, $indexNow)
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
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, 'scanSources', 'indexNow', Action::EDIT, Action::DELETE])
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
        if (Crud::PAGE_DETAIL !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $entity = $responseParameters->get('entity');
        $project = $entity instanceof EntityDto ? $entity->getInstance() : null;
        if (!$project instanceof Project || null === $project->id) {
            return $responseParameters;
        }

        $latest = $this->indexGenerationRepository->findLatestForProject($project->id);
        $active = $this->indexGenerationRepository->findActiveForProject($project->id);
        $responseParameters->set('indexStatus', $this->buildIndexStatus($project, $latest, $active));

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
    #[AdminRoute(path: '/{entityId}/scan-sources', name: 'scan-sources', options: ['methods' => ['POST']])]
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
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/index-now', name: 'index-now', options: ['methods' => ['POST']])]
    public function indexNow(AdminContext $context, AdminUrlGenerator $urlGenerator): RedirectResponse
    {
        $request = $context->getRequest();
        $csrfToken = $request->request->has('token')
            ? (string) $request->request->get('token')
            : null;

        if (!$this->isCsrfTokenValid(self::CSRF_INDEX_NOW, $csrfToken)) {
            throw new InvalidCsrfTokenException('Invalid Index now CSRF token.');
        }

        /** @var Project $project */
        $project = $context->getEntity()->getInstance();
        $this->projectIndexManager->queue($project);
        $this->addFlash('success', \sprintf('Project "%s" queued for indexing.', $project->name));

        return $this->redirectToDetail($urlGenerator, $project);
    }

    /**
     * @param AdminContext<Project> $context
     */
    #[AdminRoute(path: '/{entityId}/search-memory', name: 'search-memory', options: ['methods' => ['GET']])]
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
                ->setAction('searchMemory')
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
            /** @var array{question: string, from?: ?\DateTimeImmutable, until?: ?\DateTimeImmutable, limit: int} $data */
            $data = $form->getData();
            if (null === $active || !$active->state->isUsable() || null === $active->id) {
                $searchError = 'No committed searchable index is active for this project yet.';
            } else {
                try {
                    $searchResult = $this->hybridSearch->search(
                        $project->id,
                        $active->id,
                        trim($data['question']),
                        $this->inclusiveFromTimestamp($data['from'] ?? null),
                        $this->exclusiveUntilTimestamp($data['until'] ?? null),
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

    /**
     * @return array{
     *     latest: ?IndexGeneration,
     *     active: ?IndexGeneration,
     *     statusLabel: string,
     *     statusValue: string,
     *     pending: bool,
     *     stale: bool,
     *     error: ?string,
     *     lastIndexAt: ?\DateTimeImmutable
     * }
     */
    private function buildIndexStatus(Project $project, ?IndexGeneration $latest, ?IndexGeneration $active): array
    {
        $pending = null !== $latest && $latest->state->isInProgress();
        $stale = false;
        if (
            null !== $active
            && null !== $project->sourceRevision
            && null !== $active->committedRevision
            && $active->committedRevision < $project->sourceRevision
        ) {
            $stale = true;
        }

        $statusValue = 'none';
        $statusLabel = 'Not indexed';
        $error = null;

        if (null !== $latest) {
            $statusValue = $latest->state->value;
            $statusLabel = $latest->state->label();
            $error = $latest->lastError;
            if ($pending) {
                $statusLabel = IndexState::Building === $latest->state ? 'Indexing' : 'Updating index';
            } elseif ($stale && $latest->state->isUsable()) {
                $statusLabel = 'Index stale vs source revision';
                $statusValue = 'stale';
            }
        }

        return [
            'latest' => $latest,
            'active' => $active,
            'statusLabel' => $statusLabel,
            'statusValue' => $statusValue,
            'pending' => $pending,
            'stale' => $stale,
            'error' => $error,
            'lastIndexAt' => $project->lastSuccessfulIndexAt,
        ];
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

    private function inclusiveFromTimestamp(?\DateTimeImmutable $from): ?int
    {
        if (null === $from) {
            return null;
        }

        return $from->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0, 0)->getTimestamp();
    }

    private function exclusiveUntilTimestamp(?\DateTimeImmutable $until): ?int
    {
        if (null === $until) {
            return null;
        }

        return $until->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0, 0)->getTimestamp();
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
