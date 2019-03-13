<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Command;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\StreamWrapper;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\Uri;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\LinkingService;
use Neos\Utility\Files;
use Netlogix\ErrorHandler\Configuration\ErrorHandlerConfiguration;
use Netlogix\ErrorHandler\Service\ControllerContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class ErrorPageCommandController extends CommandController
{

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject
     * @var ErrorHandlerConfiguration
     */
    protected $configuration;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var ControllerContextFactory
     */
    protected $controllerContextFactory;

    /**
     * Generate Error Pages for configured Sites
     *
     * @param bool $verbose
     *
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

                $destination = $this->configuration->getDestinationForConfiguration($configuration, $siteNodeName, $requestUri);
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
                $file = fopen($destination, 'w+');
                stream_copy_to_stream(StreamWrapper::getResource($response->getBody()), $file);
            }
        }

        if ($hadError) {
            $this->sendAndExit(1);
        }
    }

    /**
     * @param Site $site
     * @param array $configuration
     * @return Uri
     * @throws \Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \Neos\Flow\Mvc\Exception\InvalidActionNameException
     * @throws \Neos\Flow\ObjectManagement\Exception\UnknownObjectException
     * @throws \Neos\Neos\Exception
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     */
    protected function getSiteUri(Site $site, array $configuration)
    {
        $domain = $site->getPrimaryDomain();
        if (!$domain) {
            $this->outputLine('Skipping %s as no primary domain is set.', [$site->getNodeName()]);
        }

        $context = $this->getContextForSite($site, $domain, $configuration['dimensions']);
        $source = $context->getNodeByIdentifier(substr($configuration['source'], 1));

        if (!$source instanceof NodeInterface) {
            throw new NodeNotFoundException('Could not get source node in site ' . $site->getNodeName(), 1552492278);
        }

        if (!$this->isNodeVisible($source)) {
            throw new NodeNotFoundException('Node for site ' . $site->getNodeName() . ' is not visible', 1552492532);
        }

        $controllerContext = $this->controllerContextFactory->buildControllerContext($domain->__toString());
        $nodeUri = $this->linkingService->createNodeUri($controllerContext, $source, null, null, true);

        return new Uri($nodeUri);
    }

    /**
     * @param NodeInterface $node
     * @return bool
     */
    protected function isNodeVisible(NodeInterface $node)
    {
        $currentNode = $node;

        do {
            if (!$currentNode->isVisible()) {
                return false;
            }

            $currentNode = $currentNode->getParent();
        } while ($currentNode !== null);

        return true;
    }

    /**
     * @param Site $site
     * @param Domain $domain
     * @param array $dimensions
     * @return \Neos\ContentRepository\Domain\Service\Context
     */
    protected function getContextForSite(Site $site, Domain $domain, array $dimensions)
    {
        return $this->contentContextFactory->create([
            'workspaceName' => 'live',
            'targetDimensions' => array_map(function(array $dimensionValues) {
                return current($dimensionValues);
            }, $dimensions),
            'dimensions' => $dimensions,
            'currentSite' => $site,
            'currentDomain' => $domain,
            'invisibleContentShown' => true
        ]);
    }

}
