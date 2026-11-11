<?php

return [
    /*
    | Idle timeout (minutes) for B2B API tokens. Requests from a token that has
    | been idle longer than this are rejected with HTTP 401 by B2bSessionTimeout.
    */
    'session_timeout_minutes' => (int) env('B2B_SESSION_TIMEOUT_MINUTES', 120),

    /*
    | Base URL used in verification / password-reset links sent by email.
    */
    'frontend_url' => env('B2B_FRONTEND_URL', env('APP_URL')),

    'email_verification_ttl_hours' => (int) env('B2B_EMAIL_VERIFICATION_TTL_HOURS', 24),

    /*
    | Number of days after which an unreconciled payment is reported as stale.
    */
    'reconciliation_stale_days' => (int) env('B2B_RECONCILIATION_STALE_DAYS', 7),
];
