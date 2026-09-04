<?php

namespace Watchtower\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\Facades\Watchtower;
use Watchtower\Laravel\State\RequestState;

final class Sample
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(private Core $watchtower)
    {
        //
    }

    public static function rate(float $rate): string
    {
        $rate = (string) $rate;

        if ($rate === '0') {
            $rate = '0.0';
        }

        return self::class.':'.$rate;
    }

    public static function always(): string
    {
        return self::class.':1.0';
    }

    public static function never(): string
    {
        return self::class.':0.0';
    }

    public function handle(Request $request, Closure $next, float $rate): mixed
    {
        try {
            $this->watchtower->sample($rate);
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);
        }

        return $next($request);
    }
}
