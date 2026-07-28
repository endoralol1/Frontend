<?php
/**
 * Reporting now lives inside the community forum.
 * Old links keep working: forward type/value prefills to the inline composer.
 */
require_once __DIR__ . '/includes/functions.php';

$type = strtolower(trim($_GET['type'] ?? 'website'));
if (!in_array($type, ['website', 'phone', 'crypto', 'iban', 'card'], true)) {
    $type = 'website';
}
$prefill = trim($_GET['q'] ?? $_GET['d'] ?? '');

$target = '/community.php?compose=1&type=' . rawurlencode($type);
if ($prefill !== '') {
    $target .= '&q_prefill=' . rawurlencode($prefill);
}

header('Location: ' . base_path($target), true, 301);
exit;
