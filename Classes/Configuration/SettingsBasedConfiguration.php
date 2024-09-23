<?php

declare(strict_types=1);

namespace Netlogix\ErrorHandler\Configuration;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 * @phpstan-import-type SiteConfiguration from ErrorHandlerConfiguration
 */
class SettingsBasedConfiguration
{
    /**
     * @Flow\InjectConfiguration(path="pages")
     * @var array<string, SiteConfiguration[]>
     */
    protected $pages;

    /**
     * @return array<string, SiteConfiguration[]>
     */
    public function getConfiguration(): array
    {
        // @phpstan-ignore-next-line
        return $this->pages ?? [];
    }
}
