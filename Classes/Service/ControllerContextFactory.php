<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("prototype")
 * @internal
 */
class ControllerContextFactory
{

    public function buildControllerContext(UriInterface $uri): ControllerContext
    {
        $httpRequest = self::createHttpRequestFromGlobals($uri)
            ->withAttribute(
                ServerRequestAttributes::ROUTING_PARAMETERS,
                RouteParameters::createEmpty()->withParameter('requestUriHost', $uri->getHost())
            );

        $request = ActionRequest::fromHttpRequest($httpRequest);

        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);

        return new ControllerContext(
            $request,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
    }

    private static function createHttpRequestFromGlobals(UriInterface $uri): ServerRequest
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';
        $fromGlobals = ServerRequest::fromGlobals();

        return new ServerRequest(
            $fromGlobals->getMethod(),
            $uri,
            $fromGlobals->getHeaders(),
            $fromGlobals->getBody(),
            $fromGlobals->getProtocolVersion(),
            array_merge(
                $fromGlobals->getServerParams(),
                // Empty SCRIPT_NAME to prevent "./flow" in Uri
                ['SCRIPT_NAME' => '']
            )
        );
    }

}
