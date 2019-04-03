<?php

namespace Noxlogic\RateLimitBundle\Service;

use Noxlogic\RateLimitBundle\Annotation\RateLimit;
use Noxlogic\RateLimitBundle\Util\PathLimitProcessor;
use Symfony\Component\HttpFoundation\Request;

class RateLimitValidator
{
    /**
     * @var RateLimitService
     */
    private $rateLimitService;

    /**
     * @var PathLimitProcessor
     */
    private $pathLimitProcessor;

    public function __construct(RateLimitService $rateLimitService, PathLimitProcessor $pathLimitProcessor)
    {
        $this->rateLimitService = $rateLimitService;
        $this->pathLimitProcessor = $pathLimitProcessor;
    }

    /**
     * @param Request $request
     * @param RateLimit[] $annotations
     * @return mixed|RateLimit|null
     */
    public function findBestMethodMatch(Request $request, array $annotations)
    {
        // Empty array, check the path limits
        if (count($annotations) == 0) {
            return $this->pathLimitProcessor->getRateLimit($request);
        }

        $best_match = null;
        foreach ($annotations as $annotation) {
            // cast methods to array, even method holds a string
            $methods = is_array($annotation->getMethods()) ? $annotation->getMethods() : array($annotation->getMethods());

            if (in_array($request->getMethod(), $methods)) {
                $best_match = $annotation;
            }

            // Only match "default" annotation when we don't have a best match
            if (count($annotation->getMethods()) == 0 && $best_match == null) {
                $best_match = $annotation;
            }
        }

        return $best_match;
    }

    public function getLimitInfo() {
        // Ratelimit the call
        $rateLimitInfo = $this->rateLimitService->limitRate($key);
        if (! $rateLimitInfo) {
            // Create new rate limit entry for this call
            $rateLimitInfo = $this->rateLimitService->createRate($key, $rateLimit->getLimit(), $rateLimit->getPeriod());
            if (! $rateLimitInfo) {
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }
    }
}