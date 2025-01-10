<?php

namespace HotwiredLaravel\Hotreload\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class HotreloadMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof Response && $response->isOk() && str_contains($response->headers->get('content-type'), 'text/html')) {
            return $this->addHotreloadScripts($response);
        }

        return $response;
    }

    private function addHotreloadScripts(Response $response)
    {
        $body = $response->getContent();

        if (File::exists($manifest = dirname(__DIR__, levels: 3) . '/dist/manifest.json')) {
            $body = preg_replace('/(\s*)<\/head>/', "$1{$this->scriptTag($manifest)}\n$1</head>", $body);
        }

        return $response->setContent($body);
    }

    private function scriptTag(string $manifestFile): string
    {
        $hash = json_decode($manifestFile, true)['/hotreload.js'] ?? null;

        return <<<HTML
        <script src="/hotwired-laravel-hotreload/hotreload.js?v={$hash}"></script>
        HTML;
    }
}
