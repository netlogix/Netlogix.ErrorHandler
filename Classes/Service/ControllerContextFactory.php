<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("prototype")
 * @internal
 */
class ControllerContextFactory
{

    /**
     * @param UriInterface $uri
     * @return ControllerContext
     */
    public function buildControllerContext(UriInterface $uri): ControllerContext
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';
        $httpRequest = ServerRequest::fromGlobals()->withUri($uri);
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

}
