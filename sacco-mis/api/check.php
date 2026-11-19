<?php
// Open http://localhost/sacco-mis/api/check.php : tests PHP, MySQL connection, a real write, and sessions.
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1'); error_reporting(E_ALL);
echo "PHP " . PHP_VERSION . "\n";
echo "pdo_mysql extension: " . (extension_loaded('pdo_mysql') ? 'yes' : 'NO - enable it in php.ini') . "\n";
try {
  $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  echo "Connected to database '".DB_NAME."' as ".DB_USER."\n";
  foreach (['member','staff_user','savings_transaction','loan','repayment','audit_log','guarantor'] as $t)
    echo "  table $t: ".$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()." rows\n";
  $db->exec("INSERT INTO member (member_id, full_name, phone, village, join_date) VALUES ('M999','Write test','000','test',CURDATE())");
  echo "Test INSERT into member worked.\n";
  $db->exec("DELETE FROM member WHERE member_id='M999'");
  echo "Test row removed.\n";
  $u = $db->query("SELECT password_hash FROM staff_user WHERE username='teller1'")->fetchColumn();
  echo "teller1 password check: " . ($u && password_verify('sacco123', $u) ? 'OK' : 'FAILED - re-import database/seed.sql') . "\n";
} catch (Throwable $e) { echo "PROBLEM: ".$e->getMessage()."\n"; }
$sdir = __DIR__ . '/sessions'; if (!is_dir($sdir)) @mkdir($sdir, 0777, true);
echo "sessions folder writable: " . (is_writable($sdir) ? 'yes' : 'NO') . "\n";
echo "\nIf every line above says OK/worked, the database side is fine.\n";
