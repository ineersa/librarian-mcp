<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Form\Type\ProjectDirectoryType;
use App\Project\ProjectManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * @extends AbstractCrudController<Project>
 */
#[AdminRoute(path: '/projects')]
class ProjectCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ProjectManager $projectManager,
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

        yield TextField::new('lastSuccessfulIndexAt', 'Last index')
            ->onlyOnDetail()
            ->setVirtual(true)
            ->formatValue(static fn (): string => 'Not indexed (plan04)');
    }

    public function configureActions(Actions $actions): Actions
    {
        $scanSources = Action::new('scanSources', 'Scan sources', 'fa fa-search')
            ->linkToCrudAction('scanSources')
            ->setTemplatePath('admin/project/action/scan_sources.html.twig')
            ->addCssClass('btn btn-primary');

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Register Project'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $scanSources)
            ->add(Crud::PAGE_DETAIL, $scanSources)
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
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, 'scanSources', Action::EDIT, Action::DELETE])
            ->setPermission(Action::DELETE, 'ROLE_ADMIN');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('name')
            ->add('health');
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
        $entityManager->remove($entityInstance);
        $entityManager->flush();
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

        if (!$this->isCsrfTokenValid('project-scan-sources', $csrfToken)) {
            throw new InvalidCsrfTokenException('Invalid Scan sources CSRF token.');
        }

        /** @var Project $project */
        $project = $context->getEntity()->getInstance();

        $this->projectManager->queueScan($project);
        $this->addFlash('success', \sprintf('Project "%s" queued for source discovery.', $project->name));

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
