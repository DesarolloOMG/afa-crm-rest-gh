<?php

namespace App\Http\Middleware;

use Closure;

class IpFirewallMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    protected $ips = [
        "::1",
        "13.59.5.55",
        "189.163.164.250"
    ];

    public function handle($request, Closure $next)
    {
        foreach ($request->getClientIps() as $ip) {
            if (!$this->isValidIp($ip)) {
                return response()->json([
                    'code'  => 403,
                    'message' => 'No autorizado.',
                    'ip' => $ip
                ], 403);
            }
        }

        return $next($request);
    }

    protected function isValidIp($ip)
    {
        return in_array($ip, $this->ips);
    }
}
