<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility as EelUtility;
use Neos\ContentRepository\Utility as CrUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Flow\Property\PropertyMapper;
use Neos\Neos\Domain\Service\ContentContext;

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
     * @var PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @param string $contextPath
     * @return array|null
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     */
    public function findConfigurationForContextPath(string $contextPath)
    {
        $node = $this->propertyMapper->convert($contextPath, NodeInterface::class);
        assert($node instanceof NodeInterface);
        $context = $node->getContext();
        assert($context instanceof ContentContext);

        $siteName = $context->getCurrentSite()->getNodeName();
        $configurationsForSite = array_key_exists($siteName, $this->pages) ? $this->pages[$siteName] : [];

        $matchingConfigurations = array_filter($configurationsForSite,
            function (array $configuration) use ($node, $context) {
                $targetDimensions = $context->getTargetDimensionValues();
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
    public function getDestinationForConfiguration(array $config, string $siteNodeName, Uri $requestUri): string
    {
        return $this->evaluateEelExpression($config['destination'], [
            'site' => $siteNodeName,
            'dimensions' => current(explode('/', ltrim($requestUri->getPath(), '/')))
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
