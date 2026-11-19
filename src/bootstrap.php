<?php
declare(strict_types=1);
use Ucc\Core\Database;
use Ucc\Core\Response;
define('UCC_ROOT', dirname(__DIR__));
spl_autoload_register(static function(string $class): void {
 $prefix='Ucc\\'; if(!str_starts_with($class,$prefix)) return;
 $file=UCC_ROOT.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
 if(is_file($file)) require $file;
});
if(is_file(UCC_ROOT.'/.env')) {
 foreach(file(UCC_ROOT.'/.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
  $line=trim($line); if($line===''||$line[0]==='#'||!str_contains($line,'=')) continue;
  [$key,$value]=explode('=',$line,2); $_ENV[trim($key)]=trim(trim($value),'\\"');
 }
}
$appUrlPath=(string)(parse_url((string)($_ENV['APP_URL']??''),PHP_URL_PATH)?:'');
$basePath=(string)($_ENV['APP_BASE_PATH']??$appUrlPath);
$basePath='/'.trim($basePath,'/');
if($basePath==='/')$basePath='';
define('UCC_BASE_PATH',$basePath);
date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors',($_ENV['APP_DEBUG']??'false')==='true'?'1':'0');
set_exception_handler(static function(Throwable $e): void { error_log((string)$e); Response::json(['ok'=>false,'error'=>'Internal server error'],500); });
Database::boot();
