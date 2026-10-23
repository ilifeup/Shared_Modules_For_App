<?php

declare(strict_types=1);

require __DIR__ . '/../src/TokenPolicy.php';

use EachCore\SharedCore\TokenPolicy;

if (TokenPolicy::expirationMinutes() !== 1440) {
    fwrite(STDERR, "Unexpected S1 token lifetime\n");
    exit(1);
}
echo "S1 token lifetime contract: PASS (Sanctum integration not tested)\n";
