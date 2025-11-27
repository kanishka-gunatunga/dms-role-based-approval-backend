<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyApiCsrfToken extends Middleware
{
    public function handle($request, Closure $next)
    {
        if ($request->is('api/*')) {
            $this->tokensMatch($request) ?: abort(419, 'CSRF token mismatch.');
        }

        return $next($request);
    }
}
?>