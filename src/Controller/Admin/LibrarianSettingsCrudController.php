<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LibrarianSettings;
use App\ObservationalMemory\Model\MemoryTokenLimits;
use App\Repository\LibrarianSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Edit-only singleton settings. The index creates the row with the env defaults when it is
 * missing and redirects to its edit page; there is no list, detail, new or delete.
 *
 * @extends AbstractCrudController<LibrarianSettings>
 */
#[AdminRoute(path: '/settings')]
#[IsGranted('ROLE_ADMIN')]
class LibrarianSettingsCrudController extends AbstractCrudController
{
    private const string TOKEN_HELP = 'Estimated tokens of unreflected plus retained observations in one session.';

    public function __construct(
        private readonly LibrarianSettingsRepository $settings,
        private readonly MemoryTokenLimits $tokenLimits,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $urlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return LibrarianSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Settings')
            ->setEntityLabelInPlural('Settings')
            ->setPageTitle(Crud::PAGE_EDIT, 'Settings');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::DETAIL, Action::SAVE_AND_RETURN)
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, static fn (Action $action) => $action->setLabel('Save settings'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Claude Code sessions');
        yield IntegerField::new('claudeReflectAfterObservationTokens', 'Claude reflect after (tokens)')
            ->setHelp('Run the Reflector once the working set exceeds this. '.self::TOKEN_HELP);
        yield IntegerField::new('claudeObservationsMaxTokens', 'Claude observations max (tokens)')
            ->setHelp('Dropper target after a reflection. '.self::TOKEN_HELP);

        yield FormField::addFieldset('Hatfield sessions');
        yield IntegerField::new('hatfieldReflectAfterObservationTokens', 'Hatfield reflect after (tokens)')
            ->setHelp('Run the Reflector once the working set exceeds this. '.self::TOKEN_HELP);
        yield IntegerField::new('hatfieldObservationsMaxTokens', 'Hatfield observations max (tokens)')
            ->setHelp('Dropper target after a reflection. '.self::TOKEN_HELP);
    }

    public function index(AdminContext $context): KeyValueStore|Response
    {
        $settings = $this->settings->current();
        if (null === $settings) {
            $settings = $this->tokenLimits->defaults();
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
        }

        return $this->redirect($this->editUrl((int) $settings->id));
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        $this->addFlash('success', 'Saved settings.');

        return $this->redirect($this->editUrl((int) $context->getEntity()->getPrimaryKeyValue()));
    }

    private function editUrl(int $id): string
    {
        return $this->urlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($id)
            ->generateUrl();
    }
}
