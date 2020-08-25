<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Handler;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Repository\DomainRepository;
use Netlogix\ErrorHandler\Configuration\ErrorHandlerConfiguration;
use Networkteam\SentryClient\Handler\ProductionExceptionHandler as SentryProductionExceptionHandler;

class ProductionExceptionHandler extends SentryProductionExceptionHandler
{

    /**
     * @param int $statusCode
     * @param string|null $referenceCode
     * @return string
     * @throws \Exception
     */
    protected function renderStatically(int $statusCode, string $referenceCode = null): string
    {
        $errorPage = $this->findErrorPageConfigurationForRequest($statusCode);

        if ($errorPage === null || !file_exists($errorPage)) {
            return parent::renderStatically($statusCode, $referenceCode);
        }

        return file_get_contents($errorPage);
    }

    /**
     * @param int $statusCode
     * @return string|null
     * @throws \Exception
     */
    protected function findErrorPageConfigurationForRequest(int $statusCode)
    {
        if (!Bootstrap::$staticObjectManager instanceof ObjectManagerInterface) {
            return null;
        }

        $requestHandler = Bootstrap::$staticObjectManager->get(Bootstrap::class)->getActiveRequestHandler();
        if (!$requestHandler instanceof HttpRequestHandlerInterface) {
            return null;
        }

        $currentDomain = Bootstrap::$staticObjectManager->get(DomainRepository::class)->findOneByActiveRequest();

        if (!$currentDomain) {
            return null;
        }

        $currentSite = $currentDomain->getSite();
        $errorHandlerConfiguration = Bootstrap::$staticObjectManager->get(ErrorHandlerConfiguration::class);
        $configuration = $errorHandlerConfiguration->findConfigurationForSite($currentSite, $requestHandler->getHttpRequest()->getUri(), $statusCode);

        if (!$configuration) {
            return null;
        }

        return $errorHandlerConfiguration->getDestinationForConfiguration(
            $configuration,
            $currentDomain->getSite()->getNodeName(),
            $requestHandler->getHttpRequest()->getUri()
        );
    }

}