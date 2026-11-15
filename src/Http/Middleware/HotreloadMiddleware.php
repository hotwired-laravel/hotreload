<?php

namespace HotwiredLaravel\Hotreload\Http\Middleware;

use Closure;
use HotwiredLaravel\Hotreload\Hotreload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class HotreloadMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof Response && $response->isOk() && str_contains($response->headers->get('content-type'), 'text/html')) {
            return $this->addHotreloadScripts($request, $response);
        }

        return $response;
    }

    private function addHotreloadScripts(Request $request, Response $response)
    {
        $body = $response->getContent();

        if (File::exists($manifest = dirname(__DIR__, levels: 3) . '/dist/manifest.json')) {
            $tags = $this->daemonUrlTag($request) . $this->scriptTag(file_get_contents(trim($manifest)));

            $body = preg_replace('/(\s*)<\/head>/', "$1{$tags}\n$1</head>", $body);
        }

        return $response->setContent($body);
    }

    private function daemonUrlTag(Request $request): string
    {
        if (! ($url = Hotreload::daemonUrl($request->getHost()))) {
            // The daemon is still booting, so the browser should wait for it instead of
            // falling back to the in-process SSE route (which holds up a PHP worker)...
            return Hotreload::daemonExpected() ? <<<'HTML'
            <meta name="hotwire-hotreload:daemon" content="starting">
            HTML : '';
        }

        $url = e($url);

        return <<<HTML
        <meta name="hotwire-hotreload:url" content="{$url}">
        HTML;
    }

    private function scriptTag(string $manifestFile): string
    {
        $hash = json_decode($manifestFile, true)['/hotreload.js'] ?? null;

        return <<<HTML
        <script src="/hotwired-laravel-hotreload/hotreload.js?v={$hash}"></script>
        HTML;
    }
}
