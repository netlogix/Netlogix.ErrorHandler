<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Neos\Domain\Repository\DomainRepository;
use Netlogix\ErrorHandler\Configuration\ErrorHandlerConfiguration;
use Throwable;

/**
 * @Flow\Scope("singleton")
 */
class ErrorPageResolver implements ProtectedContextAwareInterface
{
    public function __construct(
        protected ErrorHandlerConfiguration $errorHandlerConfiguration,
        protected Bootstrap $bootstrap,
        protected DomainRepository $domainRepository,
        protected DestinationResolver $destinationResolver,
        protected ThrowableStorageInterface $throwableStorage,
    ) {
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
            ->findConfigurationForSite($currentSite, $requestHandler->getHttpRequest(), $statusCode);

        if (!$configuration) {
            return null;
        }

        try {
            return $this->destinationResolver->getDestinationForConfiguration(
                $configuration,
                (string)$currentDomain->getSite()->getNodeName()
            );
        } catch (Throwable $t) {
            $this->throwableStorage->logThrowable($t);
        }

        return null;
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }

}
