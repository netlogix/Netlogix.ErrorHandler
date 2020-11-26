<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility as EelUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Neos\Domain\Model\Site;

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
     * Find error page configuration by Site, Dimension (parsed in $uri) and status code.
     * If no configuration is found that matches the parsed dimensionPathSegment, the first configuration
     * for the Site and statusCode is used.
     *
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
        $dimensionPathSegment = current(explode('/', ltrim($uri->getPath() ?? '', '/'), 2));

        $matchingStatusCodes = array_filter($configurationsForSite,
            function (array $configuration) use ($dimensionPathSegment, $statusCode) {
                return in_array($statusCode, $configuration['matchingStatusCodes'] ?? [], true);
            });

        $matchingDimensions = array_filter($matchingStatusCodes,
            function (array $configuration) use ($dimensionPathSegment, $statusCode) {
                return ($configuration['dimensionPathSegment'] ?? '') === $dimensionPathSegment;
            });

        return current($matchingDimensions) ?: current($matchingStatusCodes) ?: null;
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
        string $siteNodeName
    ): string {
        $dimensionPathSegment = $config['dimensionPathSegment'] ?? '';

        return $this->evaluateEelExpression($config['destination'], [
            'site' => $siteNodeName,
            'dimensions' => $dimensionPathSegment,
        ]);
    }

    /**
     * @return array
     */
    public function getConfiguration()
    {
        return $this->pages;
    }

}
