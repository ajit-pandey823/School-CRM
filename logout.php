<?php

/**
 * =====================================================
 * LOGOUT (root)
 * =====================================================
 *
 * 1. login_user table mein logout_time + status update karta hai.
 * 2. Session completely destroy karta hai.
 * 3. Login page par redirect karta hai.
 *
 * Yeh file har dashboard ke "../logout.php" link se judi hai.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/auth.php";

authLogout($conn, "login.php");
