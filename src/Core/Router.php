<?php
declare(strict_types=1);
namespace Ucc\Core;
final class Router {
 private array $routes=[];
 public function get(string $path,callable $handler): void { $this->routes[]=['GET',$path,$handler]; }
 public function dispatch(string $method,string $uri): never {
  foreach($this->routes as [$verb,$path,$handler]) { if($verb===$method && $path===$uri) { $result=$handler(); if(is_array($result)) Response::json(['ok'=>true,'data'=>$result]); Response::json(['ok'=>true]); } }
  Response::json(['ok'=>false,'error'=>'Route not found'],404);
 }
}
