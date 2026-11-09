<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DataTransformer\Code128PayloadTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Text field for scanner programming payloads. The model transformer lets an
 * admin type control bytes as \xNN without corrupting vendor command strings.
 */
final class Code128PayloadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new Code128PayloadTransformer());
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
