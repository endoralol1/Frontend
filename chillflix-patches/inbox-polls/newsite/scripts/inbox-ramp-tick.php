<?php
declare(strict_types=1);

/**
 * Cron: drip inbox poll/reaction ramps every minute.
 * Usage: php /var/www/chillflix-newsite/scripts/inbox-ramp-tick.php
 */
require dirname(__DIR__) . '/app/bootstrap-services.php';

InboxService::ensureTables();
$n = InboxService::tickRamps();
echo date('c') . " ramps_updated={$n}\n";
