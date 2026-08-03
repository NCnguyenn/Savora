<?php
declare(strict_types=1);
if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') { fwrite(STDERR,"BLOCKED: support integration tests require savora_test\n"); exit(2); }
require_once __DIR__ . '/../lib/services/support_service.php'; require_once __DIR__ . '/support/test_database.php';
function support_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$conn=null;$prefix='task24-support-'.bin2hex(random_bytes(5));$orderId=0;$caseId=0;
try {
    $conn=savora_test_database(); $customer=(int)$conn->query("SELECT id FROM users WHERE role='customer' LIMIT 1")->fetch_assoc()['id']; $restaurant=(int)$conn->query('SELECT id FROM restaurants LIMIT 1')->fetch_assoc()['id']; $restaurantOwner=(int)$conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'];
    $s=$conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total) VALUES(?,?,?,'preparing','cash',10,10)");$ref=strtoupper($prefix);$s->bind_param('sii',$ref,$customer,$restaurant);$s->execute();$orderId=(int)$s->insert_id;$s->close();
    $opened=support_open_case($conn,$customer,'customer',$orderId,null,'delivery_issue','Task24 issue','My order needs help',$prefix.'-open'); support_expect(($opened['ok']??false)===true,'Customer should open an owned order case: '.json_encode($opened));$caseId=(int)$opened['data']['caseId'];
    $denied=support_open_case($conn,$restaurantOwner,'restaurant',$orderId,null,'delivery_issue','Task24 issue','Restaurant note',$prefix.'-denied');support_expect(($denied['ok']??true)===false,'Restaurant owner cannot report as Customer.');
    $message=support_add_message($conn,$customer,'customer',$caseId,'Additional details',1,$prefix.'-message');support_expect(($message['ok']??false)===true,'Case owner should add a message: '.json_encode($message)); echo "PASS: role-scoped support cases hold\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
finally{if($conn instanceof mysqli){if($caseId>0){$s=$conn->prepare('DELETE FROM case_messages WHERE case_id=?');$s->bind_param('i',$caseId);$s->execute();$s->close();$s=$conn->prepare('DELETE FROM support_cases WHERE id=?');$s->bind_param('i',$caseId);$s->execute();$s->close();}$s=$conn->prepare('DELETE FROM orders WHERE id=?');$s->bind_param('i',$orderId);$s->execute();$s->close();$conn->close();}}
