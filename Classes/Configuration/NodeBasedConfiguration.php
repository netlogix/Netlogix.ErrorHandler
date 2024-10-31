<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

use Exception;
use Generator;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;

use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Netlogix\ErrorHandler\Service\ActionRequestFactory;
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
    #[Flow\Inject]
    protected ThrowableStorageInterface $throwableStorage;

    #[Flow\InjectConfiguration(path: 'destination')]
    protected string $destination;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected ActionRequestFactory $actionRequestFactory;

    /**
     * @return array<string, SiteConfiguration[]>
     */
    public function getConfiguration(): array
    {
        $configurationsForSite = [];
        $siteNodes = $this->getSiteNodes();

        foreach ($siteNodes as $siteNodeName => $siteNode) {
            assert($siteNodeName instanceof SiteNodeName);
            assert($siteNode instanceof Node);

            $sitename = (string)$siteNode->name;
            $configurationsForSite[$sitename] = $configurationsForSite[$sitename] ?? [];

            $errorNodes = FlowQuery::q($siteNode)
                ->find('[instanceof Netlogix.ErrorHandler.NodeTypes:Mixin.ErrorPage]')
                ->get();

            foreach ($errorNodes as $errorNode) {
                assert($errorNode instanceof Node);
                try {
                    $errorNodeConfiguration = $this->getErrorNodeConfiguration($siteNodeName, $errorNode);
                    $configurationsForSite[$sitename][json_encode($errorNodeConfiguration)] = $errorNodeConfiguration;
                } catch (Exception $e) {
                    // This is used for creating error pages.
                    // One faulty error page config must not prevent other error pages from being rendered.
                    $this->throwableStorage->logThrowable($e);
                    continue;
                }
            }

            usort($configurationsForSite[$sitename], fn($a, $b) => $a['position'] <=> $b['position']);
            $configurationsForSite[$sitename] = array_values($configurationsForSite[$sitename]);
        }

        return array_filter($configurationsForSite);
    }

    public function getErrorNodeConfiguration(SiteNodeName $siteNodeName, Node $errorNode): array
    {
        $pathPrefixes = $this->extractPathPrefixes($siteNodeName, $errorNode);
        $position = 1000;
        foreach ($pathPrefixes as $pathPrefix) {
            $position -= strlen($pathPrefix);
        }

        return [
            'matchingStatusCodes' => $this->extractStatusCodes($errorNode),
            'dimensions' => json_decode($errorNode->dimensionSpacePoint->toJson(), true),
            'source' => '#' . $errorNode->aggregateId,
            'destination' => $this->destination,
            'pathPrefixes' => $pathPrefixes,
            'position' => $position,
        ];
    }

    /**
     * Returns all NodeInterface Site node objects currently
     * known to the ContentRepository context.
     *
     * @return Generator<SiteNodeName, Node>
     */
    protected function getSiteNodes(): iterable
    {
        $sites = $this->siteRepository->findOnline();

        foreach ($sites as $site) {
            assert($site instanceof Site);

            $contentRepository = $this->contentRepositoryRegistry->get($site->getConfiguration()->contentRepositoryId);
            $contentGraph = $contentRepository->getContentGraph(WorkspaceName::fromString(WorkspaceName::WORKSPACE_NAME_LIVE));
            $rootNodeAggregates = $contentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create(nodeTypeName: 'Neos.Neos:Sites'));

            if ($rootNodeAggregates->count() !== 1) {
                throw new RuntimeException('Expected exactly one root node aggregate for the site.', 1730410697);
            }
            $rootNodeAggregate = $rootNodeAggregates->first();

            foreach ($this->combineAllDimensionSpacePoints($contentRepository) as $dimensionSpacePoint) {
                $subgraph = $contentGraph->getSubgraph(
                    $dimensionSpacePoint,
                    VisibilityConstraints::frontend()
                );

                $children = $subgraph->findChildNodes(
                    $rootNodeAggregate->nodeAggregateId,
                    FindChildNodesFilter::create(
                        nodeTypes: 'Neos.Neos:Site'
                    )
                );

                foreach ($children as $child) {
                    yield $site->getNodeName() => $child;
                }
            }
        }
    }

    /**
     * @param ContentRepository $contentRepository
     * @return iterable<int, DimensionSpacePoint>
     */
    protected function combineAllDimensionSpacePoints(ContentRepository $contentRepository): iterable
    {
        $contentDimensions = $contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority();
        $combinations = [];

        foreach ($contentDimensions as $contentDimension) {
            assert($contentDimension instanceof ContentDimension);

            foreach ($contentDimension->values as $contentDimensionValue) {
                foreach ($contentDimensions as $otherContentDimension) {
                    foreach ($otherContentDimension->values as $otherContentDimensionValue) {
                        $coordinates = array_merge(
                            [(string)$contentDimension->id->jsonSerialize() => $contentDimensionValue->value],
                            [(string)$otherContentDimension->id->jsonSerialize() => $otherContentDimensionValue->value]
                        );
                        ksort($coordinates);
                        $combinations[sha1(join('', $coordinates))] = DimensionSpacePoint::fromArray($coordinates);
                    }
                }
            }
        }

        return array_values($combinations);
    }

    /**
     * @return int[]
     */
    protected function extractStatusCodes(Node $errorNode): array
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
     * @return string[]
     */
    protected function extractPathPrefixes(SiteNodeName $siteNodeName, Node $errorNode): array
    {
        $siteDetectionResult = SiteDetectionResult::create(
            $siteNodeName,
            $errorNode->contentRepositoryId
        );

        $actionRequest = $this->actionRequestFactory->buildActionRequest($siteDetectionResult);

        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($actionRequest);
        $errorUrl = $nodeUriBuilder->uriFor(NodeAddress::fromNode($errorNode));

        return [dirname($errorUrl->getPath())];
    }
}
