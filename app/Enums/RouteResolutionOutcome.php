<?php

namespace App\Enums;

/**
 * The result of resolving a path via RouteResolverService.
 */
enum RouteResolutionOutcome: string
{
    case Page = 'page';
    case Asset = 'asset';
    case Redirect = 'redirect';
    case ExternalRedirect = 'external_redirect';
    case NotFound = 'not_found';
    case LoopDetected = 'loop_detected';
    case TooManyRedirects = 'too_many_redirects';
}
