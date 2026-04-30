<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\McpTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @extends AbstractCrudController<User>
 */
#[AdminRoute(path: '/users')]
class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly McpTokenManager $mcpTokenManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setPageTitle(Crud::PAGE_INDEX, 'Users')
            ->setPageTitle(Crud::PAGE_NEW, 'Create User')
            ->setPageTitle(Crud::PAGE_EDIT, static fn (User $user) => \sprintf('Edit User: %s', $user->email))
            ->setSearchFields(['email'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');

        yield TextField::new('plainPassword')
            ->setLabel('Password')
            ->onlyOnForms()
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Leave empty to keep current password.' : '');

        yield ChoiceField::new('roles', 'Roles')
            ->setChoices([
                'Admin' => 'ROLE_ADMIN',
                'MCP API Access' => 'ROLE_MCP',
            ])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('ROLE_USER is always assigned automatically.');

        yield TextField::new('maskedMcpToken', 'MCP Token')
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('disabled', true)
            ->setHelp('Token is shown only once after regeneration.');

        yield DateTimeField::new('mcpTokenCreatedAt')
            ->hideOnIndex()
            ->hideOnForm();

        yield DateTimeField::new('mcpTokenLastUsedAt')
            ->hideOnIndex()
            ->hideOnForm();

        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        $regenerateMcpToken = Action::new('regenerateMcpToken', 'Regenerate MCP token', 'fa fa-key')
            ->linkToCrudAction('regenerateMcpToken')
            ->addCssClass('btn btn-warning');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, $regenerateMcpToken)
            ->add(Crud::PAGE_DETAIL, $regenerateMcpToken);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('email');
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        $entityInstance->touch();
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        $entityInstance->touch();
        parent::updateEntity($entityManager, $entityInstance);
    }

    #[AdminRoute(path: '/{entityId}/regenerate-mcp-token', name: 'regenerate-mcp-token')]
    public function regenerateMcpToken(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context, \EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator $urlGenerator): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        /** @var User $user */
        $user = $context->getEntity()->getInstance();

        $token = $this->mcpTokenManager->regenerate($user);

        $this->addFlash('success', \sprintf('New MCP token for %s (copy now, shown once): %s', $user->email, $token));

        return new \Symfony\Component\HttpFoundation\RedirectResponse(
            $urlGenerator
                ->setController(self::class)
                ->setAction(Action::EDIT)
                ->setEntityId((string) $user->id)
                ->generateUrl(),
        );
    }

    private function hashPassword(User $user): void
    {
        $plainPassword = $user->plainPassword;
        if (null !== $plainPassword && '' !== $plainPassword) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->password = $hashedPassword;
        }
        $user->eraseCredentials();
    }
}
