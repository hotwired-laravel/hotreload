<?php

use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('assetpipeline/{file}', function ($file) {
    return Response::file(dirname(__DIR__) . "/resources/assets/{$file}");
})->where('file', '.*');

Route::get('/', function () {
    return view('hello');
});
