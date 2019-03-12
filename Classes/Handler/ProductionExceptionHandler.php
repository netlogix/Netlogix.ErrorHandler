<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Handler;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\DispatchComponent;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Utility\ObjectAccess;
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
        $errorPage = $this->findErrorPageConfigurationForRequest();

        if ($errorPage === null || !file_exists($errorPage)) {
            return parent::renderStatically($statusCode, $referenceCode);
        }

        return file_get_contents($errorPage);
    }

    /**
     * @return string|null
     * @throws \Exception
     */
    protected function findErrorPageConfigurationForRequest()
    {
        if (!Bootstrap::$staticObjectManager instanceof ObjectManagerInterface) {
            return null;
        }

        $requestHandler = Bootstrap::$staticObjectManager->get(Bootstrap::class)->getActiveRequestHandler();
        $componentContext = ObjectAccess::getProperty($requestHandler, 'componentContext', true);
        $actionRequest = $componentContext->getParameter(DispatchComponent::class, 'actionRequest');
        assert($actionRequest instanceof ActionRequest);

        if (!$actionRequest->hasArgument('node')) {
            return null;
        }

        $errorHandlerConfiguration = Bootstrap::$staticObjectManager->get(ErrorHandlerConfiguration::class);
        $node = $actionRequest->getArgument('node');
        $configuration = $errorHandlerConfiguration->findConfigurationForContextPath($node);

        if (!$configuration) {
            return null;
        }

        $currentDomain = Bootstrap::$staticObjectManager->get(DomainRepository::class)->findOneByActiveRequest();

        if (!$currentDomain) {
            return null;
        }

        return $errorHandlerConfiguration->getDestinationForConfiguration(
            $configuration,
            $currentDomain->getSite()->getNodeName(),
            $actionRequest->getHttpRequest()->getUri()
        );
    }

}