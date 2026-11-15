<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TicketTemplateSection;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One part of a ticket template: a Trix editor (toolbar limited to bold and
 * bullets via the `ticket-section-editor` class in assets/admin/admin.css)
 * and a hidden position that the sorter controller keeps in DOM order.
 */
final class TicketTemplateSectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextEditorType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['class' => 'ticket-section-editor'],
                'row_attr' => ['class' => 'ticket-section-editor'],
            ])
            ->add('position', HiddenType::class, [
                'attr' => ['data-ticket-section-position' => ''],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TicketTemplateSection::class]);
    }
}
