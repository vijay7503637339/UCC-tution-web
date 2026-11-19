<?php
declare(strict_types=1);
namespace Ucc\Services;

use PDO;
use DateTimeImmutable;
use RuntimeException;
use Ucc\Core\Database;

final class TuitionService {
 private const MAP = [
  'students'=>['table'=>'students','order'=>'name ASC','fields'=>['name','class_name','guardian_phone','batch_id','teacher_id','monthly_fee','due_day','joined_on','is_fees_advance','status','last_reminder','last_payment_date']],
  'batches'=>['table'=>'batches','order'=>'name ASC','fields'=>['name','subject','schedule','teacher_id']],
  'teachers'=>['table'=>'teachers','order'=>'name ASC','fields'=>['name','subject','phone']],
  'fees'=>['table'=>'fee_payments','order'=>'paid_on DESC, id DESC','fields'=>['student_id','amount','fee_month','paid_on','method','note']],
  'enquiries'=>['table'=>'enquiries','order'=>'created_at DESC, id DESC','fields'=>['student_name','class_name','parent_name','phone','address','enquiry_date','expected_join_date','notes','status','source','converted_to_student_id','admission_date']],
  'expenses'=>['table'=>'expenses','order'=>'expense_date DESC, id DESC','fields'=>['amount','description','category','expense_date']],
  'action-logs'=>['table'=>'action_logs','order'=>'log_date DESC, id DESC','fields'=>['message','log_date','type']]
 ];
 public static function map():array{return self::MAP;}
 public static function nextId(string $table):int {
  $allowed=['students','batches','teachers','fee_payments','enquiries','expenses','action_logs','student_children'];
  if(!in_array($table,$allowed,true))throw new RuntimeException('Invalid table');
  return (int)Database::connection()->query('SELECT COALESCE(MAX(id),0)+1 FROM '.$table)->fetchColumn();
 }
 private static function meta(string $key):array {
  if(!isset(self::MAP[$key]))throw new RuntimeException('Unknown resource');
  return self::MAP[$key];
 }
 public static function all(string $key,array $filters=[]):array {
  if($key==='attendance')return self::attendanceList($filters);
  $m=self::meta($key);$sql='SELECT * FROM '.$m['table'].' WHERE 1=1';$params=[];
  if($key==='students'){
   if(!empty($filters['status'])){$sql.=' AND status=?';$params[]=$filters['status'];}
   if(!empty($filters['q'])){$sql.=' AND (name LIKE ? OR guardian_phone LIKE ? OR class_name LIKE ?)';$v='%'.trim($filters['q']).'%';array_push($params,$v,$v,$v);}
  }
  if($key==='fees'&&!empty($filters['month'])){$sql.=' AND fee_month=?';$params[]=$filters['month'].'-01';}
  if($key==='fees'&&!empty($filters['student_id'])){$sql.=' AND student_id=?';$params[]=(int)$filters['student_id'];}
  if($key==='enquiries'&&!empty($filters['status'])){$sql.=' AND status=?';$params[]=$filters['status'];}
  $sql.=' ORDER BY '.$m['order'].' LIMIT 1000';$q=Database::connection()->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
  if($key==='students')foreach($rows as &$row)$row['children']=self::children((int)$row['id']);unset($row);
  return $rows;
 }
 public static function find(string $key,string|int $id):?array {
  if($key==='attendance')return self::attendanceFind((string)$id);
  $m=self::meta($key);$q=Database::connection()->prepare('SELECT * FROM '.$m['table'].' WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)return null;if($key==='students')$row['children']=self::children((int)$row['id']);return $row;
 }
 public static function children(int $familyId):array {
  $q=Database::connection()->prepare('SELECT id,family_id,name,class_name,batch_id,is_active FROM student_children WHERE family_id=? ORDER BY id');$q->execute([$familyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 private static function normalize(string $field,mixed $value):mixed {
  if($value==='')return null;
  if(in_array($field,['batch_id','teacher_id','student_id','converted_to_student_id','due_day'],true))return $value===null?null:(int)$value;
  if(in_array($field,['amount','monthly_fee'],true))return round((float)$value,2);
  if($field==='is_fees_advance')return (int)(bool)$value;
  if(in_array($field,['joined_on','fee_month','enquiry_date','expected_join_date','admission_date','expense_date'],true)){if($value===null)return null;$t=strtotime((string)$value);if(!$t)throw new RuntimeException('Invalid date');return date('Y-m-d',$t);}
  if(in_array($field,['paid_on','last_reminder','last_payment_date','log_date'],true)){if($value===null)return null;$t=strtotime((string)$value);if(!$t)throw new RuntimeException('Invalid date/time');return date('Y-m-d H:i:s',$t);}
  return is_string($value)?trim($value):$value;
 }
 private static function upsert(string $table,array $values):void {
  $cols=array_keys($values);$colSql=implode(',',$cols);$marks=implode(',',array_fill(0,count($cols),'?'));
  $updates=array_values(array_filter($cols,static fn($c)=>$c!=='id'));
  $set=$updates?implode(',',array_map(static fn($c)=>$c.'=VALUES('.$c.')',$updates)):'id=VALUES(id)';
  Database::connection()->prepare('INSERT INTO '.$table.' ('.$colSql.') VALUES ('.$marks.') ON DUPLICATE KEY UPDATE '.$set)->execute(array_values($values));
 }
 public static function save(string $key,array $data,string|int|null $requestedId=null):int|string {
  if($key==='attendance')return self::saveAttendance($data,$requestedId);
  $m=self::meta($key);$table=$m['table'];$id=$requestedId!==null?(int)$requestedId:(int)($data['id']??self::nextId($table));
  if($id<=0)throw new RuntimeException('Invalid record ID');
  if($key==='students'){
   $db=Database::connection();$db->beginTransaction();
   try{
    $values=['id'=>$id];foreach($m['fields'] as $f)if(array_key_exists($f,$data))$values[$f]=self::normalize($f,$data[$f]);
    if(empty($values['name']))throw new RuntimeException('Student name is required');
    if(!isset($values['joined_on']))$values['joined_on']=date('Y-m-d');if(!isset($values['status']))$values['status']='active';
    self::upsert($table,$values);
    if(array_key_exists('children',$data))self::replaceChildren($id,is_array($data['children'])?$data['children']:[]);
    elseif(!self::children($id))self::replaceChildren($id,[]);
    $db->commit();return $id;
   }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  $values=['id'=>$id];foreach($m['fields'] as $f)if(array_key_exists($f,$data))$values[$f]=self::normalize($f,$data[$f]);
  if($key==='fees'){
   if(empty($values['student_id'])||!self::find('students',(int)$values['student_id']))throw new RuntimeException('Choose a valid student');
   $values['fee_month']=$values['fee_month']??date('Y-m-01');$values['paid_on']=$values['paid_on']??date('Y-m-d H:i:s');
  }
  if($key==='expenses')$values['expense_date']=$values['expense_date']??date('Y-m-d');
  if($key==='enquiries')$values['status']=$values['status']??'New';
  if($key==='action-logs')$values['log_date']=$values['log_date']??date('Y-m-d H:i:s');
  self::upsert($table,$values);return $id;
 }
 private static function replaceChildren(int $familyId,array $children):void {
  $db=Database::connection();$db->prepare('DELETE FROM student_children WHERE family_id=?')->execute([$familyId]);
  if(!$children){$s=self::find('students',$familyId);$children=[['id'=>$familyId,'name'=>$s['name']??'Student','class_name'=>$s['class_name']??'','batch_id'=>$s['batch_id']??null,'is_active'=>1]];}
  $q=$db->prepare('INSERT INTO student_children(id,family_id,name,class_name,batch_id,is_active,raw_data) VALUES(?,?,?,?,?,?,?)');
  foreach($children as $c){$cid=(int)($c['id']??0);if($cid<=0||self::childIdExists($cid))$cid=self::nextChildId();
   $q->execute([$cid,$familyId,(string)($c['name']??''),(string)($c['class_name']??$c['className']??''),!empty($c['batch_id'])?(int)$c['batch_id']:(!empty($c['batchId'])?(int)$c['batchId']:null),isset($c['is_active'])?(int)(bool)$c['is_active']:1,json_encode($c,JSON_UNESCAPED_UNICODE)]);
  }
 }
 private static function childIdExists(int $id):bool{$q=Database::connection()->prepare('SELECT 1 FROM student_children WHERE id=?');$q->execute([$id]);return(bool)$q->fetchColumn();}
 private static function nextChildId():int{return (int)Database::connection()->query('SELECT GREATEST(COALESCE((SELECT MAX(id) FROM student_children),0),COALESCE((SELECT MAX(id) FROM students),0))+1')->fetchColumn();}
 public static function delete(string $key,string|int $id):void {
  if($key==='attendance'){Database::connection()->prepare('DELETE FROM attendance_sessions WHERE id=?')->execute([(string)$id]);return;}
  $m=self::meta($key);
  if($key==='students'){$q=Database::connection()->prepare('SELECT COUNT(*) FROM fee_payments WHERE student_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0){Database::connection()->prepare("UPDATE students SET status='inactive' WHERE id=?")->execute([$id]);return;}}
  Database::connection()->prepare('DELETE FROM '.$m['table'].' WHERE id=?')->execute([$id]);
 }
 private static function saveAttendance(array $data,string|int|null $id):string {
  $db=Database::connection();$batch=(int)($data['batch_id']??0);$date=self::normalize('expense_date',$data['session_date']??date('Y-m-d'));
  $b=$db->prepare('SELECT name FROM batches WHERE id=?');$b->execute([$batch]);$batchName=(string)$b->fetchColumn();if(!$batchName)throw new RuntimeException('Select a batch');
  $session=(string)($id??($data['id']??($date.'_'.$batch)));$records=is_array($data['records']??null)?$data['records']:[];
  $db->beginTransaction();try{
   $statuses=array_values($records);$present=count(array_filter($statuses,static fn($s)=>$s==='present'));$absent=count(array_filter($statuses,static fn($s)=>$s==='absent'));
   $q=$db->prepare('INSERT INTO attendance_sessions(id,batch_id,batch_name,session_date,present_count,absent_count,total_students,raw_data) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id),batch_name=VALUES(batch_name),session_date=VALUES(session_date),present_count=VALUES(present_count),absent_count=VALUES(absent_count),total_students=VALUES(total_students),raw_data=VALUES(raw_data)');
   $q->execute([$session,$batch,$batchName,$date,$present,$absent,count($records),json_encode($records)]);
   $db->prepare('DELETE FROM attendance_records WHERE session_id=?')->execute([$session]);
   $insert=$db->prepare('INSERT INTO attendance_records(session_id,student_id,attendance_status) VALUES(?,?,?)');
   foreach($records as $studentId=>$status)if(in_array($status,['present','absent','leave'],true))$insert->execute([$session,(int)$studentId,$status]);
   $db->commit();return $session;
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 private static function attendanceList(array $filters=[]):array {
  $sql='SELECT * FROM attendance_sessions WHERE 1=1';$p=[];
  if(!empty($filters['batch_id'])){$sql.=' AND batch_id=?';$p[]=(int)$filters['batch_id'];}
  if(!empty($filters['month'])){$sql.=' AND DATE_FORMAT(session_date,"%Y-%m")=?';$p[]=$filters['month'];}
  $sql.=' ORDER BY session_date DESC LIMIT 500';$q=Database::connection()->prepare($sql);$q->execute($p);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 private static function attendanceFind(string $id):?array {
  $q=Database::connection()->prepare('SELECT * FROM attendance_sessions WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
  $r=Database::connection()->prepare('SELECT student_id,attendance_status FROM attendance_records WHERE session_id=?');$r->execute([$id]);$row['records']=$r->fetchAll(PDO::FETCH_KEY_PAIR);return $row;
 }
 public static function attendanceStudents(int $batchId):array {
  $q=Database::connection()->prepare("SELECT c.id,c.name,c.class_name,c.family_id FROM student_children c JOIN students s ON s.id=c.family_id WHERE c.batch_id=? AND c.is_active=1 AND s.status='active' ORDER BY c.name");$q->execute([$batchId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public static function dashboard(string $month):array {
  if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');$start=$month.'-01';$end=date('Y-m-d',strtotime($start.' +1 month'));$db=Database::connection();
  $q=$db->prepare("SELECT s.*,COALESCE(p.paid,0) paid FROM students s LEFT JOIN (SELECT student_id,SUM(amount) paid FROM fee_payments WHERE fee_month=? GROUP BY student_id) p ON p.student_id=s.id WHERE s.status='active' AND s.joined_on<?");
  $q->execute([$start,$end]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$expected=0.0;$collected=0.0;$pending=0.0;$overdue=0.0;$overdueCount=0;$paidCount=0;$partialCount=0;$unpaidCount=0;$new=0;$activeChildren=0;$today=new DateTimeImmutable('today');
  foreach($rows as $s){
   $fee=max(0,(float)$s['monthly_fee']);$got=max(0,(float)$s['paid']);$due=max(0,$fee-$got);$expected+=$fee;$collected+=$got;$pending+=$due;
   if($due<=0)$paidCount++;elseif($got>0)$partialCount++;else$unpaidCount++;
   $dueMonth=(int)$s['is_fees_advance']?new DateTimeImmutable($month.'-01'):new DateTimeImmutable($month.'-01 +1 month');$day=min(max(1,(int)$s['due_day']),(int)$dueMonth->format('t'));$dueDate=$dueMonth->setDate((int)$dueMonth->format('Y'),(int)$dueMonth->format('m'),$day);
   if($due>0&&$today>$dueDate){$overdue+=$due;$overdueCount++;}
   $children=self::children((int)$s['id']);$count=count(array_filter($children,static fn($c)=>(int)$c['is_active']===1));$activeChildren+=$count;if(substr((string)$s['joined_on'],0,7)===$month)$new+=max(1,$count);
  }
  $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE paid_on>=? AND paid_on<?');$q->execute([$start,$end]);$received=(float)$q->fetchColumn();
  $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=? AND expense_date<?');$q->execute([$start,$end]);$expenses=(float)$q->fetchColumn();
  $q=$db->prepare('SELECT COUNT(*) FROM enquiries WHERE created_at>=? AND created_at<?');$q->execute([$start,$end]);$enquiries=(int)$q->fetchColumn();
  return ['month'=>$month,'active_students'=>$activeChildren,'student_families'=>count($rows),'new_admissions'=>$new,'expected'=>$expected,'collected_for_month'=>$collected,'received_during_month'=>$received,'pending'=>$pending,'overdue'=>$overdue,'overdue_count'=>$overdueCount,'paid_count'=>$paidCount,'partial_count'=>$partialCount,'unpaid_count'=>$unpaidCount,'expenses'=>$expenses,'net'=>$received-$expenses,'enquiries'=>$enquiries];
 }
 public static function reminders(string $month):array {
  $students=self::all('students',['status'=>'active']);$payments=self::all('fees',['month'=>$month]);$paid=[];
  foreach($payments as $p)$paid[(int)$p['student_id']]=($paid[(int)$p['student_id']]??0)+(float)$p['amount'];
  $today=new DateTimeImmutable('today');$out=[];
  foreach($students as $s){$balance=max(0,(float)$s['monthly_fee']-($paid[(int)$s['id']]??0));if($balance<=0)continue;
   $dueMonth=(int)$s['is_fees_advance']?new DateTimeImmutable($month.'-01'):new DateTimeImmutable($month.'-01 +1 month');$day=min(max(1,(int)$s['due_day']),(int)$dueMonth->format('t'));$date=$dueMonth->setDate((int)$dueMonth->format('Y'),(int)$dueMonth->format('m'),$day);
   $out[]=$s+['paid_for_month'=>$paid[(int)$s['id']]??0,'pending_amount'=>$balance,'due_date'=>$date->format('Y-m-d'),'days_overdue'=>$today>$date?(int)$date->diff($today)->format('%a'):0];
  }
  usort($out,static fn($a,$b)=>$b['days_overdue']<=>$a['days_overdue']?:$b['pending_amount']<=>$a['pending_amount']);return $out;
 }
 public static function attendanceRecords(string $sessionId):array {
  $q=Database::connection()->prepare('SELECT r.student_id,r.attendance_status,c.name,c.class_name FROM attendance_records r LEFT JOIN student_children c ON c.id=r.student_id WHERE r.session_id=? ORDER BY c.name');$q->execute([$sessionId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
}