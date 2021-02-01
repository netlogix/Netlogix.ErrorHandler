<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Handler;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\ProductionExceptionHandler as FlowProductionExceptionHandler;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Netlogix\ErrorHandler\Service\ErrorPageResolver;

class ProductionExceptionHandler extends FlowProductionExceptionHandler
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

    protected function findErrorPageConfigurationForRequest(int $statusCode): ?string
    {
        if (!Bootstrap::$staticObjectManager instanceof ObjectManagerInterface) {
            return null;
        }
        $errorPageResolver = Bootstrap::$staticObjectManager->get(ErrorPageResolver::class);

        return $errorPageResolver->findErrorPageForCurrentRequestAndStatusCode($statusCode);
    }

}