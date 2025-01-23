<?php

use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('assetpipeline/{file}', function ($file) {
    return Response::file(dirname(__DIR__) . "/resources/assets/{$file}", [
        'Content-Type' => match ((string) str($file)->afterLast('.')) {
            'css' => 'text/css',
            'js' => 'text/javascript',
        },
    ]);
})->where('file', '.*');

Route::get('/', function () {
    return view('hello');
});

Route::redirect('redirect', '/');
