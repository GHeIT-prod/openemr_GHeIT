<?php

require_once("../globals.php");

header('Content-Type: application/json');

$userAuthId = $_SESSION['authUserID'] ?? null;
$facilityId = sqlQuery("SELECT facility_id FROM users WHERE id = ?", [$userAuthId])['facility_id'] ?? null;
$formattedFacilityId = str_pad($facilityId, 3, '0', STR_PAD_LEFT);

$date = date('Ymd');

/*
 * Peek next AUTO_INCREMENT value
 */

$row = sqlQuery("
    SELECT AUTO_INCREMENT
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'custom_mrn_sequence'
");

$nextId = $row['AUTO_INCREMENT'] ?? 1;
$sequence = str_pad($nextId, 3, '0', STR_PAD_LEFT);
$mrn = "A-{$formattedFacilityId}-{$date}-{$sequence}";

echo json_encode([
    'mrn' => $mrn
]);