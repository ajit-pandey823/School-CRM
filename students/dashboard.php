<?php

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../auth.php";


// =====================================================
// 0. AUTH GUARD (login token + student role)
// =====================================================

authRequireType($conn, ["student"], "../login.php", "../");


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
// 5. ROLE EXISTENCE CHECK
// =====================================================

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
// 6. VERIFY USER ROLE UID WITH DATABASE ROLE UID
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
// 8. STUDENT ROLE CHECK
// =====================================================
//
// Ye dashboard sirf Student role ke liye hai.
//

if (strtolower(trim($user["role_name"])) !== "student") {

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

    $initial = "S";
}


// =====================================================
// 12. ROLE NAME
// =====================================================

$role_name = $user["role_name"];

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Student Dashboard
    </title>


    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }


        body {
            background: #f4f6f8;
            color: #222;
        }


        .container {
            width: 90%;
            max-width: 1100px;
            margin: 40px auto;
        }


        /* =========================================
           WELCOME
        ========================================= */

        .welcome {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow:
                0 3px 12px
                rgba(0, 0, 0, 0.08);
        }


        .welcome h1 {
            font-size: 30px;
            margin-bottom: 8px;
        }


        .welcome h1 span {
            color: #007bff;
        }


        .welcome p {
            color: #777;
        }


        /* =========================================
           PROFILE CARD
        ========================================= */

        .profile-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow:
                0 3px 12px
                rgba(0, 0, 0, 0.08);
        }


        /* =========================================
           PROFILE HEADER
        ========================================= */

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            padding-bottom: 25px;
            border-bottom: 1px solid #eee;
            margin-bottom: 25px;
        }


        /* =========================================
           PROFILE PHOTO
        ========================================= */

        .profile-photo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #007bff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            font-weight: bold;
            overflow: hidden;
        }


        .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }


        .profile-header h2 {
            margin-bottom: 6px;
        }


        .profile-header p {
            color: #777;
            margin-bottom: 8px;
        }


        /* =========================================
           ROLE BADGE
        ========================================= */

        .role-badge {
            display: inline-block;
            padding: 5px 10px;
            background: #e7f1ff;
            color: #007bff;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }


        /* =========================================
           DETAILS
        ========================================= */

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }


        .detail {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }


        .detail label {
            display: block;
            font-size: 13px;
            color: #777;
            margin-bottom: 6px;
        }


        .detail strong {
            font-size: 15px;
        }


        /* =========================================
           STATUS
        ========================================= */

        .status {
            color: #28a745;
            font-weight: bold;
        }


        /* =========================================
           LOGOUT
        ========================================= */

        .logout {
            display: inline-block;
            margin-top: 25px;
            padding: 11px 20px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }


        .logout:hover {
            background: #bb2d3b;
        }


        /* =========================================
           MOBILE
        ========================================= */

        @media(max-width: 700px) {

            .details {
                grid-template-columns: 1fr;
            }


            .profile-header {
                flex-direction: column;
                align-items: flex-start;
            }

        }


        .view-fees {

    color: #28a745;

    font-weight: bold;

    text-decoration: none;

    cursor: pointer;
}


.view-fees:hover {

    color: #218838;

    text-decoration: underline;
}

    </style>

</head>


<body>


<div class="container">


    <!-- =========================================
         WELCOME
    ========================================== -->

    <div class="welcome">

        <h1>

            Welcome,

            <span>

                <?= htmlspecialchars(
                    $user["username"]
                ) ?>

            </span>

        </h1>


        <p>

            Welcome to your Student Dashboard.

        </p>

    </div>



    <!-- =========================================
         PROFILE CARD
    ========================================== -->

    <div class="profile-card">


        <!-- PROFILE HEADER -->

        <div class="profile-header">


            <!-- PROFILE PHOTO -->

            <div class="profile-photo">

                <?php if (!empty($user["profile_photo"])): ?>

                    <img
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
                        $user["name"] ?? "Student"
                    ) ?>

                </h2>


                <p>

                    <?= htmlspecialchars(
                        $user["email"] ?? "N/A"
                    ) ?>

                </p>


                <span class="role-badge">

                    <?= htmlspecialchars(
                        $role_name
                    ) ?>

                </span>

            </div>

        </div>



        <!-- =====================================
             DETAILS
        ====================================== -->

        <div class="details">


            <!-- USERNAME -->

            <div class="detail">

                <label>
                    Username
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["username"]
                    ) ?>

                </strong>

            </div>



            <!-- FULL NAME -->

            <div class="detail">

                <label>
                    Full Name
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["name"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- SCHOOL -->

            <div class="detail">

                <label>
                    School
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["school_name"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- EMAIL -->

            <div class="detail">

                <label>
                    Email
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["email"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- MOBILE -->

            <div class="detail">

                <label>
                    Mobile
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["mobile"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- USER TYPE -->

            <div class="detail">

                <label>
                    User Type
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["user_type"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- ROLE UID -->

            <div class="detail">

                <label>
                    Role UID
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["db_role_uid"]
                    ) ?>

                </strong>

            </div>



            <!-- ROLE NAME -->

            <div class="detail">

                <label>
                    Role Name
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["role_name"]
                    ) ?>

                </strong>

            </div>



            <!-- ROLE DESCRIPTION -->

            <div class="detail">

                <label>
                    Role Description
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["role_description"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- SYSTEM ROLE -->

            <div class="detail">

                <label>
                    System Role
                </label>

                <strong>

                    <?= $user["is_system_role"]
                        ? "Yes"
                        : "No"
                    ?>

                </strong>

            </div>



            <!-- ACCOUNT STATUS -->

            <div class="detail">

                <label>
                    Account Status
                </label>

                <strong class="status">

                    <?= htmlspecialchars(
                        $user["status"]
                    ) ?>

                </strong>

            </div>



            <!-- VIEW FEES -->

<div class="detail">

    <label>
        Fees
    </label>

    <a
        href="../fees/dashboard.php"
        class="view-fees"
    >
        View Fees
    </a>

</div>



            <!-- ROLE STATUS -->

            <div class="detail">

                <label>
                    Role Status
                </label>

                <strong class="status">

                    <?= htmlspecialchars(
                        $user["role_status"]
                    ) ?>

                </strong>

            </div>



            <!-- ACCOUNT CREATED -->

            <div class="detail">

                <label>
                    Account Created
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["created_at"] ?? "N/A"
                    ) ?>

                </strong>

            </div>



            <!-- ROLE CREATED -->

            <div class="detail">

                <label>
                    Role Created
                </label>

                <strong>

                    <?= htmlspecialchars(
                        $user["role_created_at"] ?? "N/A"
                    ) ?>

                </strong>

            </div>


        </div>



        <!-- =====================================
             LOGOUT
        ====================================== -->

        <a
            href="../logout.php"
            class="logout"
        >
            Logout
        </a>


    </div>


</div>


</body>

</html>