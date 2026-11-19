<?php
declare(strict_types=1);
namespace Ucc\Core;
use PDO;
final class Auth {
 public static function bootSession():void {
  if(session_status()===PHP_SESSION_ACTIVE)return;
  $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
  session_name('ucc_web_session');
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
  session_start();
 }
 public static function user():?array{return isset($_SESSION['user'])&&is_array($_SESSION['user'])?$_SESSION['user']:null;}
 public static function requireWeb():array {
  $user=self::user();
  if(!$user)Response::redirect('/login');
  return $user;
 }
 public static function login(string $email,string $password):bool {
  $q=Database::connection()->prepare('SELECT id,name,email,password_hash,role FROM users WHERE email=? AND is_active=1 LIMIT 1');
  $q->execute([strtolower(trim($email))]);$user=$q->fetch(PDO::FETCH_ASSOC);
  if(!$user||!password_verify($password,$user['password_hash']))return false;
  session_regenerate_id(true);unset($user['password_hash']);$_SESSION['user']=$user;return true;
 }
 public static function logout():void {
  $_SESSION=[];
  if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path'],'domain'=>$p['domain'],'secure'=>$p['secure'],'httponly'=>$p['httponly'],'samesite'=>'Lax']);}
  session_destroy();
 }
 public static function apiUser():?array {
  $header=$_SERVER['HTTP_AUTHORIZATION']??'';
  if(!preg_match('/^Bearer\s+(.+)$/i',trim($header),$m))return null;
  $hash=hash('sha256',trim($m[1]));
  $q=Database::connection()->prepare('SELECT u.id,u.name,u.email,u.role FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>UTC_TIMESTAMP() AND u.is_active=1 LIMIT 1');
  $q->execute([$hash]);$user=$q->fetch(PDO::FETCH_ASSOC);
  if($user)Database::connection()->prepare('UPDATE api_tokens SET last_used_at=UTC_TIMESTAMP() WHERE token_hash=?')->execute([$hash]);
  return $user?:null;
 }
 public static function requireApi():array {
  $user=self::apiUser();if(!$user)Response::json(['ok'=>false,'error'=>'Unauthenticated'],401);return $user;
 }
}