<?php

namespace App\ArgumentResolver;

use App\DTO\CheckoutDTO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AutoconfigureTag('controller.argument_value_resolver', ['priority' => 50])]
class CheckoutDTOArgumentResolver implements ValueResolverInterface
{
    public function __construct(
        private ValidatorInterface $validator
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== CheckoutDTO::class) {
            return [];
        }

        $dto = new CheckoutDTO([
            'firstName' => $request->request->get('firstName'),
            'lastName' => $request->request->get('lastName'),
            'companyName' => $request->request->get('companyName'),
            'city' => $request->request->get('city'),
            'phoneNumber' => $request->request->get('phoneNumber'),
            'email' => $request->request->get('email'),
            'emailConfirm' => $request->request->get('emailConfirm'),
            'terms' => $request->request->get('terms'),
        ]);

        yield $dto;
    }
}
