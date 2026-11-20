<?php
declare(strict_types=1);
namespace Ucc\Services;
use PDO;
use Ucc\Core\Database;
final class AnalyticsService {
 public static function overview(string $month,array $filters=[]):array {
  if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
  $start=$month.'-01';$end=date('Y-m-d',strtotime($start.' +1 month'));$db=Database::connection();
  $familySql="SELECT s.class_name,COUNT(*) AS families,COALESCE(SUM(s.monthly_fee),0) AS expected FROM students s WHERE s.status='active' AND s.joined_on<?";$familyParams=[$end];
  if(!empty($filters['batch_id'])){$familySql.=' AND (s.batch_id=? OR EXISTS(SELECT 1 FROM student_children x WHERE x.family_id=s.id AND x.batch_id=? AND x.is_active=1))';$id=(int)$filters['batch_id'];array_push($familyParams,$id,$id);}
  $familySql.=' GROUP BY s.class_name ORDER BY s.class_name';$q=$db->prepare($familySql);$q->execute($familyParams);$rows=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=(string)($r['class_name']?:'Unassigned');$rows[$key]=['class_name'=>$key,'families'=>(int)$r['families'],'expected'=>(float)$r['expected'],'students'=>0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];}
  $studentSql="SELECT COALESCE(NULLIF(c.class_name,''),NULLIF(s.class_name,''),'Unassigned') AS class_name,COUNT(*) AS profiles FROM student_children c JOIN students s ON s.id=c.family_id WHERE s.status='active' AND s.joined_on<? AND c.is_active=1";$studentParams=[$end];
  if(!empty($filters['batch_id'])){$studentSql.=' AND c.batch_id=?';$studentParams[]=(int)$filters['batch_id'];}
  $studentSql.=' GROUP BY class_name';$q=$db->prepare($studentSql);$q->execute($studentParams);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=$r['class_name'];if(!isset($rows[$key]))$rows[$key]=['class_name'=>$key,'families'=>0,'expected'=>0.0,'students'=>0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];$rows[$key]['students']=(int)$r['profiles'];}
  $feeSql="SELECT COALESCE(NULLIF(s.class_name,''),'Unassigned') AS class_name,COALESCE(SUM(f.amount),0) AS collected FROM fee_payments f JOIN students s ON s.id=f.student_id WHERE f.fee_month=?";$feeParams=[$start];
  if(!empty($filters['batch_id'])){$feeSql.=' AND (s.batch_id=? OR EXISTS(SELECT 1 FROM student_children x WHERE x.family_id=s.id AND x.batch_id=? AND x.is_active=1))';$id=(int)$filters['batch_id'];array_push($feeParams,$id,$id);}
  $feeSql.=' GROUP BY class_name';$q=$db->prepare($feeSql);$q->execute($feeParams);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=$r['class_name'];if(isset($rows[$key]))$rows[$key]['collected']=(float)$r['collected'];}
  $attendanceSql="SELECT COALESCE(NULLIF(c.class_name,''),NULLIF(s.class_name,''),'Unassigned') AS class_name,r.attendance_status,COUNT(*) AS total FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id JOIN student_children c ON c.id=r.student_id JOIN students s ON s.id=c.family_id WHERE a.session_date>=? AND a.session_date<?";$attendanceParams=[$start,$end];
  if(!empty($filters['batch_id'])){$attendanceSql.=' AND a.batch_id=?';$attendanceParams[]=(int)$filters['batch_id'];}
  $attendanceSql.=' GROUP BY class_name,r.attendance_status';$q=$db->prepare($attendanceSql);$q->execute($attendanceParams);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=$r['class_name'];if(!isset($rows[$key]))$rows[$key]=['class_name'=>$key,'families'=>0,'expected'=>0.0,'students'=>0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];$status=$r['attendance_status'];if(isset($rows[$key][$status]))$rows[$key][$status]=(int)$r['total'];}
  if(!empty($filters['class_name']))$rows=array_filter($rows,static fn($r)=>$r['class_name']===(string)$filters['class_name']);
  uasort($rows,static fn($a,$b)=>strcmp($a['class_name'],$b['class_name']));
  $total=['classes'=>count($rows),'families'=>0,'students'=>0,'expected'=>0.0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];
  foreach($rows as $r)foreach($total as $k=>$v)if($k!=='classes')$total[$k]+=$r[$k]??0;
  return ['month'=>$month,'totals'=>$total,'classes'=>array_values($rows)];
 }

}
