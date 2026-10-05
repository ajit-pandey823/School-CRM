<?php

/**
 * =====================================================
 * AUTHENTICATION CORE (auth.php)
 * =====================================================
 *
 * Central authentication library for the school software.
 *
 * Flow:
 *     login.php
 *         ↓
 *     login_process.php
 *         ↓
 *     User + Role + Password verification   (authVerifyCredentials)
 *         ↓
 *     auth.php                              (secure token + session)
 *         ↓
 *     login_user table                      (authCreateLoginRecord)
 *         ↓
 *     Role verification                     (authRequire / authRequireType)
 *         ↓
 *     Correct Dashboard                     (authDashboardUrl)
 *
 * All functions use the "auth" prefix so they can never clash with the
 * existing helpers inside function/function.php (executeStatement,
 * requireLogin, loginUser, logoutAdmin, ...).
 *
 * Dependencies (auto-loaded below):
 *     function/function.php → helpers + config/db.php
 */

require_once __DIR__ . "/function/function.php";


/* =====================================================
   0. SECURITY CONFIGURATION (additive constants)
===================================================== */

if (!defined("AUTH_RATE_LIMIT_MAX")) {
    /* Ek username par itne failed attempts ke baad temporary lock */
    define("AUTH_RATE_LIMIT_MAX", 5);
}

if (!defined("AUTH_RATE_LIMIT_WINDOW_MINUTES")) {
    define("AUTH_RATE_LIMIT_WINDOW_MINUTES", 15);
}

if (!defined("AUTH_SESSION_IDLE_MINUTES")) {
    /* Idle session timeout (inactivity ke baad) */
    define("AUTH_SESSION_IDLE_MINUTES", 60);
}

if (!defined("AUTH_SESSION_ABSOLUTE_HOURS")) {
    /* Absolute session lifetime (chahe kitni bhi activity ho) */
    define("AUTH_SESSION_ABSOLUTE_HOURS", 12);
}

if (!defined("AUTH_DUMMY_HASH")) {
    /*
     * Dummy bcrypt hash → unknown-user login par bhi ek
     * password_verify chalta hai, taaki timing based
     * user enumeration na ho.
     */
    define(
        "AUTH_DUMMY_HASH",
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
    );
}


/* =====================================================
   1. SESSION BOOT
===================================================== */

function authBoot()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Session cookie ko hardened attributes (HttpOnly + SameSite=Lax +
 * Secure-on-HTTPS) ke saath dobara set karta hai.
 *
 * session_regenerate_id() ke baad call karna chahiye, kyunki regenerate
 * PHP default params ke saath naya cookie bhej sakta hai.
 */
