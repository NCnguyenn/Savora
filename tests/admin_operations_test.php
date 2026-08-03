<?php
declare(strict_types=1);putenv('SAVORA_SEED_DEMO=1');putenv('SAVORA_DB_NAME='.(getenv('SAVORA_DB_NAME')?:'savora_test'));require_once __DIR__.'/../db.php';require_once __DIR__.'/../lib/admin_security.php';require_once __DIR__.'/../lib/admin_actions.php';
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function cleanup_test_orders(mysqli $conn, string $where): void
{
    $ids = [];
    $rows = $conn->query("SELECT id FROM orders WHERE {$where}");
    while ($row = $rows->fetch_assoc()) $ids[] = (int) $row['id'];
    if ($ids === []) return;
    $list = implode(',', $ids);
    $conn->query("DELETE FROM case_messages WHERE case_id IN (SELECT id FROM support_cases WHERE order_id IN ({$list}))");
    $conn->query("DELETE FROM support_cases WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM refunds WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM wallet_transactions WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM ledger_entries WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM delivery_evidence WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN ({$list}))");
    $conn->query("DELETE FROM delivery_milestones WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN ({$list}))");
    $conn->query("DELETE FROM delivery_offers WHERE dispatch_id IN (SELECT id FROM delivery_dispatches WHERE order_id IN ({$list}))");
    $conn->query("DELETE FROM deliveries WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM delivery_dispatches WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM payments WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM order_status_history WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM order_items WHERE order_id IN ({$list})");
    $conn->query("DELETE FROM orders WHERE id IN ({$list})");
}
$actor=(int)$conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'];$customer=(int)$conn->query("SELECT id FROM users WHERE role='customer' LIMIT 1")->fetch_assoc()['id'];$restaurant=(int)$conn->query("SELECT id FROM restaurants LIMIT 1")->fetch_assoc()['id'];
cleanup_test_orders($conn, "reference_code IN ('OPS-CANCEL-TEST','OPS-REFUND-TEST','OPS-DISPATCH-TEST')");
$stmt=$conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total) VALUES('OPS-CANCEL-TEST',?,?,'preparing','card',20,20)");$stmt->bind_param('ii',$customer,$restaurant);$stmt->execute();$cancelId=$stmt->insert_id;$stmt->close();$key='ops-cancel-'.bin2hex(random_bytes(5));$cancelPayload=['order_id'=>$cancelId,'version'=>1,'reason'=>'Automated cancellation safeguard test'];$cancel=admin_execute_action($conn,'cancel_order',$cancelPayload,$actor,$key);$retry=admin_execute_action($conn,'cancel_order',$cancelPayload,$actor,$key);ensure(($cancel['ok']??false)&&$cancel===$retry,'Cancellation must be transactional and idempotent.');
$stale=admin_execute_action($conn,'open_incident',$cancelPayload,$actor,'ops-stale-'.bin2hex(random_bytes(5)));ensure(($stale['ok']??true)===false,'Operational actions must reject stale record versions.');
$stmt=$conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total) VALUES('OPS-REFUND-TEST',?,?,'delivered','card',50,50)");$stmt->bind_param('ii',$customer,$restaurant);$stmt->execute();$orderId=$stmt->insert_id;$stmt->close();$conn->query("INSERT INTO payments(order_id,method,amount,status,paid_at) VALUES({$orderId},'card',50,'paid',NOW())");$caseRef='CASE-OPS-'.bin2hex(random_bytes(3));$stmt=$conn->prepare("INSERT INTO support_cases(reference_code,order_id,case_type,reporting_role,reporting_user_id,priority,status,subject) VALUES(?,?,'refund_request','customer',?,'medium','open','Integration refund')");$stmt->bind_param('sii',$caseRef,$orderId,$customer);$stmt->execute();$caseId=$stmt->insert_id;$stmt->close();
$partial=admin_execute_action($conn,'issue_refund',['case_id'=>$caseId,'version'=>1,'amount'=>10,'destination'=>'original_payment','reason'=>'Verified partial refund'], $actor,'ops-refund-'.bin2hex(random_bytes(5)));ensure(($partial['ok']??false),'Partial refund must succeed.');$status=$conn->query("SELECT status FROM orders WHERE id={$orderId}")->fetch_assoc()['status'];ensure($status==='delivered','Partial refund must preserve fulfillment status.');$excess=admin_execute_action($conn,'issue_refund',['case_id'=>$caseId,'version'=>2,'amount'=>60,'reason'=>'Invalid excess'], $actor,'ops-excess-'.bin2hex(random_bytes(5)));ensure(($excess['ok']??true)===false,'Refund above remaining paid amount must fail.');
$conn->query("DELETE FROM orders WHERE reference_code='OPS-DISPATCH-TEST'");$stmt=$conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total) VALUES('OPS-DISPATCH-TEST',?,?,'ready_for_pickup','cash',15,15)");$stmt->bind_param('ii',$customer,$restaurant);$stmt->execute();$dispatchOrderId=$stmt->insert_id;$stmt->close();$driverId=(int)$conn->query("SELECT u.id FROM users u JOIN driver_profiles d ON d.user_id=u.id WHERE u.status='active' AND d.eligibility_status='eligible' LIMIT 1")->fetch_assoc()['id'];$assignment=admin_execute_action($conn,'reassign_driver',['order_id'=>$dispatchOrderId,'driver_user_id'=>$driverId,'version'=>1,'reason'=>'Integration dispatch assignment'],$actor,'ops-dispatch-'.bin2hex(random_bytes(5)));ensure(($assignment['ok']??false),'Eligible Driver reassignment must succeed.');$delivery=$conn->query("SELECT driver_user_id,status FROM deliveries WHERE order_id={$dispatchOrderId}")->fetch_assoc();ensure((int)($delivery['driver_user_id']??0)===$driverId&&$delivery['status']==='assigned','Reassignment must materialize the Driver delivery.');
echo "PASS: versioned cancellation, refunds and Driver assignment preserve operational integrity\n";
