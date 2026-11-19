<?php
declare(strict_types=1);
namespace Ucc\Services;
use PDO;
use Ucc\Core\Database;
final class AnalyticsService {
 public static function overview(string $month):array {
  if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
  $start=$month.'-01';$end=date('Y-m-d',strtotime($start.' +1 month'));$db=Database::connection();
  $q=$db->prepare("SELECT class_name,COUNT(*) AS families,COALESCE(SUM(monthly_fee),0) AS expected FROM students WHERE status='active' AND joined_on<? GROUP BY class_name ORDER BY class_name");
  $q->execute([$end]);$rows=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$rows[(string)($r['class_name']?:'Unassigned')]=['class_name'=>(string)($r['class_name']?:'Unassigned'),'families'=>(int)$r['families'],'expected'=>(float)$r['expected'],'students'=>0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];}
  $q=$db->prepare("SELECT COALESCE(NULLIF(c.class_name,''),NULLIF(s.class_name,''),'Unassigned') AS class_name,COUNT(*) AS profiles FROM student_children c JOIN students s ON s.id=c.family_id WHERE s.status='active' AND s.joined_on<? AND c.is_active=1 GROUP BY class_name");
  $q->execute([$end]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=$r['class_name'];if(!isset($rows[$key]))$rows[$key]=['class_name'=>$key,'families'=>0,'expected'=>0.0,'students'=>0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];$rows[$key]['students']=(int)$r['profiles'];}
  $q=$db->prepare("SELECT COALESCE(NULLIF(s.class_name,''),'Unassigned') AS class_name,COALESCE(SUM(f.amount),0) AS collected FROM fee_payments f JOIN students s ON s.id=f.student_id WHERE f.fee_month=? GROUP BY class_name");
  $q->execute([$start]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=$r['class_name'];if(isset($rows[$key]))$rows[$key]['collected']=(float)$r['collected'];}
  $q=$db->prepare("SELECT COALESCE(NULLIF(c.class_name,''),NULLIF(s.class_name,''),'Unassigned') AS class_name,r.attendance_status,COUNT(*) AS total FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id JOIN student_children c ON c.id=r.student_id JOIN students s ON s.id=c.family_id WHERE a.session_date>=? AND a.session_date<? GROUP BY class_name,r.attendance_status");
  $q->execute([$start,$end]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=$r['class_name'];if(!isset($rows[$key]))$rows[$key]=['class_name'=>$key,'families'=>0,'expected'=>0.0,'students'=>0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];$status=$r['attendance_status'];if(isset($rows[$key][$status]))$rows[$key][$status]=(int)$r['total'];}
  uasort($rows,static fn($a,$b)=>strcmp($a['class_name'],$b['class_name']));
  $total=['classes'=>count($rows),'families'=>0,'students'=>0,'expected'=>0.0,'collected'=>0.0,'present'=>0,'absent'=>0,'leave'=>0];
  foreach($rows as $r)foreach($total as $k=>$v)if($k!=='classes')$total[$k]+=$r[$k]??0;
  return ['month'=>$month,'totals'=>$total,'classes'=>array_values($rows)];
 }
}
