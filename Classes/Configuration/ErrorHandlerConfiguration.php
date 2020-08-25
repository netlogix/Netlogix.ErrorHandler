<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility as EelUtility;
use Neos\ContentRepository\Utility as CrUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Neos\Domain\Model\Site;
use Netlogix\ErrorHandler\Service\DimensionResolver;

/**
 * @Flow\Scope("singleton")
 */
class ErrorHandlerConfiguration
{

    /**
     * @Flow\InjectConfiguration(path="pages")
     * @var array
     */
    protected $pages;

    /**
     * @Flow\Inject(lazy=false)
     * @var CompilingEvaluator
     */
    protected $eelEvaluator;

    /**
     * @Flow\Inject
     * @var DimensionResolver
     */
    protected $dimensionResolver;

    /**
     * @param Site $site
     * @param Uri $uri
     * @param int $statusCode
     * @return mixed|null
     */
    public function findConfigurationForSite(
        Site $site,
        Uri $uri,
        int $statusCode
    ) {
        $siteName = $site->getNodeName();
        $configurationsForSite = array_key_exists($siteName, $this->pages) ? $this->pages[$siteName] : [];
        $targetDimensions = $this->getDimensionsByRequestUri($uri);

        $matchingConfigurations = array_filter($configurationsForSite,
            function (array $configuration) use ($targetDimensions, $statusCode) {
                $configurationDimensions = $configuration['dimensions'];
                CrUtility::sortDimensionValueArrayAndReturnDimensionsHash($configurationDimensions);

                foreach ($targetDimensions as $dimension => $values) {
                    if (!array_key_exists($dimension, $configurationDimensions)) {
                        continue;
                    }

                    if (!empty(array_diff($configurationDimensions[$dimension], $values))) {
                        return false;
                    }
                }

                if (!in_array($statusCode, $configuration['matchingStatusCodes'] ?? [], true)) {
                    return false;
                }

                return true;
            });

        return !empty($matchingConfigurations) ? current($matchingConfigurations) : null;
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
     * @param Uri $requestUri
     * @return string
     * @throws \Neos\Eel\Exception
     */
    public function getDestinationForConfiguration(
        array $config,
        string $siteNodeName,
        Uri $requestUri
    ): string {
        $firstUriPathSegment = current(explode('/', ltrim($requestUri->getPath() ?? '', '/'), 2));

        return $this->evaluateEelExpression($config['destination'], [
            'site' => $siteNodeName,
            'dimensions' => $firstUriPathSegment,
        ]);
    }

    /**
     * @return array
     */
    public function getConfiguration()
    {
        return $this->pages;
    }

    private function getDimensionsByRequestUri(Uri $uri): array
    {
        return $this->dimensionResolver->determineDimensionValuesByRequestUri($uri);
    }

}
