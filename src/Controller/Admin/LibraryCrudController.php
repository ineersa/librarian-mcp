<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use App\Service\LibraryManager;
use App\Vera\VeraIndexingConfig;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @extends AbstractCrudController<Library>
 */
#[AdminRoute(path: '/libraries')]
class LibraryCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LibraryManager $libraryManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Library::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Library')
            ->setEntityLabelInPlural('Libraries')
            ->setPageTitle(Crud::PAGE_INDEX, 'Libraries')
            ->setPageTitle(Crud::PAGE_NEW, 'Add Library')
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Library $library) => \sprintf('Edit Library: %s', $library->getName()))
            ->setSearchFields(['name', 'slug', 'gitUrl', 'branch'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->overrideTemplate('crud/index', 'admin/library/index.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('name')
            ->setHelp('Auto-derived from git URL + branch. Override if needed.');

        yield TextField::new('slug')
            ->setHelp('MCP identifier. Auto-generated from name, editable.');

        yield TextField::new('gitUrl')
            ->setLabel('Git URL')
            ->setHelp('GitHub HTTPS URL (e.g. https://github.com/symfony/symfony-docs)');

        yield TextField::new('branch')
            ->setHelp('Git branch (default: main)');

        yield TextareaField::new('description')
            ->hideOnIndex();

        yield TextField::new('path')
            ->hideOnForm()
            ->setHelp('Computed storage path (owner/repo/branch)');

        yield ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(static fn (LibraryStatus $s) => $s->value, LibraryStatus::cases()),
                LibraryStatus::cases(),
            ))
            ->renderAsBadges()
            ->setTemplatePath('admin/library/field/status.html.twig')
            ->hideOnForm();

        yield CodeEditorField::new('lastError')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('lastSyncedAt')->hideOnForm();
        yield DateTimeField::new('lastIndexedAt')->hideOnForm();

        // Virtual veraConfig fields (form only)
        yield TextareaField::new('excludePatterns', 'Exclude Patterns')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setHelp('One glob pattern per line (e.g. _build/**, **/*.rst.inc)')
            ->setFormTypeOption('attr.placeholder', "_build/**\n_images/**\n**/*.rst.inc\n.alexrc\n.doctor-rst.yaml");

        yield BooleanField::new('noIgnore', 'No Ignore')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setHelp('Pass --no-ignore to vera index');

        yield BooleanField::new('noDefaultExcludes', 'No Default Excludes')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setHelp('Pass --no-default-excludes to vera index');

        // Detail-only: show current veraConfig as read-only
        yield CodeEditorField::new('veraConfig', 'Vera Config')
            ->onlyOnDetail()
            ->formatValue(static fn (?VeraIndexingConfig $config) => null !== $config ? json_encode($config->toArray(), \JSON_PRETTY_PRINT) : 'Not configured');
    }

    public function configureActions(Actions $actions): Actions
    {
        $syncNow = Action::new('syncNow', 'Sync Now', 'fa fa-sync')
            ->linkToCrudAction('syncLibrary')
            ->addCssClass('btn btn-primary');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $syncNow)
            ->add(Crud::PAGE_DETAIL, $syncNow)
            ->setPermission(Action::DELETE, 'ROLE_ADMIN');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('name')
            ->add('status');
    }

    public function createEntity(string $entityFqcn): Library
    {
        $library = new Library();
        $library->setBranch('main');

        return $library;
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Library);

        $this->applyVeraConfigFromForm($entityInstance);
        $this->libraryManager->create($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Library);

        $this->applyVeraConfigFromForm($entityInstance);
        $this->libraryManager->update($entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        \assert($entityInstance instanceof Library);
        $this->libraryManager->delete($entityInstance);
    }

    /**
     * Custom action: dispatch sync message and set status to queued.
     */
    #[AdminRoute(path: '/{entityId}/sync', name: 'sync')]
    public function syncLibrary(AdminContext $context, AdminUrlGenerator $urlGenerator): RedirectResponse
    {
        /** @var Library $library */
        $library = $context->getEntity()->getInstance();

        try {
            $this->libraryManager->markQueued($library);
            $this->addFlash('success', \sprintf('Library "%s" queued for sync.', $library->getName()));
        } catch (\LogicException $e) {
            $this->addFlash('danger', \sprintf('Cannot sync: %s', $e->getMessage()));
        }

        return new RedirectResponse(
            $urlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl(),
        );
    }

    /**
     * Read virtual form fields and assemble VeraIndexingConfig.
     */
    private function applyVeraConfigFromForm(Library $library): void
    {
        $request = $this->getContext()?->getRequest();
        if (null === $request) {
            return;
        }

        $formData = $request->request->all()['Library'] ?? [];

        $excludePatterns = [];
        $rawPatterns = $formData['excludePatterns'] ?? '';
        if (\is_string($rawPatterns) && '' !== trim($rawPatterns)) {
            $excludePatterns = explode("\n", $rawPatterns)
                    |> (static fn ($x) => array_map('trim', $x))
                    |> (static fn ($x) => array_filter($x, static fn (string $line) => '' !== $line));
        }

        $noIgnore = isset($formData['noIgnore']) && filter_var($formData['noIgnore'], \FILTER_VALIDATE_BOOLEAN);
        $noDefaultExcludes = isset($formData['noDefaultExcludes']) && filter_var($formData['noDefaultExcludes'], \FILTER_VALIDATE_BOOLEAN);

        $library->setVeraConfig(new VeraIndexingConfig(
            excludePatterns: $excludePatterns,
            noIgnore: $noIgnore,
            noDefaultExcludes: $noDefaultExcludes,
        ));
    }
}
