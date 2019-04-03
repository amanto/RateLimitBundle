<?php

namespace Noxlogic\RateLimitBundle\EventListener;

use Noxlogic\RateLimitBundle\Annotation\RateLimit;
use Noxlogic\RateLimitBundle\Events\CheckedRateLimitEvent;
use Noxlogic\RateLimitBundle\Events\GenerateKeyEvent;
use Noxlogic\RateLimitBundle\Events\RateLimitEvents;
use Noxlogic\RateLimitBundle\Exception\RateLimitExceptionInterface;
use Noxlogic\RateLimitBundle\Service\RateLimitService;
use Noxlogic\RateLimitBundle\Service\RateLimitValidator;
use Noxlogic\RateLimitBundle\Util\PathLimitProcessor;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class RateLimitAnnotationListener extends BaseListener
{

    /**
     * @var eventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var \Noxlogic\RateLimitBundle\Service\RateLimitService
     */
    protected $rateLimitService;

    /**
     * @var \Noxlogic\RateLimitBundle\Util\PathLimitProcessor
     */
    protected $pathLimitProcessor;

    /**
     * @var RateLimitValidator
     */
    protected $rateLimitValidator;

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @param RateLimitService $rateLimitService
     * @param PathLimitProcessor $pathLimitProcessor
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        RateLimitService $rateLimitService,
        PathLimitProcessor $pathLimitProcessor
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->rateLimitService = $rateLimitService;
        $this->pathLimitProcessor = $pathLimitProcessor;
        $this->rateLimitValidator = new RateLimitValidator($rateLimitService, $pathLimitProcessor);
    }

    /**
     * @param FilterControllerEvent $event
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        // Skip if the bundle isn't enabled (for instance in test environment)
        if( ! $this->getParameter('enabled', true)) {
            return;
        }

        // Skip if we aren't the main request
        if ($event->getRequestType() != HttpKernelInterface::MASTER_REQUEST) {
            return;
        }

        // Find the best match
        $annotations = $event->getRequest()->attributes->get('_x-rate-limit', array());
        $rateLimit = $this->rateLimitValidator->findBestMethodMatch($event->getRequest(), $annotations);

        // Another treatment before applying RateLimit ?
        $checkedRateLimitEvent = new CheckedRateLimitEvent($event->getRequest(), $rateLimit);
        $this->eventDispatcher->dispatch(RateLimitEvents::CHECKED_RATE_LIMIT, $checkedRateLimitEvent);
        $rateLimit = $checkedRateLimitEvent->getRateLimit();

        // No matching annotation found
        if (! $rateLimit) {
            return;
        }

        $key = $this->getKey($event, $rateLimit, $annotations);

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


        // Store the current rating info in the request attributes
        $request = $event->getRequest();
        $request->attributes->set('rate_limit_info', $rateLimitInfo);

        // Reset the rate limits
        if(time() >= $rateLimitInfo->getResetTimestamp()) {
            $this->rateLimitService->resetRate($key);
            $rateLimitInfo = $this->rateLimitService->createRate($key, $rateLimit->getLimit(), $rateLimit->getPeriod());
            if (! $rateLimitInfo) {
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }

        // When we exceeded our limit, return a custom error response
        if ($rateLimitInfo->getCalls() > $rateLimitInfo->getLimit()) {

            // Throw an exception if configured.
            if ($this->getParameter('rate_response_exception')) {
                $class = $this->getParameter('rate_response_exception');

                $e = new $class($this->getParameter('rate_response_message'), $this->getParameter('rate_response_code'));

                if ($e instanceof RateLimitExceptionInterface) {
                    $e->setPayload($rateLimit->getPayload());
                }

                throw $e;
            }

            $message = $this->getParameter('rate_response_message');
            $code = $this->getParameter('rate_response_code');
            $event->setController(function () use ($message, $code) {
                // @codeCoverageIgnoreStart
                return new Response($message, $code);
                // @codeCoverageIgnoreEnd
            });
            $event->stopPropagation();
        }

    }

    private function getKey(FilterControllerEvent $event, RateLimit $rateLimit, array $annotations)
    {
        // Let listeners manipulate the key
        $keyEvent = new GenerateKeyEvent($event->getRequest(), '', $rateLimit->getPayload());

        $rateLimitMethods = join('.', $rateLimit->getMethods());
        $keyEvent->addToKey($rateLimitMethods);

        $rateLimitAlias = count($annotations) === 0
            ? str_replace('/', '.', $this->pathLimitProcessor->getMatchedPath($event->getRequest()))
            : $this->getAliasForRequest($event);
        $keyEvent->addToKey($rateLimitAlias);

        $this->eventDispatcher->dispatch(RateLimitEvents::GENERATE_KEY, $keyEvent);

        return $keyEvent->getKey();
    }

    private function getAliasForRequest(FilterControllerEvent $event)
    {
        if (($route = $event->getRequest()->attributes->get('_route'))) {
            return $route;
        }

        $controller = $event->getController();

        if (is_string($controller) && false !== strpos($controller, '::')) {
            $controller = explode('::', $controller);
        }

        if (is_array($controller)) {
            return str_replace('\\', '.', is_string($controller[0]) ? $controller[0] : get_class($controller[0])) . '.' . $controller[1];
        }

        if ($controller instanceof \Closure) {
            return 'closure';
        }

        if (is_object($controller)) {
            return str_replace('\\', '.', get_class($controller[0]));
        }

        return 'other';
    }
}
