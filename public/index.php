<?php
/**
 * Root — removed landing, redirect straight to Sign In
 * per client request (2026-09-02): landing card deleted, / -> /auth/login.php
 */
header('Location: /auth/login.php', true, 302);
exit;
