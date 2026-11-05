<?php

require __DIR__.'/../vendor/autoload.php';

use NourApi\Framework\Application;
use NourApi\Framework\Http\Request;

$app = new Application(dirname(__DIR__));
for ($route = 0; $route < 100; $route++) {
    $app->router->get('/resources/'.$route.'/{id}', static fn ($id) => $id);
}
$iterations = 2000;
$app->handle(Request::create('/resources/99/42'));
$start = hrtime(true);
for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $response = $app->handle(Request::create('/resources/99/42'));
    if ($response->getContent() !== '42') {
        throw new RuntimeException('Unexpected routing response.');
    }
}
printf("100 dynamic routes, %d sequential requests: %.2f ms\n", $iterations, (hrtime(true) - $start) / 1e6);
