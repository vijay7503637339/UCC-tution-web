<?php
declare(strict_types=1);
namespace Ucc\Core;
final class Response {
 public static function json(array $payload,int $status=200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
 }
 public static function html(string $html,int $status=200): never {
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  echo $html;
  exit;
 }
 public static function redirect(string $path): never {
  header('Location: '.$path, true, 303);
  exit;
 }
}