<?php
declare(strict_types=1);
namespace Ucc\Core;
use PDO;
final class Auth {
 private static ?array $issuedToken=null;
 public static function bootSession():void {
  if(session_status()===PHP_SESSION_ACTIVE)return;
  ini_set('session.use_strict_mode','1');
  $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
  session_name('ucc_web_session');
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
  session_start();
 }
 public static function user():?array{return isset($_SESSION['user'])&&is_array($_SESSION['user'])?$_SESSION['user']:null;}
 public static function requireWeb():array{$u=self::user();if(!$u)Response::redirect('/login');return $u;}
 public static function login(string $email,string $password):bool {
  $q=Database::connection()->prepare('SELECT id,name,email,password_hash,role FROM users WHERE email=? AND is_active=1 LIMIT 1');
  $q->execute([strtolower(trim($email))]);$u=$q->fetch(PDO::FETCH_ASSOC);
  if(!$u||!password_verify($password,$u['password_hash']))return false;
  session_regenerate_id(true);unset($u['password_hash']);$_SESSION['user']=$u;return true;
 }
 public static function loginToken(string $email,string $password):bool {
  $q=Database::connection()->prepare('SELECT id,name,email,password_hash,role FROM users WHERE email=? AND is_active=1 LIMIT 1');
  $q->execute([strtolower(trim($email))]);$u=$q->fetch(PDO::FETCH_ASSOC);
  if(!$u||!password_verify($password,$u['password_hash']))return false;
  $token=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+604800);
  Database::connection()->prepare('INSERT INTO api_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)')->execute([$u['id'],hash('sha256',$token),$expires]);
  unset($u['password_hash']);self::$issuedToken=['token'=>$token,'token_type'=>'Bearer','expires_at'=>$expires,'user'=>$u];return true;
 }
 public static function lastIssuedToken():?array{return self::$issuedToken;}
 public static function logout():void {
  $_SESSION=[];
  if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path'],'domain'=>$p['domain'],'secure'=>$p['secure'],'httponly'=>$p['httponly'],'samesite'=>'Lax']);}
  session_destroy();
 }
 public static function apiUser():?array {
  $token=Request::bearer();if(!$token)return null;
  $hash=hash('sha256',$token);
  $q=Database::connection()->prepare('SELECT u.id,u.name,u.email,u.role FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>UTC_TIMESTAMP() AND u.is_active=1 LIMIT 1');
  $q->execute([$hash]);$u=$q->fetch(PDO::FETCH_ASSOC);
  if($u)Database::connection()->prepare('UPDATE api_tokens SET last_used_at=UTC_TIMESTAMP() WHERE token_hash=?')->execute([$hash]);
  return $u?:null;
 }
 public static function requireApi():array{$u=self::apiUser();if(!$u)Response::json(['ok'=>false,'error'=>'Unauthenticated'],401);return $u;}
}