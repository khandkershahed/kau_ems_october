<?php
return [
    // Optional: full absolute path to a headless Chromium/Chrome executable.
    // Leave unset on hosts without a verified server-side browser; HTML native
    // printing remains usable and does not require Composer dependencies.
    'chromium_binary' => env('KAU_REPORT_CHROMIUM_BINARY', ''),
];
