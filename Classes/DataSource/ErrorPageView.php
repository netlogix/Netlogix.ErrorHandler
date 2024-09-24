<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\DataSource;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Service\DataSource\DataSourceInterface;
use Netlogix\ErrorHandler\Configuration\NodeBasedConfiguration;
use Neos\Flow\Annotations as Flow;
use Netlogix\ErrorHandler\Service\DestinationResolver;

final class ErrorPageView implements DataSourceInterface
{
    /**
     * @var NodeBasedConfiguration
     * @Flow\Inject
     */
    protected $nodeBasedConfiguration;

    /**
     * @var DomainRepository
     * @Flow\Inject
     */
    protected $domainRepository;

    /**
     * @var DestinationResolver
     * @Flow\Inject
     */
    protected $destinationResolver;

    public function getData(NodeInterface $node = null, array $arguments = []): array
    {
        if (!$node) {
            return [];
        }
        $domain = $this->domainRepository->findOneByActiveRequest();
        if (!($domain instanceof Domain)) {
            return [];
        }
        $config = $this->nodeBasedConfiguration->getErrorNodeConfiguration($node);
        return [
            'data' => [
                'rows' => [
                    [
                        'destination' => $this->destinationResolver->getDestinationForConfiguration(
                            $config,
                            $domain->getSite()->getNodeName()
                        ),
                    ],
                ]
            ],
        ];
    }

    public static function getIdentifier(): string
    {
        return 'netlogix-errorhandler-errorpage-view';
    }
}