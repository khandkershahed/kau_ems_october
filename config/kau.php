<?php

return [
    // Prevent anonymous visitors creating privileged payroll logins.
    // Existing authenticated Employee Management functions are unaffected.
    'allow_public_registration' => env('KAU_ALLOW_PUBLIC_REGISTRATION', false),
];
