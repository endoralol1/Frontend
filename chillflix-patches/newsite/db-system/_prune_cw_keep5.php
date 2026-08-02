<?php
declare(strict_types=1);

/**
 * CLI only: collapse legacy episode CW keys, prune each user to 5, smoke-test upsert.
 * PK is (user_id, media_key) — no id column.
 */
$root = '/var/www/chillflix-newsite';
chdir($root);

require_once $root . '/app/helpers.php';
require_once $root . '/app/Services/Database.php';
require_once $root . '/app/Services/Auth.php';
require_once $root . '/app/Services/UserData.php';

$pdo = Database::pdo();
echo "Connected via Database::pdo\n";

$rows = $pdo->query(
    'SELECT user_id, media_key, media_type, tmdb_id, updated_at
     FROM continue_watching
     ORDER BY updated_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$seen = [];
$deletePairs = [];
foreach ($rows as $r) {
    $group = $r['user_id'] . '|' . $r['media_type'] . '|' . $r['tmdb_id'];
    $want = ($r['media_type'] === 'tv' ? 'tv:' : 'movie:') . $r['tmdb_id'];
    if (!isset($seen[$group])) {
        $seen[$group] = true;
        if ($r['media_key'] !== $want) {
            // Prefer renaming; on conflict drop the legacy key.
            try {
                $pdo->prepare(
                    'UPDATE continue_watching SET media_key = ? WHERE user_id = ? AND media_key = ?'
                )->execute([$want, $r['user_id'], $r['media_key']]);
                echo "renamed {$r['media_key']} -> {$want}\n";
            } catch (Throwable $e) {
                echo "rename conflict {$r['media_key']}: {$e->getMessage()}\n";
                $deletePairs[] = [$r['user_id'], $r['media_key']];
            }
        }
    } else {
        $deletePairs[] = [$r['user_id'], $r['media_key']];
    }
}
$del = $pdo->prepare('DELETE FROM continue_watching WHERE user_id = ? AND media_key = ?');
foreach ($deletePairs as [$uid, $key]) {
    $del->execute([$uid, $key]);
}
if ($deletePairs) {
    echo 'Deleted ' . count($deletePairs) . " duplicate/legacy episode rows\n";
}

$users = $pdo->query('SELECT DISTINCT user_id FROM continue_watching')->fetchAll(PDO::FETCH_COLUMN);
foreach ($users as $uid) {
    UserData::pruneContinueToLimit((string) $uid, 5);
    $cstmt = $pdo->prepare('SELECT COUNT(*) FROM continue_watching WHERE user_id = ?');
    $cstmt->execute([$uid]);
    echo "user {$uid} => " . $cstmt->fetchColumn() . " rows\n";
}

$uid = $users[0] ?? null;
if (!$uid) {
    $uid = (string) $pdo->query('SELECT id FROM users ORDER BY created_at DESC LIMIT 1')->fetchColumn();
}
if ($uid) {
    for ($i = 1; $i <= 6; $i++) {
        UserData::upsertContinue((string) $uid, [
            'type' => 'movie',
            'id' => 900000 + $i,
            'title' => "Smoke {$i}",
            't' => 10 + $i,
            'd' => 100,
        ]);
    }
    $cstmt = $pdo->prepare('SELECT COUNT(*) FROM continue_watching WHERE user_id = ?');
    $cstmt->execute([$uid]);
    $c = (int) $cstmt->fetchColumn();
    echo "SMOKE after 6 upserts count={$c} (expect <=5)\n";
    $keys = $pdo->prepare('SELECT media_key FROM continue_watching WHERE user_id = ? ORDER BY updated_at DESC');
    $keys->execute([$uid]);
    echo 'keys: ' . implode(', ', $keys->fetchAll(PDO::FETCH_COLUMN)) . "\n";
    $pdo->prepare("DELETE FROM continue_watching WHERE user_id = ? AND media_key LIKE 'movie:90000%'")
        ->execute([$uid]);
    echo "cleaned smoke rows\n";
    $cstmt->execute([$uid]);
    echo "user {$uid} final=" . $cstmt->fetchColumn() . "\n";
    if ($c > 5) {
        fwrite(STDERR, "FAIL: smoke count > 5\n");
        exit(1);
    }
} else {
    echo "No user for smoke; skipped\n";
}

$tot = (int) $pdo->query('SELECT COUNT(*) FROM continue_watching')->fetchColumn();
$max = (int) $pdo->query(
    'SELECT COALESCE(MAX(c),0) FROM (SELECT COUNT(*) c FROM continue_watching GROUP BY user_id) x'
)->fetchColumn();
echo "TOTAL rows={$tot} MAX_PER_USER={$max}\n";

if ($max > 5) {
    fwrite(STDERR, "FAIL: max per user > 5\n");
    exit(1);
}
echo "OK keep-5 verified\n";
