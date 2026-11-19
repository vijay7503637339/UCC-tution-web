<?php
declare(strict_types=1);
namespace Ucc\Core;
final class Router {
 private array $routes=[];
 public function get(string $path,callable $handler):void{$this->add('GET',$path,$handler);}
 public function post(string $path,callable $handler):void{$this->add('POST',$path,$handler);}
 public function put(string $path,callable $handler):void{$this->add('PUT',$path,$handler);}
 public function delete(string $path,callable $handler):void{$this->add('DELETE',$path,$handler);}
 private function add(string $method,string $path,callable $handler):void{$this->routes[]=[$method,$path,$handler];}
 public function dispatch(string $method,string $uri):never {
  foreach($this->routes as [$verb,$path,$handler]){
   $names=[];
   $pattern=preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',static function($m)use(&$names){$names[]=$m[1];return '([^/]+)';},$path);
   if($verb!==$method||!preg_match('#^'.$pattern.'$#',$uri,$matches))continue;
   array_shift($matches);$params=[];
   foreach($names as $i=>$name)$params[$name]=rawurldecode($matches[$i]);
   $result=$handler(...array_values($params));
   if(is_array($result))Response::json(['ok'=>true,'data'=>$result]);
   Response::json(['ok'=>true,'data'=>$result]);
  }
  Response::json(['ok'=>false,'error'=>'Route not found'],404);
 }
}