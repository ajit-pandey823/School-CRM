<?php

/**
 * =====================================================
 * SECURITY LAYER (config/security.php)
 * =====================================================
 *
 * Loaded automatically from config/db.php, therefore it runs
 * on EVERY request (login, dashboards, modules - sab par).
 *
 * 1. Session cookie security
 *      - HttpOnly  : JavaScript se cookie accessible nahi
 *      - SameSite  : Lax (CSRF protection layer)
 *      - Secure    : sirf HTTPS par (production)
 *
 * 2. Security headers
 *      - X-Content-Type-Options : nosniff
 *      - X-Frame-Options        : SAMEORIGIN
 *      - Referrer-Policy        : same-origin
 *      - Strict-Transport-Security (sirf HTTPS par)
 *      - Content-Security-Policy
 *
 * Koi existing session/functionality change nahi hota:
 * session id wahi rehti hai, sirf attributes harden hote hain.
 */

(function () {

    $is_https = (
        (!empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off")
        || (isset($_SERVER["SERVER_PORT"]) && (int)$_SERVER["SERVER_PORT"] === 443)
        || (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https")
    );

    /* -------------------------------------------------
       1. SESSION COOKIE SECURITY
    ------------------------------------------------- */

    if (session_status() === PHP_SESSION_ACTIVE) {

        /*
         * Session pehle se start ho chuka hai.
         *
         * NOTE: session_set_cookie_params() active session par warning
         * deta hai, isliye sirf current cookie ko hardened attributes
         * ke saath re-issue kiya jata hai. session_regenerate_id() ke
         * baad login_process.php authHardenSessionCookie() call karta
         * hai, taaki naya cookie bhi hardened ho.
         */
        $params = session_get_cookie_params();

        if (!headers_sent()) {
            setcookie(session_name(), session_id(), [
                "expires"  => 0,
                "path"     => $params["path"],
                "domain"   => $params["domain"],
                "secure"   => $is_https,
                "httponly" => true,
                "samesite" => "Lax",
            ]);
        }

    } else {

        /* Session abhi start nahi hua → session_start() ye params use karega */
        @ini_set("session.cookie_httponly", "1");
        @ini_set("session.cookie_samesite", "Lax");
        @ini_set("session.cookie_secure", $is_https ? "1" : "0");
    }

    /* -------------------------------------------------
       2. SECURITY HEADERS
    ------------------------------------------------- */

    if (!headers_sent()) {

        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: same-origin");

        if ($is_https) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        /*
         * CSP: app ke apne assets (self) + inline styles/scripts jo
         * dashboards use karte hain + https CDN fallback.
         * Production mein isko nonce/hash based policy par tighten karein.
         */
        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "img-src 'self' data: blob: https:; "
            . "style-src 'self' 'unsafe-inline' https:; "
            . "script-src 'self' 'unsafe-inline' https:; "
            . "font-src 'self' data: https:; "
            . "connect-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'"
        );
    }

})();
