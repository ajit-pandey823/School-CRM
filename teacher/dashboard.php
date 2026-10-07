<?php

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../auth.php";


// =====================================================
// 0. AUTH GUARD (login token + teacher role)
// =====================================================

authRequireType($conn, ["teacher"], "../login.php", "../");


// =====================================================
// 1. LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit();
}


$user_id = $_SESSION["user_id"];


// =====================================================
// 2. FETCH USER + ROLE + SCHOOL
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
            u.created_at,
            u.updated_at,

            r.id AS role_id,
            r.role_uid AS db_role_uid,
            r.role_name,
            r.description AS role_description,
            r.is_system_role,
            r.status AS role_status,
            r.created_at AS role_created_at,

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


// =====================================================
// 3. USER NOT FOUND
// =====================================================

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
// 4. USER STATUS CHECK
// =====================================================

if ($user["status"] !== "active") {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 5. ROLE CHECK
// =====================================================
//
// users.role_uid
//       â†“
// roles.role_uid
//
// Agar role match nahi hua to access denied.
//

if (
    empty($user["role_uid"]) ||
    empty($user["db_role_uid"])
) {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 6. VERIFY ROLE UID MATCH
// =====================================================

if ($user["role_uid"] !== $user["db_role_uid"]) {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 7. ROLE STATUS CHECK
// =====================================================

if ($user["role_status"] !== "active") {

    session_unset();
    session_destroy();

    die("Your assigned role is inactive.");
}


// =====================================================
// 8. TEACHER ROLE CHECK
// =====================================================
//
// Teacher dashboard sirf teacher role ke liye hai.
//

if (strtolower(trim($user["role_name"])) !== "teacher") {

    // Agar user ka role teacher nahi hai,
    // to usko apne role ke according dashboard
    // future mein redirect kiya ja sakta hai.

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 9. STORE USER DETAILS IN SESSION
// =====================================================

$_SESSION["user_id"]       = $user["id"];
$_SESSION["user_uid"]      = $user["user_uid"];
$_SESSION["school_uid"]    = $user["school_uid"];

$_SESSION["username"]      = $user["username"];
$_SESSION["name"]          = $user["name"];
$_SESSION["email"]         = $user["email"];
$_SESSION["mobile"]        = $user["mobile"];
$_SESSION["profile_photo"] = $user["profile_photo"];
$_SESSION["user_type"]     = $user["user_type"];


// =====================================================
// 10. STORE COMPLETE ROLE DETAILS
// =====================================================

$_SESSION["role_uid"] = $user["db_role_uid"];

$_SESSION["role"] = [

    "id"             => $user["role_id"],
    "role_uid"       => $user["db_role_uid"],
    "role_name"      => $user["role_name"],
    "description"    => $user["role_description"],
    "is_system_role" => $user["is_system_role"],
    "status"         => $user["role_status"],
    "created_at"     => $user["role_created_at"]

];


// =====================================================
// 11. PROFILE INITIAL
// =====================================================

$name = trim($user["name"] ?? "");

if ($name !== "") {

    $initial = strtoupper(
        substr($name, 0, 1)
    );

} else {

    $initial = "T";
}


// =====================================================
// 12. ROLE NAME
// =====================================================

$role_name = $user["role_name"];

?>

?>

<?php
$UI_ROOT         = '';
$UI_ACTIVE       = 'dashboard';
$UI_TITLE        = 'Teacher Dashboard';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $user["name"] ?? "Teacher";
include __DIR__ . '/includes/header.php';
?>

<div class="card mb-4">
    <div class="card-body">
        <h1 class="h3 mb-1">
            Welcome,
            <span class="text-primary"><?= htmlspecialchars($user["username"]) ?></span>
        </h1>
        <p class="text-body-secondary mb-0">Welcome to your Teacher Dashboard.</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <strong>Profile</strong>
    </div>
    <div class="card-body">



    <!-- =====================================
         PROFILE
    ====================================== -->


        <!-- PROFILE HEADER -->

        <div class="d-flex align-items-center gap-3 flex-wrap pb-3 mb-4 border-bottom">


            <!-- PROFILE PHOTO -->

            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0" style="width:90px;height:90px;font-size:35px;font-weight:bold;">

                <?php if (!empty($user["profile_photo"])): ?>

                    <img
                        style="width:100%;height:100%;object-fit:cover;"
                        src="<?= htmlspecialchars(
                            $user["profile_photo"]
                        ) ?>"
                        alt="Profile Photo"
                    >

                <?php else: ?>

                    <?= htmlspecialchars($initial) ?>

                <?php endif; ?>

            </div>


            <!-- USER INFO -->

            <div>

                <h2>

                    <?= htmlspecialchars(
                        $user["name"] ?? "Teacher"
                    ) ?>

                </h2>


                <p>

                    <?= htmlspecialchars(
                        $user["email"] ?? "N/A"
                    ) ?>

                </p>


                <span class="badge bg-primary text-white">

                    <?= htmlspecialchars(
                        $role_name
                    ) ?>

                </span>

            </div>

        </div>



        <!-- =====================================
             DETAILS
        ====================================== -->

        <div class="row g-3">


            <!-- USERNAME -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Username
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["username"]
                    ) ?>

                </strong>

            </div>



            <!-- FULL NAME -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Full Name
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["name"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- SCHOOL -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    School
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["school_name"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- EMAIL -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Email
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["email"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- MOBILE -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Mobile
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["mobile"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- USER TYPE -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    User Type
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["user_type"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- ROLE UID -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Role UID
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["db_role_uid"]
                    ) ?>

                </strong>

            </div>



            <!-- ROLE NAME -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Role Name
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["role_name"]
                    ) ?>

                </strong>

            </div>



            <!-- ROLE DESCRIPTION -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Role Description
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["role_description"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- SYSTEM ROLE -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    System Role
                </div>

                <strong>

                    <?= $user["is_system_role"]
                        ? "Yes"
                        : "No"
                    ?>

                </strong>

            </div>



            <!-- ACCOUNT STATUS -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Account Status
                </div>

                <strong class="text-success">

                    <?= htmlspecialchars(
                        $user["status"]
                    ) ?>

                </strong>

            </div>



            <!-- ROLE STATUS -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Role Status
                </div>

                <strong class="text-success">

                    <?= htmlspecialchars(
                        $user["role_status"]
                    ) ?>

                </strong>

            </div>



            <!-- ACCOUNT CREATED -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Account Created
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["created_at"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- ROLE CREATED -->

            <div class="col-md-6 border rounded p-3 bg-light">

                <div class="text-muted small mb-1">
                    Role Created
                </div>

                <strong>

                    <?= htmlspecialchars(
                        $user["role_created_at"] ?? "N/A"
                    ) ?>

                </strong>

            </div>


        </div>



        <!-- LOGOUT -->

        <a href="../logout.php" class="btn btn-danger mt-4">Logout</a>


    </div>


</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
