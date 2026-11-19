<?php
declare(strict_types=1);
namespace Ucc\Core;
final class Request {
 public static function json():array {
  $raw=file_get_contents('php://input')?:'';
  if($raw==='')return [];
  $data=json_decode($raw,true);
  if(!is_array($data))Response::json(['ok'=>false,'error'=>'Invalid JSON body'],400);
  return $data;
 }
 public static function bearer():?string {
  $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
  if($header===''&&function_exists('getallheaders')){
   foreach(getallheaders() as $name=>$value)if(strtolower((string)$name)==='authorization'){$header=(string)$value;break;}
  }
  return preg_match('/^Bearer\s+(.+)$/i',trim($header),$m)?trim($m[1]):null;
 }
 public static function page(string $key,int $default,int $min,int $max):int {
  $v=filter_var($_GET[$key]??$default,FILTER_VALIDATE_INT);
  return $v===false?$default:max($min,min($max,$v));
 }
}