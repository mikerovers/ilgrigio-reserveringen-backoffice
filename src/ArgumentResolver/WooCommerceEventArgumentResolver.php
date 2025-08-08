<?php

namespace App\ArgumentResolver;

use App\Service\WooCommerceEventsService;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AutoconfigureTag('controller.argument_value_resolver', ['priority' => 50])]
class WooCommerceEventArgumentResolver implements ValueResolverInterface
{
    public function __construct(
        private WooCommerceEventsService $eventsService
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getName() !== 'event' || $argument->getType() !== 'array') {
            return [];
        }

        $id = $request->attributes->get('id');
        if (!$id) {
            return [];
        }

        $events = $this->eventsService->getEvents();
        $event = null;

        foreach ($events as $e) {
            if ($e['id'] == $id) {
                $event = $e;
                break;
            }
        }

        if (!$event) {
            throw new NotFoundHttpException('Event not found');
        }

        yield $event;
    }
}
