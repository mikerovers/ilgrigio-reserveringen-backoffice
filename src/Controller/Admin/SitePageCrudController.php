<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SitePage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class SitePageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SitePage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Website page')
            ->setEntityLabelInPlural('Website pages')
            ->setDefaultSort(['pageKey' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('pageKey')->setDisabled();

        if ($this->isTermsPage()) {
            yield TextField::new('heroTitle')->setLabel('Title');
            yield TextareaField::new('heroDescription')
                ->setLabel('Terms text')
                ->setNumOfRows(20)
                ->setHelp('Een lege regel begint een nieuwe alinea. HTML wordt niet weergegeven.');

            return;
        }

        yield TextField::new('heroTitle')->setLabel('Hero title');
        yield TextField::new('heroDateText')->setLabel('Hero date text');
        yield TextareaField::new('heroDescription')->setLabel('Hero description');
    }

    /**
     * The terms page only uses the title and description; the hero date is for the homepage.
     */
    private function isTermsPage(): bool
    {
        $page = $this->getContext()?->getEntity()?->getInstance();

        return $page instanceof SitePage && 'terms' === $page->pageKey;
    }
}
