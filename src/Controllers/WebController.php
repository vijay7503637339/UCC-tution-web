<?php
declare(strict_types=1);
namespace Ucc\Controllers;

use Ucc\Core\Auth;
use Ucc\Core\Database;
use Ucc\Core\Helpers as H;
use Ucc\Core\Response;
use Ucc\Services\TuitionService;
use Ucc\Services\AnalyticsService;

final class WebController {
 private const TITLES=['students'=>'Students','batches'=>'Batches','teachers'=>'Teachers','fees'=>'Fee Management','enquiries'=>'Enquiries & Admissions','expenses'=>'Expenses','action-logs'=>'Activity Logs'];
 public function handle(string $method,string $path):never {
  Auth::bootSession();
  if($path==='/login'){$method==='POST'?$this->login():$this->loginPage(); }
  if($path==='/logout'&&$method==='POST'){H::verifyCsrf();Auth::logout();Response::redirect('/login');}
  Auth::requireWeb();
  if($path==='/'||$path==='/dashboard')$this->dashboard();
  if($path==='/receiving'&&$method==='GET')$this->receivingDetail(null);
  if(preg_match('#^/receiving/(\\d{4}-\\d{2}-\\d{2})$#',$path,$receiving)&&$method==='GET')$this->receivingDetail($receiving[1]);
  if(preg_match('#^/students/([0-9]+)$#',$path,$profile)&&$method==='GET')$this->studentProfile((int)$profile[1]);
  if(preg_match('#^/batches/([0-9]+)/students$#',$path,$batchStudents)&&$method==='GET')$this->batchStudents((int)$batchStudents[1]);
  if(preg_match('#^/enquiries/([0-9]+)/convert$#',$path,$conversion))$this->convertEnquiry((int)$conversion[1],$method);
  if($path==='/attendance')$this->attendance($method);
  if($path==='/reports')$this->reports();
  if($path==='/analytics')$this->analytics();
  if($path==='/reminders')$this->reminders();
  if($path==='/assistant')$this->assistant();
  if(preg_match('#^/attendance/([^/]+)$#',$path,$m))$this->attendanceDetail(rawurldecode($m[1]));
  if(preg_match('#^/([a-z-]+)(?:/([^/]+))?(?:/(edit|delete))?$#',$path,$m)){
   $key=$m[1];$id=$m[2]??null;$action=$m[3]??'';
   if(isset(self::TITLES[$key])){
    if($action==='delete'&&$method==='POST')$this->delete($key,(string)$id);
    if($action==='edit'||$id==='new'){$this->resourceForm($key,$id==='new'?null:(string)$id);}
    if($id===null&&$method==='GET')$this->resourceList($key);
   }
  }
  Response::html($this->layout('Page not found','<div class="panel">Page not found.</div>'),404);
 }
 private function login():never {
  H::verifyCsrf();
  if(Auth::login((string)($_POST['email']??''),(string)($_POST['password']??''))){H::flash('success','Welcome back.');Response::redirect('/');}
  H::flash('error','Email or password is incorrect.');Response::redirect('/login');
 }
 private function loginPage():never {
  $flash=H::takeFlash();$message='';
  try{$count=(int)Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();if($count===0)$message='<div class="notice warning">No admin account exists yet. Run <code>php bin/create-admin.php</code> from the project directory, then sign in here.</div>';}catch(\Throwable){$message='<div class="notice warning">Database is not configured yet. Copy <code>.env.example</code> to <code>.env</code>, set MySQL details and import <code>database/schema.sql</code>.</div>';}
  $body='<div class="login-wrap"><div class="login-card"><div class="logo large">U</div><p class="eyebrow">UNIQUE COACHING CLASSES</p><h1>UCC Tuition Manager</h1><p class="muted">Sign in to manage students, fees and attendance.</p>'.$message.$this->flashHtml($flash).'<form method="post" action="/login">'. $this->csrfInput().'<label class="field"><span>Email address</span><input type="email" name="email" required autocomplete="username"></label><label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label><button class="btn primary full">Sign in</button></form><p class="muted tiny">Private administration panel · Secure session sign-in</p></div></div>';
  Response::html($this->layout('Login',$body,false));
 }
  private function dashboard():never {
  $month=$this->monthParam();$filters=['batch_id'=>$_GET['batch_id']??'','class_name'=>$_GET['class_name']??''];
  $s=TuitionService::dashboard($month,$filters);$monthLabel=date('F Y',strtotime($month.'-01'));
  $cards=[
   ['Fees Today',H::money($s['received_today']),'Received today in IST'],
   ['This Month Receiving',H::money($s['received_during_month']),'Actual payments received in '.$monthLabel],
   ['Active students',number_format((int)$s['active_students']),'Across selected active profiles'],
   ['Expected fee',H::money($s['expected']),'Selected fee month'],
   ['Fee-month collected',H::money($s['collected_for_month']),'Payments allocated to '.$monthLabel],
   ['Pending fees',H::money($s['pending']),'Expected less fee-month payments'],
   ['Overdue fees',H::money($s['overdue']),(int)$s['overdue_count'].' active families overdue'],
   ['New admissions',number_format((int)$s['new_admissions']),'Joined in selected month'],
   ['Expenses',H::money($s['expenses']),'Centre-wide expenses in selected month'],
   ['Net received',H::money($s['net']),'Filtered receiving minus centre-wide expenses']
  ];
  $body='<section class="welcome"><div><p class="eyebrow light">OVERVIEW</p><h2>Good day, '.H::e(Auth::user()['name']??'Admin').'</h2><p>Here is the tuition centre summary for '.H::e($monthLabel).'.</p></div><form method="get" action="/" class="dashboard-filter"><label class="field"><span>Fee month</span><input type="month" name="month" value="'.H::e($month).'"></label>'.$this->reportingFilterFields($filters).'<button class="btn light-btn">Apply filters</button></form></section><div class="grid stats">';
  foreach($cards as $i=>$card){
   $cardHtml='<article class="stat-card"><div class="stat-top"><span>'.H::e($card[0]).'</span><span class="stat-dot dot-'.($i%7).'"></span></div><strong>'.H::e($card[1]).'</strong><small>'.H::e($card[2]).'</small></article>';
   $filtered=http_build_query(array_filter(['batch_id'=>$filters['batch_id'],'class_name'=>$filters['class_name']],static fn($v)=>$v!==''&&$v!==null));
   $cardHref=$i===0?'/receiving/'.date('Y-m-d').($filtered!==''?'?'.$filtered:''):($i===1?'/receiving?'.http_build_query(array_filter(['month'=>$month,'batch_id'=>$filters['batch_id'],'class_name'=>$filters['class_name']],static fn($v)=>$v!==''&&$v!==null)):null);
   $body.=$cardHref!==null?'<a class="stat-card-anchor" href="'.H::e($cardHref).'">'.$cardHtml.'</a>':$cardHtml;
  }
  $body.='</div><div class="grid two section"><section class="panel"><div class="panel-head"><h3>Fee collection status</h3><a href="/fees">View payments</a></div><div class="status-row"><div><strong>'.(int)$s['paid_count'].'</strong><span>Fully paid</span></div><div><strong>'.(int)$s['partial_count'].'</strong><span>Partially paid</span></div><div><strong>'.(int)$s['unpaid_count'].'</strong><span>Unpaid</span></div></div><div class="progress"><span style="width:'.H::e($s['expected']>0?min(100,($s['collected_for_month']/$s['expected'])*100):0).'%"></span></div><p class="muted tiny">Collection progress by fee month. Receiving totals use actual payment date in India Standard Time.</p></section><section class="panel"><div class="panel-head"><h3>Quick actions</h3></div><div class="quick-links"><a href="/students/new">+ Add student</a><a href="/fees/new">+ Record fee</a><a href="/attendance">Take attendance</a><a href="/enquiries/new">+ Add enquiry</a><a href="/reports">Open reports</a><a href="/reminders">Fee reminders</a></div></section></div>';
  $daily=$s['daily_receiving']??[];$maxDaily=0.0;foreach($daily as $day)$maxDaily=max($maxDaily,(float)$day['amount']);$dailyHtml='';
  foreach($daily as $day){
   $date=new \DateTimeImmutable((string)$day['date']);$amount=(float)$day['amount'];$transactions=(int)$day['transactions'];$width=$maxDaily>0?min(100,($amount/$maxDaily)*100):0;
   $description=$transactions===0?'No fee received':$transactions.' payment'.($transactions===1?'':'s');
   $dayQuery=http_build_query(array_filter(['batch_id'=>$filters['batch_id'],'class_name'=>$filters['class_name']],static fn($v)=>$v!==''&&$v!==null));
   $href='/receiving/'.$date->format('Y-m-d').($dayQuery!==''?'?'.$dayQuery:'');
   $dailyHtml.='<a class="daily-receiving-row daily-receiving-link" href="'.H::e($href).'"><span class="daily-date-badge"><strong>'.H::e($date->format('d')).'</strong><small>'.H::e($date->format('M')).'</small></span><span class="daily-receiving-main"><span class="daily-receiving-top"><span><strong>'.H::e($date->format('D, d M')).'</strong><small>'.H::e($description).'</small></span><strong>'.H::money($amount).'</strong></span><span class="daily-receiving-track"><span style="width:'.H::e($width).'%"></span></span></span></a>';
  }
  if($dailyHtml==='')$dailyHtml='<div class="empty">No elapsed days for this selected month yet.</div>';
  $body.='<section class="panel section daily-receiving-panel"><div class="panel-head"><div><h3>Day-wise receiving</h3><p class="muted tiny">Actual cash received by payment date · '.H::e($monthLabel).' · IST</p></div><div class="daily-receiving-total"><small>Month total</small><strong>'.H::money($s['received_during_month']).'</strong></div></div><div class="daily-receiving-list">'.$dailyHtml.'</div></section>';
  Response::html($this->layout('Dashboard',$body));
 }
 private function receivingDetail(?string $date):never {
  $timezone=new \DateTimeZone('Asia/Kolkata');$filters=['batch_id'=>$_GET['batch_id']??'','class_name'=>$_GET['class_name']??''];
  if($date!==null){
   $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,$timezone);
   if(!$parsed||$parsed->format('Y-m-d')!==$date)Response::html($this->layout('Receiving details','<div class="panel">Invalid payment date.</div>'),400);
   $start=$date;$end=$parsed->modify('+1 day')->format('Y-m-d');$heading='Payments received on '.$parsed->format('d F Y');$rangeLabel=$parsed->format('d M Y');$backMonth=$parsed->format('Y-m');
  }else{
   $month=$this->monthParam();$monthStart=new \DateTimeImmutable($month.'-01',$timezone);$start=$monthStart->format('Y-m-d');$end=$monthStart->modify('+1 month')->format('Y-m-d');
   $heading='Payments received in '.$monthStart->format('F Y');$rangeLabel=$monthStart->format('F Y');$backMonth=$month;
  }
  $rows=TuitionService::receivedPayments($start,$end,$filters);$total=0.0;foreach($rows as $row)$total+=(float)$row['amount'];
  $query=http_build_query(array_filter(['month'=>$backMonth,'batch_id'=>$filters['batch_id'],'class_name'=>$filters['class_name']],static fn($v)=>$v!==''&&$v!==null));
  $body='<div class="toolbar"><a class="btn secondary" href="/?'.H::e($query).'">← Back to dashboard</a><span class="pill blue">'.count($rows).' transactions</span></div>';
  $body.='<div class="grid stats three"><article class="stat-card"><span>Total received</span><strong>'.H::money($total).'</strong><small>'.H::e($rangeLabel).' · IST</small></article><article class="stat-card"><span>Transactions</span><strong>'.number_format(count($rows)).'</strong><small>Individual fee receipts</small></article><article class="stat-card"><span>Average receipt</span><strong>'.H::money(count($rows)>0?$total/count($rows):0).'</strong><small>Per transaction</small></article></div>';
  $body.='<section class="panel section"><div class="panel-head"><div><h3>'.H::e($heading).'</h3><p class="muted tiny">Original Firestore timestamps are converted to India Standard Time.</p></div><a href="/fees">All fee records</a></div><div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Student</th><th>Class</th><th>Fee month</th><th>Received date & time</th><th>Amount</th><th>Method</th><th>Note</th></tr></thead><tbody>';
  foreach($rows as $row){$paidAt=new \DateTimeImmutable((string)$row['paid_on_local'],$timezone);$feeMonth=date('F Y',strtotime((string)$row['fee_month']));$student='<a href="/students/'.(int)$row['student_id'].'">'.H::e($row['student_name']??'Unknown student').'</a>';$body.='<tr><td>#'.H::e($row['id']).'</td><td><strong>'.$student.'</strong></td><td>'.H::e($row['student_class']??'').'</td><td>'.H::e($feeMonth).'</td><td>'.H::e($paidAt->format('d M Y, h:i A')).'</td><td><strong>'.H::money($row['amount']).'</strong></td><td>'.H::e($row['method']??'').'</td><td>'.H::e($row['note']??'').'</td></tr>';}
  if(!$rows)$body.='<tr><td colspan="8" class="empty">No fee transactions were received on this date.</td></tr>';
  $body.='</tbody></table></div></section>';Response::html($this->layout('Receiving details',$body));
 }
