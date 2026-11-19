<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit("Not found\n");}
require dirname(__DIR__).'/src/bootstrap.php';
use Ucc\Core\Auth;
use Ucc\Core\Database;
use Ucc\Services\AnalyticsService;
use Ucc\Services\TuitionService;

$db=Database::connection();
$suffix=(string)random_int(100000,999999);
$teacherId=(int)('91'.$suffix);
$batchId=(int)('92'.$suffix);
$studentId=(int)('93'.$suffix);
$childId=(int)('94'.$suffix);
$feeId=(int)('95'.$suffix);
$enquiryId=(int)('96'.$suffix);
$expenseId=(int)('97'.$suffix);
$logId=(int)('98'.$suffix);
$email='ci-'.$suffix.'@example.test';
try {
 TuitionService::save('teachers',['name'=>'CI Teacher','subject'=>'Maths','phone'=>'9999999999'],$teacherId);
 TuitionService::save('batches',['name'=>'CI Batch','subject'=>'Maths','schedule'=>'Mon-Fri','teacher_id'=>$teacherId],$batchId);
 TuitionService::save('students',[
  'name'=>'CI Student','class_name'=>'Class 8','guardian_phone'=>'9999999999',
  'batch_id'=>$batchId,'teacher_id'=>$teacherId,'monthly_fee'=>1250,'due_day'=>1,
  'joined_on'=>date('Y-m-d'),'is_fees_advance'=>1,'status'=>'active',
  'children'=>[['id'=>$childId,'name'=>'CI Child','class_name'=>'Class 8','batch_id'=>$batchId,'is_active'=>1]]
 ],$studentId);
 TuitionService::save('fees',['student_id'=>$studentId,'amount'=>500,'fee_month'=>date('Y-m-01'),'paid_on'=>date('Y-m-d H:i:s'),'method'=>'UPI','note'=>'CI payment'],$feeId);
 TuitionService::save('attendance',['batch_id'=>$batchId,'session_date'=>date('Y-m-d'),'records'=>[$childId=>'present']]);
 TuitionService::save('enquiries',['student_name'=>'CI Enquiry','class_name'=>'Class 8','parent_name'=>'Guardian','phone'=>'9999999999','enquiry_date'=>date('Y-m-d'),'expected_join_date'=>date('Y-m-d'),'status'=>'New'],$enquiryId);
 TuitionService::save('expenses',['amount'=>100,'description'=>'CI expense','category'=>'Other','expense_date'=>date('Y-m-d')],$expenseId);
 TuitionService::save('action-logs',['message'=>'CI log','type'=>'test','log_date'=>date('Y-m-d H:i:s')],$logId);

 $month=date('Y-m');
 $dashboard=TuitionService::dashboard($month);
 if(abs((float)$dashboard['expected']-1250.0)>0.01)throw new RuntimeException('Dashboard expected-fee calculation failed');
 if(abs((float)$dashboard['collected_for_month']-500.0)>0.01)throw new RuntimeException('Dashboard collection calculation failed');
 if(abs((float)$dashboard['pending']-750.0)>0.01)throw new RuntimeException('Dashboard pending calculation failed');
 $profile=TuitionService::find('students',$studentId);
 if(!$profile||count($profile['children'])!==1)throw new RuntimeException('Student child-profile save/read failed');
 $attendance=TuitionService::find('attendance',date('Y-m-d').'_'.$batchId);
 if(!$attendance||($attendance['records'][(string)$childId]??null)!=='present')throw new RuntimeException('Attendance save/read failed');
 $analytics=AnalyticsService::overview($month);
 if((int)$analytics['totals']['students']<1)throw new RuntimeException('Student analytics failed');
 $reminders=TuitionService::reminders($month);
 if(!count($reminders))throw new RuntimeException('Fee reminder calculation failed');

 $password='CI-Passphrase-'.$suffix;
 $db->prepare('INSERT INTO users(name,email,password_hash,role,is_active) VALUES(?,?,?,"admin",1)')->execute(['CI Admin',$email,password_hash($password,PASSWORD_DEFAULT)]);
 if(!Auth::loginToken($email,$password))throw new RuntimeException('API token login failed');
 $token=Auth::lastIssuedToken()['token']??'';
 $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
 if(!Auth::apiUser())throw new RuntimeException('API bearer-token validation failed');

 echo "PASS: MySQL schema, CRUD, dashboard, child profiles, attendance, analytics, reminders and API auth.\n";
} finally {
 $db->prepare('DELETE FROM api_tokens WHERE user_id IN (SELECT id FROM users WHERE email=?)')->execute([$email]);
 $db->prepare('DELETE FROM users WHERE email=?')->execute([$email]);
 $db->prepare('DELETE FROM attendance_sessions WHERE id=?')->execute([date('Y-m-d').'_'.$batchId]);
 $db->prepare('DELETE FROM fee_payments WHERE id=?')->execute([$feeId]);
 $db->prepare('DELETE FROM enquiries WHERE id=?')->execute([$enquiryId]);
 $db->prepare('DELETE FROM expenses WHERE id=?')->execute([$expenseId]);
 $db->prepare('DELETE FROM action_logs WHERE id=?')->execute([$logId]);
 $db->prepare('DELETE FROM students WHERE id=?')->execute([$studentId]);
 $db->prepare('DELETE FROM batches WHERE id=?')->execute([$batchId]);
 $db->prepare('DELETE FROM teachers WHERE id=?')->execute([$teacherId]);
}
