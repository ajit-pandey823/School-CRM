<?php

/**
 * =====================================================
 * LOGOUT ALL DEVICES (session revocation)
 * =====================================================
 *
 * Current logout.php bilkul unchanged hai.
 * Ye nayi capability: logged-in user ki SAARI active
 * sessions (sare devices) ek saath revoke karo:
 *
 *     login_user rows → status = 'expired'
 *     audit record    → logout_all / session_revoked
 *     phir apna session destroy + login page redirect
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/auth.php";

authBoot();

if (!empty($_SESSION["user_id"])) {
    authLogoutAllSessions($conn);
}

authDestroySession();

header("Location: login.php?msg=logout_all");
exit();
