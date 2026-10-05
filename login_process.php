<?php

/**
 * =====================================================
 * LOGIN PROCESS
 * =====================================================
 *
 * Flow:
 *     login.php  →  login_process.php (POST)
 *         ↓
 *     User + Role + Password verification
 *         ↓
 *     auth.php (secure token + session)
 *         ↓
 *     login_user table entry
 *         ↓
 *     Role based redirect → Correct dashboard
 *
 * Koi bhi output yahan direct print nahi hota, sirf redirects.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/auth.php";


/* =====================================================
   1. ONLY POST ALLOWED
===================================================== */

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: login.php");
    exit();
}


/* =====================================================
   2. INPUT
===================================================== */

$username = trim($_POST["username"] ?? "");
$password = (string)($_POST["password"] ?? "");


/* =====================================================
   3. BRUTE-FORCE PROTECTION
   5 failed attempts = 15 minute temporary lock.
   Existing "invalid" response bilkul same rehta hai.
===================================================== */

if (authIsRateLimited($conn, $username)) {

    authAudit($conn, "rate_limited", [
        "username" => $username,
        "details"  => "blocked_attempt",
    ]);

    header("Location: login.php?error=" . urlencode("rate_limit"));
    exit();
}


/* =====================================================
   4. USER + ROLE + PASSWORD VERIFICATION
===================================================== */

$check = authVerifyCredentials($conn, $username, $password);

if (!$check["ok"]) {

    /* Failed login attempt audit + rate-limit counter */
    if ($check["error"] === "invalid") {
        authRegisterFailedLogin($conn, $username);
    }

    header("Location: login.php?error=" . urlencode($check["error"]));
    exit();
}

$user = $check["user"];
$role = $check["role"];


/* =====================================================
   5. DASHBOARD EXISTS FOR THIS ROLE?
===================================================== */

$dashboard = authDashboardUrl($user["user_type"]);

if ($dashboard === null) {
    header("Location: login.php?error=no_dashboard");
    exit();
}


/* =====================================================
   6. SECURE TOKEN
===================================================== */

$token = bin2hex(random_bytes(32));


/*
 * Purana session data hata do, phir session id regenerate karo
 * taaki session fixation possible na ho.
 */

$_SESSION = [];

session_regenerate_id(true);

/* Regenerated cookie bhi hardened attributes ke saath bhejo */
authHardenSessionCookie();


/* =====================================================
   7. LOGIN_USER TABLE ENTRY
   (user, role, time, IP, browser/device, token)
===================================================== */

$login_id = authCreateLoginRecord($conn, $user, $role, $token);

if (!$login_id) {
    header("Location: login.php?error=log");
    exit();
}


/* =====================================================
   8. SESSION SET
===================================================== */

authSetUserSession($user, $role, $token, $login_id);


/* =====================================================
   9. LAST LOGIN UPDATE
===================================================== */

executeStatement(
    $conn,
    "UPDATE users SET last_login = NOW() WHERE id = ?",
    "i",
    [$user["id"]]
);


/* =====================================================
   10. ROLE BASED REDIRECT
===================================================== */

header("Location: " . $dashboard);
exit();