private function resourceList(string $key):never {
  $filters=$_GET;$rows=TuitionService::all($key,$filters);$title=self::TITLES[$key];
  $body='<form method="get" class="resource-filter-form"><div class="resource-filter-main"><label class="field"><span>Search</span><input type="search" name="q" value="'.H::e($filters['q']??'').'" placeholder="Search '.H::e(strtolower($title)).'"></label><div class="filter-actions"><button class="btn secondary">Search / Apply</button><a class="btn ghost" href="/'.$key.'">Reset</a>';
  if($key!=='action-logs')$body.='<a class="btn primary" href="/'.$key.'/new">+ Add '.rtrim($title,'s').'</a>';
  $body.='</div></div><details class="advanced-filter"><summary>Advanced filters</summary><div class="filter-grid">'.$this->resourceFilterFields($key,$filters).'</div><div class="filter-actions"><button class="btn secondary">Apply filters</button><a class="btn ghost" href="/'.$key.'">Clear all</a></div></details></form>';
  $summary=$this->resourceSummary($key,$rows);
  $body.='<div class="grid stats three section resource-summary">';
  foreach($summary as $card)$body.='<article class="stat-card"><div class="stat-top"><span>'.H::e($card[0]).'</span></div><strong>'.H::e($card[1]).'</strong><small>'.H::e($card[2]).'</small></article>';
  $body.='</div>';
  $headers=match($key){
   'students'=>['Student / Family','Class','Guardian phone','Batch','Monthly fee','Students','Due day','Status','Actions'],
   'batches'=>['Batch','Subject','Schedule','Teacher','Students','Actions'],
   'teachers'=>['Teacher','Subject','Phone','Batches','Actions'],
   'fees'=>['Receipt ID','Student','Fee month','Paid on','Amount','Method','Actions'],
   'enquiries'=>['Student','Parent','Phone','Class','Status','Expected join','Actions'],
   'expenses'=>['Date','Description','Category','Amount','Actions'],
   default=>['Date','Message','Type']
  };
  $body.='<div class="table-wrap"><table><thead><tr>';foreach($headers as $h)$body.='<th>'.H::e($h).'</th>';$body.='</tr></thead><tbody>';
  if(!$rows)$body.='<tr><td class="empty" colspan="'.count($headers).'">No records found for the selected filters.</td></tr>';
  foreach($rows as $row)$body.='<tr>'.$this->resourceCells($key,$row).'</tr>';
  $body.='</tbody></table></div><p class="muted tiny">'.number_format(count($rows)).' matching records shown · Summary values above use the current filters.</p>';
  Response::html($this->layout($title,$body));
 }
 private function resourceSummary(string $key,array $rows):array {
  $count=count($rows);
  if($key==='students'){
   $students=0;$monthly=0.0;$families=0;
   foreach($rows as $r){if(($r['status']??'')==='active'){$students+=count(array_filter($r['children']??[],static fn($c)=>(int)($c['is_active']??0)===1));$monthly+=(float)$r['monthly_fee'];$families++;}}
   return [['Student profiles',number_format($students),'Active child profiles in the filtered families'],['Families',number_format($families),number_format($count).' matching family records'],['Potential monthly fees',H::money($monthly),'Monthly fee total for active families']];
  }
  if($key==='batches'){
   $students=array_sum(array_map(static fn($r)=>(int)($r['student_count']??0),$rows));$empty=count(array_filter($rows,static fn($r)=>(int)($r['student_count']??0)===0));
   return [['Batches',number_format($count),'Matching batches'],['Assigned students',number_format($students),'Active students across these batches'],['Empty batches',number_format($empty),'No active students assigned']];
  }
  if($key==='teachers'){
   $batches=array_sum(array_map(static fn($r)=>(int)($r['batch_count']??0),$rows));
   return [['Teachers',number_format($count),'Matching teacher records'],['Assigned batches',number_format($batches),'Batches assigned to these teachers'],['Unassigned teachers',number_format(count(array_filter($rows,static fn($r)=>(int)($r['batch_count']??0)===0))),'No batches currently assigned']];
  }
  if($key==='fees'){
   $amount=array_sum(array_map(static fn($r)=>(float)$r['amount'],$rows));
   return [['Transactions',number_format($count),'Matching fee receipts'],['Total fees received',H::money($amount),'Sum of the filtered receipts'],['Average receipt',H::money($count?$amount/$count:0),'Average amount per transaction']];
  }
  if($key==='enquiries'){
   $converted=count(array_filter($rows,static fn($r)=>!empty($r['converted_to_student_id'])));$open=count(array_filter($rows,static fn($r)=>!in_array(strtolower((string)$r['status']),['confirmed','rejected'],true)));
   return [['Enquiries',number_format($count),'Matching enquiry records'],['Converted admissions',number_format($converted),'Successfully converted to students'],['Open follow-ups',number_format($open),'Not confirmed or rejected']];
  }
  if($key==='expenses'){
   $amount=array_sum(array_map(static fn($r)=>(float)$r['amount'],$rows));
   return [['Expense entries',number_format($count),'Matching expense records'],['Total expenses',H::money($amount),'Sum of the filtered expenses'],['Average expense',H::money($count?$amount/$count:0),'Average per expense entry']];
  }
  return [['Activity logs',number_format($count),'Matching activity records'],['Activity types',number_format(count(array_unique(array_map(static fn($r)=>(string)$r['type'],$rows)))),'Distinct activity types'],['Latest activity',$count?H::dateTime((string)$rows[0]['log_date']):'—','Most recent matching log']];
 }
 private function resourceFilterFields(string $key,array $filters):string {
  $html='';$batches=[];foreach(TuitionService::all('batches') as $r)$batches[(string)$r['id']]=$r['name'];
  $teachers=[];foreach(TuitionService::all('teachers') as $r)$teachers[(string)$r['id']]=$r['name'];
  if($key==='students'){
   $html.=$this->filterSelect('status','Student status',['active'=>'Active','inactive'=>'Inactive'],$filters['status']??'');
   $html.=$this->filterSelect('batch_id','Batch',$batches,$filters['batch_id']??'');
   $html.=$this->filterSelect('teacher_id','Teacher',$teachers,$filters['teacher_id']??'');
   $classes=[];foreach(TuitionService::distinctValues('students','class_name') as $v)$classes[$v]=$v;
   $html.=$this->filterSelect('class_name','Class',$classes,$filters['class_name']??'');
   $html.=$this->filterInput('joined_from','Admission from',$filters['joined_from']??'','date').$this->filterInput('joined_to','Admission to',$filters['joined_to']??'','date');
   $html.=$this->filterInput('min_fee','Minimum monthly fee',$filters['min_fee']??'','number').$this->filterInput('max_fee','Maximum monthly fee',$filters['max_fee']??'','number');
  }elseif($key==='batches'){
   $html.=$this->filterSelect('teacher_id','Teacher',$teachers,$filters['teacher_id']??'');
   $html.=$this->filterInput('subject_filter','Subject contains',$filters['subject_filter']??'','text');
   $html.=$this->filterSelect('has_students','Student assignment',['yes'=>'Has active students','no'=>'No active students'],$filters['has_students']??'');
  }elseif($key==='teachers'){
   $html.=$this->filterInput('subject_filter','Subject contains',$filters['subject_filter']??'','text').$this->filterSelect('batch_id','Assigned batch',$batches,$filters['batch_id']??'');
  }elseif($key==='fees'){
   $students=[];foreach(TuitionService::all('students',['status'=>'active']) as $r)$students[(string)$r['id']]=$r['name'];
   $classes=[];foreach(TuitionService::distinctValues('students','class_name') as $v)$classes[$v]=$v;
   $html.=$this->filterInput('month','Fee month',$filters['month']??'','month').$this->filterSelect('student_id','Student / family',$students,$filters['student_id']??'').$this->filterSelect('batch_id','Batch',$batches,$filters['batch_id']??'').$this->filterSelect('class_name','Class',$classes,$filters['class_name']??'');
   $html.=$this->filterSelect('method','Payment method',['Cash'=>'Cash','UPI'=>'UPI','Bank Transfer'=>'Bank Transfer','Card'=>'Card','Other'=>'Other'],$filters['method']??'');
   $html.=$this->filterInput('paid_from','Received from',$filters['paid_from']??'','date').$this->filterInput('paid_to','Received to',$filters['paid_to']??'','date');
   $html.=$this->filterInput('min_amount','Minimum amount',$filters['min_amount']??'','number').$this->filterInput('max_amount','Maximum amount',$filters['max_amount']??'','number');
  }elseif($key==='enquiries'){
   $classes=[];foreach(TuitionService::distinctValues('enquiries','class_name') as $v)$classes[$v]=$v;$sources=[];foreach(TuitionService::distinctValues('enquiries','source') as $v)$sources[$v]=$v;$statuses=[];foreach(TuitionService::distinctValues('enquiries','status') as $v)$statuses[$v]=$v;
   $html.=$this->filterSelect('status','Enquiry status',$statuses,$filters['status']??'').$this->filterSelect('class_name','Class',$classes,$filters['class_name']??'').$this->filterSelect('source','Lead source',$sources,$filters['source']??'').$this->filterSelect('converted','Admission converted',['yes'=>'Converted','no'=>'Not converted'],$filters['converted']??'');
   $html.=$this->filterInput('date_from','Enquiry from',$filters['date_from']??'','date').$this->filterInput('date_to','Enquiry to',$filters['date_to']??'','date');
  }elseif($key==='expenses'){
   $categories=[];foreach(TuitionService::distinctValues('expenses','category') as $v)$categories[$v]=$v;
   $html.=$this->filterSelect('category','Expense category',$categories,$filters['category']??'').$this->filterInput('date_from','Expense from',$filters['date_from']??'','date').$this->filterInput('date_to','Expense to',$filters['date_to']??'','date');
   $html.=$this->filterInput('min_amount','Minimum amount',$filters['min_amount']??'','number').$this->filterInput('max_amount','Maximum amount',$filters['max_amount']??'','number');
  }elseif($key==='action-logs'){
   $types=[];foreach(TuitionService::distinctValues('action-logs','type') as $v)$types[$v]=$v;
   $html.=$this->filterSelect('type','Activity type',$types,$filters['type']??'').$this->filterInput('date_from','From date',$filters['date_from']??'','date').$this->filterInput('date_to','To date',$filters['date_to']??'','date');
  }
  return $html;
 }
 private function filterInput(string $name,string $label,mixed $value,string $type='text'):string {
  return '<label class="field filter-field"><span>'.H::e($label).'</span><input type="'.H::e($type).'" name="'.H::e($name).'" value="'.H::e((string)$value).'"></label>';
 }
 private function filterSelect(string $name,string $label,array $options,mixed $selected):string {
  $html='<label class="field filter-field"><span>'.H::e($label).'</span><select name="'.H::e($name).'"><option value="">All</option>';
  foreach($options as $value=>$text)$html.='<option value="'.H::e((string)$value).'"'.((string)$selected===(string)$value?' selected':'').'>'.H::e((string)$text).'</option>';
  return $html.'</select></label>';
 }
 private function batchStudents(int $id):never {
  $batch=TuitionService::find('batches',$id);if(!$batch)Response::html($this->layout('Batch students','<div class="panel">Batch not found.</div>'),404);
  $rows=TuitionService::batchStudents($id);$total=count($rows);$families=count(array_unique(array_map(static fn($r)=>(int)$r['family_id'],$rows)));$feeByFamily=[];foreach($rows as $r)$feeByFamily[(int)$r['family_id']]=(float)$r['monthly_fee'];$fee=array_sum($feeByFamily);
  $body='<div class="toolbar"><a class="btn secondary" href="/batches">← Back to batches</a><a class="btn primary" href="/students?batch_id='.$id.'">View family records</a></div><div class="panel section"><div class="panel-head"><div><p class="eyebrow">BATCH STUDENT REGISTER</p><h2>'.H::e($batch['name']).'</h2><p class="muted">'.H::e($batch['subject']).' · '.H::e($batch['schedule']).'</p></div><span class="pill blue">'.number_format($total).' students</span></div></div><div class="grid stats three section"><article class="stat-card"><span>Students</span><strong>'.number_format($total).'</strong><small>Active child profiles in this batch</small></article><article class="stat-card"><span>Families</span><strong>'.number_format($families).'</strong><small>Unique parent / family records</small></article><article class="stat-card"><span>Linked monthly fee</span><strong>'.H::money($fee).'</strong><small>Family monthly fee counted once per assigned student</small></article></div><section class="panel section"><div class="panel-head"><h3>Student list</h3><span class="muted">'.number_format($total).' active students</span></div><div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Parent / family</th><th>Guardian phone</th><th>Monthly fee</th><th>Profile</th></tr></thead><tbody>';
  foreach($rows as $r)$body.='<tr><td><strong>'.H::e($r['name']).'</strong></td><td>'.H::e($r['class_name']).'</td><td>'.H::e($r['family_name']).'</td><td>'.H::e($r['guardian_phone']).'</td><td>'.H::money($r['monthly_fee']).'</td><td><a href="/students/'.(int)$r['family_id'].'">Open family</a></td></tr>';
  if(!$rows)$body.='<tr><td colspan="6" class="empty">No active students are assigned to this batch.</td></tr>';
  $body.='</tbody></table></div></section>';Response::html($this->layout('Batch students',$body));
 }
 private function resourceCells(string $key,array $r):string {
  $actions=static fn($id)=>'<td class="actions"><a href="/'.$key.'/'.rawurlencode((string)$id).'/edit">Edit</a><form method="post" action="/'.$key.'/'.rawurlencode((string)$id).'/delete" onsubmit="return confirm(\'Delete this record?\')">'.self::csrfStatic().'<button class="link danger">Delete</button></form></td>';
  return match($key){
   'students'=>'<td><strong><a href="/students/'.(int)$r['id'].'">'.H::e($r['name']).'</a></strong><small class="subline">'.H::e($r['guardian_phone']).'</small></td><td>'.H::e($r['class_name']).'</td><td>'.H::e($r['guardian_phone']).'</td><td>'.H::e($this->lookupName('batches',$r['batch_id']??null)).'</td><td>'.H::money($r['monthly_fee']).'</td><td>'.number_format(($r['status']??'')==='active'?count(array_filter($r['children']??[],static fn($c)=>(int)($c['is_active']??0)===1)):0).'</td><td>'.H::e($r['due_day']).'</td><td><span class="pill '.($r['status']==='active'?'green':'gray').'">'.H::e(ucfirst($r['status'])).'</span></td>'.$actions($r['id']),
   'batches'=>'<td><strong>'.H::e($r['name']).'</strong></td><td>'.H::e($r['subject']).'</td><td>'.H::e($r['schedule']).'</td><td>'.H::e($this->lookupName('teachers',$r['teacher_id']??null)).'</td><td><a class="count-link" href="/batches/'.(int)$r['id'].'/students"><strong>'.number_format((int)($r['student_count']??0)).'</strong><small>View students</small></a></td>'.$actions($r['id']),
   'teachers'=>'<td><strong>'.H::e($r['name']).'</strong></td><td>'.H::e($r['subject']).'</td><td>'.H::e($r['phone']).'</td><td><a href="/batches?teacher_id='.(int)$r['id'].'">'.number_format((int)($r['batch_count']??0)).' batches</a></td>'.$actions($r['id']),
   'fees'=>'<td>#'.H::e($r['id']).'</td><td>'.H::e($this->lookupName('students',$r['student_id'])).'</td><td>'.H::date($r['fee_month']).'</td><td>'.$this->paymentDateLink((string)$r['paid_on']).'</td><td><strong>'.H::money($r['amount']).'</strong></td><td>'.H::e($r['method']).'</td>'.$actions($r['id']),
   'enquiries'=>'<td><strong>'.H::e($r['student_name']).'</strong></td><td>'.H::e($r['parent_name']).'</td><td>'.H::e($r['phone']).'</td><td>'.H::e($r['class_name']).'</td><td><span class="pill blue">'.H::e($r['status']).'</span></td><td>'.H::date($r['expected_join_date']).'</td><td class="actions">'.(empty($r['converted_to_student_id'])?'<a href="/enquiries/'.(int)$r['id'].'/convert">Convert</a>':'<a href="/students/'.(int)$r['converted_to_student_id'].'">Student</a>').'<a href="/enquiries/'.(int)$r['id'].'/edit">Edit</a><form method="post" action="/enquiries/'.(int)$r['id'].'/delete">'.self::csrfStatic().'<button class="link danger">Delete</button></form></td>',
   'expenses'=>'<td>'.H::date($r['expense_date']).'</td><td>'.H::e($r['description']).'</td><td>'.H::e($r['category']).'</td><td><strong>'.H::money($r['amount']).'</strong></td>'.$actions($r['id']),
   default=>'<td>'.H::date($r['log_date']).'</td><td>'.H::e($r['message']).'</td><td>'.H::e($r['type']).'</td>'
  };
 }
 private function studentProfile(int $id):never {
  $student=TuitionService::find('students',$id);
  if(!$student)Response::html($this->layout('Student profile','<div class="panel">Student not found.</div>'),404);
  $payments=TuitionService::all('fees',['student_id'=>$id]);
  $body='<div class="toolbar"><div><a class="btn secondary" href="/students">← Students</a> <a class="btn primary" href="/students/'.$id.'/edit">Edit student</a></div><span class="pill '.($student['status']==='active'?'green':'gray').'">'.H::e(ucfirst($student['status'])).'</span></div>';
  $body.='<div class="grid two"><section class="panel"><div class="panel-head"><h3>'.H::e($student['name']).'</h3><span class="muted">Student / family profile</span></div><div class="insight-list"><div><span>Class</span><strong>'.H::e($student['class_name']).'</strong></div><div><span>Guardian phone</span><strong>'.H::e($student['guardian_phone']).'</strong></div><div><span>Batch</span><strong>'.H::e($this->lookupName('batches',$student['batch_id'])).'</strong></div><div><span>Teacher</span><strong>'.H::e($this->lookupName('teachers',$student['teacher_id'])).'</strong></div><div><span>Monthly fee</span><strong>'.H::money($student['monthly_fee']).'</strong></div><div><span>Due day</span><strong>'.H::e($student['due_day']).'</strong></div><div><span>Joined</span><strong>'.H::date($student['joined_on']).'</strong></div></div></section><section class="panel"><div class="panel-head"><h3>Child profiles</h3><a href="/students/'.$id.'/edit">Manage</a></div><div class="history-list">';
  foreach(($student['children']??[]) as $child)$body.='<div class="history-item"><span><strong>'.H::e($child['name']).'</strong><small>'.H::e($child['class_name']).'</small></span><span class="pill '.((int)$child['is_active']===1?'green':'gray').'">'.((int)$child['is_active']===1?'Active':'Inactive').'</span></div>';
  if(empty($student['children']))$body.='<p class="empty">No child profiles recorded.</p>';
  $body.='</div></section></div><section class="panel section"><div class="panel-head"><h3>Fee payment history</h3><a class="btn primary" href="/fees/new">Record payment</a></div><div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Fee month</th><th>Paid on</th><th>Amount</th><th>Method</th><th>Note</th></tr></thead><tbody>';
  foreach($payments as $p)$body.='<tr><td>#'.H::e($p['id']).'</td><td>'.H::date($p['fee_month']).'</td><td>'.$this->paymentDateLink((string)$p['paid_on']).'</td><td><strong>'.H::money($p['amount']).'</strong></td><td>'.H::e($p['method']).'</td><td>'.H::e($p['note']).'</td></tr>';
  if(!$payments)$body.='<tr><td colspan="6" class="empty">No fee transactions yet.</td></tr>';
  $body.='</tbody></table></div></section>';
  Response::html($this->layout('Student profile',$body));
 }
 private function convertEnquiry(int $id,string $method):never {
  $enquiry=TuitionService::find('enquiries',$id);
  if(!$enquiry)Response::html($this->layout('Convert enquiry','<div class="panel">Enquiry not found.</div>'),404);
  if(!empty($enquiry['converted_to_student_id']))Response::redirect('/students/'.(int)$enquiry['converted_to_student_id']);
  if($method==='POST'){
   H::verifyCsrf();
   try{
    $studentId=(int)TuitionService::save('students',[
     'name'=>$enquiry['student_name'],'class_name'=>$enquiry['class_name'],'guardian_phone'=>$enquiry['phone'],
     'batch_id'=>$_POST['batch_id']??null,'teacher_id'=>$_POST['teacher_id']??null,
     'monthly_fee'=>$_POST['monthly_fee']??0,'due_day'=>$_POST['due_day']??10,
     'joined_on'=>$_POST['joined_on']??date('Y-m-d'),'is_fees_advance'=>$_POST['is_fees_advance']??1,'status'=>'active'
    ]);
    $enquiry['converted_to_student_id']=$studentId;$enquiry['admission_date']=$_POST['joined_on']??date('Y-m-d');$enquiry['status']='Confirmed';
    TuitionService::save('enquiries',$enquiry,$id);
    H::flash('success','Enquiry converted to student admission.');Response::redirect('/students/'.$studentId);
   }catch(\Throwable $e){H::flash('error',$e->getMessage());Response::redirect('/enquiries/'.$id.'/convert');}
  }
  $body='<div class="form-panel"><p class="muted">Convert enquiry for <strong>'.H::e($enquiry['student_name']).'</strong> into a student record. This creates a new student in the web MySQL database and marks the enquiry as confirmed.</p><form method="post" action="/enquiries/'.$id.'/convert">'.$this->csrfInput().'<div class="form-grid">';
  $body.=H::field('monthly_fee','Monthly fee (INR)','0','number',['min'=>'0','step'=>'0.01','required'=>'required']);
  $body.=H::field('due_day','Fee due day','10','number',['min'=>'1','max'=>'31']);
  $body.=H::field('joined_on','Admission date',date('Y-m-d'),'date');
  $body.=$this->selectHtml('batch_id','Batch',TuitionService::all('batches'),null);
  $body.=$this->selectHtml('teacher_id','Teacher',TuitionService::all('teachers'),null);
  $body.=$this->selectSimple('is_fees_advance','Fee collection timing',['1'=>'Advance (same month)','0'=>'After month ends'],'1');
  $body.='</div><div class="form-actions"><a class="btn secondary" href="/enquiries">Cancel</a><button class="btn primary">Create admission</button></div></form></div>';
  Response::html($this->layout('Convert enquiry to admission',$body));
 }
 private function resourceForm(string $key,?string $id):never {
  if($key==='action-logs')Response::redirect('/activity-logs');
  $record=$id?TuitionService::find($key,$id):null;if($id&&!$record){H::flash('error','Record not found.');Response::redirect('/'.$key);}
  if($_SERVER['REQUEST_METHOD']==='POST')$this->saveForm($key,$id);
  $editing=$record!==null;$title=($editing?'Edit ':'Add ').rtrim(self::TITLES[$key],'s');
  $body='<div class="form-panel"><form method="post" action="/'.$key.'/'.($editing?H::e($id).'/edit':'new').'">'.$this->csrfInput().'<div class="form-grid">';
  $v=static fn($field,$default='')=>$record[$field]??$default;
  if($key==='students'){
   $body.=H::field('name','Student / family name',$v('name'),'text',['required'=>'required']);
   $body.=H::field('class_name','Class',$v('class_name'));
   $body.=H::field('guardian_phone','Guardian phone',$v('guardian_phone'),'tel');
   $body.=$this->selectHtml('batch_id','Default batch',TuitionService::all('batches'),$v('batch_id'));
   $body.=$this->selectHtml('teacher_id','Teacher',TuitionService::all('teachers'),$v('teacher_id'));
   $body.=H::field('monthly_fee','Monthly fee (INR)',$v('monthly_fee','0'),'number',['step'=>'0.01','min'=>'0']);
   $body.=H::field('due_day','Fee due day',$v('due_day','10'),'number',['min'=>'1','max'=>'31']);
   $body.=H::field('joined_on','Joined on',$v('joined_on',date('Y-m-d')),'date');
   $body.=$this->selectSimple('is_fees_advance','Fee collection timing',['1'=>'Advance (same month)','0'=>'After month ends'],(string)$v('is_fees_advance','1'));
   $body.=$this->selectSimple('status','Student status',['active'=>'Active','inactive'=>'Inactive'],$v('status','active'));
   $children=$record['children']??[];$lines=[];foreach($children as $c)$lines[]=($c['id']??'').'|'.($c['name']??'').'|'.($c['class_name']??'').'|'.($c['batch_id']??'');
   $body.='<label class="field wide"><span>Child profiles (one per line: ID | Name | Class | Batch ID)</span><textarea name="children_raw" rows="4" placeholder="Leave blank for a single legacy student profile">'.H::e(implode("\n",$lines)).'</textarea><small>Existing IDs are preserved where possible. For a new profile, leave ID blank.</small></label>';
  } elseif($key==='batches'){
   $body.=H::field('name','Batch name',$v('name'),'text',['required'=>'required']);
   $body.=H::field('subject','Subject',$v('subject'));
   $body.=H::field('schedule','Schedule (e.g. Mon-Wed-Fri, 4 PM)',$v('schedule'));
   $body.=$this->selectHtml('teacher_id','Teacher',TuitionService::all('teachers'),$v('teacher_id'));
  } elseif($key==='teachers'){
   $body.=H::field('name','Teacher name',$v('name'),'text',['required'=>'required']);
   $body.=H::field('subject','Subject',$v('subject'));
   $body.=H::field('phone','Phone',$v('phone'),'tel');
  } elseif($key==='fees'){
   $body.=$this->selectHtml('student_id','Student',TuitionService::all('students'),$v('student_id'),true);
   $body.=H::field('amount','Amount received (INR)',$v('amount'),'number',['step'=>'0.01','min'=>'0.01','required'=>'required']);
   $body.=H::field('fee_month','Fee for month',substr((string)$v('fee_month',date('Y-m-01')),0,7),'month');
   $body.=H::field('paid_on','Payment date',substr((string)$v('paid_on',date('Y-m-d')),0,10),'date');
   $body.=$this->selectSimple('method','Payment method',['Cash'=>'Cash','UPI'=>'UPI','Bank Transfer'=>'Bank Transfer','Card'=>'Card','Other'=>'Other'],$v('method','Cash'));
   $body.='<label class="field wide"><span>Note</span><textarea name="note" rows="3">'.H::e($v('note')).'</textarea></label>';
  } elseif($key==='enquiries'){
   $body.=H::field('student_name','Student name',$v('student_name'),'text',['required'=>'required']);
   $body.=H::field('class_name','Class',$v('class_name'));
   $body.=H::field('parent_name','Parent / guardian name',$v('parent_name'));
   $body.=H::field('phone','Phone',$v('phone'),'tel');
   $body.=H::field('enquiry_date','Enquiry date',$v('enquiry_date',date('Y-m-d')),'date');
   $body.=H::field('expected_join_date','Expected joining date',$v('expected_join_date'),'date');
   $body.=H::field('source','Source',$v('source'));
   $body.=$this->selectSimple('status','Status',['New'=>'New','Follow Up'=>'Follow Up','Confirmed'=>'Confirmed','Rejected'=>'Rejected'],$v('status','New'));
   $body.='<label class="field wide"><span>Address</span><textarea name="address" rows="2">'.H::e($v('address')).'</textarea></label><label class="field wide"><span>Notes</span><textarea name="notes" rows="3">'.H::e($v('notes')).'</textarea></label>';
  } elseif($key==='expenses'){
   $body.=H::field('description','Description',$v('description'),'text',['required'=>'required']);
   $body.=H::field('amount','Amount (INR)',$v('amount'),'number',['step'=>'0.01','min'=>'0.01','required'=>'required']);
   $body.=H::field('expense_date','Expense date',$v('expense_date',date('Y-m-d')),'date');
   $body.=$this->selectSimple('category','Category',['Rent'=>'Rent','Salary'=>'Salary','Utilities'=>'Utilities','Stationery'=>'Stationery','Transport'=>'Transport','Other'=>'Other'],$v('category','Other'));
  }
  $body.='</div><div class="form-actions"><a class="btn secondary" href="/'.$key.'">Cancel</a><button class="btn primary">Save record</button></div></form></div>';
  Response::html($this->layout($title,$body));
 }
 private function saveForm(string $key,?string $id):never {
  H::verifyCsrf();$data=$_POST;unset($data['_csrf'],$data['children_raw']);
  if($key==='students'){
   $children=[];$raw=trim((string)($_POST['children_raw']??''));
   if($raw!=='')foreach(preg_split('/\r\n|\r|\n/',$raw) as $line){$parts=array_map('trim',explode('|',$line));if(count($parts)<2)continue;$children[]=['id'=>(int)($parts[0]??0),'name'=>$parts[1]??'','class_name'=>$parts[2]??($_POST['class_name']??''),'batch_id'=>(int)($parts[3]??$_POST['batch_id']??0),'is_active'=>1];}
   $data['children']=$children;
  }
  try{$saved=TuitionService::save($key,$data,$id);H::flash('success','Record saved successfully.');Response::redirect('/'.$key.($key==='students'?'/'.rawurlencode((string)$saved).'/edit':''));}
  catch(\Throwable $e){H::flash('error',$e->getMessage());Response::redirect('/'.$key.($id?'/'.$id.'/edit':'/new'));}
 }
 private function delete(string $key,string $id):never {
  H::verifyCsrf();try{TuitionService::delete($key,$id);H::flash('success',$key==='students'?'Student deleted or marked inactive.':'Record deleted.');}catch(\Throwable $e){H::flash('error','Could not delete: '.$e->getMessage());}Response::redirect('/'.$key);
 }
  private function attendance(string $method):never {
  if($method==='POST')$this->saveAttendance();
  $batches=TuitionService::all('batches');$batchId=(int)($_GET['batch_id']??0);$date=(string)($_GET['date']??date('Y-m-d'));$students=$batchId?TuitionService::attendanceStudents($batchId):[];
  $historyFilters=['batch_id'=>$_GET['history_batch_id']??'','date_from'=>$_GET['history_from']??'','date_to'=>$_GET['history_to']??'','q'=>$_GET['history_q']??''];$sessions=TuitionService::all('attendance',$historyFilters);
  $present=array_sum(array_map(static fn($s)=>(int)$s['present_count'],$sessions));$absent=array_sum(array_map(static fn($s)=>(int)$s['absent_count'],$sessions));$marked=array_sum(array_map(static fn($s)=>(int)$s['total_students'],$sessions));
  $body='<div class="grid two"><section class="panel"><div class="panel-head"><h3>Take attendance</h3><span class="pill blue">Daily register</span></div><form method="get" action="/attendance" class="inline-form"><label class="field"><span>Batch</span><select name="batch_id" required><option value="">Select batch</option>';
  foreach($batches as $b)$body.='<option value="'.(int)$b['id'].'"'.($batchId===(int)$b['id']?' selected':'').'>'.H::e($b['name']).' · '.(int)($b['student_count']??0).' students</option>';
  $body.='</select></label>'.H::field('date','Date',$date,'date').'<button class="btn secondary">Load students</button></form>';
  if($batchId&&$students){$body.='<div class="pill blue">'.count($students).' active students in this batch</div><form method="post" action="/attendance" class="attendance-form">'.$this->csrfInput().'<input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="session_date" value="'.H::e($date).'"><div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody>';
   foreach($students as $s)$body.='<tr><td>'.H::e($s['name']).'</td><td>'.H::e($s['class_name']).'</td><td><select name="records['.(int)$s['id'].']"><option value="present">Present</option><option value="absent">Absent</option><option value="leave">Leave</option></select></td></tr>';
   $body.='</tbody></table></div><button class="btn primary">Save attendance</button></form>';
  }elseif($batchId)$body.='<div class="empty">No active child profiles found in this batch. Assign students to this batch first.</div>';
  else $body.='<p class="muted">Choose a batch and date to load its active students.</p>';
  $body.='</section><section class="panel"><div class="panel-head"><h3>Attendance history</h3><span class="pill blue">'.number_format(count($sessions)).' sessions</span></div><form method="get" class="attendance-history-filter"><label class="field"><span>Batch</span><select name="history_batch_id"><option value="">All batches</option>';
  foreach($batches as $b)$body.='<option value="'.(int)$b['id'].'"'.((string)$historyFilters['batch_id']===(string)$b['id']?' selected':'').'>'.H::e($b['name']).'</option>';
  $body.='</select></label>'.$this->filterInput('history_from','From date',$historyFilters['date_from'],'date').$this->filterInput('history_to','To date',$historyFilters['date_to'],'date').$this->filterInput('history_q','Batch name',$historyFilters['q'],'text').'<button class="btn secondary">Filter history</button><a class="btn ghost" href="/attendance">Reset</a></form><div class="grid stats three section"><article class="stat-card"><span>Sessions</span><strong>'.number_format(count($sessions)).'</strong><small>Filtered records</small></article><article class="stat-card"><span>Present marks</span><strong>'.number_format($present).'</strong><small>Across filtered sessions</small></article><article class="stat-card"><span>Absent marks</span><strong>'.number_format($absent).'</strong><small>'.number_format($marked).' total marked students</small></article></div><div class="history-list">';
  foreach(array_slice($sessions,0,20) as $s)$body.='<a class="history-item" href="/attendance/'.rawurlencode($s['id']).'"><span><strong>'.H::e($s['batch_name']).'</strong><small>'.H::date($s['session_date']).' · '.(int)$s['total_students'].' students</small></span><span class="pill green">'.(int)$s['present_count'].' present</span></a>';
  if(!$sessions)$body.='<p class="empty">No attendance sessions match these filters.</p>';
  $body.='</div></section></div>';Response::html($this->layout('Attendance',$body));
 }
