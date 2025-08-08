<?php

namespace App\ArgumentResolver;

use App\DTO\TicketOrderDTO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AutoconfigureTag('controller.argument_value_resolver', ['priority' => 50])]
class TicketOrderDTOArgumentResolver implements ValueResolverInterface
{
    public function __construct(
        private ValidatorInterface $validator
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== TicketOrderDTO::class) {
            return [];
        }

        $dto = new TicketOrderDTO([
            'tickets' => $request->request->all('tickets'),
            'applied_coupon' => $request->request->get('applied_coupon'),
            'discount_amount' => $request->request->get('discount_amount', 0),
        ]);

        yield $dto;
    }
}
