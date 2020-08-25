<?php
declare(strict_types=1);

namespace Netlogix\ErrorHandler\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Neos\Routing\FrontendNodeRoutePartHandler;

/**
 * Helper Class to parse request uri into dimension values.
 * Unfortunately the logic resides inside the FrontendNodeRoutePartHandler
 * which cannot be used as a service.
 *
 * @see \Neos\Neos\Routing\FrontendNodeRoutePartHandler
 *
 * @Flow\Scope("singleton")
 */
class DimensionResolver extends FrontendNodeRoutePartHandler
{

    /**
     * @Flow\InjectConfiguration(package="Neos.Neos", path="routing.supportEmptySegmentForDimensions")
     * @var boolean
     */
    protected $supportEmptySegmentForDimensions;

    public function determineDimensionValuesByRequestUri(Uri $uri): array
    {
        $requestPath = $uri->getPath() ?? '';

        if ($this->supportEmptySegmentForDimensions) {
            $dimensionsAndDimensionValues = $this->parseDimensionsAndNodePathFromRequestPathAllowingEmptySegment($requestPath);
        } else {
            $dimensionsAndDimensionValues = $this->parseDimensionsAndNodePathFromRequestPathAllowingNonUniqueSegment($requestPath);
        }

        return $dimensionsAndDimensionValues;
    }

}
