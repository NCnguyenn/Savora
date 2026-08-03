<?php
declare(strict_types=1);
function commercial_repository_one(mysqli $conn,string $sql,string $types='',array $params=[]):array{$s=$conn->prepare($sql);if($types!=='')$s->bind_param($types,...$params);$s->execute();$r=$s->get_result()->fetch_assoc()?:[];$s->close();return$r;}
function commercial_repository_rows(mysqli $conn,string $sql,string $types='',array $params=[]):array{$s=$conn->prepare($sql);if($types!=='')$s->bind_param($types,...$params);$s->execute();$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return$r;}
