<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class MollieRequestParser extends AbstractRequestParser
{
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
        ]);
    }

    protected function doParse(Request $request, string $secret): RemoteEvent
    {
        $mollieId = $request->request->get('id');

        if (!$mollieId) {
            throw new RejectWebhookException(400, 'Missing Mollie payment id in webhook payload.');
        }

        return new RemoteEvent('mollie.payment_status', $mollieId, ['id' => $mollieId]);
    }
}
