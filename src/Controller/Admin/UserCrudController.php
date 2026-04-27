<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
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
            ])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('ROLE_USER is always assigned automatically.');

        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
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
