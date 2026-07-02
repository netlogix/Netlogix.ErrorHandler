<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

use Generator;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\Eel\CompilingEvaluator;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\FrontendRouting\DimensionResolution\DimensionResolverFactoryInterface;
use Neos\Neos\FrontendRouting\DimensionResolution\RequestToDimensionSpacePointContext;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

use function array_filter;
use function array_values;
use function current;
use function in_array;
use function iterator_to_array;

/**
 * @Flow\Scope("singleton")
 * @phpstan-type SiteConfiguration array{
 *      matchingStatusCodes: int[],
 *      dimensions: array<string, string[]>,
 *      source: string,
 *      destination: string,
 *      dimensionPathSegment?: string,
 *      pathPrefixes?: string[],
 *      position: int
 *  }
 */
class ErrorHandlerConfiguration
{
    #[Flow\Inject]
    protected CompilingEvaluator $eelEvaluator;

    #[Flow\Inject]
    protected NodeBasedConfiguration $nodeBasedConfiguration;

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    private function resolveDimensionSpacePointFromRequest(Site $site, string $requestPath): DimensionSpacePoint
    {
        $siteConfiguration = $site->getConfiguration();
        $dimensionResolverFactory = $this->objectManager->get(
            $siteConfiguration->contentDimensionResolverFactoryClassName
        );
        assert($dimensionResolverFactory instanceof DimensionResolverFactoryInterface);
        $dimensionResolver = $dimensionResolverFactory->create($siteConfiguration->contentRepositoryId, $siteConfiguration);
        $siteDetectionResult = SiteDetectionResult::create($site->getNodeName(), $siteConfiguration->contentRepositoryId);
        $routeParameters = $siteDetectionResult->storeInRouteParameters(RouteParameters::createEmpty());

        $dimensionResolverContext = RequestToDimensionSpacePointContext::fromUriPathAndRouteParametersAndResolvedSite($requestPath, $routeParameters, $site);
        $dimensionResolverContext = $dimensionResolver->fromRequestToDimensionSpacePoint($dimensionResolverContext);

        return $dimensionResolverContext->resolvedDimensionSpacePoint;
    }

    /**
     *
     * /**
     * Find error page configuration by Site, Dimension (parsed in $uri) and status code.
     * If no configuration is found that matches the parsed dimensionPathSegment, the first configuration
     * for the Site and statusCode is used.
     *
     * @param Site $site
     * @param UriInterface $uri
     * @param int $statusCode
     * @return SiteConfiguration|null
     */
    public function findConfigurationForSite(
        Site $site,
        ServerRequestInterface $request,
        int $statusCode
    ) {
        $siteName = (string)$site->getNodeName();
        $requestPath = ltrim($request->getUri()->getPath() ?? '', '/');
        $dimensionSpacePoint = $this->resolveDimensionSpacePointFromRequest($site, $requestPath);

        $configurationsForSite = $this->getConfiguration();
        $configurationsForSite = array_key_exists(
            $siteName,
            $configurationsForSite
        ) ? $configurationsForSite[$siteName] : [];

        $configurationsForSite = array_filter($configurationsForSite,
            function (array $configuration) use ($statusCode) {
                return in_array($statusCode, $configuration['matchingStatusCodes'] ?? [], true);
            });

        $configurationsForSite = array_filter($configurationsForSite,
            function (array $configuration) use ($dimensionSpacePoint) {
                $configuredDimensionSpacePoint = DimensionSpacePoint::fromArray($configuration['dimensions']);

                return $configuredDimensionSpacePoint->equals($dimensionSpacePoint);
            });

        $configurationsForSite = array_filter($configurationsForSite,
            function (array $configuration) use ($requestPath) {
                foreach ($configuration['pathPrefixes'] ?? [] as $pathPrefix) {
                    if (strpos($requestPath, ltrim($pathPrefix, '/')) === 0) {
                        return true;
                    }
                }

                return false;
            });

        return current($configurationsForSite) ?: null;
    }

    /**
     * @return array<string, SiteConfiguration[]>
     */
    public function getConfiguration()
    {
        return iterator_to_array($this->generateConfiguration());
    }

    /**
     * @return Generator<string, SiteConfiguration[]>
     */
    private function generateConfiguration(): Generator
    {
        $nodeBased = $this->nodeBasedConfiguration->getConfiguration();

        $sites = array_keys($nodeBased);
        foreach ($sites as $site) {
            yield $site => array_values($nodeBased[$site] ?? []);
        }
    }

}
