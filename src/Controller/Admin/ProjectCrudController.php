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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends AbstractCrudController<Project>
 */
#[AdminRoute(path: '/projects')]
class ProjectCrudController extends AbstractCrudController
{
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
            ->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $searchMemory = Action::new(self::ACTION_SEARCH_MEMORY, 'Search memory', 'fa fa-magnifying-glass')
            ->linkToCrudAction(self::ACTION_SEARCH_MEMORY)
            ->addCssClass('btn btn-outline-primary');

        return $actions
            ->disable(Action::EDIT)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Register Project'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // EasyAdmin resolves row navigation from configured entity actions.
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action
                ->addCssClass('d-none'))
            ->add(Crud::PAGE_INDEX, $searchMemory)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action
                ->setLabel('Delete')
                ->setIcon('fa fa-trash'))
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, self::ACTION_SEARCH_MEMORY, Action::DELETE])
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

    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Project);

        // Registration removal must never delete source project files.
        $this->projectIndexManager->removeRegistration($entityInstance);
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
