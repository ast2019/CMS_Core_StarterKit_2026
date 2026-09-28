<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Digits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `?page=۲` means page 2.
 *
 * A number typed on a Persian keyboard, or built by a frontend that localised its digits,
 * arrives as ۰-۹. PHP's integer parsing reads those as 0, so `?page=۲` silently served
 * page 1 and `?per_page=۲۰` the default size. Only the numeric paging parameters are
 * folded — everything else in the query string is somebody's text and stays as sent.
 */
class NormaliseNumericQuery
{
    public const PARAMETERS = ['page', 'per_page'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::PARAMETERS as $parameter) {
            $value = $request->query->get($parameter);

            if (is_string($value)) {
                $request->query->set($parameter, Digits::toAscii($value));
            }
        }

        return $next($request);
    }
}
