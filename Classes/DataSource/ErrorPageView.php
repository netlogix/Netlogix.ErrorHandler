<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\DataSource;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Service\DataSource\DataSourceInterface;
use Netlogix\ErrorHandler\Configuration\NodeBasedConfiguration;
use Neos\Flow\Annotations as Flow;
use Netlogix\ErrorHandler\Service\DestinationResolver;

final class ErrorPageView implements DataSourceInterface
{
    #[Flow\Inject]
    protected NodeBasedConfiguration $nodeBasedConfiguration;

    #[Flow\Inject]
    protected DomainRepository $domainRepository;

    #[Flow\Inject]
    protected DestinationResolver $destinationResolver;

    public function getData(Node $node = null, array $arguments = []): array
    {
        if (!$node) {
            return [];
        }
        $domain = $this->domainRepository->findOneByActiveRequest();
        if (!($domain instanceof Domain)) {
            return [];
        }
        $config = $this->nodeBasedConfiguration->getErrorNodeConfiguration($domain->getSite()->getNodeName(), $node);

        return [
            'data' => [
                'rows' => [
                    [
                        'destination' => $this->destinationResolver->getDestinationForConfiguration(
                            $config,
                            (string)$domain->getSite()->getNodeName()
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