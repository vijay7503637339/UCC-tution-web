<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use Ucc\Core\Router;
use Ucc\Core\Response;
$router = new Router();
$router->get('/health', static fn() => ['service'=>'ucc-tuition-web','status'=>'ok','version'=>'0.1.0']);
$router->get('/', static function() { Response::html('<h1>UCC Tuition Manager Web</h1><p>Web panel scaffold is being built.</p><p><a href="/health">API health</a></p>'); });
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
