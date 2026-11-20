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
  $m=self::meta($key);$table=$m['table'];$sql='SELECT * FROM '.$table.' WHERE 1=1';$params=[];
  $q=trim((string)($filters['q']??''));
  if($q!==''){
   $like='%'.$q.'%';
   $search=match($key){
    'students'=>'(name LIKE ? OR guardian_phone LIKE ? OR class_name LIKE ?)',
    'batches'=>'(name LIKE ? OR subject LIKE ? OR schedule LIKE ?)',
    'teachers'=>'(name LIKE ? OR subject LIKE ? OR phone LIKE ?)',
    'fees'=>"(CAST(id AS CHAR) LIKE ? OR method LIKE ? OR note LIKE ? OR EXISTS(SELECT 1 FROM students sx WHERE sx.id=fee_payments.student_id AND sx.name LIKE ?))",
    'enquiries'=>'(student_name LIKE ? OR parent_name LIKE ? OR phone LIKE ? OR class_name LIKE ?)',
    'expenses'=>'(description LIKE ? OR category LIKE ?)',
    'action-logs'=>'(message LIKE ? OR type LIKE ?)',
    default=>''
   };
   if($search!==''){$sql.=' AND '.$search;$count=substr_count($search,'?');for($i=0;$i<$count;$i++)$params[]=$like;}
  }
  if($key==='students'){
   if(in_array(($filters['status']??''),['active','inactive'],true)){$sql.=' AND status=?';$params[]=$filters['status'];}
   if(!empty($filters['batch_id'])){$sql.=' AND (batch_id=? OR EXISTS(SELECT 1 FROM student_children c WHERE c.family_id=students.id AND c.batch_id=? AND c.is_active=1))';$id=(int)$filters['batch_id'];array_push($params,$id,$id);}
   if(!empty($filters['teacher_id'])){$sql.=' AND teacher_id=?';$params[]=(int)$filters['teacher_id'];}
   if(!empty($filters['class_name'])){$sql.=' AND (class_name=? OR EXISTS(SELECT 1 FROM student_children c WHERE c.family_id=students.id AND c.class_name=? AND c.is_active=1))';array_push($params,$filters['class_name'],$filters['class_name']);}
   if(!empty($filters['joined_from'])){$sql.=' AND joined_on>=?';$params[]=$filters['joined_from'];}
   if(!empty($filters['joined_to'])){$sql.=' AND joined_on<=?';$params[]=$filters['joined_to'];}
   if(isset($filters['min_fee'])&&$filters['min_fee']!==''){$sql.=' AND monthly_fee>=?';$params[]=(float)$filters['min_fee'];}
   if(isset($filters['max_fee'])&&$filters['max_fee']!==''){$sql.=' AND monthly_fee<=?';$params[]=(float)$filters['max_fee'];}
  }elseif($key==='batches'){
   if(!empty($filters['teacher_id'])){$sql.=' AND teacher_id=?';$params[]=(int)$filters['teacher_id'];}
   if(!empty($filters['subject_filter'])){$sql.=' AND subject LIKE ?';$params[]='%'.trim((string)$filters['subject_filter']).'%';}
   if(($filters['has_students']??'')==='yes')$sql.=' AND EXISTS(SELECT 1 FROM student_children c JOIN students s ON s.id=c.family_id WHERE c.batch_id=batches.id AND c.is_active=1 AND s.status="active")';
   if(($filters['has_students']??'')==='no')$sql.=' AND NOT EXISTS(SELECT 1 FROM student_children c JOIN students s ON s.id=c.family_id WHERE c.batch_id=batches.id AND c.is_active=1 AND s.status="active")';
  }elseif($key==='teachers'){
   if(!empty($filters['subject_filter'])){$sql.=' AND subject LIKE ?';$params[]='%'.trim((string)$filters['subject_filter']).'%';}
   if(!empty($filters['batch_id'])){$sql.=' AND EXISTS(SELECT 1 FROM batches b WHERE b.teacher_id=teachers.id AND b.id=?)';$params[]=(int)$filters['batch_id'];}
  }elseif($key==='fees'){
   if(!empty($filters['month'])&&preg_match('/^\d{4}-\d{2}$/',(string)$filters['month'])){$sql.=' AND fee_month=?';$params[]=$filters['month'].'-01';}
   if(!empty($filters['student_id'])){$sql.=' AND student_id=?';$params[]=(int)$filters['student_id'];}
   if(!empty($filters['batch_id'])){$sql.=' AND EXISTS(SELECT 1 FROM students s LEFT JOIN student_children c ON c.family_id=s.id AND c.is_active=1 WHERE s.id=fee_payments.student_id AND (s.batch_id=? OR c.batch_id=?))';$id=(int)$filters['batch_id'];array_push($params,$id,$id);}
   if(!empty($filters['method'])){$sql.=' AND method=?';$params[]=$filters['method'];}
   if(isset($filters['min_amount'])&&$filters['min_amount']!==''){$sql.=' AND amount>=?';$params[]=(float)$filters['min_amount'];}
   if(isset($filters['max_amount'])&&$filters['max_amount']!==''){$sql.=' AND amount<=?';$params[]=(float)$filters['max_amount'];}
  }elseif($key==='enquiries'){
   if(!empty($filters['status'])){$sql.=' AND status=?';$params[]=$filters['status'];}
   if(!empty($filters['class_name'])){$sql.=' AND class_name=?';$params[]=$filters['class_name'];}
   if(!empty($filters['source'])){$sql.=' AND source=?';$params[]=$filters['source'];}
   if(($filters['converted']??'')==='yes')$sql.=' AND converted_to_student_id IS NOT NULL';
   if(($filters['converted']??'')==='no')$sql.=' AND converted_to_student_id IS NULL';
   if(!empty($filters['date_from'])){$sql.=' AND COALESCE(enquiry_date,DATE(created_at))>=?';$params[]=$filters['date_from'];}
   if(!empty($filters['date_to'])){$sql.=' AND COALESCE(enquiry_date,DATE(created_at))<=?';$params[]=$filters['date_to'];}
  }elseif($key==='expenses'){
   if(!empty($filters['category'])){$sql.=' AND category=?';$params[]=$filters['category'];}
   if(!empty($filters['date_from'])){$sql.=' AND expense_date>=?';$params[]=$filters['date_from'];}
   if(!empty($filters['date_to'])){$sql.=' AND expense_date<=?';$params[]=$filters['date_to'];}
   if(isset($filters['min_amount'])&&$filters['min_amount']!==''){$sql.=' AND amount>=?';$params[]=(float)$filters['min_amount'];}
   if(isset($filters['max_amount'])&&$filters['max_amount']!==''){$sql.=' AND amount<=?';$params[]=(float)$filters['max_amount'];}
  }elseif($key==='action-logs'){
   if(!empty($filters['type'])){$sql.=' AND type=?';$params[]=$filters['type'];}
   if(!empty($filters['date_from'])){$sql.=' AND DATE(log_date)>=?';$params[]=$filters['date_from'];}
   if(!empty($filters['date_to'])){$sql.=' AND DATE(log_date)<=?';$params[]=$filters['date_to'];}
  }
  $sql.=' ORDER BY '.$m['order'].' LIMIT 5000';$query=Database::connection()->prepare($sql);$query->execute($params);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
  if($key==='students'){
   foreach($rows as &$row)$row['children']=self::children((int)$row['id']);
   unset($row);
  }
  if($key==='fees'){
   foreach($rows as &$row)$row=self::localizePaymentRow($row);
   unset($row);
   $from=trim((string)($filters['paid_from']??''));$to=trim((string)($filters['paid_to']??''));
   if($from!==''||$to!=='')$rows=array_values(array_filter($rows,static function(array $row)use($from,$to):bool{
    $day=substr((string)$row['paid_on'],0,10);return ($from===''||$day>=$from)&&($to===''||$day<=$to);
   }));
  }
  if($key==='batches'){
   $counts=Database::connection()->query("SELECT c.batch_id,COUNT(*) AS student_count FROM student_children c JOIN students s ON s.id=c.family_id WHERE c.is_active=1 AND s.status='active' GROUP BY c.batch_id")->fetchAll(PDO::FETCH_KEY_PAIR);
   foreach($rows as &$row)$row['student_count']=(int)($counts[$row['id']]??0);
   unset($row);
  }
  if($key==='teachers'){
   $counts=Database::connection()->query('SELECT teacher_id,COUNT(*) AS batch_count FROM batches WHERE teacher_id IS NOT NULL GROUP BY teacher_id')->fetchAll(PDO::FETCH_KEY_PAIR);
   foreach($rows as &$row)$row['batch_count']=(int)($counts[$row['id']]??0);
   unset($row);
  }
  return $rows;
 }
 public static function find(string $key,string|int $id):?array {
  if($key==='attendance')return self::attendanceFind((string)$id);
  $m=self::meta($key);$q=Database::connection()->prepare('SELECT * FROM '.$m['table'].' WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)return null;
  if($key==='students')$row['children']=self::children((int)$row['id']);
  if($key==='fees')$row=self::localizePaymentRow($row);
  return $row;
 }
 public static function batchStudents(int $batchId):array {
  $q=Database::connection()->prepare("SELECT c.id AS student_id,c.name,c.class_name,c.family_id,s.name AS family_name,s.guardian_phone,s.monthly_fee,s.status FROM student_children c JOIN students s ON s.id=c.family_id WHERE c.batch_id=? AND c.is_active=1 AND s.status='active' ORDER BY c.name");
  $q->execute([$batchId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public static function distinctValues(string $key,string $field):array {
  $allowed=[
   'students'=>['class_name'=>'students.class_name'],
   'batches'=>['subject_filter'=>'batches.subject'],
   'teachers'=>['subject_filter'=>'teachers.subject'],
   'enquiries'=>['class_name'=>'enquiries.class_name','source'=>'enquiries.source','status'=>'enquiries.status'],
   'expenses'=>['category'=>'expenses.category'],
   'action-logs'=>['type'=>'action_logs.type']
  ];
  if(!isset($allowed[$key][$field]))return [];
  $column=$allowed[$key][$field];$sql='SELECT DISTINCT '.$column.' AS value FROM '.$key.' WHERE '.$column." IS NOT NULL AND ".$column."<>'' ORDER BY value";
  return array_values(array_filter(array_map('strval',Database::connection()->query($sql)->fetchAll(PDO::FETCH_COLUMN))));
 }
 public static function children(int $familyId):array {
  $q=Database::connection()->prepare('SELECT id,family_id,name,class_name,batch_id,is_active FROM student_children WHERE family_id=? ORDER BY id');$q->execute([$familyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }

 // Firestore timestamps were exported as UTC ISO strings and the first importer
 // stored their UTC wall-clock value in MySQL DATETIME. Re-read the preserved
 // source timestamp and convert it to India time; web-entered DATETIME values
 // without a tagged Firestore timestamp are already local time.
 private static function paymentLocalDateTime(array $row):DateTimeImmutable {
  $timezone=new \DateTimeZone('Asia/Kolkata');
  $raw=$row['raw_data']??null;
  if(is_string($raw)&&$raw!==''){
   $source=json_decode($raw,true);
   $sourceDate=is_array($source)?($source['date']??null):null;
   if(is_array($sourceDate)&&($sourceDate['__ucc_type__']??null)==='timestamp'&&!empty($sourceDate['value'])){
    try{return (new DateTimeImmutable((string)$sourceDate['value']))->setTimezone($timezone);}catch(\Throwable){}
   }
  }
  return new DateTimeImmutable((string)($row['paid_on']??'now'),$timezone);
 }
 private static function localizePaymentRow(array $row):array {
  $local=self::paymentLocalDateTime($row);
  $row['paid_on_stored']=$row['paid_on']??null;
  $row['paid_on']=$local->format('Y-m-d H:i:s');
  $row['paid_on_local']=$row['paid_on'];
  return $row;
 }
 public static function receivedPayments(string $start,string $end):array {
  $timezone=new \DateTimeZone('Asia/Kolkata');
  // Use a two-day storage window because imported UTC values can sit on the
  // previous MySQL calendar date; PHP applies the exact local-date filter below.
  $from=(new DateTimeImmutable($start,$timezone))->modify('-2 days')->format('Y-m-d H:i:s');
  $until=(new DateTimeImmutable($end,$timezone))->modify('+2 days')->format('Y-m-d H:i:s');
  $sql='SELECT p.*,s.name AS student_name,s.class_name AS student_class
   FROM fee_payments p LEFT JOIN students s ON s.id=p.student_id
   WHERE p.paid_on>=? AND p.paid_on<?
   ORDER BY p.paid_on DESC,p.id DESC';
  $q=Database::connection()->prepare($sql);$q->execute([$from,$until]);$out=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
   $local=self::paymentLocalDateTime($row);$localDate=$local->format('Y-m-d');
   if($localDate<$start||$localDate>=$end)continue;
   $row['paid_on_local']=$local->format('Y-m-d H:i:s');
   $row['local_paid_date']=$localDate;
   $out[]=$row;
  }
  return $out;
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
   // If an imported receipt is edited, stop using its old Firestore timestamp.
   if(array_key_exists('paid_on',$data))$values['raw_data']=null;
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
  if(!empty($filters['month'])&&preg_match('/^\d{4}-\d{2}$/',(string)$filters['month'])){$sql.=' AND DATE_FORMAT(session_date,"%Y-%m")=?';$p[]=$filters['month'];}
  if(!empty($filters['date_from'])){$sql.=' AND session_date>=?';$p[]=$filters['date_from'];}
  if(!empty($filters['date_to'])){$sql.=' AND session_date<=?';$p[]=$filters['date_to'];}
  if(!empty($filters['q'])){$sql.=' AND batch_name LIKE ?';$p[]='%'.trim((string)$filters['q']).'%';}
  $sql.=' ORDER BY session_date DESC LIMIT 1000';$q=Database::connection()->prepare($sql);$q->execute($p);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 private static function attendanceFind(string $id):?array {
  $q=Database::connection()->prepare('SELECT * FROM attendance_sessions WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
  $r=Database::connection()->prepare('SELECT student_id,attendance_status FROM attendance_records WHERE session_id=?');$r->execute([$id]);$row['records']=$r->fetchAll(PDO::FETCH_KEY_PAIR);return $row;
 }
 public static function attendanceStudents(int $batchId):array {
  $q=Database::connection()->prepare("SELECT c.id,c.name,c.class_name,c.family_id FROM student_children c JOIN students s ON s.id=c.family_id WHERE c.batch_id=? AND c.is_active=1 AND s.status='active' ORDER BY c.name");$q->execute([$batchId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public static function dashboard(string $month):array {
  if(!preg_match('/^\\d{4}-\\d{2}$/',$month))$month=date('Y-m');
  $start=$month.'-01';
  $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
  $db=Database::connection();

  // A billable family must be active and must contain at least one active child profile.
  // This prevents inactive/empty families from inflating the selected month's overdue balance.
  $q=$db->prepare("SELECT s.*,COALESCE(p.paid,0) paid
   FROM students s
   LEFT JOIN (
    SELECT student_id,SUM(amount) paid
    FROM fee_payments WHERE fee_month=? GROUP BY student_id
   ) p ON p.student_id=s.id
   WHERE s.status='active'
    AND s.joined_on<?");
  $q->execute([$start,$end]);
  $rows=$q->fetchAll(PDO::FETCH_ASSOC);

  $expected=0.0;$collected=0.0;$pending=0.0;$overdue=0.0;
  $overdueCount=0;$paidCount=0;$partialCount=0;$unpaidCount=0;
  $new=0;$activeChildren=0;$today=new DateTimeImmutable('today');

  foreach($rows as $s){
   $fee=max(0,(float)$s['monthly_fee']);
   $got=max(0,(float)$s['paid']);
   $due=max(0,$fee-$got);
   $expected+=$fee;$collected+=$got;$pending+=$due;

   if($due<=0)$paidCount++;elseif($got>0)$partialCount++;else$unpaidCount++;

   $children=self::children((int)$s['id']);
   $count=count(array_filter($children,static fn($c)=>(int)$c['is_active']===1));
   $activeChildren+=$count;

   $dueMonth=(int)$s['is_fees_advance']
    ?new DateTimeImmutable($month.'-01')
    :new DateTimeImmutable($month.'-01 +1 month');
   $day=min(max(1,(int)$s['due_day']),(int)$dueMonth->format('t'));
   $dueDate=$dueMonth->setDate((int)$dueMonth->format('Y'),(int)$dueMonth->format('m'),$day);
   $joinedOn=new DateTimeImmutable((string)$s['joined_on']);

   // Overdue is counted only when this active family has an active child
   // and the family joined on or before the selected month's due date.
   if($count>0 && $due>0 && $today>$dueDate && $joinedOn<=$dueDate){
    $overdue+=$due;
    $overdueCount++;
   }
   if(substr((string)$s['joined_on'],0,7)===$month)$new+=max(1,$count);
  }

  // Receiving is grouped by the original payment instant converted to India time.
  // Fee-month allocation remains separate from the actual date money was received.
  $monthPayments=self::receivedPayments($start,$end);
  $byDay=[];$received=0.0;
  foreach($monthPayments as $payment){
   $date=(string)$payment['local_paid_date'];$amount=(float)$payment['amount'];
   $received+=$amount;
   if(!isset($byDay[$date]))$byDay[$date]=['amount'=>0.0,'transactions'=>0];
   $byDay[$date]['amount']+=$amount;$byDay[$date]['transactions']++;
  }

  $todayStart=(new DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
  $tomorrow=(new DateTimeImmutable($todayStart,new \DateTimeZone('Asia/Kolkata')))->modify('+1 day')->format('Y-m-d');
  if($todayStart>=$start&&$todayStart<$end){
   $receivedToday=(float)($byDay[$todayStart]['amount']??0);
  }else{
   $todayPayments=self::receivedPayments($todayStart,$tomorrow);$receivedToday=0.0;
   foreach($todayPayments as $payment)$receivedToday+=(float)$payment['amount'];
  }

  // Show every elapsed day in the selected month, including zero-receipt days.
  $monthDate=new DateTimeImmutable($start);
  $daysInMonth=(int)$monthDate->format('t');
  $currentMonth=date('Y-m');
  $lastDay=$month===$currentMonth?(int)date('j'):($month<$currentMonth?$daysInMonth:0);
  $dailyReceiving=[];
  for($day=$lastDay;$day>=1;$day--){
   $date=$monthDate->setDate((int)$monthDate->format('Y'),(int)$monthDate->format('m'),$day)->format('Y-m-d');
   $dailyReceiving[]=[
    'date'=>$date,
    'amount'=>(float)($byDay[$date]['amount']??0),
    'transactions'=>(int)($byDay[$date]['transactions']??0)
   ];
  }

  $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=? AND expense_date<?');
  $q->execute([$start,$end]);$expenses=(float)$q->fetchColumn();
  $q=$db->prepare('SELECT COUNT(*) FROM enquiries WHERE created_at>=? AND created_at<?');
  $q->execute([$start,$end]);$enquiries=(int)$q->fetchColumn();

  return [
   'month'=>$month,'active_students'=>$activeChildren,'student_families'=>count($rows),
   'new_admissions'=>$new,'expected'=>$expected,'collected_for_month'=>$collected,
   'received_during_month'=>$received,'received_today'=>$receivedToday,'daily_receiving'=>$dailyReceiving,
   'pending'=>$pending,'overdue'=>$overdue,'overdue_count'=>$overdueCount,
   'paid_count'=>$paidCount,'partial_count'=>$partialCount,'unpaid_count'=>$unpaidCount,
   'expenses'=>$expenses,'net'=>$received-$expenses,'enquiries'=>$enquiries
  ];
 }
 public static function reminders(string $month,array $filters=[]):array {
  $studentFilters=$filters;$studentFilters['status']='active';$students=self::all('students',$studentFilters);
  $payments=self::all('fees',['month'=>$month]);$paid=[];
  foreach($payments as $p)$paid[(int)$p['student_id']]=($paid[(int)$p['student_id']]??0)+(float)$p['amount'];
  $today=new DateTimeImmutable('today',new \DateTimeZone('Asia/Kolkata'));$out=[];
  foreach($students as $s){
   $joinedMonth=substr((string)($s['joined_on']??''),0,7);if($joinedMonth!==''&&$joinedMonth>$month)continue;
   $balance=max(0,(float)$s['monthly_fee']-($paid[(int)$s['id']]??0));if($balance<=0)continue;
   $dueMonth=(int)$s['is_fees_advance']?new DateTimeImmutable($month.'-01'):new DateTimeImmutable($month.'-01 +1 month');$day=min(max(1,(int)$s['due_day']),(int)$dueMonth->format('t'));$date=$dueMonth->setDate((int)$dueMonth->format('Y'),(int)$dueMonth->format('m'),$day);
   $out[]=$s+['paid_for_month'=>$paid[(int)$s['id']]??0,'pending_amount'=>$balance,'due_date'=>$date->format('Y-m-d'),'days_overdue'=>$today>$date?(int)$date->diff($today)->format('%a'):0];
  }
  usort($out,static fn($a,$b)=>$b['days_overdue']<=>$a['days_overdue']?:$b['pending_amount']<=>$a['pending_amount']);return $out;
 }
 public static function attendanceRecords(string $sessionId):array {
  $q=Database::connection()->prepare('SELECT r.student_id,r.attendance_status,c.name,c.class_name FROM attendance_records r LEFT JOIN student_children c ON c.id=r.student_id WHERE r.session_id=? ORDER BY c.name');$q->execute([$sessionId]);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
}