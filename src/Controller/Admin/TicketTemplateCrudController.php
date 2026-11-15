<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TicketTemplate;
use App\Form\TicketTemplateSectionType;
use App\Service\TicketContentSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class TicketTemplateCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TicketContentSanitizer $sanitizer,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TicketTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ticket template')
            ->setEntityLabelInPlural('Ticket templates')
            ->setDefaultSort(['name' => 'ASC']);
    }

    /**
     * The parts use EasyAdmin's Trix editor inside our own form type, so no
     * TextEditorField is on the page to register Trix's script and styles.
     */
    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addCssFile(Asset::fromEasyAdminAssetPackage('field-text-editor.css')->onlyOnForms())
            ->addJsFile(Asset::fromEasyAdminAssetPackage('field-text-editor.js')->onlyOnForms());
    }

    public function configureActions(Actions $actions): Actions
    {
        // The default template is the fallback for every event: never delete it.
        // The buttons are hidden here; deleteEntity() enforces it for direct URLs.
        return $actions->disable(Action::BATCH_DELETE)->add(Crud::PAGE_INDEX, Action::DETAIL)->update(
            Crud::PAGE_INDEX,
            Action::DELETE,
            fn (Action $action) => $action->displayIf(fn (TicketTemplate $t): bool => !$t->isDefault),
        )->update(
            Crud::PAGE_DETAIL,
            Action::DELETE,
            fn (Action $action) => $action->displayIf(fn (TicketTemplate $t): bool => !$t->isDefault),
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield BooleanField::new('isDefault')
            ->setLabel('Default')
            ->setHelp('Used for events without their own template. Only one template can be the default.')
            ->renderAsSwitch(false);
        yield TextField::new('id', 'Parts')
            ->setSortable(false)
            ->onlyOnIndex()
            ->formatValue(
                static fn (mixed $value, TicketTemplate $template): string => (string) $template->sections->count(),
            );
        yield TextField::new('id', 'Parts')
            ->onlyOnDetail()
            ->renderAsHtml()
            ->formatValue(static fn (mixed $value, TicketTemplate $template): string => implode(
                '<hr>',
                array_map(
                    static fn ($section): string => (string) $section->content,
                    $template->sections->toArray(),
                ),
            ));
        yield CollectionField::new('sections')
            ->setLabel('Parts')
            ->setEntryType(TicketTemplateSectionType::class)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->setEntryIsComplex()
            ->showEntryLabel(false)
            ->setFormTypeOption('attr', ['data-controller' => 'admin--ticket-sections'])
            ->setHelp('Add, remove and reorder parts. They appear on the ticket below the date and customer, '
                . 'in this order. Only bold text and bullet lists are available.')
            ->onlyOnForms();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof TicketTemplate && $entityInstance->isDefault) {
            throw new AccessDeniedHttpException('The default ticket template cannot be deleted.');
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->prepare($entityManager, $entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->prepare($entityManager, $entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * Sanitize every part, number them in submitted order and keep exactly one default.
     */
    private function prepare(EntityManagerInterface $entityManager, TicketTemplate $template): void
    {
        // The sorter controller writes the visual order into `position`.
        $sections = $template->sections->toArray();
        usort($sections, static fn ($a, $b): int => $a->position <=> $b->position);

        $position = 0;
        foreach ($sections as $section) {
            $section->content = $this->sanitizer->sanitize($section->content);

            // A part that was added but left empty is dropped (orphanRemoval deletes it).
            if (trim(strip_tags($section->content)) === '') {
                $template->removeSection($section);
                continue;
            }

            $section->position = $position++;
        }

        if ($template->isDefault) {
            $entityManager->createQuery(
                'UPDATE ' . TicketTemplate::class . ' t SET t.isDefault = false WHERE t.id != :id AND t.isDefault = true'
            )->setParameter('id', $template->id, 'uuid')->execute();
        }
    }
}