function authHardenSessionCookie()
{
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $is_https = (
        (!empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off")
        || (isset($_SERVER["SERVER_PORT"]) && (int)$_SERVER["SERVER_PORT"] === 443)
        || (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https")
    );

    $params = session_get_cookie_params();

    setcookie(session_name(), session_id(), [
        "expires"  => 0,
        "path"     => $params["path"],
        "domain"   => $params["domain"],
        "secure"   => $is_https,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
}


/* =====================================================
   2. CLIENT INFO HELPERS
===================================================== */

function authClientIp()
{
    if (!empty($_SERVER["REMOTE_ADDR"])) {
        return substr((string)$_SERVER["REMOTE_ADDR"], 0, 45);
    }

    return "0.0.0.0";
}

function authUserAgent()
{
    $ua = (string)($_SERVER["HTTP_USER_AGENT"] ?? "");

    if ($ua === "") {
        return "Unknown";
    }

    return substr($ua, 0, 512);
}

function authDescribeDevice($user_agent = null)
{
    $ua = $user_agent !== null
        ? (string)$user_agent
        : (string)($_SERVER["HTTP_USER_AGENT"] ?? "");

    $browser = "Other";

    if (preg_match('/Edg\//i', $ua)) {
        $browser = "Edge";
    } elseif (preg_match('/OPR\//i', $ua)) {
        $browser = "Opera";
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = "Firefox";
    } elseif (preg_match('/Chrome\//i', $ua)) {
        $browser = "Chrome";
    } elseif (preg_match('/Safari\//i', $ua)) {
        $browser = "Safari";
    }

    $os = "Unknown";

    if (preg_match('/Windows/i', $ua)) {
        $os = "Windows";
    } elseif (preg_match('/Android/i', $ua)) {
        $os = "Android";
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = "iOS";
    } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
        $os = "macOS";
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = "Linux";
    }

    return $browser . " on " . $os;
}


/* =====================================================
   3. DASHBOARD ROUTING (Role based)
===================================================== */

/**
 * Root-relative dashboard path for a user_type.
 * $base must be added by the caller when the page is inside a sub folder.
 * Returns NULL when no dashboard is assigned for the role.
 */
function authDashboardUrl($user_type)
{
    switch (strtolower(trim((string)$user_type))) {

        case "super_admin":
            return "super-admin/dashboard.php";

        case "school_admin":
            return "school-admin/dashboard.php";

        case "teacher":
            return "teacher/dashboard.php";

        case "student":
            return "students/dashboard.php";

        default:
            return null;
    }
}

/* =====================================================
   4. USER + ROLE LOOKUP
===================================================== */

function authFindUser($conn, $username)
{
    $sql = "SELECT
                id,
                user_uid,
                school_uid,
                role_uid,
                username,
                password,
                name,
                email,
                mobile,
                profile_photo,
                user_type,
                status
            FROM users
            WHERE username = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$username]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function authFindUserById($conn, $user_id)
{
    $sql = "SELECT
                id,
                user_uid,
                school_uid,
                role_uid,
                username,
                password,
                name,
                email,
                mobile,
                profile_photo,
                user_type,
                status
            FROM users
            WHERE id = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "i", [$user_id]);

    return $stmt ? fetchSingleRow($stmt) : false;
}

function authFindRole($conn, $role_uid)
{
    $sql = "SELECT
                id,
                role_uid,
                school_uid,
                role_name,
                description,
                is_system_role,
                status
            FROM roles
            WHERE role_uid = ?
            LIMIT 1";

    $stmt = executeStatement($conn, $sql, "s", [$role_uid]);

    return $stmt ? fetchSingleRow($stmt) : false;
}


/* =====================================================
   5. LOGIN VERIFICATION
   User + Role + Password
===================================================== */

/**
 * Returns:
 *     ["ok" => true,  "user" => array, "role" => array]
 *     ["ok" => false, "error" => code]
 *
 * Error codes are translated by authLoginErrorMessage().
 */
function authVerifyCredentials($conn, $username, $password)
{
    if ($username === "" || $password === "") {
        return ["ok" => false, "error" => "required"];
    }

    /* ---- USER ---- */

    $user = authFindUser($conn, $username);

    if (!$user) {
        /*
         * User nahi mila → phir bhi ek dummy password_verify chalao
         * taaki unknown-user aur wrong-password ka response time same
         * rahe (timing based user enumeration band).
         */
        password_verify($password, AUTH_DUMMY_HASH);

        return ["ok" => false, "error" => "invalid"];
    }

    /* ---- PASSWORD ----
     *
     * Password check USER STATUS se pehle hota hai, taaki bina sahi
     * password ke attacker ko na pata chale account inactive/blocked
     * hai ya nahi. Inactive/role messages sirf sahi password dene
     * wale (legit user) ko dikhti hain.
     */

    if (!password_verify($password, $user["password"])) {
        return ["ok" => false, "error" => "invalid"];
    }

    /* ---- USER STATUS ---- */

    if ($user["status"] !== "active") {
        return ["ok" => false, "error" => "inactive"];
    }

    /* ---- ROLE UID ---- */

    if (empty($user["role_uid"])) {
        return ["ok" => false, "error" => "role"];
    }

    $role = authFindRole($conn, $user["role_uid"]);

    if (!$role) {
        return ["ok" => false, "error" => "role"];
    }

    if ($role["status"] !== "active") {
        return ["ok" => false, "error" => "role_inactive"];
    }

    /*
     * School match:
     *     System roles (e.g. Super Admin) have school_uid = NULL → allowed.
     *     School specific roles must belong to the same school.
     */
    if (
        !empty($role["school_uid"]) &&
        $role["school_uid"] !== $user["school_uid"]
    ) {
        return ["ok" => false, "error" => "role"];
    }

    /* ---- DASHBOARD EXISTS? ---- */

    if (authDashboardUrl($user["user_type"]) === null) {
        return ["ok" => false, "error" => "no_dashboard"];
    }

    return ["ok" => true, "user" => $user, "role" => $role];
}

/* =====================================================
   6. LOGIN_USER TABLE (token + audit)
===================================================== */

function authNextLoginUid($conn)
{
    $result = mysqli_query(
        $conn,
        "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM login_user"
    );

    if (!$result) {
        return false;
    }

    $row = mysqli_fetch_assoc($result);

    return "LGIN" . str_pad((string)$row["next_id"], 8, "0", STR_PAD_LEFT);
}

/**
 * Records a successful login inside login_user.
 * Returns the new login_user.id or FALSE.
 *
 * Stores: kis user, kis role, kab login, IP, browser/device,
 *         token/session reference, login status.
 */
function authCreateLoginRecord($conn, $user, $role, $token)
{
    $ip_address = authClientIp();
    $user_agent = authUserAgent();
    $device     = authDescribeDevice($user_agent);
    $session_id = session_id();
    $role_name  = $role["role_name"] ?? "";

    /*
     * Raw token kabhi DB mein nahi jaata — sirf SHA-256 hash
     * store hota hai. Session mein raw token rehta hai.
     */
    $token_hash = authTokenHash($token);

    /* Temporary unique uid; final uid row id se banta hai (neeche) */
    $temp_uid = "LGIN_TMP_" . bin2hex(random_bytes(8));

    $sql = "INSERT INTO login_user
            (
                login_uid,
                user_id,
                user_uid,
                school_uid,
                role_uid,
                role_name,
                username,
                auth_token,
                session_id,
                ip_address,
                user_agent,
                device,
                login_time,
                expires_at,
                last_activity,
                status
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                NOW(),
                DATE_ADD(NOW(), INTERVAL " . (int)AUTH_SESSION_ABSOLUTE_HOURS . " HOUR),
                NOW(),
                'active'
            )";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sissssssssss",
        [
            $temp_uid,
            (int)$user["id"],
            $user["user_uid"],
            $user["school_uid"],
            $user["role_uid"],
            $role_name,
            $user["username"],
            $token_hash,
            $session_id,
            $ip_address,
            $user_agent,
            $device
        ]
    );

    if (!$stmt) {
        return false;
    }

    $login_id = (int)$stmt->insert_id;

    /*
     * Final login_uid = LGIN + 8-digit row id.
     * Row id AUTO_INCREMENT se aati hai, isliye simultaneous
     * logins mein collision possible hi nahi.
     * Format: LGINXXXXXXXX (existing format preserved).
     */
    executeStatement(
        $conn,
        "UPDATE login_user SET login_uid = ? WHERE id = ?",
        "si",
        [
            "LGIN" . str_pad((string)$login_id, 8, "0", STR_PAD_LEFT),
            $login_id
        ]
    );

    return $login_id;
}

/**
 * Marks the current login record as logged_out + logout_time.
 */
function authRecordLogout($conn, $token)
{
    if (empty($token)) {
        return false;
    }

    $sql = "UPDATE login_user
            SET status = 'logged_out',
                logout_time = NOW()
            WHERE auth_token = ?
              AND status = 'active'";

    /* DB mein hash store hota hai, isliye lookup bhi hash se */
    $stmt = executeStatement($conn, $sql, "s", [authTokenHash($token)]);

    return $stmt !== false;
}

/* =====================================================
   7. SESSION HANDLING
===================================================== */

/**
 * Stores every session key that the existing dashboards,
 * guards and teacher modules already expect.
 */
function authSetUserSession($user, $role, $token, $login_id)
{
    $_SESSION["user_id"]       = (int)$user["id"];
    $_SESSION["user_uid"]      = $user["user_uid"];
    $_SESSION["school_uid"]    = $user["school_uid"];
    $_SESSION["role_uid"]      = $user["role_uid"];
    $_SESSION["username"]      = $user["username"];
    $_SESSION["name"]          = $user["name"];
    $_SESSION["email"]         = $user["email"];
    $_SESSION["mobile"]        = $user["mobile"];
    $_SESSION["profile_photo"] = $user["profile_photo"];

    /*
     * user_type = users.user_type (enum) yahan store hota hai,
     * role_name = roles.role_name  alag se.
     *
     * Pehle user_type mein role_name jaata tha, jisse teacher modules
     * (jo user_type === "teacher" check karte hain) ke liye mismatch
     * hota tha. Ab dono alag hain.
     */
    $_SESSION["user_type"]     = $user["user_type"];
    $_SESSION["role_name"]     = $role["role_name"] ?? "";

    $_SESSION["auth_token"]    = $token;
    $_SESSION["login_user_id"] = (int)$login_id;
}

function authDestroySession()
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            (bool)$params["secure"],
            (bool)$params["httponly"]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}


