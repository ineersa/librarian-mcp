<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProjectHealth;
use App\Repository\ProjectRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        #[Autowire(param: 'app.display_timezone')]
        private readonly string $displayTimezone,
    ) {
    }

    public function index(): Response
    {
        $projects = $this->projectRepository->findBy([], ['id' => 'DESC'], 10);
        $counts = [
            'total' => $this->projectRepository->count([]),
            'pending' => $this->projectRepository->count(['health' => ProjectHealth::Pending]),
            'scanning' => $this->projectRepository->count(['health' => ProjectHealth::Scanning]),
            'ready' => $this->projectRepository->count(['health' => ProjectHealth::Ready]),
            'error' => $this->projectRepository->count(['health' => ProjectHealth::Error]),
        ];

        return $this->render('admin/dashboard.html.twig', [
            'projectCounts' => $counts,
            'recentProjects' => $projects,
        ]);
    }

    public function configureCrud(): Crud
    {
        return Crud::new()->setTimezone($this->displayTimezone);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addAssetMapperEntry('admin');
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

        yield MenuItem::section('Projects');
        yield MenuItem::linkTo(ProjectCrudController::class, 'Projects', 'fas fa-folder-open');

        yield MenuItem::section();
        yield MenuItem::linkToLogout('Logout', 'fa fa-sign-out');
        yield MenuItem::linkToRoute('Back to site', 'fas fa-arrow-left', 'app_home');
    }
}
