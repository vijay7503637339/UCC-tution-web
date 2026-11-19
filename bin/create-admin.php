<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit("Not found\n");}
require dirname(__DIR__).'/src/bootstrap.php';
use Ucc\Core\Database;

$options=getopt('', ['name:', 'email:']);
$name=trim((string)($options['name']??''));
$email=strtolower(trim((string)($options['email']??'')));
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){
 fwrite(STDERR,"Usage: php bin/create-admin.php --name=\"Admin Name\" --email=\"admin@example.com\"\n");
 exit(2);
}
fwrite(STDOUT,"New admin password: ");
if(function_exists('shell_exec'))@shell_exec('stty -echo');
$password=trim((string)fgets(STDIN));
if(function_exists('shell_exec'))@shell_exec('stty echo');
fwrite(STDOUT,"\n");
if(strlen($password)<12){fwrite(STDERR,"Use a password of at least 12 characters.\n");exit(2);}
$db=Database::connection();
$q=$db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$q->execute([$email]);
$hash=password_hash($password,PASSWORD_DEFAULT);
if($q->fetchColumn()){
 $db->prepare('UPDATE users SET name=?,password_hash=?,role="admin",is_active=1 WHERE email=?')->execute([$name,$hash,$email]);
 fwrite(STDOUT,"Admin account updated.\n");
}else{
 $db->prepare('INSERT INTO users(name,email,password_hash,role,is_active) VALUES(?,?,?,"admin",1)')->execute([$name,$email,$hash]);
 fwrite(STDOUT,"Admin account created.\n");
}
fwrite(STDOUT,"Sign in at /login. Keep this password private.\n");
