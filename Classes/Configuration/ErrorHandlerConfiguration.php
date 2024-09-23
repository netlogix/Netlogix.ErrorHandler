<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

use Generator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility as EelUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Service\LinkingService;
use Psr\Http\Message\UriInterface;

use function array_filter;
use function array_values;
use function current;
use function explode;
use function in_array;
use function iterator_to_array;
use function preg_match;

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
        $requestedDimensionPathSegment = current(explode('/', $requestPath, 2));

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
     * @param string $expression
     * @param array $context
     * @return mixed
     * @throws \Neos\Eel\Exception
     */
    protected function evaluateEelExpression(string $expression, array $context)
    {
        return EelUtility::evaluateEelExpression($expression, $this->eelEvaluator, $context, []);
    }

    /**
     * @param array $config
     * @param string $siteNodeName
     * @return string
     * @throws \Neos\Eel\Exception
     */
    public function getDestinationForConfiguration(
        array $config,
        string $siteNodeName
    ): string {
        $dimensionPathSegment = $config['dimensionPathSegment'] ?? '';
        $nodeIdentifier = preg_match(
            '/^#([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/',
            $config['source'] ?? '',
            $matches
        )
            ? $matches[1]
            : null;

        return $this->evaluateEelExpression($config['destination'], [
            'site' => $siteNodeName,
            'dimensions' => $dimensionPathSegment,
            'node' => $nodeIdentifier,
        ]);
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
        $settingsBased = $this->settingsBasedConfiguration->getConfiguration();

        $sites = array_keys($nodeBased + $settingsBased);
        foreach ($sites as $site) {
            yield $site => array_values(array_merge(($nodeBased[$site] ?? []), ($settingsBased[$site] ?? [])));
        }
    }

}