private function saveAttendance():never {
  H::verifyCsrf();try{$id=TuitionService::save('attendance',['batch_id'=>(int)($_POST['batch_id']??0),'session_date'=>$_POST['session_date']??date('Y-m-d'),'records'=>$_POST['records']??[]]);H::flash('success','Attendance saved.');Response::redirect('/attendance/'.rawurlencode((string)$id));}catch(\Throwable $e){H::flash('error',$e->getMessage());Response::redirect('/attendance');}
 }
 private function attendanceDetail(string $id):never {
  $s=TuitionService::find('attendance',$id);if(!$s)Response::html($this->layout('Attendance','<div class="panel">Attendance session not found.</div>'),404);
  $rows=TuitionService::attendanceRecords($id);$body='<div class="panel"><div class="panel-head"><div><h3>'.H::e($s['batch_name']).'</h3><p class="muted">'.H::date($s['session_date']).'</p></div><a href="/attendance" class="btn secondary">Back</a></div><div class="status-row"><div><strong>'.(int)$s['present_count'].'</strong><span>Present</span></div><div><strong>'.(int)$s['absent_count'].'</strong><span>Absent</span></div><div><strong>'.(int)$s['total_students'].'</strong><span>Total</span></div></div><div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody>';
  foreach($rows as $r)$body.='<tr><td>'.H::e($r['name']??'Unknown').'</td><td>'.H::e($r['class_name']??'').'</td><td><span class="pill '.($r['attendance_status']==='present'?'green':'gray').'">'.H::e(ucfirst($r['attendance_status'])).'</span></td></tr>';
  $body.='</tbody></table></div></div>';Response::html($this->layout('Attendance details',$body));
 }
  private function reports():never {
  $month=$this->monthParam();$filters=['batch_id'=>$_GET['batch_id']??'','class_name'=>$_GET['class_name']??''];$d=TuitionService::dashboard($month,$filters);$fees=TuitionService::all('fees',['month'=>$month]+$filters);
  $body='<form method="get" class="resource-filter-form"><div class="resource-filter-main"><label class="field"><span>Fee month</span><input type="month" name="month" value="'.H::e($month).'"></label><div class="filter-actions"><button class="btn secondary">Apply filters</button><a class="btn ghost" href="/reports">Reset</a><a class="btn primary" href="/reports?'.H::e(http_build_query(['month'=>$month,'batch_id'=>$filters['batch_id'],'class_name'=>$filters['class_name'],'export'=>'csv'])).'">Export CSV</a></div></div><details class="advanced-filter" open><summary>Advanced filters</summary><div class="filter-grid">'.$this->reportingFilterFields($filters).'</div></details></form><div class="grid stats three section">';
  foreach([['Expected',H::money($d['expected'])],['Fee month collected',H::money($d['collected_for_month'])],['Pending',H::money($d['pending'])],['Overdue',H::money($d['overdue'])],['Received in month',H::money($d['received_during_month'])],['Centre-wide expenses',H::money($d['expenses'])]] as $card)$body.='<article class="stat-card"><span>'.H::e($card[0]).'</span><strong>'.H::e($card[1]).'</strong></article>';
  $body.='</div><div class="panel section"><div class="panel-head"><h3>Payment transactions</h3><a href="/fees">Manage fees</a></div><div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Student</th><th>Fee month</th><th>Paid on</th><th>Amount</th><th>Method</th></tr></thead><tbody>';
  foreach($fees as $fee)$body.='<tr><td>#'.H::e($fee['id']).'</td><td>'.H::e($this->lookupName('students',$fee['student_id'])).'</td><td>'.H::date($fee['fee_month']).'</td><td>'.$this->paymentDateLink((string)$fee['paid_on']).'</td><td>'.H::money($fee['amount']).'</td><td>'.H::e($fee['method']).'</td></tr>';
  if(!$fees)$body.='<tr><td colspan="6" class="empty">No transactions for the selected filters.</td></tr>';
  $body.='</tbody></table></div></div>';if(($_GET['export']??'')==='csv')$this->exportCsv($month,$filters);Response::html($this->layout('Reports & Analytics',$body));
 }
 private function exportCsv(string $month,array $filters=[]):never {
  $rows=TuitionService::all('fees',['month'=>$month]+$filters);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="ucc-fees-'.$month.'.csv"');$out=fopen('php://output','w');fputcsv($out,['Receipt ID','Student ID','Student','Fee Month','Paid On (IST)','Amount','Method','Note']);
  foreach($rows as $r)fputcsv($out,[$r['id'],$r['student_id'],$this->lookupName('students',$r['student_id']),$r['fee_month'],$r['paid_on'],$r['amount'],$r['method'],$r['note']]);fclose($out);exit;
 }
 private function analytics():never {
  $month=$this->monthParam();$filters=['batch_id'=>$_GET['batch_id']??'','class_name'=>$_GET['class_name']??''];$data=AnalyticsService::overview($month,$filters);$totals=$data['totals'];
  $body='<form method="get" class="resource-filter-form"><div class="resource-filter-main"><label class="field"><span>Month</span><input type="month" name="month" value="'.H::e($month).'"></label><div class="filter-actions"><button class="btn secondary">Apply filters</button><a class="btn ghost" href="/analytics">Reset</a></div></div><details class="advanced-filter" open><summary>Advanced filters</summary><div class="filter-grid">'.$this->reportingFilterFields($filters).'</div></details></form><div class="toolbar section"><span class="pill blue">'.(int)$totals['classes'].' classes</span></div><div class="grid stats three">';
  foreach([['Students',number_format((int)$totals['students'])],['Student families',number_format((int)$totals['families'])],['Expected fees',H::money($totals['expected'])],['Collected',H::money($totals['collected'])],['Present marks',number_format((int)$totals['present'])],['Absent marks',number_format((int)$totals['absent'])]] as $card)$body.='<article class="stat-card"><span>'.H::e($card[0]).'</span><strong>'.H::e($card[1]).'</strong></article>';
  $body.='</div><section class="panel section"><div class="panel-head"><h3>Class-wise performance</h3><span class="muted">Fee month '.H::e(date('F Y',strtotime($month.'-01'))).'</span></div><div class="table-wrap"><table><thead><tr><th>Class</th><th>Families</th><th>Students</th><th>Expected fees</th><th>Collected</th><th>Present</th><th>Absent</th><th>Leave</th><th>Attendance rate</th></tr></thead><tbody>';
  foreach($data['classes'] as $r){$marked=(int)$r['present']+(int)$r['absent']+(int)$r['leave'];$rate=$marked>0?round(((int)$r['present']/$marked)*100):0;$body.='<tr><td><strong>'.H::e($r['class_name']).'</strong></td><td>'.(int)$r['families'].'</td><td>'.(int)$r['students'].'</td><td>'.H::money($r['expected']).'</td><td>'.H::money($r['collected']).'</td><td>'.(int)$r['present'].'</td><td>'.(int)$r['absent'].'</td><td>'.(int)$r['leave'].'</td><td><div class="rate"><span style="width:'.$rate.'%"></span></div><small class="subline">'.$rate.'%</small></td></tr>';}
  if(!$data['classes'])$body.='<tr><td colspan="9" class="empty">No class data for the selected filters.</td></tr>';
  $body.='</tbody></table></div><p class="muted tiny">Attendance rate is based on recorded present, absent and leave marks for the selected calendar month.</p></section>';Response::html($this->layout('Student Analytics',$body));
 }
 private function reminders():never {
  $month=$this->monthParam();$filters=['batch_id'=>$_GET['batch_id']??'','class_name'=>$_GET['class_name']??''];$items=TuitionService::reminders($month,$filters);$pending=array_sum(array_map(static fn($s)=>(float)$s['pending_amount'],$items));
  $body='<form method="get" class="resource-filter-form"><div class="resource-filter-main"><label class="field"><span>Fee month</span><input type="month" name="month" value="'.H::e($month).'"></label><div class="filter-actions"><button class="btn secondary">Apply filters</button><a class="btn ghost" href="/reminders">Reset</a></div></div><details class="advanced-filter" open><summary>Advanced filters</summary><div class="filter-grid">'.$this->reportingFilterFields($filters).'</div></details></form><div class="grid stats three section"><article class="stat-card"><span>Students with balance</span><strong>'.number_format(count($items)).'</strong><small>Filtered students</small></article><article class="stat-card"><span>Total pending fees</span><strong>'.H::money($pending).'</strong><small>Amount to be collected</small></article><article class="stat-card"><span>Fee month</span><strong>'.H::e(date('M Y',strtotime($month.'-01'))).'</strong><small>Selected period</small></article></div><div class="table-wrap"><table><thead><tr><th>Student</th><th>Guardian phone</th><th>Fee</th><th>Paid</th><th>Pending</th><th>Due date</th><th>Reminder</th></tr></thead><tbody>';
  foreach($items as $s){$phone=preg_replace('/\D+/','',(string)$s['guardian_phone']);$phone=(strlen($phone)===10?'91':'').$phone;$message='Hello, fee reminder for '.$s['name'].'. Pending amount: INR '.number_format((float)$s['pending_amount'],0).' for '.date('M Y',strtotime($month.'-01')).'. Please clear it soon. - UCC';$url='https://wa.me/'.$phone.'?text='.rawurlencode($message);
   $body.='<tr><td><strong>'.H::e($s['name']).'</strong></td><td>'.H::e($s['guardian_phone']).'</td><td>'.H::money($s['monthly_fee']).'</td><td>'.H::money($s['paid_for_month']).'</td><td><strong>'.H::money($s['pending_amount']).'</strong></td><td>'.H::date($s['due_date']).($s['days_overdue']>0?'<small class="subline danger-text">'.$s['days_overdue'].' days overdue</small>':'').'</td><td>'.($phone?' <a class="btn whatsapp" target="_blank" rel="noopener" href="'.H::e($url).'">WhatsApp</a>':'<span class="muted">No phone</span>').'</td></tr>';
  }
  if(!$items)$body.='<tr><td class="empty" colspan="7">No pending balances match these filters.</td></tr>';
  $body.='</tbody></table></div><p class="muted tiny">WhatsApp opens a pre-filled draft. A person must review and press Send; no automatic message is sent.</p>';Response::html($this->layout('Fee Reminders',$body));
 }
 private function assistant():never {
  $month=$this->monthParam();$filters=['batch_id'=>$_GET['batch_id']??'','class_name'=>$_GET['class_name']??''];$d=TuitionService::dashboard($month,$filters);$question=trim((string)($_POST['question']??''));$answer='';
  if($_SERVER['REQUEST_METHOD']==='POST'){H::verifyCsrf();$q=strtolower($question);$answer=match(true){
   str_contains($q,'pending')||str_contains($q,'बाकी')=>'Selected month pending fees are '.H::money($d['pending']).', with '.(int)$d['unpaid_count'].' unpaid and '.(int)$d['partial_count'].' partially paid families.',
   str_contains($q,'overdue')||str_contains($q,'लेट')=>'Overdue balance is '.H::money($d['overdue']).' across '.(int)$d['overdue_count'].' families.',
   str_contains($q,'collection')||str_contains($q,'collected')||str_contains($q,'जमा')=>'Collected for the selected fee month: '.H::money($d['collected_for_month']).'. Payments received during the calendar month: '.H::money($d['received_during_month']).'.',
   str_contains($q,'admission')=>'New admissions in this month: '.(int)$d['new_admissions'].'.',
   default=>'For '.date('F Y',strtotime($month.'-01')).': expected '.H::money($d['expected']).', collected '.H::money($d['collected_for_month']).', pending '.H::money($d['pending']).', overdue '.H::money($d['overdue']).'. Ask about pending fees, overdue fees or collection.'
  };}
  $context=http_build_query(array_filter(['month'=>$month,'batch_id'=>$filters['batch_id'],'class_name'=>$filters['class_name']],static fn($v)=>$v!==''&&$v!==null));
  $body='<form method="get" class="resource-filter-form"><div class="resource-filter-main"><label class="field"><span>Fee month</span><input type="month" name="month" value="'.H::e($month).'"></label><div class="filter-actions"><button class="btn secondary">Apply filters</button><a class="btn ghost" href="/assistant">Reset</a></div></div><details class="advanced-filter" open><summary>Advanced filters</summary><div class="filter-grid">'.$this->reportingFilterFields($filters).'</div></details></form><div class="grid two section"><section class="panel"><p class="eyebrow">UCC FEE AGENT · RULE-BASED</p><h2>Ask about your tuition data</h2><p class="muted">This first web version uses deterministic MySQL calculations from the selected period and filters. It does not call an external AI service or modify financial records.</p><form method="post" action="/assistant?'.H::e($context).'">'.$this->csrfInput().'<label class="field"><span>Your question</span><textarea name="question" rows="3" placeholder="How much fee is pending this month?">'.H::e($question).'</textarea></label><button class="btn primary">Analyse</button></form>'.($answer?'<div class="answer"><strong>Analysis</strong><p>'.H::e($answer).'</p></div>':'').'</section><section class="panel"><div class="panel-head"><h3>Current month snapshot</h3></div><div class="insight-list"><div><span>Expected</span><strong>'.H::money($d['expected']).'</strong></div><div><span>Collected</span><strong>'.H::money($d['collected_for_month']).'</strong></div><div><span>Pending</span><strong>'.H::money($d['pending']).'</strong></div><div><span>Overdue</span><strong>'.H::money($d['overdue']).'</strong></div><div><span>Actual receiving</span><strong>'.H::money($d['received_during_month']).'</strong></div></div></section></div>';Response::html($this->layout('Fee Management Agent',$body));
 }
 private function reportingFilterFields(array $filters):string {
  $batches=[];foreach(TuitionService::all('batches') as $r)$batches[(string)$r['id']]=$r['name'];
  $classes=[];foreach(TuitionService::distinctValues('students','class_name') as $v)$classes[$v]=$v;
  return $this->filterSelect('batch_id','Batch',$batches,$filters['batch_id']??'').$this->filterSelect('class_name','Class',$classes,$filters['class_name']??'');
 }
