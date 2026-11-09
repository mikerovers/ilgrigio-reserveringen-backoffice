<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ScannerProgrammingBarcode;
use App\Enum\Code128Set;
use App\Form\Code128PayloadType;
use App\Repository\BarcodeScannerTypeRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ScannerProgrammingBarcodeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly BarcodeScannerTypeRepository $scannerTypeRepository,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ScannerProgrammingBarcode::class;
    }

    public function createEntity(string $entityFqcn): ScannerProgrammingBarcode
    {
        $scannerTypeId = $this->getContext()?->getRequest()->query->get('scannerType');
        $scannerType = $scannerTypeId !== null
            ? $this->scannerTypeRepository->find($scannerTypeId)
            : null;
        $scannerType ??= $this->scannerTypeRepository->findFirstOrdered();

        if ($scannerType === null) {
            throw new \RuntimeException(
                'Cannot create a programming barcode without at least one existing scanner type.',
            );
        }

        return new ScannerProgrammingBarcode($scannerType);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Programmeringsbarcode')
            ->setEntityLabelInPlural('Programmeringsbarcodes')
            ->setDefaultSort(['scannerType' => 'ASC', 'sortOrder' => 'ASC']);
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $scannerTypeId = $this->getContext()?->getRequest()->query->get('scannerType');
        if ($scannerTypeId !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.scannerType = :scannerType', $queryBuilder->getRootAliases()[0]))
                ->setParameter('scannerType', $scannerTypeId);
        }

        return $queryBuilder;
    }

    public function configureFields(string $pageName): iterable
    {
        $setChoices = [];
        foreach (Code128Set::cases() as $set) {
            $setChoices[$set->label()] = $set;
        }

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('scannerType')
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT);
        yield TextField::new('label');
        yield TextareaField::new('description')->hideOnIndex();
        yield TextField::new('payload')
            ->setFormType(Code128PayloadType::class)
            ->hideOnIndex()
            ->setHelp('Maximaal 80 tekens. Voer de gedecodeerde inhoud uit de handleiding in, '
                . 'niet een omschrijving van de instelling. Stuurtekens typt u als \\xNN '
                . '(bijvoorbeeld \\x0D voor Enter); een letterlijke backslash is \\\\. '
                . 'NUL-bytes zijn niet toegestaan.');
        yield ChoiceField::new('codeSet')->setChoices($setChoices);
        yield IntegerField::new('sortOrder')
            ->setHelp('Volgorde waarin de codes op de scanbalie gescand moeten worden.');
        yield BooleanField::new('active');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
