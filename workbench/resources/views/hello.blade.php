<x-app-layout>
    <x-slot name="meta">
        @if (request("reload_method") === 'morph')
        <meta name="hotwire-hotreload:html-reload-method" content="morph">
        @endif
    </x-slot>

    <div data-controller="dummy REPLACE_CONTROLLER">
        <h1>Hotwired Laravel Hotreload App</h1>
        <p>REPLACE_HTML</p>
        <x-test />

        <div id="replace">REPLACE_PLACEHOLDER</div>
        <div id="other_replace">REPLACE_PLACEHOLDER</div>
    </div>
</x-app-layout>
