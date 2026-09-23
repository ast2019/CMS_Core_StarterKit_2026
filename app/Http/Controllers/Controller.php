<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
     * Laravel 11+ ships a bare base controller, so $this->authorize() is not
     * available unless this trait is added back.
     *
     * The Management API relies on it to reuse the SAME policies the Filament panel
     * uses (Requirement 9.1). A separate set of API-only permission checks would be
     * a second place for the ability matrix to drift, and the weaker of the two
     * would define the real security posture.
     */
    use AuthorizesRequests;
}
