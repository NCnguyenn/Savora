<?php
declare(strict_types=1);
if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') { fwrite(STDERR,"BLOCKED: commercial integration tests require savora_test\n"); exit(2); }
require_once __DIR__ . '/../lib/services/commercial_service.php'; require_once __DIR__ . '/support/test_database.php';
function commercial_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$conn=null;try{$conn=savora_test_database();$customer=(int)$conn->query("SELECT id FROM users WHERE role='customer' LIMIT 1")->fetch_assoc()['id'];$restaurant=(int)$conn->query('SELECT id FROM restaurants LIMIT 1')->fetch_assoc()['id'];$rules=commercial_active_rules($conn,$restaurant,$customer,new DateTimeImmutable('now'));commercial_expect(isset($rules['maintenanceMode'],$rules['feeRule'],$rules['serviceAreas']),'Commercial resolver should return bounded server rules.');echo "PASS: commercial rule resolver holds\n";}catch(Throwable$e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}finally{if($conn instanceof mysqli)$conn->close();}
