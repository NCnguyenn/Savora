<?php
declare(strict_types=1);
function support_repository_one(mysqli $conn,string $sql,string $types='',array $params=[]):array{$s=$conn->prepare($sql);if($types!=='')$s->bind_param($types,...$params);$s->execute();$r=$s->get_result()->fetch_assoc()?:[];$s->close();return$r;}
function support_repository_case(mysqli $conn,int $caseId,bool $forUpdate=false):array{$sql='SELECT * FROM support_cases WHERE id=? LIMIT 1';if($forUpdate)$sql.=' FOR UPDATE';return support_repository_one($conn,$sql,'i',[$caseId]);}
function support_repository_order_scope(mysqli $conn,int $orderId):array{return support_repository_one($conn,'SELECT o.id,o.reference_code,o.customer_user_id,r.owner_user_id,d.driver_user_id,d.status AS delivery_status,d.superseded_at FROM orders o JOIN restaurants r ON r.id=o.restaurant_id LEFT JOIN deliveries d ON d.order_id=o.id AND d.superseded_at IS NULL WHERE o.id=? ORDER BY d.id DESC LIMIT 1','i',[$orderId]);}
