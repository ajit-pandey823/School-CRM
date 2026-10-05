<?php

session_start();

require_once __DIR__ . "/../config/db.php";


// =====================================================
// LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit();
}


$user_id = $_SESSION["user_id"];


// =====================================================
// USER + ROLE + SCHOOL FETCH
// =====================================================

$sql = "SELECT
            u.id,
            u.user_uid,
            u.school_uid,
            u.role_uid,
            u.username,
            u.name,
            u.email,
            u.mobile,
            u.profile_photo,
            u.user_type,
            u.status,

            r.role_uid AS db_role_uid,
            r.role_name,
            r.description AS role_description,
            r.is_system_role,
            r.status AS role_status,

            s.school_name

        FROM users u

        LEFT JOIN roles r
            ON u.role_uid = r.role_uid

        LEFT JOIN schools s
            ON u.school_uid = s.school_uid

        WHERE u.id = ?

        LIMIT 1";


$stmt = $conn->prepare($sql);

if (!$stmt) {

    die("Database query failed: " . $conn->error);
}


$stmt->bind_param("i", $user_id);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows !== 1) {

    $stmt->close();

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


$user = $result->fetch_assoc();

$stmt->close();


// =====================================================
// USER STATUS
// =====================================================

if ($user["status"] !== "active") {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// ROLE MATCH
// =====================================================

if (
    empty($user["role_uid"]) ||
    empty($user["db_role_uid"]) ||
    $user["role_uid"] !== $user["db_role_uid"]
) {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// ROLE STATUS
// =====================================================

if ($user["role_status"] !== "active") {

    session_unset();
    session_destroy();

    die("Your role is inactive.");
}


// =====================================================
// ADMIN ROLE CHECK
// =====================================================

$role_name = strtolower(trim($user["role_name"]));


if (
    $role_name !== "school admin" &&
    $role_name !== "school-admin"
) {

    header("Location: ../login.php");
    exit();
}

?>