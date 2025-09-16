<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

use Generator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility as EelUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ConfigurationContentDimensionPresetSource;
use Neos\Neos\Routing\FrontendNodeRoutePartHandlerInterface;
use Neos\Neos\Service\LinkingService;
use Psr\Http\Message\UriInterface;

use ReflectionMethod;
use Throwable;
use function array_filter;
use function array_values;
use function current;
use function explode;
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
 *      pathPrefixes?: string[]
 *  }
 */
class ErrorHandlerConfiguration
{
    /**
     * @Flow\Inject(lazy=false)
     * @var CompilingEvaluator
     */
    protected $eelEvaluator;

    /**
     * @Flow\Inject(lazy=false)
     * @var ContextFactoryInterface
     */
    protected ContextFactoryInterface $contextFactory;

    /**
     * @Flow\Inject(lazy=false)
     * @var LinkingService
     */
    protected LinkingService $linkingService;

    /**
     * @Flow\Inject(lazy=false)
     * @var NodeBasedConfiguration
     */
    protected NodeBasedConfiguration $nodeBasedConfiguration;

    /**
     * @Flow\Inject(lazy=false)
     * @var SettingsBasedConfiguration
     */
    protected SettingsBasedConfiguration $settingsBasedConfiguration;

    /**
     * @Flow\Inject(lazy=false)
     * @var FrontendNodeRoutePartHandlerInterface
     */
    protected FrontendNodeRoutePartHandlerInterface $frontendNodeRoutePartHandler;

    /**
     * @Flow\Inject(lazy=false)
     * @var ConfigurationContentDimensionPresetSource
     */
    protected ConfigurationContentDimensionPresetSource $configurationContentDimensionPresetSource;

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
        UriInterface $uri,
        int $statusCode
    ) {
        $siteName = $site->getNodeName();
        $requestPath = ltrim($uri->getPath() ?? '', '/');
        $requestedDimensionPathSegment = $this->resolveRequestedDimensionPathSegment($requestPath);

        $configurationsForSite = $this->getConfiguration();
        $configurationsForSite = array_key_exists(
            $siteName,
            $configurationsForSite
        ) ? $configurationsForSite[$siteName] : [];

        $matchingStatusCodes = array_filter($configurationsForSite,
            function (array $configuration) use ($statusCode) {
                return in_array($statusCode, $configuration['matchingStatusCodes'] ?? [], true);
            });

        $matchingDimensions = array_filter($matchingStatusCodes,
            function (array $configuration) use ($requestedDimensionPathSegment) {
                $dimensionPathSegment = $configuration['dimensionPathSegment'] ?? '';
                if ($dimensionPathSegment === '' && empty($configuration['dimensions'] ?? [])) {
                    return true;
                }

                return $dimensionPathSegment === $requestedDimensionPathSegment;
            });

        $configurationWithPathPrefixes = array_filter($matchingDimensions,
            function (array $configuration) {
                return !empty($configuration['pathPrefixes'] ?? []);
            });

        $configurationsWithoutPathPrefixes = array_filter($matchingDimensions,
            function (array $configuration) {
                return empty($configuration['pathPrefixes'] ?? []);
            });

        if (!empty($configurationWithPathPrefixes)) {
            $matchingPathPrefixes = array_filter($matchingDimensions,
                function (array $configuration) use ($requestPath) {
                    foreach ($configuration['pathPrefixes'] ?? [] as $pathPrefix) {
                        if (strpos($requestPath, ltrim($pathPrefix, '/')) === 0) {
                            return true;
                        }
                    }

                    return false;
                });

            if (empty($matchingPathPrefixes)) {
                return current($configurationsWithoutPathPrefixes);
            }

            return current($matchingPathPrefixes);
        }

        return current($configurationsWithoutPathPrefixes) ?: current($matchingStatusCodes) ?: null;
    }

    /**
     * @return array<string, SiteConfiguration[]>
     */
    public function getConfiguration(): array
    {
        return iterator_to_array($this->generateConfiguration());
    }

    /**
     * @param string $expression
     * @param array $context
     * @return mixed
     * @throws \Neos\Eel\Exception
     */
    protected function evaluateEelExpression(string $expression, array $context)
    {
        return EelUtility::evaluateEelExpression($expression, $this->eelEvaluator, $context, []);
    }

    private function resolveRequestedDimensionPathSegment(string $requestPath): string
    {
        if (method_exists($this->frontendNodeRoutePartHandler, 'parseDimensionsAndNodePathFromRequestPath')) {
            try {
                $reflectionMethod = new ReflectionMethod(
                    $this->frontendNodeRoutePartHandler,
                    'parseDimensionsAndNodePathFromRequestPath'
                );
                $reflectionMethod->setAccessible(true);
                $parsedDimensions = $reflectionMethod->invokeArgs($this->frontendNodeRoutePartHandler, [&$requestPath]);
                $result = [];

                foreach ($parsedDimensions as $dimension => $values) {
                    $preset = $this->configurationContentDimensionPresetSource->findPresetByDimensionValues(
                        $dimension, $values
                    );

                    $result[] = $preset['uriSegment'];
                }

                return join('_', $result);
            } catch (Throwable) {
            }
        }

        return current(explode('/', $requestPath, 2));
    }

    /**
     * @return Generator<string, SiteConfiguration[]>
     */
    private function generateConfiguration(): Generator
    {
        $nodeBased = $this->nodeBasedConfiguration->getConfiguration();
        $settingsBased = $this->settingsBasedConfiguration->getConfiguration();

        $sites = array_keys($nodeBased + $settingsBased);
        foreach ($sites as $site) {
            yield $site => array_values(array_merge(($nodeBased[$site] ?? []), ($settingsBased[$site] ?? [])));
        }
    }

}
