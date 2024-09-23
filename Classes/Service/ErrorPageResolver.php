<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Neos\Domain\Repository\DomainRepository;
use Netlogix\ErrorHandler\Configuration\ErrorHandlerConfiguration;

/**
 * @Flow\Scope("singleton")
 */
class ErrorPageResolver implements ProtectedContextAwareInterface
{

    /**
     * @var ErrorHandlerConfiguration
     */
    protected $errorHandlerConfiguration;

    /**
     * @var Bootstrap
     */
    private $bootstrap;

    /**
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @var DestinationResolver
     */
    protected $destinationResolver;

    public function __construct(
        ErrorHandlerConfiguration $errorHandlerConfiguration,
        Bootstrap $bootstrap,
        DomainRepository $domainRepository,
        DestinationResolver $destinationResolver
    ) {
        $this->errorHandlerConfiguration = $errorHandlerConfiguration;
        $this->bootstrap = $bootstrap;
        $this->domainRepository = $domainRepository;
        $this->destinationResolver = $destinationResolver;
    }

    public function findErrorPageForCurrentRequestAndStatusCode(int $statusCode): ?string
    {
        $requestHandler = $this->bootstrap->getActiveRequestHandler();
        if (!$requestHandler instanceof HttpRequestHandlerInterface) {
            return null;
        }

        $currentDomain = $this->domainRepository->findOneByActiveRequest();
        if (!$currentDomain) {
            return null;
        }

        $currentSite = $currentDomain->getSite();
        if (!$currentSite) {
            return null;
        }

        $configuration = $this
            ->errorHandlerConfiguration
            ->findConfigurationForSite($currentSite, $requestHandler->getHttpRequest()->getUri(), $statusCode);

        if (!$configuration) {
            return null;
        }

        try {
            return $this->destinationResolver->getDestinationForConfiguration(
                $configuration,
                $currentDomain->getSite()->getNodeName()
            );
        } catch (\Throwable $t) {
        }

        return null;
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }

}
