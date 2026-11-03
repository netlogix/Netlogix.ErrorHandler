<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\UriInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 * @internal
 */
final class ActionRequestFactory
{
    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    public function buildActionRequest(SiteDetectionResult $siteDetectionResult): ActionRequest
    {
        $site = $this->siteRepository->findOneByNodeName($siteDetectionResult->siteNodeName);
        $baseUri = new Uri((string)$site->getPrimaryDomain());

        $httpRequest = self::buildHttpRequest($baseUri);
        $httpRequest = $siteDetectionResult->storeInRequest($httpRequest);

        return ActionRequest::fromHttpRequest($httpRequest);
    }

    private static function buildHttpRequest(UriInterface $uri): ServerRequest
    {
        return self::createHttpRequestFromGlobals($uri);
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