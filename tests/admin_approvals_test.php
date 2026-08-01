<?php
declare(strict_types=1);
putenv('SAVORA_SEED_DEMO=1');
putenv('SAVORA_DB_NAME='.(getenv('SAVORA_DB_NAME')?:'savora_test'));
require_once __DIR__.'/../db.php'; require_once __DIR__.'/../lib/admin_security.php'; require_once __DIR__.'/../lib/admin_actions.php';
$actor=(int)$conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'];

function assert_true(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function create_documents(mysqli $conn,string $table,int $id,array $types):void{$stmt=$conn->prepare("INSERT INTO {$table} (application_id,document_type,stored_path,mime_type,verification_status,expires_at) VALUES (?,?,'tests/demo.pdf','application/pdf','verified',DATE_ADD(NOW(),INTERVAL 1 YEAR))");foreach($types as $type){$stmt->bind_param('is',$id,$type);$stmt->execute();}$stmt->close();}

$conn->query("DELETE FROM users WHERE username IN ('approval-restaurant-test','approval-driver-test')");
$conn->query("DELETE FROM restaurant_applications WHERE reference_code='RA-INTEGRATION-TEST'");
$hash=password_hash('123456',PASSWORD_DEFAULT);
$stmt=$conn->prepare("INSERT INTO restaurant_applications(reference_code,username,password_hash,owner_name,owner_email,restaurant_name,cuisine,city,address,status,risk_level) VALUES('RA-INTEGRATION-TEST','approval-restaurant-test',?,'Approval Owner','approval.restaurant@test.local','Approval Kitchen','Test','Central City','Test address','pending','low')");$stmt->bind_param('s',$hash);$stmt->execute();$restaurantApp=$stmt->insert_id;$stmt->close();create_documents($conn,'restaurant_application_documents',$restaurantApp,['business_registration','food_safety_certificate','owner_identity']);
$key='approve-restaurant-'.bin2hex(random_bytes(5));$payload=['application_id'=>$restaurantApp,'version'=>1,'reviewer_note'=>'Documents verified'];$first=admin_execute_action($conn,'approve_restaurant',$payload,$actor,$key);$retry=admin_execute_action($conn,'approve_restaurant',$payload,$actor,$key);assert_true(($first['ok']??false)&&$first===$retry,'Restaurant approval must be successful and idempotent.');
$restaurantUser=(int)$conn->query("SELECT COUNT(*) AS total FROM users WHERE username='approval-restaurant-test' AND role='restaurant'")->fetch_assoc()['total'];$credential=$conn->query("SELECT password_hash,status FROM restaurant_applications WHERE id={$restaurantApp}")->fetch_assoc();assert_true($restaurantUser===1&&$credential['password_hash']===null&&$credential['status']==='approved','Restaurant approval must create one account and consume credentials.');

$conn->query("DELETE FROM driver_applications WHERE reference_code='DA-INTEGRATION-TEST'");
$stmt=$conn->prepare("INSERT INTO driver_applications(reference_code,username,password_hash,full_name,email,city,vehicle_type,vehicle_model,license_plate,service_area,status,risk_level) VALUES('DA-INTEGRATION-TEST','approval-driver-test',?,'Approval Driver','approval.driver@test.local','Central City','Motorbike','Test Bike','TEST-01','Central District','pending','low')");$stmt->bind_param('s',$hash);$stmt->execute();$driverApp=$stmt->insert_id;$stmt->close();create_documents($conn,'driver_application_documents',$driverApp,['driver_license','vehicle_registration','background_check']);
$driver=admin_execute_action($conn,'approve_driver',['application_id'=>$driverApp,'version'=>1,'reviewer_note'=>'Identity and eligibility verified'],$actor,'approve-driver-'.bin2hex(random_bytes(5)));assert_true(($driver['ok']??false),'Driver approval must succeed.');$driverUser=(int)$conn->query("SELECT COUNT(*) AS total FROM users WHERE username='approval-driver-test' AND role='driver'")->fetch_assoc()['total'];assert_true($driverUser===1,'Driver approval must create exactly one Driver account.');
echo "PASS: Restaurant and Driver approvals create one account only after verified documents\n";
