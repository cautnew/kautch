<?php

namespace App\Enums;

/**
 * The result of walking a chain of redirect routes — see RouteLoopValidator.
 */
enum RouteChainOutcome
{
    /** The chain terminated at a non-redirect (page/asset) route. */
    case Resolved;

    /** The chain terminated at a redirect pointing to an external URL. */
    case ExternalRedirect;

    /** The chain would loop back on a route it already visited. */
    case Loop;

    /** The chain is longer than RouteLoopValidator::MAX_CHAIN_LENGTH. */
    case TooManyRedirects;

    /** A route in the chain points at a redirect target that no longer exists. */
    case Broken;
}
