<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use NourApi\Framework\Application;
use NourApi\Framework\Runtime\Concurrent;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

$app = new Application(dirname(__DIR__, 3));
$app->withFacades();
$app->router->get('/', static fn () => 'runtime ready');
$app->router->get('/context', static fn () => ['console' => $app->runningInConsole(), 'memory' => ini_get('memory_limit')]);
$app->router->get('/delay/{id}', static function (Request $request, string $id) {
    static $requests = 0;
    $requests++;
    usleep(400000);
    return ['id' => $id, 'requests' => $requests, 'query' => $request->query('id'), 'host' => URL::to('/')];
});
$app->router->get('/async', static fn () => Concurrent::run([
    'first' => static function () { usleep(200000); return 'first'; },
    'second' => static function () { usleep(200000); return 'second'; },
]));
$app->router->post('/echo', static function (Request $request) {
    $file = $request->file('attachments.report');
    return [
        'json' => $request->json()->all(), 'form' => $request->request->all(), 'cookies' => $request->cookies->all(),
        'uri' => $request->getRequestUri(),
        'content' => base64_encode($request->getContent()), 'proxy' => isset($_SERVER['HTTP_PROXY']),
        'file' => $file ? ['name' => $file->getClientOriginalName(), 'content' => file_get_contents($file->getPathname()), 'valid' => $file->isValid()] : null,
        'file_global' => $_FILES['attachments']['name']['report'] ?? null,
    ];
});
$app->router->get('/cookies', static function () {
    $response = new Response("binary\x00\xff");
    $response->headers->setCookie(Cookie::create('first', 'one')->withHttpOnly(true));
    $response->headers->setCookie(Cookie::create('second', 'two')->withSecure(true));
    $response->headers->set('Connection', 'X-Private-Hop');
    $response->headers->set('X-Private-Hop', 'remove');
    return $response;
});
$app->router->get('/stream', static fn () => new StreamedResponse(static function () { echo 'part one'; echo 'part two'; }));
$app->router->get('/large', static fn () => str_repeat('x', 30000));
$app->router->get('/timeout', static function () { sleep(5); return 'too late'; });
$app->router->post('/upload-timeout', static function (Request $request) {
    $request->file('attachments.report')->getContent();
    sleep(5);
    return 'too late';
});
$app->router->post('/move', static function (Request $request) {
    $target = sys_get_temp_dir().'/nour-moved-'.bin2hex(random_bytes(8));
    $file = $request->file('attachments.report')->move(dirname($target), basename($target));
    try {
        return ['content' => file_get_contents($file->getPathname())];
    } finally {
        unlink($file->getPathname());
    }
});
$app->router->get('/exit', static function () { posix_kill(getmypid(), SIGKILL); });
$app->router->get('/termination', static function () use ($app) {
    register_shutdown_function(static function () { echo 'Legacy shutdown output must not corrupt the transport'; });
    $path = sys_get_temp_dir().'/nour-termination-'.getmypid();
    $app->terminating(static function () use ($path) { file_put_contents($path, 'done'); });
    return ['marker' => $path];
});
return $app;
