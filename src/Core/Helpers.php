<?php
declare(strict_types=1);
namespace Ucc\Core;
final class Helpers {
 public static function e(mixed $value):string{return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 public static function money(float|int|string|null $amount):string{return '₹'.number_format((float)$amount,2);}
 public static function csrf():string {
  if(empty($_SESSION['_csrf']))$_SESSION['_csrf']=bin2hex(random_bytes(32));
  return (string)$_SESSION['_csrf'];
 }
 public static function verifyCsrf():void {
  $token=(string)($_POST['_csrf']??'');
  if(!hash_equals(self::csrf(),$token))Response::html('Invalid form token. Please go back and try again.',419);
 }
 public static function flash(string $type,string $message):void{$_SESSION['_flash']=['type'=>$type,'message'=>$message];}
 public static function takeFlash():?array{$f=$_SESSION['_flash']??null;unset($_SESSION['_flash']);return is_array($f)?$f:null;}
 public static function redirect(string $path):never{Response::redirect($path);}
 public static function date(?string $date):string {
  if(!$date)return '—';
  $ts=strtotime($date);return $ts?date('d M Y',$ts):'—';
 }
 public static function dateTime(?string $dateTime):string {
  if(!$dateTime)return '—';
  try{$value=new \DateTimeImmutable($dateTime,new \DateTimeZone('Asia/Kolkata'));return $value->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('d M Y, h:i A');}
  catch(\Throwable){return '—';}
 }
 public static function field(string $name,string $label,mixed $value='',string $type='text',array $attrs=[]):string {
  $attr='';foreach($attrs as $k=>$v)$attr.=' '.$k.'="'.self::e($v).'"';
  return '<label class="field"><span>'.self::e($label).'</span><input type="'.self::e($type).'" name="'.self::e($name).'" value="'.self::e($value).'"'.$attr.'></label>';
 }
 public static function select(string $name,string $label,array $options,mixed $selected=null,bool $required=false):string {
  $html='<label class="field"><span>'.self::e($label).'</span><select name="'.self::e($name).'"'.($required?' required':'').'><option value="">Select '.self::e($label).'</option>';
  foreach($options as $value=>$text)$html.='<option value="'.self::e($value).'"'.((string)$selected===(string)$value?' selected':'').'>'.self::e($text).'</option>';
  return $html.'</select></label>';
 }
}