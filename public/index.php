<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Ucc\Core\Auth;
use Ucc\Core\Response;
use Ucc\Controllers\ApiController;
use Ucc\Controllers\WebController;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';

if(str_starts_with($path,'/api/')){
 (new ApiController())->handle($method,$path);
}

Auth::bootSession();
(new WebController())->handle($method,$path);
