<?php
declare(strict_types=1);
namespace Ucc\Controllers;

use Ucc\Core\Auth;
use Ucc\Core\Database;
use Ucc\Core\Request;
use Ucc\Core\Response;
use Ucc\Services\TuitionService;
use Ucc\Services\AnalyticsService;

final class ApiController {
 public function handle(string $method,string $path):never {
  if($method==='GET'&&$path==='/api/v1/health')Response::json(['ok'=>true,'service'=>'ucc-tuition-web','database'=>$this->databaseStatus()]);
  if($method==='POST'&&$path==='/api/v1/auth/login')$this->login();
  if($method==='GET'&&$path==='/api/v1/auth/me')Response::json(['ok'=>true,'data'=>Auth::requireApi()]);
  if($method==='POST'&&$path==='/api/v1/auth/logout')$this->logout();
  if($method==='GET'&&$path==='/api/v1/dashboard'){Auth::requireApi();$month=(string)($_GET['month']??date('Y-m'));Response::json(['ok'=>true,'data'=>TuitionService::dashboard($month)]);}
  if($method==='GET'&&$path==='/api/v1/analytics'){Auth::requireApi();$month=(string)($_GET['month']??date('Y-m'));Response::json(['ok'=>true,'data'=>AnalyticsService::overview($month)]);}
  if($method==='GET'&&$path==='/api/v1/reminders'){Auth::requireApi();$month=(string)($_GET['month']??date('Y-m'));Response::json(['ok'=>true,'data'=>TuitionService::reminders($month)]);}
  if($method==='GET'&&$path==='/api/v1/reports/fees'){Auth::requireApi();$month=(string)($_GET['month']??date('Y-m'));Response::json(['ok'=>true,'data'=>TuitionService::dashboard($month)]);}
  if($method==='GET'&&$path==='/api/v1/attendance/students'){Auth::requireApi();$batch=(int)($_GET['batch_id']??0);Response::json(['ok'=>true,'data'=>TuitionService::attendanceStudents($batch)]);}
  if(preg_match('#^/api/v1/attendance/([^/]+)$#',$path,$m)&&$method==='GET'){Auth::requireApi();$data=TuitionService::find('attendance',rawurldecode($m[1]));if(!$data)Response::json(['ok'=>false,'error'=>'Session not found'],404);Response::json(['ok'=>true,'data'=>$data]);}
  if($path==='/api/v1/attendance'&&$method==='POST'){Auth::requireApi();$data=Request::json();$id=TuitionService::save('attendance',$data);Response::json(['ok'=>true,'data'=>['id'=>$id]],201);}
  if(preg_match('#^/api/v1/([a-z-]+)(?:/([^/]+))?$#',$path,$m)){
   $key=$m[1];$id=$m[2]??null;
   $aliases=['fees'=>'fees','action-logs'=>'action-logs'];
   $resources=array_keys(TuitionService::map());$resources[]='attendance';
   if(!in_array($key,$resources,true))Response::json(['ok'=>false,'error'=>'Unknown endpoint'],404);
   Auth::requireApi();
   if($method==='GET'&&$id===null)Response::json(['ok'=>true,'data'=>['items'=>TuitionService::all($key,$_GET)]]);
   if($method==='GET'&&$id!==null){$record=TuitionService::find($key,$id);if(!$record)Response::json(['ok'=>false,'error'=>'Record not found'],404);Response::json(['ok'=>true,'data'=>$record]);}
   if($method==='POST'&&$id===null){$newId=TuitionService::save($key,Request::json());Response::json(['ok'=>true,'data'=>['id'=>$newId]],201);}
   if($method==='PUT'&&$id!==null){$newId=TuitionService::save($key,Request::json(),$id);Response::json(['ok'=>true,'data'=>['id'=>$newId]]);}
   if($method==='DELETE'&&$id!==null){TuitionService::delete($key,$id);Response::json(['ok'=>true,'data'=>['deleted'=>true]]);}
  }
  Response::json(['ok'=>false,'error'=>'API route not found'],404);
 }
 private function login():never {
  $body=Request::json();$email=(string)($body['email']??'');$password=(string)($body['password']??'');
  if(!Auth::loginToken($email,$password))Response::json(['ok'=>false,'error'=>'Invalid email or password'],401);
  Response::json(['ok'=>true,'data'=>Auth::lastIssuedToken()]);
 }
 private function logout():never {
  $token=Request::bearer();
  if($token)Database::connection()->prepare('DELETE FROM api_tokens WHERE token_hash=?')->execute([hash('sha256',$token)]);
  Response::json(['ok'=>true,'data'=>['logged_out'=>true]]);
 }
 private function databaseStatus():string {
  try{Database::connection()->query('SELECT 1');return 'connected';}catch(\Throwable){return 'not_configured';}
 }
}
