<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - PROFILE : LOGOUT FROM ACCOUNT
 |--------------------------------------------------------------------------
 | Ends the session completely and sends the teacher back to the login page.
 |
 | GET is accepted so the Profile page can offer a plain logout link; a POST
 | form is not required because logging out changes nothing on the server
 | that the teacher could lose.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../auth.php";

/* login_user table mein logout time + status update */
if (!empty($_SESSION["auth_token"])) {
    authRecordLogout($conn, $_SESSION["auth_token"]);
}

/* the user's own record is the only thing being used here */
if (isset($_SESSION["user_uid"]) && ($_SESSION["user_type"] ?? "") === "teacher") {

    $school_uid = $_SESSION["school_uid"] ?? "";
    $user_uid   = $_SESSION["user_uid"];
}

/*
 | Empty the session completely, then destroy it, so no profile data is left
 | behind in the session store.
 */
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

session_unset();
session_destroy();

header("Location: ../../login.php");
exit();
