<?php
// JSON API: every change made in the web page is written to the MySQL "sacco" database here.
require __DIR__ . '/config.php';
// Never let PHP warnings break the JSON; report every error as readable JSON instead.
ini_set('display_errors', '0');
ob_start();
function jsonError($msg, $code = 500) {
  while (ob_get_level()) ob_end_clean();
  if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
  echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}
set_exception_handler(function ($e) { jsonError('Server error: '.$e->getMessage()); });
set_error_handler(function ($no, $str, $file, $line) { if (error_reporting() & $no) throw new ErrorException($str, 0, $no, $file, $line); return true; });
// Use our own session folder if XAMPP's default one is not writable
$sdir = __DIR__ . '/sessions';
if (!is_dir($sdir)) @mkdir($sdir, 0777, true);
if (is_dir($sdir) && is_writable($sdir)) session_save_path($sdir);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ROLE_OUT = ['teller'=>'Teller','loans_officer'=>'Loans officer','manager'=>'Manager','member'=>'Member','accountant'=>'Accountant'];
const CHAN_IN  = ['Cash'=>'cash','MTN MoMo'=>'mtn_momo','Airtel Money'=>'airtel_money'];
const CHAN_OUT = ['cash'=>'Cash','mtn_momo'=>'MTN MoMo','airtel_money'=>'Airtel Money'];

function out($d, $code = 200) { while (ob_get_level()) ob_end_clean(); http_response_code($code); echo json_encode($d); exit; }
function fail($m, $code = 400) { out(['ok'=>false,'error'=>$m], $code); }