private function paymentDateLink(?string $paidOn):string {
  if(!$paidOn)return '—';
  $date=substr($paidOn,0,10);
  return '<a class="payment-date-link" href="/receiving/'.H::e($date).'">'.H::dateTime($paidOn).'</a>';
 }
 private function lookupName(string $key,mixed $id):string {
  if(!$id)return '—';$row=TuitionService::find($key,(int)$id);return $row?(string)($row['name']??'—'):'—';
 }
 private function selectHtml(string $name,string $label,array $rows,mixed $selected,bool $required=false):string {
  $html='<label class="field"><span>'.H::e($label).'</span><select name="'.H::e($name).'"'.($required?' required':'').'><option value="">Select '.H::e($label).'</option>';
  foreach($rows as $r)$html.='<option value="'.H::e($r['id']).'"'.((string)$selected===(string)$r['id']?' selected':'').'>'.H::e($r['name']).'</option>';
  return $html.'</select></label>';
 }
 private function selectSimple(string $name,string $label,array $options,mixed $selected):string {
  $html='<label class="field"><span>'.H::e($label).'</span><select name="'.H::e($name).'">';
  foreach($options as $value=>$text)$html.='<option value="'.H::e($value).'"'.((string)$selected===(string)$value?' selected':'').'>'.H::e($text).'</option>';
  return $html.'</select></label>';
 }
 private function csrfInput():string{return '<input type="hidden" name="_csrf" value="'.H::e(H::csrf()).'">';}
 private static function csrfStatic():string{return '<input type="hidden" name="_csrf" value="'.H::e(H::csrf()).'">';}
 private function monthParam():string{$v=(string)($_GET['month']??date('Y-m'));return preg_match('/^\d{4}-\d{2}$/',$v)?$v:date('Y-m');}
 private function flashHtml(?array $flash):string {if(!$flash)return '';return '<div class="notice '.H::e($flash['type']).'">'.H::e($flash['message']).'</div>';}
 private function layout(string $title,string $content,bool $authed=true):string {
  $flash=$this->flashHtml(H::takeFlash());$user=Auth::user();
  $nav=[['/','Dashboard','⌂'],['/students','Students','♙'],['/fees','Fees','₹'],['/batches','Batches','▦'],['/teachers','Teachers','♧'],['/attendance','Attendance','✓'],['/enquiries','Enquiries','◇'],['/expenses','Expenses','↗'],['/reports','Reports','▤'],['/analytics','Analytics','◫'],['/reminders','Reminders','◷'],['/assistant','Fee Agent','✦'],['/action-logs','Activity Logs','≡']];
  $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.H::e($title).' · UCC Tuition Manager</title><link rel="stylesheet" href="/assets/app.css"></head><body>';
  if($authed){$html.='<div class="app-shell"><aside class="sidebar"><a class="brand" href="/"><span class="logo">U</span><span><strong>UCC</strong><small>Tuition Manager</small></span></a><div class="nav-caption">WORKSPACE</div><nav>';foreach($nav as [$url,$label,$icon])$html.='<a class="'.(($_SERVER['REQUEST_URI']??'/')===$url?'active':'').'" href="'.$url.'"><span class="nav-icon">'.$icon.'</span>'.H::e($label).'</a>';$html.='</nav><div class="sidebar-foot"><span class="online-dot"></span>MySQL web workspace</div></aside><main class="main"><header class="topbar"><div><p class="eyebrow">UNIQUE COACHING CLASSES</p><h1>'.H::e($title).'</h1></div><div class="top-actions"><span class="user-chip">'.H::e($user['name']??'Admin').'<i></i></span><form method="post" action="/logout">'.$this->csrfInput().'<button class="btn secondary small-btn">Sign out</button></form></div></header>'.$flash.$content.'<footer>UCC Tuition Manager Web · Separate MySQL workspace · <span data-current-year></span></footer></main></div>';}
  else{$html.=$flash.$content;}
  $html.='<script src="/assets/app.js" defer></script></body></html>';return $html;
 }
}