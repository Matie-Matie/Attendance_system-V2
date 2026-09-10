<?php
/**
 * Logout
 */
require_once __DIR__ . '/../../bootstrap.php';

logout();
$_SESSION['_flash']['info'] = 'You have been logged out successfully.';
redirect('/auth/login.php');
