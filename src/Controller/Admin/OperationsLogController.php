<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Operations\LogSource;
use App\Operations\OperationsLogReader;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class OperationsLogController extends AbstractController
{
    public const string ROUTE = DashboardController::ROUTE_PREFIX.self::ROUTE_SUFFIX;
    private const string ROUTE_SUFFIX = 'operations_logs';

    #[AdminRoute('/operations/logs', self::ROUTE_SUFFIX, options: ['methods' => [Request::METHOD_GET]])]
    public function index(Request $request, OperationsLogReader $logs): Response
    {
        $source = LogSource::tryFrom($request->query->getString('source'));

        return $this->render('admin/operations/logs.html.twig', [
            'entries' => $logs->recent($source),
            'sources' => LogSource::cases(),
            'selected' => $source,
        ])->setPrivate();
    }
}
