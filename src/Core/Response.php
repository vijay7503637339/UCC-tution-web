<?php
declare(strict_types=1);
namespace Ucc\Core;
final class Response {
 private static function withBasePath(string $path):string {
  $base=defined('UCC_BASE_PATH')?UCC_BASE_PATH:'';
  if($base==='' || $path==='' || $path[0]!=='/' || str_starts_with($path,'//'))return $path;
  if($path===$base || str_starts_with($path,$base.'/'))return $path;
  return $base.$path;
 }
 public static function json(array $payload,int $status=200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
 }
 public static function html(string $html,int $status=200): never {
  $base=defined('UCC_BASE_PATH')?UCC_BASE_PATH:'';
  if($base!==''){
   // Prefix root-relative HTML attributes and root-relative JS string URLs.
   $html=preg_replace_callback('/(["\\'])\\/(?!\\/)/',static function(array $m)use($base):string{return $m[1].$base.'/';},$html)??$html;
  }
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  echo $html;
  exit;
 }
 public static function redirect(string $path): never {
  header('Location: '.self::withBasePath($path), true, 303);
  exit;
 }
}