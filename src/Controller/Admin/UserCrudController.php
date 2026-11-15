<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Backoffice users (admins, register and door staff). Super admins only. This screen cannot
 * create or remove the super admin role; that is done on the CLI.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function createEntity(string $entityFqcn): User
    {
        return new User('', '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setSearchFields(['name', 'email'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('name');
        yield EmailField::new('email');
        // Super admin is shown (index/detail) but never offered in the form: it can only be
        // granted with `bin/console app:user:create`.
        $roles = ['Admin' => 'ROLE_ADMIN', 'Register' => 'ROLE_REGISTER', 'Check-in' => 'ROLE_CHECK_IN'];
        if ($pageName !== Crud::PAGE_NEW && $pageName !== Crud::PAGE_EDIT) {
            $roles = ['Super admin' => 'ROLE_SUPER_ADMIN'] + $roles;
        }
        yield ChoiceField::new('roles')
            ->setChoices($roles)
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges()
            ->setHelp('Admins also get register and check-in. Super admins can only be created on the CLI.');
        yield TextField::new('plainPassword', 'Password')
            ->onlyOnForms()
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $pageName === Crud::PAGE_NEW
                    ? [new NotBlank(), new Length(min: self::MIN_PASSWORD_LENGTH)]
                    : [new Length(min: self::MIN_PASSWORD_LENGTH)],
            ])
            ->setHelp($pageName === Crud::PAGE_NEW
                ? sprintf('At least %d characters.', self::MIN_PASSWORD_LENGTH)
                : 'Leave empty to keep the current password.');
        yield VenueDateTimeField::new('createdAt')->onlyOnDetail();
        yield VenueDateTimeField::new('updatedAt')->onlyOnDetail();
    }

    public function createNewFormBuilder(
        EntityDto $entityDto,
        KeyValueStore $formOptions,
        AdminContext $context,
    ): FormBuilderInterface {
        return $this->withPasswordHashing(parent::createNewFormBuilder($entityDto, $formOptions, $context), false);
    }

    public function createEditFormBuilder(
        EntityDto $entityDto,
        KeyValueStore $formOptions,
        AdminContext $context,
    ): FormBuilderInterface {
        $user = $entityDto->getInstance();
        $keepSuperAdmin = $user instanceof User && in_array('ROLE_SUPER_ADMIN', $user->roles, true);

        return $this->withPasswordHashing(
            parent::createEditFormBuilder($entityDto, $formOptions, $context),
            $keepSuperAdmin,
        );
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $current = $this->getUser();
        if ($entityInstance instanceof User && $current?->getUserIdentifier() === $entityInstance->email) {
            $this->addFlash('danger', 'You cannot delete your own account.');

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    /**
     * Hashes the unmapped plain password onto the user once the form is valid.
     * An empty password on edit leaves the stored hash untouched.
     */
    private function withPasswordHashing(FormBuilderInterface $builder, bool $keepSuperAdmin): FormBuilderInterface
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($keepSuperAdmin): void {
            $form = $event->getForm();
            $user = $event->getData();
            if (!$user instanceof User) {
                return;
            }

            // The form cannot grant or revoke ROLE_SUPER_ADMIN; an existing one stays.
            if ($keepSuperAdmin && !in_array('ROLE_SUPER_ADMIN', $user->roles, true)) {
                $user->roles[] = 'ROLE_SUPER_ADMIN';
            }

            if (!$form->isValid() || !$form->has('plainPassword')) {
                return;
            }

            $plain = $form->get('plainPassword')->getData();
            if (is_string($plain) && $plain !== '') {
                $user->password = $this->passwordHasher->hashPassword($user, $plain);
            }
        });

        return $builder;
    }
}
