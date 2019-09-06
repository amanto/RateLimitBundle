<?php

namespace Noxlogic\RateLimitBundle;

use Noxlogic\RateLimitBundle\Annotation\RateLimit;
use Symfony\Component\HttpFoundation\Request;

interface LimitProcessorInterface
{
    /**
     * @param Request $request
     * @return RateLimit|null
     */
    public function getRateLimit(Request $request);

    /**
     * @param Request $request
     * @param callable $controller
     * @return string
     */
    public function getRateLimitAlias(Request $request, callable $controller);
}
