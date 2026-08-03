<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: onboarding workflow test requires savora_test\n"); exit(2);
}
putenv('SAVORA_SEED_DEMO=1');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../lib/services/registration_service.php';
require_once __DIR__ . '/../lib/services/partner_application_service.php';
require_once __DIR__ . '/../lib/admin_actions.php';

function onboarding_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$prefix = 'auth-flow-' . bin2hex(random_bytes(5));
$userIds = []; $applicationIds = ['restaurant' => [], 'driver' => []]; $keys = [];
$failure = null;
try {
    foreach (['index.php','login.php','register.php','register_customer.php','register_restaurant.php','register_driver.php','registration_result.php','forgot_password.php','reset_password.php'] as $route) onboarding_expect(is_file(__DIR__ . '/../' . $route), "Missing public route: {$route}");
    $super = $conn->query("SELECT u.id FROM users u JOIN admin_profiles ap ON ap.user_id=u.id WHERE u.role='admin' AND u.status='active' AND ap.privilege_level='super_admin' ORDER BY u.id LIMIT 1")->fetch_assoc();
    $superId = (int) ($super['id'] ?? 0); onboarding_expect($superId > 0, 'Super Admin seed is required.');

    $customerPassword = 'Strong-Customer-123!';
    $customer = registration_register_customer($conn, ['fullName'=>'Flow Customer','username'=>$prefix.'-customer','email'=>$prefix.'-customer@example.test','phone'=>'+1 555 011 1000','password'=>$customerPassword,'passwordConfirmation'=>$customerPassword,'deliveryAddress'=>'100 Flow Street','defaultDeliveryNotes'=>'Reception','acceptedTerms'=>true]);
    onboarding_expect(($customer['ok'] ?? false) === true, 'Customer must activate immediately.');
    $customerId = (int) $customer['data']['userId']; $userIds[] = $customerId;

    $restaurantPassword = 'Strong-Restaurant-123!';
    $restaurant = partner_submit_application($conn, 'restaurant', ['ownerName'=>'Flow Owner','username'=>$prefix.'-restaurant','email'=>$prefix.'-restaurant@example.test','phone'=>'+1 555 011 2000','password'=>$restaurantPassword,'passwordConfirmation'=>$restaurantPassword,'restaurantName'=>'Flow Kitchen','description'=>'Workflow restaurant','cuisine'=>'Vietnamese','address'=>'200 Flow Street','city'=>'Central City','restaurantPhone'=>'+1 555 011 2001','opensAt'=>'09:00','closesAt'=>'22:00','acceptedTerms'=>true]);
    onboarding_expect(($restaurant['ok'] ?? false) === true, 'Restaurant must submit as pending.');
    $restaurantApp = (int) $restaurant['data']['applicationId']; $applicationIds['restaurant'][] = $restaurantApp;
    onboarding_expect(partner_application_one($conn, 'SELECT id FROM users WHERE username=?', 's', [$prefix.'-restaurant']) === [], 'Pending Restaurant must have no user.');
    $key = 'flow-restaurant-' . bin2hex(random_bytes(5)); $keys[] = $key;
    $restaurantApproval = admin_execute_action($conn, 'approve_restaurant', ['application_id'=>$restaurantApp,'version'=>1,'reviewer_note'=>'Workflow approval'], $superId, $key);
    $restaurantRetry = admin_execute_action($conn, 'approve_restaurant', ['application_id'=>$restaurantApp,'version'=>1,'reviewer_note'=>'Workflow approval'], $superId, $key);
    onboarding_expect(($restaurantApproval['ok'] ?? false) === true && $restaurantApproval === $restaurantRetry, 'Restaurant approval must be idempotent.');
    $restaurantUser = (int) $restaurantApproval['data']['user_id']; $userIds[] = $restaurantUser;

    $driverPassword = 'Strong-Driver-123!';
    $driver = partner_submit_application($conn, 'driver', ['fullName'=>'Flow Driver','username'=>$prefix.'-driver','email'=>$prefix.'-driver@example.test','phone'=>'+1 555 011 3000','password'=>$driverPassword,'passwordConfirmation'=>$driverPassword,'city'=>'Central City','serviceArea'=>'Central District','vehicleType'=>'Motorcycle','vehicleModel'=>'Honda Wave','licensePlate'=>'FLOW-3000','vehicleColor'=>'Black','acceptedTerms'=>true]);
    onboarding_expect(($driver['ok'] ?? false) === true, 'Driver must submit without files.');
    $driverApp = (int) $driver['data']['applicationId']; $applicationIds['driver'][] = $driverApp;
    $key = 'flow-driver-' . bin2hex(random_bytes(5)); $keys[] = $key;
    $driverApproval = admin_execute_action($conn, 'approve_driver', ['application_id'=>$driverApp,'version'=>1,'reviewer_note'=>'Workflow approval'], $superId, $key);
    onboarding_expect(($driverApproval['ok'] ?? false) === true, 'Driver approval must create an account.');
    $driverUser = (int) $driverApproval['data']['user_id']; $userIds[] = $driverUser;

    $adminPassword = 'Strong-Admin-123!'; $key = 'flow-admin-' . bin2hex(random_bytes(5)); $keys[] = $key;
    $admin = admin_provision_account($conn, ['full_name'=>'Flow Admin','username'=>$prefix.'-admin','email'=>$prefix.'-admin@example.test','phone'=>'+1 555 011 4000','password'=>$adminPassword,'password_confirmation'=>$adminPassword,'privilege_level'=>'admin'], $superId, $key);
    onboarding_expect(($admin['ok'] ?? false) === true, 'Super Admin must create a normal Admin.');
    $adminUser = (int) $admin['data']['user_id']; $userIds[] = $adminUser;
    $forbidden = admin_provision_account($conn, ['full_name'=>'Blocked Admin','username'=>$prefix.'-blocked','email'=>$prefix.'-blocked@example.test','phone'=>'+1 555 011 4001','password'=>$adminPassword,'password_confirmation'=>$adminPassword,'privilege_level'=>'admin'], $adminUser, 'flow-forbidden-'.bin2hex(random_bytes(5)));
    onboarding_expect(($forbidden['status'] ?? 0) === 403, 'Normal Admin must not provision another Admin.');

    $credentials = [[$customerId,$customerPassword,'customer'],[$restaurantUser,$restaurantPassword,'restaurant'],[$driverUser,$driverPassword,'driver'],[$adminUser,$adminPassword,'admin']];
    foreach ($credentials as [$id,$password,$role]) { $row = partner_application_one($conn, 'SELECT password,role,status FROM users WHERE id=?', 'i', [$id]); onboarding_expect($row['role'] === $role && $row['status'] === 'active' && password_verify($password, $row['password']), "{$role} login credentials must be valid."); }

    savora_start_session(); $_SESSION = ['user_id'=>$customerId,'role'=>'customer','session_version'=>1]; savora_register_user_session($conn, $customerId); $sessionHash = savora_session_hash(); savora_revoke_current_session($conn);
    $revoked = partner_application_one($conn, 'SELECT revoked_at FROM user_sessions WHERE user_id=? AND session_hash=?', 'is', [$customerId,$sessionHash]);
    onboarding_expect(($revoked['revoked_at'] ?? null) !== null, 'Logout must revoke the current server session.');
    savora_end_session();
} catch (Throwable $exception) { $failure = $exception; }
finally {
    if ($keys !== []) { $p=implode(',',array_fill(0,count($keys),'?'));$t=str_repeat('s',count($keys));$s=$conn->prepare("DELETE FROM idempotency_keys WHERE idempotency_key IN ({$p})");$s->bind_param($t,...$keys);$s->execute();$s->close(); }
    if ($userIds !== []) { $list=implode(',',array_map('intval',$userIds));$conn->query("DELETE FROM notification_outbox WHERE notification_id IN (SELECT id FROM notifications WHERE user_id IN ({$list}))");$conn->query("DELETE FROM notifications WHERE user_id IN ({$list})");$conn->query("DELETE FROM user_sessions WHERE user_id IN ({$list})");$conn->query("DELETE FROM restaurant_weekly_hours WHERE restaurant_id IN (SELECT id FROM restaurants WHERE owner_user_id IN ({$list}))");$conn->query("DELETE FROM restaurants WHERE owner_user_id IN ({$list})");$conn->query("DELETE FROM driver_profiles WHERE user_id IN ({$list})");$conn->query("DELETE FROM customer_profiles WHERE user_id IN ({$list})");$conn->query("DELETE FROM identity_claims WHERE owner_kind='user' AND owner_id IN ({$list})");$conn->query("DELETE FROM admin_profiles WHERE user_id IN ({$list})");$conn->query("DELETE FROM users WHERE id IN ({$list})"); }
    foreach($applicationIds as $type=>$ids) if($ids!==[]){$list=implode(',',array_map('intval',$ids));$conn->query("DELETE FROM identity_claims WHERE owner_kind='{$type}_application' AND owner_id IN ({$list})");$conn->query("DELETE FROM {$type}_applications WHERE id IN ({$list})");}
    $conn->close();
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "PASS: cross-role onboarding, approval, credential routing, Admin authorization, and logout revocation work together\n";
