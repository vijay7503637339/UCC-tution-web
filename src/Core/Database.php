<?php
declare(strict_types=1);
namespace Ucc\Core;
use PDO;
use RuntimeException;
final class Database {
 private static ?PDO $pdo = null;
 public static function boot(): void {
  if (self::$pdo !== null) return;
  $host=$_ENV['DB_HOST']??''; $name=$_ENV['DB_NAME']??''; $user=$_ENV['DB_USER']??'';
  if ($host==='' || $name==='' || $user==='') {
   if (($_ENV['APP_ENV']??'local')==='production') throw new RuntimeException('Database configuration is incomplete');
   return;
  }
  $dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$host,$_ENV['DB_PORT']??'3306',$name);
  self::$pdo=new PDO($dsn,$user,$_ENV['DB_PASS']??'',[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
   PDO::ATTR_EMULATE_PREPARES=>false
  ]);
 }
 public static function connection(): PDO {
  if (self::$pdo===null) self::boot();
  if (self::$pdo===null) throw new RuntimeException('Database is not configured');
  return self::$pdo;
 }
}
