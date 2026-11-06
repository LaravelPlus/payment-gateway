<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin area is 404 to anyone the viewPaymentAdmin gate refuses, guests
 * included, so its existence isn't disclosed. Redefine the gate to change who gets in.
 */
final class AuthorizePaymentAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::allows('viewPaymentAdmin'), 404);

        return $next($request);
    }
}
