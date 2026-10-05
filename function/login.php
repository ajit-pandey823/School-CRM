<?php

session_start();

require_once __DIR__ . "/../config/db.php";

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    die("Username and password are required.");
}

/* =========================
   USER FETCH
========================= */

$stmt = $conn->prepare("
    SELECT
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
        status,
        created_at,
        updated_at
    FROM users
    WHERE username = ?
    LIMIT 1
");

if (!$stmt) {
    die("User query failed: " . $conn->error);
}

$stmt->bind_param("s", $username);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Invalid username or password.");
}

$user = $result->fetch_assoc();

/* =========================
   USER STATUS
========================= */

if ($user["status"] !== "active") {
    die("User account is inactive.");
}

/* =========================
   PASSWORD CHECK
========================= */

if (!password_verify($password, $user["password"])) {
    die("Invalid username or password.");
}

/* =========================
   ROLE UID CHECK
========================= */

if (empty($user["role_uid"])) {
    die("No role assigned to this user.");
}

/* =========================
   ROLE FETCH
========================= */

$role_stmt = $conn->prepare("
    SELECT
        role_uid,
        school_uid,
        role_name,
        status
    FROM roles
    WHERE role_uid = ?
      AND school_uid = ?
    LIMIT 1
");

if (!$role_stmt) {
    die("Role query failed: " . $conn->error);
}

$role_stmt->bind_param(
    "ss",
    $user["role_uid"],
    $user["school_uid"]
);

$role_stmt->execute();

$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    die(
        "Role mapping error.<br>" .
        "Role UID: " . htmlspecialchars($user["role_uid"]) . "<br>" .
        "School UID: " . htmlspecialchars($user["school_uid"])
    );
}

$role = $role_result->fetch_assoc();

/* =========================
   ROLE STATUS
========================= */

if ($role["status"] !== "active") {
    die("Assigned role is inactive.");
}

/* =========================
   SESSION START
========================= */

session_regenerate_id(true);

$_SESSION["user_id"]       = $user["id"];
$_SESSION["user_uid"]      = $user["user_uid"];
$_SESSION["school_uid"]    = $user["school_uid"];
$_SESSION["role_uid"]      = $user["role_uid"];
$_SESSION["username"]      = $user["username"];
$_SESSION["name"]          = $user["name"];
$_SESSION["email"]         = $user["email"];
$_SESSION["mobile"]        = $user["mobile"];
$_SESSION["profile_photo"] = $user["profile_photo"];
$_SESSION["user_type"]     = $role["role_name"];
$_SESSION["role_name"]     = $role["role_name"];

/* =========================
   LAST LOGIN
========================= */

$update = $conn->prepare("
    UPDATE users
    SET updated_at = NOW()
    WHERE id = ?
");

if ($update) {
    $update->bind_param("i", $user["id"]);
    $update->execute();
}

/* =========================
   ROLE BASED REDIRECT
========================= */

switch (strtolower($role["role_name"])) {

    case "school_admin":
    case "admin":
        header("Location: ../school-admin/dashboard.php");
        exit();

    case "teacher":
        header("Location: ../teacher/dashboard.php");
        exit();

    case "student":
        header("Location: ../students/dashboard.php");
        exit();

    default:
        session_unset();
        session_destroy();

        die(
            "Login successful, but no dashboard is assigned for role: " .
            htmlspecialchars($role["role_name"])
        );
}
?>