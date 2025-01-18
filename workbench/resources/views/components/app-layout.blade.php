<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="hotwire-hotreload:logging" content="true">
        {{ $meta ?? '' }}
        <title>Hotreload App</title>
        @foreach (File::files(Orchestra\Testbench\workbench_path('resources', 'assets', 'css')) as $file)
        <link href="{{ asset('/assetpipeline/css/' . str($file->getPathname())->afterLast('css' . DIRECTORY_SEPARATOR) . '?v=' . $file->getMtime()) }}" rel="stylesheet">
        @endforeach
        <script type="importmap">
        {
            "imports": {
                @foreach (File::files(Orchestra\Testbench\workbench_path('resources', 'assets', 'js', 'controllers')) as $file)
                "{{ str($file->getPathname())->afterLast('js' . DIRECTORY_SEPARATOR)->beforeLast('.js') }}": "{{ asset('/assetpipeline/js/' . str($file->getPathname())->afterLast('js' . DIRECTORY_SEPARATOR) . '?v=' . $file->getMtime()) }}",
                @endforeach
                "app.js": "{{ asset('/assetpipeline/js/app.js') }}",
                "@hotwired/stimulus-loading": "{{ asset('/assetpipeline/js/lib/stimulus-loading.js') }}",
                "@hotwired/stimulus": "https://cdn.skypack.dev/@hotwired/stimulus",
                "@hotwired/turbo": "https://cdn.jsdelivr.net/npm/@hotwired/turbo@8.0.12/+esm"
            }
        }
        </script>
        <script type="module">
            import "app.js";
        </script>
    </head>
    <body>
        {{ $slot }}
    </body>
</html>
