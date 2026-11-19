<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * PoC ONLY: the React app sends the id of the demo user it is acting as in
 * the X-Demo-User header, so one browser can switch between buyer, HR,
 * trainer and admin. Replace with real auth (SkillMatch login / Sanctum)
 * before this goes anywhere near production.
 */
class DemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app()->isProduction(), 403, 'Demo user switching is disabled in production.');

        $user = User::find($request->header('X-Demo-User'));
        abort_unless($user, 401, 'Pick a demo user first.');

        Auth::setUser($user);

        return $next($request);
    }
}
