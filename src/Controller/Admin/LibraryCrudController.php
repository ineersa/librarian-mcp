<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use App\Service\LibraryManager;
use App\Vera\VeraCli;
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
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractCrudController<Library>
 */
#[AdminRoute(path: '/libraries')]
class LibraryCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LibraryManager $libraryManager,
        private readonly VeraCli $veraCli,
        private readonly RequestStack $requestStack,
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
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', 'admin/library/index.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('gitUrl')
            ->setLabel('Git URL')
            ->setHelp('GitHub HTTPS URL (e.g. https://github.com/symfony/symfony-docs)')
            ->setFormTypeOption('priority', 1000)
            ->setFormTypeOption('attr.data-controller', 'library-form');

        yield TextField::new('name')
            ->setHelp('Auto-generated from git URL + branch (e.g. symfony/symfony-docs or symfony/symfony-docs@6.4). Override if needed.');

        yield TextField::new('slug')
            ->setHelp('Auto-generated identifier used for search. Use package-style slug (owner/repo@version preferred, e.g. symfony/symfony-docs or symfony/symfony-docs@6.4).');

        yield TextField::new('branch')
            ->setHelp('Git branch (default: main). For non-main branches, @branch is appended to name + slug.');

        yield TextareaField::new('description')
            ->hideOnIndex();

        yield TextField::new('path')
            ->hideOnForm()
            ->hideOnIndex()
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
        yield DateTimeField::new('lastIndexedAt')->hideOnForm()->hideOnIndex();

        // Virtual veraConfig fields (form only)
        $formVeraConfig = $this->resolveFormVeraConfig($pageName);

        $excludePatternsField = TextareaField::new('excludePatterns', 'Exclude Patterns')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setHelp('One glob pattern per line (e.g. _build/**, **/*.rst.inc)');

        if (Crud::PAGE_NEW === $pageName) {
            $excludePatternsField->setFormTypeOption('data', "_build/**\n_images/**\n**/*.rst.inc\n.alexrc\n.doctor-rst.yaml");
        } elseif (null !== $formVeraConfig) {
            $excludePatternsField->setFormTypeOption('data', implode("\n", $formVeraConfig->excludePatterns));
        }

        yield $excludePatternsField;

        yield BooleanField::new('noDefaultExcludes', 'No Default Excludes')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('data', null !== $formVeraConfig ? $formVeraConfig->noDefaultExcludes : false)
            ->setFormTypeOption('help_html', true)
            ->setHelp($this->buildNoDefaultExcludesHelp($pageName));

        $defaultExcludes = $this->resolveDefaultExcludes($pageName);
        if ([] !== $defaultExcludes) {
            yield TextareaField::new('defaultExcludesPreview', 'Vera Default Excludes')
                ->onlyOnForms()
                ->setFormTypeOption('mapped', false)
                ->setFormTypeOption('disabled', true)
                ->setFormTypeOption('data', implode("\n", $defaultExcludes))
                ->setHelp('Read from vera config: indexing.default_excludes');
        }

        // Detail-only: show current veraConfig as read-only
        yield CodeEditorField::new('veraConfig', 'Vera Config')
            ->onlyOnDetail()
            ->formatValue(static fn (?VeraIndexingConfig $config) => null !== $config ? json_encode($config->toArray(), \JSON_PRETTY_PRINT) : 'Not configured');

        // Detail-only: show latest overview output captured for this library
        yield CodeEditorField::new('veraOverview', 'Vera Overview')
            ->onlyOnDetail()
            ->setVirtual(true)
            ->setValue('')
            ->setLanguage('javascript')
            ->setNumOfRows(30)
            ->formatValue(function (mixed $value, Library $library): string {
                $request = $this->requestStack->getCurrentRequest();
                $session = $request?->getSession();

                if (null === $session || null === $library->getId()) {
                    return 'Run the "Overview" action to fetch vera overview JSON for this library.';
                }

                $key = $this->buildOverviewSessionKey($library->getId());
                $overview = $session->get($key);

                return \is_string($overview) && '' !== $overview
                    ? $overview
                    : 'Run the "Overview" action to fetch vera overview JSON for this library.';
            });
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
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action
                ->setLabel('Details')
                ->setIcon('fa fa-eye'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action) => $action
                ->setLabel('Edit')
                ->setIcon('fa fa-pen'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action
                ->setLabel('Delete')
                ->setIcon('fa fa-trash'))
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, 'syncNow', Action::EDIT, Action::DELETE])
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
     *
     * @param AdminContext<Library> $context
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
     * Custom action: run `vera overview --json` for this library and show result on detail page.
     *
     * @param AdminContext<Library> $context
     */
    #[AdminRoute(path: '/{entityId}/overview', name: 'overview')]
    public function overviewLibrary(AdminContext $context, AdminUrlGenerator $urlGenerator): RedirectResponse
    {
        /** @var Library $library */
        $library = $context->getEntity()->getInstance();

        try {
            $overview = $this->veraCli->overviewLibrary($this->libraryManager->getAbsolutePath($library));
            $json = json_encode($overview, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

            $libraryId = $library->getId();
            if (null !== $libraryId) {
                $context->getRequest()->getSession()->set($this->buildOverviewSessionKey($libraryId), $json);
            }

            $this->addFlash('success', 'Vera overview fetched successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', \sprintf('Overview failed: %s', $e->getMessage()));
        }

        return new RedirectResponse(
            $urlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId((string) $library->getId())
                ->generateUrl(),
        );
    }

    private function resolveFormVeraConfig(string $pageName): ?VeraIndexingConfig
    {
        if (Crud::PAGE_EDIT !== $pageName) {
            return null;
        }

        $entity = $this->getContext()?->getEntity()?->getInstance();

        return $entity instanceof Library ? $entity->getVeraConfig() : null;
    }

    private function buildNoDefaultExcludesHelp(string $pageName): string
    {
        $base = 'Pass <code>--no-default-excludes</code> to <code>vera index</code>.';

        $defaults = $this->resolveDefaultExcludes($pageName);
        if ([] === $defaults) {
            return $base;
        }

        $escaped = array_map(static fn (string $x): string => htmlspecialchars($x, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $defaults);

        return $base.'<br><small>Default excludes from vera config: <code>'.implode('</code>, <code>', $escaped).'</code></small>';
    }

    /** @return array<string> */
    private function resolveDefaultExcludes(string $pageName): array
    {
        if (Crud::PAGE_NEW !== $pageName && Crud::PAGE_EDIT !== $pageName) {
            return [];
        }

        try {
            return $this->veraCli->getIndexingDefaultExcludes();
        } catch (\Throwable) {
            return [];
        }
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

        $noDefaultExcludes = isset($formData['noDefaultExcludes']) && filter_var($formData['noDefaultExcludes'], \FILTER_VALIDATE_BOOLEAN);

        $library->setVeraConfig(new VeraIndexingConfig(
            excludePatterns: $excludePatterns,
            noDefaultExcludes: $noDefaultExcludes,
        ));
    }

    private function buildOverviewSessionKey(int $libraryId): string
    {
        return \sprintf('admin.library.vera_overview.%d', $libraryId);
    }
}
