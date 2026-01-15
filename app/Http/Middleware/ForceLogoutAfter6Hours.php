<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ForceLogoutAfter6Hours
{
    /**
     * Handle an incoming request.
     * Force logout users after exactly 12 hours from login time, regardless of activity.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only check for authenticated users
        if (Auth::check()) {
            $loginTime = $request->session()->get('login_time');
            
            // If login time is not set, set it now (for existing sessions)
            if (!$loginTime) {
                $request->session()->put('login_time', now()->timestamp);
                return $next($request);
            }
            
            // Calculate time elapsed since login
            $timeElapsed = now()->timestamp - $loginTime;
            $twelveHoursInSeconds = 12 * 60 * 60; // 12 hours = 43200 seconds
            
            // Force logout if 12 hours have passed
            if ($timeElapsed >= $twelveHoursInSeconds) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                return redirect()->route('login')
                    ->with('status', 'Your session has expired. Please login again.');
            }
        }
        
        return $next($request);
    }
}
