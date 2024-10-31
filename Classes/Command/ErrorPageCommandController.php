<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\StreamWrapper;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Core\Bootstrap;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\FrontendRouting\Exception\NodeNotFoundException;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Utility\Files;
use Netlogix\ErrorHandler\Configuration\ErrorHandlerConfiguration;
use Netlogix\ErrorHandler\Service\ActionRequestFactory;
use Netlogix\ErrorHandler\Service\DestinationResolver;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function fopen;
use function is_dir;
use function stream_copy_to_stream;

/**
 * @Flow\Scope("singleton")
 */
class ErrorPageCommandController extends CommandController
{

    #[Flow\Inject]
    protected Bootstrap $bootstrap;

    #[Flow\Inject]
    protected ErrorHandlerConfiguration $configuration;

    #[Flow\Inject]
    protected DestinationResolver $destinationResolver;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected ActionRequestFactory $actionRequestFactory;

    /**
     * Generate Error Pages for configured Sites
     *
     * @param bool $verbose
     * @throws \Exception
     */
    public function generateCommand(bool $verbose = false)
    {
        $client = new Client([
            'verify' => $this->bootstrap->getContext()->isProduction()
        ]);
        $hadError = false;

        foreach ($this->configuration->getConfiguration() as $siteNodeName => $configurations) {
            foreach ($configurations as $configuration) {
                $site = $this->siteRepository->findOneByNodeName($siteNodeName);
                assert($site instanceof Site);

                if (!$site->isOnline()) {
                    $verbose && $this->outputLine('Site %s is not online', [$siteNodeName]);
                    continue;
                }
                try {
                    $requestUri = $this->getSiteUri($site, $configuration);
                } catch (\Exception $e) {
                    $verbose && $this->outputLine('Could not resolve Error Page Uri for %s, "%s"', [$siteNodeName, $e->getMessage()]);
                    continue;
                }
                $verbose && $this->outputLine('Downloading Error Page for %s from %s', [$siteNodeName, $requestUri]);

                try {
                    $response = $client->get($requestUri);
                } catch (\Exception $e) {
                    $hadError = true;
                    $this->outputLine('Could not fetch Error Page for %s, "%s"', [$siteNodeName, $e->getMessage()]);
                    continue;
                }

                $destination = $this->destinationResolver->getDestinationForConfiguration($configuration, $siteNodeName);
                $directory = dirname($destination);
                if (!is_dir($directory)) {
                    try {
                        Files::createDirectoryRecursively($directory);
                    } catch (\Exception $e) {
                        $hadError = true;
                        $this->outputLine('Could not create target directory for %s, "%s"', [$siteNodeName, $e->getMessage()]);
                        continue;
                    }
                }
                $verbose && $this->outputLine('Saving Error Page for %s to %s', [$siteNodeName, $destination]);
                $file = fopen($destination, 'w+');
                stream_copy_to_stream(StreamWrapper::getResource($response->getBody()), $file);
            }
        }

        if ($hadError) {
            $this->sendAndExit(1);
        }
    }

    /**
     * Export current error page configuration in YAML format.
     * This combines both, Node Type based settings, and YAML based settings.
     *
     * @param bool $verbose
     * @throws \Exception
     */
    public function showConfigurationCommand(): void
    {
        $result = [
            'Netlogix' => [
                'ErrorHandler' => [
                    'pages' => $this->configuration->getConfiguration()
              ]
            ]
        ];
        $this->output(Yaml::dump($result, 10, 2));
    }

    protected function getSiteUri(Site $site, array $configuration): UriInterface
    {
        $domain = $site->getPrimaryDomain();
        if (!$domain) {
            throw new RuntimeException(sprintf('Site %s has no primary domain', $site->getNodeName()), 1708944032);
        }

        $contentRepository = $this->contentRepositoryRegistry->get($site->getConfiguration()->contentRepositoryId);
        $contentGraph = $contentRepository->getContentGraph(WorkspaceName::fromString(WorkspaceName::WORKSPACE_NAME_LIVE));

        $nodeAggregate = $contentGraph->findNodeAggregateById(NodeAggregateId::fromString(substr($configuration['source'], 1)));
        $dimensionSpacePoint = DimensionSpacePoint::fromJsonString(json_encode($configuration['dimensions']));

        $source = $nodeAggregate->getNodeByCoveredDimensionSpacePoint($dimensionSpacePoint);

        if (!$this->isNodeVisible($source)) {
            throw new NodeNotFoundException('Node ' . $source->aggregateId . ' is not visible', 1552492532);
        }

        $siteDetectionResult = SiteDetectionResult::create($site->getNodeName(), $contentRepository->id);
        $actionRequest = $this->actionRequestFactory->buildActionRequest($siteDetectionResult);
        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($actionRequest);

        return $nodeUriBuilder->uriFor(NodeAddress::fromNode($source), Options::createForceAbsolute());
    }

    protected function isNodeVisible(Node $node): bool
    {
        return !$node->tags->contain(SubtreeTag::disabled());
    }

}