/* =====================================================
   8. SESSION VALIDATION (token + user + role)
===================================================== */

/**
 * Validates the current session against the login_user table
 * and re-checks that the user and role are still active.
 *
 * Returns the fresh user row, or FALSE.
 */
function authValidate($conn)
{
    if (empty($_SESSION["user_id"]) || empty($_SESSION["auth_token"])) {
        return false;
    }

    /* ---- LOGIN_USER TOKEN CHECK (DB has hash only) ---- */

    /*
     * Expiry flags SQL mein hi calculate hote hain (NOW() vs NOW()),
     * taaki MySQL/PHP timezone mismatch ki wajah se expiry galat na ho.
     */
    $sql = "SELECT
                id,
                user_id,
                status,
                (
                    expires_at IS NOT NULL
                    AND expires_at < NOW()
                ) AS is_absolute_expired,
                (
                    last_activity IS NOT NULL
                    AND last_activity < DATE_SUB(NOW(), INTERVAL "
                    . (int)AUTH_SESSION_IDLE_MINUTES
                    . " MINUTE)
                ) AS is_idle_expired
            FROM login_user
            WHERE auth_token = ?
            LIMIT 1";

    $stmt = executeStatement(
        $conn,
        $sql,
        "s",
        [authTokenHash($_SESSION["auth_token"])]
    );

    $login = $stmt ? fetchSingleRow($stmt) : false;

    if (!$login) {
        authAudit($conn, "auth_failed", [
            "user_id" => (int)$_SESSION["user_id"],
            "details" => "token_not_found",
        ]);
        return false;
    }

    if ($login["status"] !== "active") {
        authAudit($conn, "session_revoked", [
            "user_id" => (int)$_SESSION["user_id"],
            "details" => "login_status_" . $login["status"],
        ]);
        return false;
    }

    if ((string)$login["user_id"] !== (string)$_SESSION["user_id"]) {
        authAudit($conn, "auth_failed", [
            "user_id" => (int)$_SESSION["user_id"],
            "details" => "token_user_mismatch",
        ]);
        return false;
    }

    /* ---- SESSION EXPIRY (additional check) ---- */

    if ((int)$login["is_absolute_expired"] === 1) {
        authAudit($conn, "session_expired", [
            "user_id" => (int)$_SESSION["user_id"],
            "details" => "absolute_timeout",
        ]);
        return false;
    }

    if ((int)$login["is_idle_expired"] === 1) {
        authAudit($conn, "session_expired", [
            "user_id" => (int)$_SESSION["user_id"],
            "details" => "idle_timeout",
        ]);
        return false;
    }

    /* ---- USER CHECK ---- */

    $user = authFindUserById($conn, (int)$_SESSION["user_id"]);

    if (!$user || $user["status"] !== "active") {
        authAudit($conn, "auth_failed", [
            "user_id" => (int)$_SESSION["user_id"],
            "details" => "user_inactive_or_missing",
        ]);
        return false;
    }

    /* ---- SESSION vs DB ROLE MATCH ---- */

    if (
        empty($_SESSION["user_type"]) ||
        $_SESSION["user_type"] !== $user["user_type"]
    ) {
        authAudit($conn, "auth_failed", [
            "user_id"  => (int)$user["id"],
            "user_uid" => $user["user_uid"],
            "details"  => "user_type_changed",
        ]);
        return false;
    }

    /* ---- ROLE CHECK ---- */

    if (!empty($user["role_uid"])) {

        $role = authFindRole($conn, $user["role_uid"]);

        /* role_uid present hai par role DB mein hi nahi → invalid */
        if (!$role) {
            authAudit($conn, "auth_failed", [
                "user_id"  => (int)$user["id"],
                "user_uid" => $user["user_uid"],
                "details"  => "role_missing:" . $user["role_uid"],
            ]);
            return false;
        }

        if ($role["status"] !== "active") {
            authAudit($conn, "auth_failed", [
                "user_id"  => (int)$user["id"],
                "user_uid" => $user["user_uid"],
                "details"  => "role_inactive:" . $user["role_uid"],
            ]);
            return false;
        }

        /*
         * SCHOOL / TENANT RELATIONSHIP
         * System roles (school_uid = NULL) allowed hain,
         * school specific roles user ke school se match honi chahiye.
         */
        if (
            !empty($role["school_uid"]) &&
            $role["school_uid"] !== $user["school_uid"]
        ) {
            authAudit($conn, "auth_failed", [
                "user_id"  => (int)$user["id"],
                "user_uid" => $user["user_uid"],
                "details"  => "school_mismatch:" . $role["school_uid"],
            ]);
            return false;
        }
    }

    /* ---- KEEP SESSION FRESH ---- */

    $_SESSION["school_uid"] = $user["school_uid"];
    $_SESSION["role_uid"]   = $user["role_uid"];

    /* ---- IDLE TIMER REFRESH (throttled: 2 minute se pehle nahi) ---- */

    executeStatement(
        $conn,
        "UPDATE login_user
         SET last_activity = NOW()
         WHERE id = ?
           AND (last_activity IS NULL
                OR last_activity < DATE_SUB(NOW(), INTERVAL 2 MINUTE))",
        "i",
        [$login["id"]]
    );

    return $user;
}

/* =====================================================
   9. GUARDS (page protection)
===================================================== */

/**
 * Login required. Invalid session → login page.
 */
function authRequire($conn, $login_url)
{
    authBoot();

    $user = authValidate($conn);

    if (!$user) {
        authDestroySession();
        header("Location: " . $login_url);
        exit();
    }

    return $user;
}

/**
 * Login + exact role required.
 *
 * Agar logged-in user galat role se aaya hai, to usko wapas uske
 * apne sahi dashboard par redirect kiya jata hai (ya login page par
 * jab uske liye koi dashboard hi nahi hai).
 *
 * $base = "../" (ya "../../") current page ke hisaab se, taaki
 *         dashboard links sahi resolve hon.
 */
function authRequireType($conn, array $types, $login_url, $base = "")
{
    $user = authRequire($conn, $login_url);

    if (!in_array($user["user_type"], $types, true)) {

        $dashboard = authDashboardUrl($user["user_type"]);

        if ($dashboard !== null) {
            header("Location: " . $base . $dashboard);
        } else {
            header("Location: " . $login_url);
        }

        exit();
    }

    return $user;
}


/* =====================================================
   10. LOGOUT
===================================================== */

function authLogout($conn, $login_url = "login.php")
{
    authBoot();

    if (!empty($_SESSION["auth_token"])) {
        authRecordLogout($conn, $_SESSION["auth_token"]);
    }

    authDestroySession();

    header("Location: " . $login_url . "?msg=logout");
    exit();
}


/* =====================================================
   11. LOGIN ERROR MESSAGES
===================================================== */

function authLoginErrorMessage($code)
{
    $messages = [
        "required"      => "Username and password are required.",
        "invalid"       => "Invalid username or password.",
        "inactive"      => "Your account is inactive. Please contact the administrator.",
        "blocked"       => "Your account has been blocked. Please contact the administrator.",
        "role"          => "No valid role is assigned to your account.",
        "role_inactive" => "Your assigned role is inactive.",
        "no_dashboard"  => "Login successful, but no dashboard is assigned for your role.",
        "session"       => "Your session is no longer valid. Please login again.",
        "log"           => "Login could not be recorded. Please try again.",
        "rate_limit"    => "Too many failed login attempts. Please try again later."
    ];

    return $messages[$code]
        ?? "Unable to login. Please try again.";
}


/* =====================================================
   12. TOKEN HASHING
=====================================================
   DB mein sirf SHA-256 hash store hota hai.
   Session mein raw token rehta hai (existing behavior).
===================================================== */

function authTokenHash($token)
{
    return hash("sha256", (string)$token);
}


/* =====================================================
   13. AUDIT LOGGING (auth_audit table)
=====================================================
   Events:
     login_failed   - galat username/password
     rate_limited   - brute-force lock / threshold
     auth_failed    - suspicious session validation failure
     session_expired- idle/absolute timeout
     session_revoked- logout_all / token revoked
     logout_all     - sab devices se logout
===================================================== */

function authAudit($conn, $event_type, array $data = [])
{
    if ($event_type === "" || empty($conn)) {
        return false;
    }

    /* Audit write fail ho to page kabhi fail nahi honi chahiye */
    $sql = "INSERT INTO auth_audit
            (
                event_type,
                user_id,
                user_uid,
                username,
                school_uid,
                ip_address,
                user_agent,
                details
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = executeStatement(
        $conn,
        $sql,
        "sissssss",
        [
            substr((string)$event_type, 0, 40),
            isset($data["user_id"]) ? (int)$data["user_id"] : null,
            isset($data["user_uid"]) ? substr((string)$data["user_uid"], 0, 30) : null,
            isset($data["username"]) ? substr((string)$data["username"], 0, 100) : null,
            isset($data["school_uid"]) ? substr((string)$data["school_uid"], 0, 30) : null,
            authClientIp(),
            authUserAgent(),
            isset($data["details"]) ? substr((string)$data["details"], 0, 255) : null
        ]
    );

    return $stmt !== false;
}


/* =====================================================
   14. BRUTE-FORCE PROTECTION (rate limiting)
=====================================================
   AUTH_RATE_LIMIT_MAX failed attempts par us username ka
   login AUTH_RATE_LIMIT_WINDOW_MINUTES minute ke liye lock.
   Response: login.php?error=rate_limit
   (existing "invalid" response bilkul same rehta hai)
===================================================== */

function authRecentFailedCount($conn, $username)
{
    $username = substr(trim((string)$username), 0, 100);

    if ($username === "") {
        return 0;
    }

    $sql = "SELECT COUNT(*) AS total
            FROM auth_audit
            WHERE event_type = 'login_failed'
              AND username = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL "
              . (int)AUTH_RATE_LIMIT_WINDOW_MINUTES
              . " MINUTE)";

    $stmt = executeStatement($conn, $sql, "s", [$username]);

    $row = $stmt ? fetchSingleRow($stmt) : false;

    return $row ? (int)$row["total"] : 0;
}

function authIsRateLimited($conn, $username)
{
    return authRecentFailedCount($conn, $username) >= AUTH_RATE_LIMIT_MAX;
}

function authRegisterFailedLogin($conn, $username)
{
    $username = substr(trim((string)$username), 0, 100);

    $logged = authAudit($conn, "login_failed", ["username" => $username]);

    /* Threshold hit hone par ek extra audit row */
    if (
        $username !== "" &&
        authRecentFailedCount($conn, $username) >= AUTH_RATE_LIMIT_MAX
    ) {
        authAudit($conn, "rate_limited", [
            "username" => $username,
            "details"  => "threshold_reached",
        ]);
    }

    return $logged;
}


/* =====================================================
   15. CSRF PROTECTION (login ke alawa har sensitive POST)
=====================================================
   Forms mein   : <?php echo csrfField(); ?>
   Handlers mein: csrfVerify();
   (Login form isse exempt hai - checklist ke hisaab se)
===================================================== */

function csrfToken()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8")
        . '">';
}

function csrfVerify()
{
    /* Sirf POST validate hota hai - GET flows bilkul same */
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
        return true;
    }

    $sent  = (string)($_POST["csrf_token"] ?? "");
    $known = (string)($_SESSION["csrf_token"] ?? "");

    if ($sent === "" || $known === "" || !hash_equals($known, $sent)) {
        http_response_code(403);
        header("Content-Type: text/html; charset=utf-8");
        echo "<!DOCTYPE html>\n"
            . "<html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<title>403 Forbidden</title></head><body>"
            . "<p>403 - Invalid or missing security token. "
            . "Please go back, refresh the page and try again.</p>"
            . "</body></html>";
        exit();
    }

    return true;
}

