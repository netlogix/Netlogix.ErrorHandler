<?php
namespace Netlogix\ErrorHandler\Service;

/*
 * This file is part of the Netlogix.ErrorHandler package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\Response;
use Neos\Flow\Http\Uri;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Controller\Frontend\NodeController;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Utility\ObjectAccess;

/**
 * @Flow\Scope("prototype")
 * @internal
 */
class ControllerContextFactory
{

    /**
     * @Flow\Inject(lazy=false)
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @param string $requestUri
     * @return ControllerContext
     * @throws \Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \Neos\Flow\Mvc\Exception\InvalidActionNameException
     * @throws \Neos\Flow\ObjectManagement\Exception\UnknownObjectException
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     */
    public function buildControllerContext(string $requestUri)
    {
        $request = $this->getRequest($requestUri);
        ObjectAccess::setProperty($this->securityContext, 'initialized', true, true);
        $this->securityContext->setRequest($request);
        $uriBuilder = $this->getUriBuilder($request);

        return new ControllerContext(
            $request,
            new Response(),
            new Arguments(array()),
            $uriBuilder
        );
    }

    /**
     * @param string $requestUri
     * @return ActionRequest
     * @throws \Neos\Flow\Mvc\Exception\InvalidActionNameException
     * @throws \Neos\Flow\ObjectManagement\Exception\UnknownObjectException
     */
    protected function getRequest(string $requestUri)
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';
        $httpRequest = Request::create(new Uri($requestUri));
        $request = new ActionRequest($httpRequest);
        $request->setControllerObjectName(NodeController::class);
        $request->setControllerActionName('show');
        $request->setFormat('html');

        return $request;
    }

    /**
     * @param ActionRequest $request
     * @return UriBuilder
     * @throws \Neos\Utility\Exception\PropertyNotAccessibleException
     * @throws \Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function getUriBuilder($request)
    {
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);
        if (FLOW_SAPITYPE === 'CLI') {
            $routesConfiguration = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_ROUTES);
            $router = ObjectAccess::getProperty($uriBuilder, 'router', true);
            $router->setRoutesConfiguration($routesConfiguration);
        }

        return $uriBuilder;
    }

}
