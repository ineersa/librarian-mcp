<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LibraryStatus;
use App\Mcp\Tool\GrepTool;
use App\Mcp\Tool\ReadTool;
use App\Mcp\Tool\SearchLibrariesTool;
use App\Mcp\Tool\SearchScope;
use App\Mcp\Tool\SemanticSearchTool;
use App\Mcp\Tool\SymbolType;
use App\Repository\LibraryRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly RequestStack $requestStack,
        private readonly LibraryRepository $libraryRepository,
        private readonly SearchLibrariesTool $searchLibrariesTool,
        private readonly SemanticSearchTool $semanticSearchTool,
        private readonly ReadTool $readTool,
        private readonly GrepTool $grepTool,
    ) {
    }

    public function index(): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            throw new \LogicException('No current request available.');
        }

        $readyLibraryChoices = $this->buildReadyLibraryChoices();

        $searchLibrariesForm = $this->createSearchLibrariesForm();
        $semanticSearchForm = $this->createSemanticSearchForm($readyLibraryChoices);
        $readForm = $this->createReadForm($readyLibraryChoices);
        $grepForm = $this->createGrepForm($readyLibraryChoices);

        $forms = [
            'search_libraries' => $searchLibrariesForm,
            'semantic_search' => $semanticSearchForm,
            'read' => $readForm,
            'grep' => $grepForm,
        ];

        $results = [];
        $activeWidget = null;

        foreach ($forms as $widget => $form) {
            $form->handleRequest($request);

            if (!$form->isSubmitted()) {
                continue;
            }

            $activeWidget = $widget;
            if (!$form->isValid()) {
                continue;
            }

            $data = $form->getData();

            $result = match ($widget) {
                'search_libraries' => $this->searchLibrariesTool->search($data['query'], $data['limit']),
                'semantic_search' => $this->semanticSearchTool->search(
                    $data['library'],
                    $data['query'],
                    null !== $data['lang'] ? trim($data['lang']) : null,
                    null !== $data['path'] ? trim($data['path']) : null,
                    null !== $data['type'] ? SymbolType::from($data['type']) : null,
                    null !== $data['scope'] ? SearchScope::from($data['scope']) : null,
                    $data['limit'],
                ),
                'read' => $this->readTool->read($data['library'], $data['file'], $data['offset'], $data['limit']),
                'grep' => $this->grepTool->grep(
                    $data['library'],
                    $data['pattern'],
                    $data['ignoreCase'],
                    $data['context'],
                    null !== $data['scope'] ? SearchScope::from($data['scope']) : null,
                    $data['limit'],
                ),
            };

            $results[$widget] = $this->normalizeToolResult($result);
            break;
        }

        return $this->render('admin/dashboard.html.twig', [
            'searchLibrariesForm' => $searchLibrariesForm->createView(),
            'semanticSearchForm' => $semanticSearchForm->createView(),
            'readForm' => $readForm->createView(),
            'grepForm' => $grepForm->createView(),
            'toolResults' => $results,
            'activeWidget' => $activeWidget,
            'readyLibrariesCount' => \count($readyLibraryChoices),
            'toolDescriptions' => [
                'search_libraries' => 'Find libraries in the catalog that match a query. Returns ready libraries ranked by relevance (DB metadata + semantic match). Each result includes slug, description, git URL, last indexed time, and match reason. Use the returned slug as the `library` parameter for semantic-search, grep, and read. Returns [] when no libraries match.',
                'semantic_search' => 'Run a semantic code search inside one ready library. Uses hybrid BM25 + vector similarity with optional reranking. By default, search is source-biased; use scope=docs for documentation-focused queries. Returns ranked code chunks with file path, line range, content, and symbol type.',
                'read' => 'Read a text file window from one ready library. Best used after semantic-search or grep to inspect exact matched lines. Returns a line-based slice with line numbers, similar to `sed -n`. Access is sandboxed: only files previously discovered via semantic-search or grep are readable, and the path must stay inside the library repository root.',
                'grep' => 'Run a regex pattern search inside one ready library. Searches indexed file contents with surrounding context lines. Searches only files included in the current index state/exclusions. Returns matches with file path, line range, and content.',
            ],
        ]);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('css/admin.css')
            ->addAssetMapperEntry('app');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Librarian MCP')
            ->setFaviconPath('favicon.svg')
            ->renderContentMaximized()
            ->renderSidebarMinimized(false)
            ->setDefaultColorScheme('dark');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Management');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fas fa-users');

        yield MenuItem::section('Libraries');
        yield MenuItem::linkTo(LibraryCrudController::class, 'Libraries', 'fas fa-book');

        yield MenuItem::section();
        yield MenuItem::linkToLogout('Logout', 'fa fa-sign-out');
        yield MenuItem::linkToRoute('Back to site', 'fas fa-arrow-left', 'app_home');
    }

    /** @return array<string, string> */
    private function buildReadyLibraryChoices(): array
    {
        $readyLibraries = $this->libraryRepository->findBy(['status' => LibraryStatus::Ready], ['name' => 'ASC']);
        $choices = [];

        foreach ($readyLibraries as $library) {
            $choices[\sprintf('%s (%s)', $library->getName(), $library->getSlug())] = $library->getSlug();
        }

        return $choices;
    }

    private function createSearchLibrariesForm(): FormInterface
    {
        return $this->formFactory->createNamedBuilder('search_libraries')
            ->add('query', TextareaType::class, [
                'label' => 'query',
                'help' => 'Natural-language query (e.g. "symfony docs", "easyadmin", "react router")',
                'required' => true,
                'attr' => [
                    'rows' => 1,
                    'class' => 'mcp-textarea-lg',
                ],
            ])
            ->add('limit', IntegerType::class, [
                'label' => 'limit',
                'help' => 'Max number of libraries to return (1..50)',
                'required' => false,
                'empty_data' => '10',
                'attr' => ['min' => 1, 'max' => 50],
            ])
            ->getForm();
    }

    /** @param array<string, string> $readyLibraryChoices */
    private function createSemanticSearchForm(array $readyLibraryChoices): FormInterface
    {
        return $this->formFactory->createNamedBuilder('semantic_search')
            ->add('library', ChoiceType::class, [
                'label' => 'library',
                'help' => 'Ready library slug, e.g. "symfony/symfony-docs@8.0"',
                'choices' => $readyLibraryChoices,
                'placeholder' => 0 === \count($readyLibraryChoices) ? 'No ready libraries available' : 'Select ready library',
                'required' => true,
            ])
            ->add('query', TextareaType::class, [
                'label' => 'query',
                'help' => 'Search intent, e.g. "dashboard controller", "routing attribute", "crud actions"',
                'required' => true,
                'attr' => [
                    'rows' => 1,
                    'class' => 'mcp-textarea-lg',
                ],
            ])
            ->add('lang', TextType::class, [
                'label' => 'lang',
                'help' => 'Optional language filter (examples: "php", "js", "md"); leave empty to search all languages',
                'required' => false,
            ])
            ->add('path', TextType::class, [
                'label' => 'path',
                'help' => 'Optional path glob filter (examples: "src/**", "doc/**"); leave empty to search all paths',
                'required' => false,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'type',
                'help' => 'Optional symbol-type filter',
                'choices' => $this->symbolTypeChoices(),
                'placeholder' => 'Any symbol type',
                'required' => false,
            ])
            ->add('scope', ChoiceType::class, [
                'label' => 'scope',
                'help' => 'Corpus scope filter: source, docs, runtime, or all',
                'choices' => $this->searchScopeChoices(),
                'placeholder' => 'Default scope',
                'required' => false,
            ])
            ->add('limit', IntegerType::class, [
                'label' => 'limit',
                'help' => 'Max number of results to return (1..100)',
                'required' => false,
                'empty_data' => '20',
                'attr' => ['min' => 1, 'max' => 100],
            ])
            ->getForm();
    }

    /** @param array<string, string> $readyLibraryChoices */
    private function createReadForm(array $readyLibraryChoices): FormInterface
    {
        return $this->formFactory->createNamedBuilder('read')
            ->add('library', ChoiceType::class, [
                'label' => 'library',
                'help' => 'Ready library slug, e.g. "easycorp/easyadminbundle@5.x"',
                'choices' => $readyLibraryChoices,
                'placeholder' => 0 === \count($readyLibraryChoices) ? 'No ready libraries available' : 'Select ready library',
                'required' => true,
            ])
            ->add('file', TextType::class, [
                'label' => 'file',
                'help' => 'Relative file path discovered via semantic-search or grep',
                'required' => true,
            ])
            ->add('offset', IntegerType::class, [
                'label' => 'offset',
                'help' => '1-based start line (min 1)',
                'required' => false,
                'empty_data' => '1',
                'attr' => ['min' => 1],
            ])
            ->add('limit', IntegerType::class, [
                'label' => 'limit',
                'help' => 'Number of lines to return (1..2000)',
                'required' => false,
                'empty_data' => '200',
                'attr' => ['min' => 1, 'max' => 2000],
            ])
            ->getForm();
    }

    /** @param array<string, string> $readyLibraryChoices */
    private function createGrepForm(array $readyLibraryChoices): FormInterface
    {
        return $this->formFactory->createNamedBuilder('grep')
            ->add('library', ChoiceType::class, [
                'label' => 'library',
                'help' => 'Ready library slug, e.g. "easycorp/easyadminbundle@5.x"',
                'choices' => $readyLibraryChoices,
                'placeholder' => 0 === \count($readyLibraryChoices) ? 'No ready libraries available' : 'Select ready library',
                'required' => true,
            ])
            ->add('pattern', TextareaType::class, [
                'label' => 'pattern',
                'help' => 'Regex pattern (vera/Rust regex syntax), e.g. "AbstractCrudController" or "TODO|FIXME"',
                'required' => true,
                'attr' => [
                    'rows' => 1,
                    'class' => 'mcp-textarea-lg',
                ],
            ])
            ->add('ignoreCase', CheckboxType::class, [
                'label' => 'ignoreCase',
                'help' => 'True for case-insensitive matching',
                'required' => false,
            ])
            ->add('context', IntegerType::class, [
                'label' => 'context',
                'help' => 'Context lines before/after each match (0..20)',
                'required' => false,
                'empty_data' => '2',
                'attr' => ['min' => 0, 'max' => 20],
            ])
            ->add('scope', ChoiceType::class, [
                'label' => 'scope',
                'help' => 'Corpus scope filter: source, docs, runtime, or all',
                'choices' => $this->searchScopeChoices(),
                'placeholder' => 'Default scope',
                'required' => false,
            ])
            ->add('limit', IntegerType::class, [
                'label' => 'limit',
                'help' => 'Max number of results to return (1..100)',
                'required' => false,
                'empty_data' => '20',
                'attr' => ['min' => 1, 'max' => 100],
            ])
            ->getForm();
    }

    /** @return array<string, string> */
    private function searchScopeChoices(): array
    {
        return [
            'source' => SearchScope::Source->value,
            'docs' => SearchScope::Docs->value,
            'runtime' => SearchScope::Runtime->value,
            'all' => SearchScope::All->value,
        ];
    }

    /** @return array<string, string> */
    private function symbolTypeChoices(): array
    {
        $choices = [];

        foreach (SymbolType::cases() as $symbolType) {
            $choices[$symbolType->value] = $symbolType->value;
        }

        return $choices;
    }

    /**
     * @return array{isError: bool, raw: string}
     */
    private function normalizeToolResult(CallToolResult $result): array
    {
        $parts = [];
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $parts[] = (string) $content->text;
            }
        }

        $raw = trim(implode("\n\n", $parts));

        return [
            'isError' => $result->isError,
            'raw' => '' !== $raw ? $raw : '[]',
        ];
    }
}