try {
  $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
  fail('Cannot connect to MySQL database "'.DB_NAME.'". Start MySQL in XAMPP and import database/schema.sql and seed.sql. ('.$e->getMessage().')', 500);
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = $_GET['action'] ?? ($in['action'] ?? 'state');

function me() { return $_SESSION['user'] ?? null; }
function need(array $roles) {
  $u = me();
  if (!$u || !in_array($u['role'], $roles, true)) fail('You are not allowed to do that.', 403);
  return $u;
}
function audit(PDO $db, $uid, $text) {
  if (!$uid) return;
  $db->prepare('INSERT INTO audit_log (user_id, action, logged_at) VALUES (?,?,?)')->execute([$uid, $text, date('Y-m-d H:i:s')]);
}
function nextId(PDO $db, $table, $col, $prefix) {
  $max = 0;
  foreach ($db->query("SELECT $col AS id FROM $table") as $r) $max = max($max, (int)substr($r['id'], 1));
  return $prefix . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
}
function savingsOf(PDO $db, $mid) {
  $s = $db->prepare("SELECT COALESCE(SUM(CASE WHEN txn_type='deposit' THEN amount ELSE -amount END),0) AS b FROM savings_transaction WHERE member_id=?");
  $s->execute([$mid]); return (float)$s->fetch()['b'];
}
function loanTotal($l) { return $l['amount'] * (1 + $l['interest_rate']/100 * $l['months'] / 12); }
function loanPaid(PDO $db, $id) {
  $s = $db->prepare('SELECT COALESCE(SUM(amount),0) AS p FROM repayment WHERE loan_id=?'); $s->execute([$id]); return (float)$s->fetch()['p'];
}
function fmt($n) { return 'UGX ' . number_format(round($n)); }

function state(PDO $db) {
  $members = [];
  foreach ($db->query("SELECT m.member_id, m.full_name, m.phone, m.village,
      COALESCE((SELECT SUM(CASE WHEN txn_type='deposit' THEN amount ELSE -amount END) FROM savings_transaction s WHERE s.member_id=m.member_id),0) AS sav
      FROM member m ORDER BY m.member_id") as $r)
    $members[] = ['id'=>$r['member_id'],'name'=>$r['full_name'],'phone'=>$r['phone'],'village'=>$r['village'] ?? '','sav'=>(float)$r['sav']];
  $loans = [];
  foreach ($db->query("SELECT l.*, COALESCE((SELECT SUM(amount) FROM repayment r WHERE r.loan_id=l.loan_id),0) AS paid FROM loan l ORDER BY l.loan_id") as $r)
    $loans[] = ['id'=>$r['loan_id'],'m'=>$r['member_id'],'amt'=>(float)$r['amount'],'months'=>(int)$r['months'],'rate'=>$r['interest_rate']/100,
      'st'=>$r['status'],'paid'=>(float)$r['paid'],'due'=>$r['next_due'] ?? '','purpose'=>$r['purpose'] ?? ''];
  $tx = [];
  foreach ($db->query("SELECT member_id, txn_type, amount, txn_date FROM savings_transaction") as $r)
    $tx[] = ['t'=>$r['txn_date'],'d'=>substr($r['txn_date'],0,10),'m'=>$r['member_id'],'type'=>ucfirst($r['txn_type']),'a'=>(float)$r['amount']];
  foreach ($db->query("SELECT l.member_id, r.channel, r.amount, r.paid_on FROM repayment r JOIN loan l ON l.loan_id=r.loan_id") as $r)
    $tx[] = ['t'=>$r['paid_on'],'d'=>substr($r['paid_on'],0,10),'m'=>$r['member_id'],'type'=>'Repayment ('.CHAN_OUT[$r['channel']].')','a'=>(float)$r['amount']];
  usort($tx, fn($a,$b)=>strcmp($b['t'],$a['t']));
  $audit = [];
  foreach ($db->query("SELECT a.logged_at, u.username, u.role, a.action FROM audit_log a JOIN staff_user u ON u.user_id=a.user_id ORDER BY a.logged_at DESC, a.log_id DESC LIMIT 200") as $r)
    $audit[] = $r['logged_at'].' - '.$r['username'].' ('.(ROLE_OUT[$r['role']] ?? $r['role']).') - '.$r['action'];
  $u = me();
  return ['ok'=>true,'members'=>$members,'loans'=>$loans,'tx'=>$tx,'audit'=>$audit,
    'user'=>$u ? ['name'=>$u['name'],'role'=>ROLE_OUT[$u['role']] ?? $u['role'],'mid'=>$u['mid']] : null];
}

switch ($act) {
case 'state': out(state($db));

case 'login':
  $name = strtolower(trim($in['username'] ?? '')); $pw = $in['password'] ?? '';
  $s = $db->prepare('SELECT * FROM staff_user WHERE LOWER(username)=?'); $s->execute([$name]); $u = $s->fetch();
  if ($u && password_verify($pw, $u['password_hash'])) {
    $_SESSION['user'] = ['id'=>(int)$u['user_id'],'name'=>$u['username'],'role'=>$u['role'],'mid'=>$u['member_id']];
  } else {
    // A Member ID (e.g. M002) can also log in as a member with the shared prototype password
    $m = $db->prepare('SELECT member_id FROM member WHERE LOWER(member_id)=?'); $m->execute([$name]); $m = $m->fetch();
    if (!$m || $pw !== 'sacco123') fail('Wrong username or password.', 401);
    $_SESSION['user'] = ['id'=>0,'name'=>$m['member_id'],'role'=>'member','mid'=>$m['member_id']];
  }
  audit($db, $_SESSION['user']['id'], 'Signed in');
  out(state($db));

case 'logout':
  if (me()) audit($db, me()['id'], 'Signed out');
  $_SESSION = []; session_destroy();
  out(['ok'=>true]);

case 'register':
  $u = need(['teller','manager']);
  $n = trim($in['name'] ?? ''); $p = trim($in['phone'] ?? ''); $v = trim($in['village'] ?? '');
  if ($n === '' || $p === '') fail('Enter the member name and phone number.');
  $id = nextId($db, 'member', 'member_id', 'M');
  $db->prepare('INSERT INTO member (member_id, full_name, phone, village, join_date) VALUES (?,?,?,?,?)')->execute([$id,$n,$p,$v,date('Y-m-d')]);
  audit($db, $u['id'], 'Registered member '.$id);
  $r = state($db); $r['message'] = "$n registered as $id."; out($r);

case 'saving':
  $u = need(['teller']);
  $mid = $in['member'] ?? ''; $a = (float)($in['amount'] ?? 0); $type = ($in['type'] ?? '') === 'withdrawal' ? 'withdrawal' : 'deposit';
  if (!($a > 0)) fail('Enter an amount greater than zero.');
  $m = $db->prepare('SELECT full_name FROM member WHERE member_id=?'); $m->execute([$mid]); $m = $m->fetch();
  if (!$m) fail('Member not found.');
  $db->beginTransaction();
  $bal = savingsOf($db, $mid);
  if ($type === 'withdrawal' && $a > $bal) { $db->rollBack(); fail($m['full_name'].' has only '.fmt($bal).' available.'); }
  $db->prepare('INSERT INTO savings_transaction (member_id, txn_type, amount, txn_date, recorded_by) VALUES (?,?,?,?,?)')
     ->execute([$mid,$type,$a,date('Y-m-d H:i:s'),$u['id']]);
  audit($db, $u['id'], ucfirst($type).' '.fmt($a).' for '.$mid);
  $db->commit();
  $new = $bal + ($type === 'deposit' ? $a : -$a);
  $r = state($db); $r['message'] = ucfirst($type).' of '.fmt($a).' recorded. New balance '.fmt($new).'.'; out($r);

case 'applyLoan':
  $u = need(['loans_officer']);
  $mid = $in['member'] ?? ''; $a = (float)($in['amount'] ?? 0); $t = (int)($in['months'] ?? 0); $purpose = trim($in['purpose'] ?? '') ?: 'Not stated';
  if (!($a > 0) || !($t > 0)) fail('Enter the loan amount and the number of months.');
  $m = $db->prepare('SELECT full_name FROM member WHERE member_id=?'); $m->execute([$mid]); $m = $m->fetch();
  if (!$m) fail('Member not found.');
  $sav = savingsOf($db, $mid);
  if ($a > 3 * $sav) fail('Not allowed: '.$m['full_name'].' can borrow up to '.fmt(3*$sav).' (3 times savings).');
  $id = nextId($db, 'loan', 'loan_id', 'L');
  $db->prepare("INSERT INTO loan (loan_id, member_id, amount, interest_rate, months, purpose, status, next_due, approved_by) VALUES (?,?,?,?,?,?, 'pending', NULL, NULL)")
     ->execute([$id,$mid,$a,12,$t,$purpose]);
  audit($db, $u['id'], "Application $id for $mid");
  $r = state($db); $r['message'] = "Application $id submitted for manager approval."; out($r);

case 'decide':
  $u = need(['manager']);
  $id = $in['loan'] ?? ''; $ok = !empty($in['approve']);
  $l = $db->prepare('SELECT * FROM loan WHERE loan_id=?'); $l->execute([$id]); $l = $l->fetch();
  if (!$l || $l['status'] !== 'pending') fail('Only pending applications can be approved or rejected.');
  $db->prepare('UPDATE loan SET status=?, next_due=?, approved_by=? WHERE loan_id=?')
     ->execute([$ok?'approved':'rejected', $ok ? date('Y-m-d', strtotime('+30 days')) : null, $ok ? $u['id'] : null, $id]);
  audit($db, $u['id'], ($ok?'Approved':'Rejected')." loan $id");
  $r = state($db); $r['message'] = "$id ".($ok?'approved':'rejected').'.'; out($r);

case 'repay':
  $u = need(['loans_officer']);
  $id = $in['loan'] ?? ''; $a = (float)($in['amount'] ?? 0); $chName = $in['channel'] ?? 'Cash'; $ref = trim($in['reference'] ?? '');
  if (!isset(CHAN_IN[$chName])) fail('Unknown payment channel.');
  $l = $db->prepare('SELECT * FROM loan WHERE loan_id=?'); $l->execute([$id]); $l = $l->fetch();
  if (!$l || $l['status'] !== 'approved') fail('No active loans to repay.');
  if (!($a > 0)) fail('Enter the repayment amount.');
  $db->beginTransaction();
  $total = loanTotal($l); $paid = loanPaid($db, $id); $bal = $total - $paid;
  if ($a > $bal + 1) { $db->rollBack(); fail('Amount is more than the balance of '.fmt($bal).'.'); }
  if ($chName !== 'Cash' && $ref === '') { $db->rollBack(); fail('Enter the mobile money reference.'); }
  $db->prepare('INSERT INTO repayment (loan_id, amount, channel, reference, paid_on, recorded_by) VALUES (?,?,?,?,?,?)')
     ->execute([$id,$a,CHAN_IN[$chName],$ref ?: null,date('Y-m-d H:i:s'),$u['id']]);
  $inst = $total / $l['months']; $due = $l['next_due']; $status = 'approved';
  if ($a >= $inst - 1 && $due) $due = date('Y-m-d', strtotime($due.' +30 days'));
  if ($bal - $a < 1) { $status = 'closed'; $due = null; }
  $db->prepare('UPDATE loan SET next_due=?, status=? WHERE loan_id=?')->execute([$due,$status,$id]);
  audit($db, $u['id'], 'Repayment '.fmt($a)." on $id via $chName");
  $db->commit();
  $r = state($db); $r['message'] = 'Repayment of '.fmt($a)." recorded on $id."; out($r);

default: fail('Unknown action.');
}
