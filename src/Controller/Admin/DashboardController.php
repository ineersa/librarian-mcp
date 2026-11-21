<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProjectHealth;
use App\Messenger\MessengerTransport;
use App\Messenger\Monitor\MessengerStatsProvider;
use App\Messenger\Monitor\MessengerStatsPublisher;
use App\Messenger\Monitor\MessengerStatsTopics;
use App\ObservationalMemory\Storage\ActiveMemoryCounter;
use App\Repository\ProjectRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Failure\FailedMessageFilter;
use Symfony\Component\Messenger\Failure\FailedMessageRepository;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public const array MESSENGER_RETRY_ROUTES = ['admin_'.self::RETRY_ROUTE, 'admin_'.self::RETRY_ALL_ROUTE];
    public const string MESSENGER_CSRF_ID = 'messenger_failed';
    private const string RETRY_ROUTE = 'messenger_retry';
    private const string RETRY_ALL_ROUTE = 'messenger_retry_all';

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ActiveMemoryCounter $activeMemoryCounter,
        private readonly MessengerStatsProvider $messengerStats,
        private readonly MessengerStatsPublisher $messengerPublisher,
        private readonly FailedMessageRepository $failed,
        #[Autowire(service: 'messenger.senders_locator')]
        private readonly SendersLocatorInterface $senders,
        #[Autowire(param: 'app.display_timezone')]
        private readonly string $displayTimezone,
    ) {
    }

    public function index(): Response
    {
        $projects = $this->projectRepository->findBy([], ['id' => 'DESC'], 10);
        $projectIds = [];
        foreach ($projects as $project) {
            if (null !== $project->id) {
                $projectIds[] = $project->id;
            }
        }
        $activeCounts = $this->activeMemoryCounter->forProjects($projectIds);
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
            'activeMemoryCounts' => $activeCounts,
            'stats' => $this->messengerStats->snapshot(),
            'messengerTopic' => MessengerStatsTopics::DASHBOARD,
        ]);
    }

    #[AdminRoute('/messenger/{id}/retry', self::RETRY_ROUTE, options: ['methods' => [Request::METHOD_POST], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(self::MESSENGER_CSRF_ID)]
    public function retryFailed(string $id): RedirectResponse
    {
        return $this->manageFailed(true, id: $id);
    }

    #[AdminRoute('/messenger/{id}/remove', 'messenger_remove', options: ['methods' => [Request::METHOD_POST], 'requirements' => ['id' => '\d+']])]
    #[IsCsrfTokenValid(self::MESSENGER_CSRF_ID)]
    public function removeFailed(string $id): RedirectResponse
    {
        return $this->manageFailed(false, id: $id);
    }

    #[AdminRoute('/messenger/retry-all', self::RETRY_ALL_ROUTE, options: ['methods' => [Request::METHOD_POST]])]
    #[IsCsrfTokenValid(self::MESSENGER_CSRF_ID)]
    public function retryAllFailed(Request $request): RedirectResponse
    {
        return $this->manageFailed(true, class: trim($request->request->getString('class')));
    }

    #[AdminRoute('/messenger/remove-all', 'messenger_remove_all', options: ['methods' => [Request::METHOD_POST]])]
    #[IsCsrfTokenValid(self::MESSENGER_CSRF_ID)]
    public function removeAllFailed(Request $request): RedirectResponse
    {
        return $this->manageFailed(false, class: trim($request->request->getString('class')));
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

        yield MenuItem::section('Operations');
        yield MenuItem::linkTo(CronRunCrudController::class, 'Cron runs', 'fas fa-clock-rotate-left');

        yield MenuItem::section();
        yield MenuItem::linkToLogout('Logout', 'fa fa-sign-out');
        yield MenuItem::linkToRoute('Back to site', 'fas fa-arrow-left', 'app_home');
    }

    private function manageFailed(bool $retry, ?string $id = null, string $class = ''): RedirectResponse
    {
        try {
            if (null !== $id) {
                $envelope = $this->failed->find($id);
                if (null === $envelope) {
                    $this->addFlash('info', 'This failed message was already handled.');

                    return $this->redirectToRoute('admin', status: Response::HTTP_SEE_OTHER);
                }

                $envelopes = [$envelope];
            } else {
                $envelopes = iterator_to_array($this->failed->all(filter: new FailedMessageFilter('' === $class ? null : $class)), false);
            }

            $changed = 0;
            $skipped = 0;
            foreach ($envelopes as $envelope) {
                if ($retry) {
                    if (!$this->canRedispatch($envelope)) {
                        ++$skipped;

                        continue;
                    }
                    $this->failed->redispatch($envelope);
                } else {
                    $this->failed->remove($envelope);
                }
                ++$changed;
            }

            if (0 < $changed) {
                $this->addFlash('success', \sprintf($retry ? 'Queued %d failed message(s) for retry.' : 'Removed %d failed message(s).', $changed));
            } elseif (0 === $skipped) {
                $this->addFlash('info', 'No matching failed messages.');
            }
            if (0 < $skipped) {
                $this->addFlash('warning', \sprintf('Left %d failed message(s) in place because they cannot be sent to a configured queue.', $skipped));
            }

            return $this->redirectToRoute('admin', status: Response::HTTP_SEE_OTHER);
        } finally {
            $this->messengerPublisher->publish();
        }
    }

    private function canRedispatch(Envelope $envelope): bool
    {
        $message = $envelope->getMessage();
        if ($message instanceof \__PHP_Incomplete_Class || $message instanceof MessageDecodingFailedException) {
            return false;
        }

        // A missing sender would fall through to the synchronous handler middleware.
        $hasSender = false;
        foreach ($this->senders->getSenders(FailedMessageRepository::prepareForRedispatch($envelope)) as $alias => $sender) {
            if (!$sender instanceof DoctrineTransport || !\in_array(MessengerTransport::tryFrom((string) $alias), MessengerTransport::monitored(), true)) {
                return false;
            }
            $hasSender = true;
        }

        return $hasSender;
    }
}
