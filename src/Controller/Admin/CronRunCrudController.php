<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CronJob;
use App\Entity\CronRun;
use App\Entity\CronRunStatus;
use App\Message\RunCronJobMessage;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Read-only cron run history with a "Run backup now" action that queues a forced run.
 *
 * @extends AbstractCrudController<CronRun>
 */
#[AdminRoute(path: '/cron-runs')]
class CronRunCrudController extends AbstractCrudController
{
    private const string ACTION_RUN_BACKUP = 'runBackup';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return CronRun::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cron run')
            ->setEntityLabelInPlural('Cron runs')
            ->setPageTitle(Crud::PAGE_INDEX, 'Cron runs')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (CronRun $run) => \sprintf('Cron run #%d', (int) $run->id))
            ->setDefaultSort(['id' => 'DESC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->setPaginatorPageSize(30);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');

        yield ChoiceField::new('job')
            ->setChoices(array_combine(
                array_map(static fn (CronJob $job): string => $job->label(), CronJob::cases()),
                CronJob::cases(),
            ))
            ->formatValue(static fn (?CronJob $job): string => $job?->label() ?? '');

        yield DateField::new('runDay', 'Day');

        yield ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(static fn (CronRunStatus $status): string => $status->label(), CronRunStatus::cases()),
                CronRunStatus::cases(),
            ))
            ->renderAsBadges([
                CronRunStatus::Running->value => 'info',
                CronRunStatus::Succeeded->value => 'success',
                CronRunStatus::Failed->value => 'danger',
            ]);

        yield BooleanField::new('forced')->renderAsSwitch(false);

        yield DateTimeField::new('startedAt', 'Started');

        yield DateTimeField::new('finishedAt', 'Finished');

        yield TextareaField::new('error')
            ->setMaxLength(Crud::PAGE_INDEX === $pageName ? 120 : CronRun::MAX_ERROR_LENGTH);

        yield ArrayField::new('results', 'Uploaded')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $runBackup = Action::new(self::ACTION_RUN_BACKUP, 'Run backup now', 'fa fa-play')
            ->linkToCrudAction(self::ACTION_RUN_BACKUP)
            ->createAsGlobalAction()
            ->renderAsForm()
            ->addCssClass('btn btn-primary');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // EasyAdmin resolves row navigation from configured entity actions.
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action->addCssClass('d-none'))
            ->add(Crud::PAGE_INDEX, $runBackup);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('job')
            ->add('status');
    }

    /** Queues a forced run on the `cron` transport; it bypasses the once-per-day check. */
    #[AdminRoute(path: '/run-backup', name: 'run-backup', options: ['methods' => [Request::METHOD_POST]])]
    public function runBackup(AdminUrlGenerator $urlGenerator): Response
    {
        $this->messageBus->dispatch(new RunCronJobMessage(CronJob::Backup, true));
        $this->addFlash('success', 'Backup queued. Refresh to see its run.');

        return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }
}
