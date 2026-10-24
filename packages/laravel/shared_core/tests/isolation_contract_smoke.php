<?php

declare(strict_types=1);

$decision = file_get_contents(__DIR__ . '/../../../../docs/decisions/ADR-004.md');
if ($decision === false) {
    fwrite(STDERR, "Missing isolation ADR\n");
    exit(1);
}
foreach (['separate database', 'No cross-App account lookup', 'Sanctum tokens', 'fourth deployed backend'] as $rule) {
    if (stripos($decision, $rule) === false) {
        fwrite(STDERR, "Missing isolation decision: {$rule}\n");
        exit(1);
    }
}
echo "S1 isolation decision smoke: PASS (runtime isolation not tested)\n";
