<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Utility as EelUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\LinkingService;

use function preg_match;

/**
 * @Flow\Scope("singleton")

 */
class DestinationResolver
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
     * @param string $expression
     * @param array $context
     * @return mixed
     * @throws \Neos\Eel\Exception
     */
    protected function evaluateEelExpression(string $expression, array $context)
    {
        return EelUtility::evaluateEelExpression($expression, $this->eelEvaluator, $context, []);
    }
}
