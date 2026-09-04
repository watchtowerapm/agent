<?php

namespace Watchtower\LaravelAgent\Factories;

use React\Http\Browser as ReactBrowser;
use React\Socket\Connector;
use Watchtower\LaravelAgent\Browser as WatchtowerBrowser;
use Watchtower\LaravelAgent\Contracts\Browser as BrowserContract;

class BrowserFactory
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __invoke(
        float $connectionTimeout,
        float $timeout,
        array $headers = [],
        ?string $baseUrl = null,
    ): BrowserContract {
        $connector = new Connector(['timeout' => $connectionTimeout]);

        $browser = (new ReactBrowser($connector))
            ->withTimeout($timeout)
            ->withBase($baseUrl)
            ->withoutHeader('User-Agent');

        foreach ($headers as $key => $value) {
            $browser = $browser->withHeader($key, $value);
        }

        return new WatchtowerBrowser($browser);
    }
}
