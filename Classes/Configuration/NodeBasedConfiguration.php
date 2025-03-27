<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

use Exception;
use Generator;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Service\LinkingService;
use Netlogix\ErrorHandler\Service\ControllerContextFactory;

use RuntimeException;

use function array_map;
use function array_values;
use function dirname;
use function is_array;
use function is_scalar;
use function json_encode;

/**
 * @Flow\Scope("singleton")
 * @phpstan-import-type SiteConfiguration from ErrorHandlerConfiguration
 */
class NodeBasedConfiguration
{
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
     * @var ControllerContextFactory
     */
    protected ControllerContextFactory $controllerContextFactory;

    /**
     * @Flow\Inject(lazy=false)
     * @var ContentDimensionPresetSourceInterface
     */
    protected ContentDimensionPresetSourceInterface $contentDimensionPresetSource;

    /**
     * @Flow\Inject(lazy=false)
     * @var ContentDimensionCombinator
     */
    protected ContentDimensionCombinator $contentDimensionCombinator;

    /**
     * @Flow\Inject(lazy=false)
     * @var ThrowableStorageInterface
     */
    protected ThrowableStorageInterface $throwableStorage;

    /**
     * @Flow\InjectConfiguration(path="destination")
     */
    protected string $destination;

    /**
     * @return array<string, SiteConfiguration[]>
     */
    public function getConfiguration(): array
    {
        $configurationsForSite = [];
        $siteNodes = $this->getSiteNodes();
        $siteNodes = iterator_to_array($siteNodes, false);
        foreach ($siteNodes as $siteNode) {
            assert($siteNode instanceof NodeInterface);

            $sitename = (string)$siteNode->getNodeName();
            $configurationsForSite[$sitename] = $configurationsForSite[$sitename] ?? [];

            $errorNodes = FlowQuery::q($siteNode)
                ->find('[instanceof Netlogix.ErrorHandler.NodeTypes:Mixin.ErrorPage]')
                ->get();

            foreach ($errorNodes as $errorNode) {
                assert($errorNode instanceof NodeInterface);
                try {
                    $errorNodeConfiguration = $this->getErrorNodeConfiguration($errorNode);
                    $configurationsForSite[$sitename][json_encode($errorNodeConfiguration)] = $errorNodeConfiguration;
                } catch (Exception $e) {
                    // This is used for creating error pages.
                    // One faulty error page config must not prevent other error pages from being rendered.
                    $this->throwableStorage->logThrowable($e);
                }
            }

            usort($configurationsForSite[$sitename], fn($a, $b) => $a['position'] <=> $b['position']);
            $configurationsForSite[$sitename] = array_values($configurationsForSite[$sitename]);
        }

        return $configurationsForSite;
    }

    public function getErrorNodeConfiguration(NodeInterface $errorNode): array
    {
        $pathPrefixes = $this->extractPathPrefixes($errorNode);
        $position = 1000;
        foreach ($pathPrefixes as $pathPrefix) {
            $position -= strlen($pathPrefix);
        }

        return [
            'matchingStatusCodes' => $this->extractStatusCodes($errorNode),
            'dimensions' => $this->extractDimensions($errorNode),
            'dimensionPathSegment' => $this->extractDimensionsPathSegment($errorNode),
            'source' => '#' . $errorNode->getIdentifier(),
            'destination' => $this->destination,
            'pathPrefixes' => $pathPrefixes,
            'position' => $position,
        ];
    }

    /**
     * Returns all NodeInterface Site node objects currently
     * known to the ContentRepository context.
     *
     * @return Generator<NodeInterface>
     */
    protected function getSiteNodes()
    {
        $currentContexts = $this->getContentContexts();
        foreach ($currentContexts as $contextContext) {
            $rootNode = $contextContext->getRootNode();
            $sitesCollections = $rootNode->findChildNodes();
            foreach ($sitesCollections as $sitesCollection) {
                yield from $sitesCollection->findChildNodes();
            }
        }
    }

    /**
     * Map every dimension combination to a corresponding content context.
     *
     * @return ContentContext[]
     */
    protected function getContentContexts(): array
    {
        $dimensionCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        foreach ($dimensionCombinations as $dimensionCombination) {
            $context = $this->contextFactory->create(['dimensions' => $dimensionCombination]);
            assert($context instanceof ContentContext);
            $contentContextsByDimension[json_encode($dimensionCombination)] = $context;
        }
        return array_values($contentContextsByDimension);
    }

    /**
     * @return int[]
     */
    protected function extractStatusCodes(NodeInterface $errorNode): array
    {
        $matchingStatusCodes = $errorNode->getProperty('matchingStatusCodes');
        if (is_array($matchingStatusCodes) && count($matchingStatusCodes) > 0) {
            return array_map('intval', $matchingStatusCodes);
        }
        if (is_scalar($matchingStatusCodes)) {
            return [(int)$matchingStatusCodes];
        }
        // This exception gets ignored but results in this very page not being rendered.
        throw new RuntimeException('The page is not responsible for any status codes.', 1727101503);
    }

    /**
     * @return array<string, string[]>
     */
    protected function extractDimensions(NodeInterface $errorNode): array
    {
        // @phpstan-ignore-next-line
        return $errorNode->getDimensions();
    }

    protected function extractDimensionsPathSegment(NodeInterface $errorNode): string
    {
        $result = [];
        foreach ($errorNode->getDimensions() as $singleDimensionValues) {
            foreach ($singleDimensionValues as $singleDimensionValue) {
                $result[] = $singleDimensionValue;
            }
        }
        return join('-', $result);
    }

    /**
     * @return string[]
     */
    protected function extractPathPrefixes(NodeInterface $errorNode): array
    {
        $controllerContext = $this->controllerContextFactory->buildControllerContext(new Uri());
        $errorUrl = new Uri($this->linkingService->createNodeUri($controllerContext, $errorNode));
        return [dirname($errorUrl->getPath())];
    }
}
