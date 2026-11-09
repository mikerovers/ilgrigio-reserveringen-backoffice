<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BarcodeScannerType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class BarcodeScannerTypeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BarcodeScannerType::class;
    }

    public function createEntity(string $entityFqcn): BarcodeScannerType
    {
        return new BarcodeScannerType();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Scanner type')
            ->setEntityLabelInPlural('Scanner types')
            ->setDefaultSort(['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $manageBarcodes = Action::new('manageBarcodes', 'Barcodes', 'fa fa-barcode')
            ->linkToUrl(fn (BarcodeScannerType $type): string => $this->adminUrlGenerator
                ->setController(ScannerProgrammingBarcodeCrudController::class)
                ->setAction(Crud::PAGE_INDEX)
                ->set('scannerType', (string) $type->id)
                ->generateUrl());

        return $actions
            ->add(Crud::PAGE_INDEX, $manageBarcodes)
            ->add(Crud::PAGE_DETAIL, $manageBarcodes);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('slug')
            ->hideOnForm()
            ->setHelp('Wordt automatisch gegenereerd uit de naam.');
        yield TextField::new('manufacturer')->hideOnIndex();
        yield TextareaField::new('notes')
            ->hideOnIndex()
            ->setHelp('Instructietekst boven de barcodes, bijvoorbeeld: zet de scanner in de '
                . 'programmeermodus, scan de codes in volgorde en sluit de modus af.');
        yield IntegerField::new('sortOrder');
        yield BooleanField::new('active');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