/* =====================================================
   16. SESSION REVOCATION (logout all devices)
=====================================================
   Current logout (authLogout) bilkul same rehta hai.
   Ye nayi capability: ek click mein us user ki SAARI
   active login_user rows revoke karo.
===================================================== */

function authLogoutAllSessions($conn)
{
    $user_id = (int)($_SESSION["user_id"] ?? 0);

    if ($user_id <= 0) {
        return 0;
    }

    $sql = "UPDATE login_user
            SET status = 'expired',
                logout_time = NOW()
            WHERE user_id = ?
              AND status = 'active'";

    $stmt = executeStatement($conn, $sql, "i", [$user_id]);

    $count = 0;

    if ($stmt) {
        $count = (int)$stmt->affected_rows;
    }

    authAudit($conn, "logout_all", [
        "user_id" => $user_id,
        "details" => "revoked_sessions:" . $count,
    ]);

    return $count;
}


/* =====================================================
   17. PERMISSION LAYER (future-proof authorization)
=====================================================
   authRequire() / authRequireType() UNCHANGED hain.
   Ye nayi optional layer hai:

       authHasPermission($conn, "students.add")
       authRequirePermission($conn, "students.add", "../login.php", "../")

   permissions / role_permissions / user_permissions tables
   ka use hota hai. Role based access backward compatible
   rehta hai - purana flow bilkul same chalta hai.
===================================================== */

