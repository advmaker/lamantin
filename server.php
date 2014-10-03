<?php
require 'vendor/autoload.php';

function processing(\Intervention\Image\Image $img) {
}

$filename = __DIR__.'/storage/lamantin.png';
$imageManager = new \Intervention\Image\ImageManager();
$img = $imageManager->make($filename);
$getImageProcess = new React\ChildProcess\Process(
    'avconv -i rtmp://live.cctv.teorema.info/hvga/benua-lamantin -frames 1 -f image2 -c:v png '.$filename.' 2>&1'
);
$getImageProcess->on('exit', function($exitCode, $termSignal) use($imageManager, &$img, $filename) {
        echo "Get Image done \n";
        $img->destroy();
        $img = $imageManager->make($filename);
    });

$appLoop = React\EventLoop\Factory::create();
$appLoop->addPeriodicTimer(5, function () {
        $memory = memory_get_usage() / 1024;
        $formatted = number_format($memory, 3).'K';
        echo "Current memory usage: {$formatted}\n";
    });

$appLoop->addPeriodicTimer(5, function ($timer) use ($getImageProcess) {
        if (!$getImageProcess->isRunning()) {
            echo "Get Image started \n";
            $getImageProcess->start($timer->getLoop());
        }
    });

$socket = new React\Socket\Server($appLoop);
$http = new React\Http\Server($socket, $appLoop);
$router = new FastRoute\RouteCollector(new FastRoute\RouteParser\Std(), new FastRoute\DataGenerator\GroupCountBased());
$router->addRoute('get', '/img', function(\React\Http\Request $request, \React\Http\Response $response)use(&$img){
        $response->writeHead(200, ['Content-Type', 'image/png']);
        $response->end($img->encode('png'));
    });
$router->addRoute('get', '/colors', function(\React\Http\Request $request, \React\Http\Response $response)use(&$img){
        $colorMap = [];
        for ($i = $img->getHeight(); $i >= 0; $i--) {
            for ($j = $img->getWidth(); $j >= 0; $j--) {
                $hexcolor = $img->pickColor($j, $i, 'hex');
                if (!isset($colorMap[$hexcolor])) {
                    $colorMap[$hexcolor] = 0;
                }
                $colorMap[$hexcolor]++;
            }
        }
        ksort($colorMap);
        $response->writeHead(200, ['Content-Type', 'text/html']);
        //$response->end('<pre>'.print_r([$img->getHeight(), $img->getWidth()], true).'</pre>');
        $response->end('<pre>'.print_r($colorMap, true).'</pre>');
    });
$dispatcher = new FastRoute\Dispatcher\GroupCountBased($router->getData());

$http->on('request', function (\React\Http\Request $request, \React\Http\Response $response) use (&$dispatcher) {
        $routeInfo = $dispatcher->dispatch(strtolower($request->getMethod()), $request->getPath());
        switch ($routeInfo[0]) {
            case FastRoute\Dispatcher::NOT_FOUND:
                $response->writeHead(404, []);
                break;
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $response->writeHead(405, []);
                break;
            case FastRoute\Dispatcher::FOUND:
                call_user_func_array($routeInfo[1], ['request' => $request, 'response' => $response ]+$routeInfo[2]);
                break;
        }
        $response->end();
    });

echo "Server running at http://127.0.0.1:1337\n";
$socket->listen(1337);
$appLoop->run();