function authPermissionKeys($conn)
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    $role_uid = $_SESSION["role_uid"] ?? null;
    $user_uid = $_SESSION["user_uid"] ?? null;

    /* ---- ROLE PERMISSIONS ---- */

    if (!empty($role_uid)) {
        $sql = "SELECT p.permission_key
                FROM role_permissions rp
                INNER JOIN permissions p
                    ON p.permission_uid = rp.permission_uid
                WHERE rp.role_uid = ?";

        $stmt = executeStatement($conn, $sql, "s", [$role_uid]);

        if ($stmt) {
            foreach (fetchAllRows($stmt) as $row) {
                $cache[$row["permission_key"]] = true;
            }
        }
    }

    /* ---- USER PERMISSIONS (deny > allow) ---- */

    if (!empty($user_uid)) {
        $sql = "SELECT p.permission_key, up.permission_type
                FROM user_permissions up
                INNER JOIN permissions p
                    ON p.permission_uid = up.permission_uid
                WHERE up.user_uid = ?";

        $stmt = executeStatement($conn, $sql, "s", [$user_uid]);

        if ($stmt) {
            foreach (fetchAllRows($stmt) as $row) {
                $cache[$row["permission_key"]] = ($row["permission_type"] !== "deny");
            }
        }
    }

    return $cache;
}

function authHasPermission($conn, $permission_key)
{
    $keys = authPermissionKeys($conn);

    return isset($keys[$permission_key]) && $keys[$permission_key] === true;
}

function authRequirePermission($conn, $permission_key, $login_url, $base = "")
{
    $user = authRequire($conn, $login_url);

    if (!authHasPermission($conn, $permission_key)) {

        $dashboard = authDashboardUrl($user["user_type"]);

        if ($dashboard !== null) {
            header("Location: " . $base . $dashboard);
        } else {
            header("Location: " . $login_url);
        }

        exit();
    }

    return $user;
}






