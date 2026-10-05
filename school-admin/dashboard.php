<?php

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../auth.php";


// =====================================================
// 0. AUTH GUARD (login token + school_admin role)
// =====================================================
//
// Agar koi teacher / student / super admin yahan aaya hai
// to usko auth.php apne sahi dashboard par bhej dega.
//

authRequireType($conn, ["school_admin"], "../login.php", "../");


// =====================================================
// 1. LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit();

}

$user_id = $_SESSION["user_id"];


// =====================================================
// 2. ROLE CHECK
// =====================================================
//
// Role verification ab authRequireType() upar hi kar chuka hai.
//



// =====================================================
// 3. FETCH ADMIN + SCHOOL DETAILS
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
            u.last_login,
            u.created_at,
            u.updated_at,

            s.school_code,
            s.school_name,
            s.short_name,
            s.email AS school_email,
            s.phone AS school_phone,
            s.alternate_phone,
            s.address,
            s.city,
            s.state,
            s.pincode,
            s.country,
            s.logo,
            s.affiliation_no,
            s.udise_code,
            s.principal_name,
            s.status AS school_status

        FROM users u

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
// 4. DATABASE ROLE CHECK
// =====================================================

if (
    empty($user["user_type"]) ||
    $user["user_type"] !== "school_admin"
) {
    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();
}


// =====================================================
// 5. ACCOUNT STATUS CHECK
// =====================================================

if ($user["status"] !== "active") {

    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit();

}


// =====================================================
// 6. SCHOOL UID
// =====================================================

$school_uid = $user["school_uid"];


// =====================================================
// 7. STUDENT COUNT
// =====================================================

$student_count = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM students
    WHERE school_uid = ?
    AND status = 'active'
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param("s", $school_uid);
    $stmt->execute();

    $count_result = $stmt->get_result();

    if ($count_result) {

        $row = $count_result->fetch_assoc();

        $student_count = (int) ($row["total"] ?? 0);

    }

    $stmt->close();
}


// =====================================================
// 8. TEACHER COUNT
// =====================================================

$teacher_count = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE school_uid = ?
    AND user_type = 'teacher'
    AND status = 'active'
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param("s", $school_uid);
    $stmt->execute();

    $count_result = $stmt->get_result();

    if ($count_result) {

        $row = $count_result->fetch_assoc();

        $teacher_count = (int) ($row["total"] ?? 0);

    }

    $stmt->close();
}


// =====================================================
// 9. STAFF COUNT
// =====================================================

$staff_count = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE school_uid = ?
    AND user_type = 'staff'
    AND status = 'active'
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param("s", $school_uid);
    $stmt->execute();

    $count_result = $stmt->get_result();

    if ($count_result) {

        $row = $count_result->fetch_assoc();

        $staff_count = (int) ($row["total"] ?? 0);

    }

    $stmt->close();
}


// =====================================================
// 10. ACCOUNTANT COUNT
// =====================================================

$accountant_count = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE school_uid = ?
    AND user_type = 'accountant'
    AND status = 'active'
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param("s", $school_uid);
    $stmt->execute();

    $count_result = $stmt->get_result();

    if ($count_result) {

        $row = $count_result->fetch_assoc();

        $accountant_count = (int) ($row["total"] ?? 0);

    }

    $stmt->close();
}


// =====================================================
// 11. OTHER USERS COUNT
// =====================================================

$other_count = 0;

$sql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE school_uid = ?
    AND status = 'active'
    AND user_type NOT IN (
        'school_admin',
        'super_admin',
        'teacher',
        'staff',
        'accountant',
        'student'
    )
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param("s", $school_uid);
    $stmt->execute();

    $count_result = $stmt->get_result();

    if ($count_result) {

        $row = $count_result->fetch_assoc();

        $other_count = (int) ($row["total"] ?? 0);

    }

    $stmt->close();
}


// =====================================================
// 12. ADMIN INITIAL
// =====================================================

$name = trim($user["name"] ?? "");

if ($name !== "") {

    $initial = strtoupper(substr($name, 0, 1));

} else {

    $initial = "A";

}


$role_name = "School Admin";

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>School Admin Dashboard</title>


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
            width: 92%;
            max-width: 1200px;
            margin: 30px auto;
        }


        /* =========================================
           WELCOME
        ========================================= */

        .welcome {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }


        .welcome h1 {
            font-size: 30px;
            margin-bottom: 8px;
        }


        .welcome h1 span {
            color: #28a745;
        }


        .welcome p {
            color: #777;
        }


        /* =========================================
           STAT CARDS
        ========================================= */

        .stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }


        .stat-card {
            background: white;
            padding: 25px 15px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }


        .stat-card h3 {
            font-size: 32px;
            color: #28a745;
            margin-bottom: 8px;
        }


        .stat-card p {
            color: #777;
            font-size: 14px;
        }


        /* =========================================
           COMMON CARD
        ========================================= */

        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }


        .card-title {
            font-size: 22px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }


        /* =========================================
           PROFILE HEADER
        ========================================= */

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid #eee;
        }


        .profile-photo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #28a745;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            font-weight: bold;
            overflow: hidden;
            flex-shrink: 0;
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
            font-size: 14px;
        }


        .role-badge {
            display: inline-block;
            margin-top: 8px;
            padding: 5px 10px;
            background: #e8f5e9;
            color: #28a745;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }


        /* =========================================
           DETAILS GRID
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
            word-break: break-word;
        }


        .status {
            color: #28a745;
            font-weight: bold;
        }


        /* =========================================
           SCHOOL CARD
        ========================================= */

        .school-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }


        .school-title h2 {
            font-size: 22px;
        }


        .school-status {
            background: #e8f5e9;
            color: #28a745;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
        }


        /* =========================================
           ADD STUDENT
        ========================================= */

        .action-area {
            display: flex;
            justify-content: flex-end;
            margin-top: 25px;
        }


        .add-student-btn {
            display: inline-block;
            padding: 13px 22px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 7px;
            font-weight: bold;
            transition: 0.2s;
        }


        .add-student-btn:hover {
            background: #218838;
        }


        /* =========================================
           LOGOUT
        ========================================= */

        .logout {
            display: inline-block;
            padding: 11px 20px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: 0.2s;
        }


        .logout:hover {
            background: #bb2d3b;
        }


        /* =========================================
           MOBILE
        ========================================= */

        @media(max-width: 900px) {

            .stats {
                grid-template-columns: repeat(3, 1fr);
            }

        }


        @media(max-width: 700px) {

            .container {
                width: 95%;
            }


            .stats {
                grid-template-columns: 1fr 1fr;
            }


            .details {
                grid-template-columns: 1fr;
            }


            .profile-header {
                flex-direction: column;
                align-items: flex-start;
            }


            .school-title {
                flex-direction: column;
                align-items: flex-start;
            }


            .action-area {
                justify-content: stretch;
            }


            .add-student-btn {
                width: 100%;
                text-align: center;
            }

        }


        @media(max-width: 450px) {

            .stats {
                grid-template-columns: 1fr;
            }

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
                <?= htmlspecialchars($user["username"] ?? "Admin") ?>
            </span>

        </h1>

        <p>
            Welcome to your School Management Admin Dashboard.
        </p>

    </div>


    <!-- =========================================
         STATISTICS
    ========================================== -->

    <div class="stats">


        <div class="stat-card">

            <h3>
                <?= $student_count ?>
            </h3>

            <p>Students</p>

        </div>


        <div class="stat-card">

            <h3>
                <?= $teacher_count ?>
            </h3>

            <p>Teachers</p>

        </div>


        <div class="stat-card">

            <h3>
                <?= $staff_count ?>
            </h3>

            <p>Staff</p>

        </div>


        <div class="stat-card">

            <h3>
                <?= $accountant_count ?>
            </h3>

            <p>Accountants</p>

        </div>


        <div class="stat-card">

            <h3>
                <?= $other_count ?>
            </h3>

            <p>Other Users</p>

        </div>


    </div>


    <!-- =========================================
         ADMIN PROFILE
    ========================================== -->

    <div class="card">


        <div class="profile-header">


            <div class="profile-photo">

                <?php if (!empty($user["profile_photo"])): ?>

                    <img
                        src="<?= htmlspecialchars($user["profile_photo"]) ?>"
                        alt="Profile Photo"
                    >

                <?php else: ?>

                    <?= htmlspecialchars($initial) ?>

                <?php endif; ?>

            </div>


            <div>

                <h2>
                    <?= htmlspecialchars($user["name"] ?? "School Admin") ?>
                </h2>

                <p>
                    <?= htmlspecialchars($user["email"] ?? "N/A") ?>
                </p>

                <span class="role-badge">
                    <?= htmlspecialchars($role_name) ?>
                </span>

            </div>


        </div>


        <h2 class="card-title">
            Admin Details
        </h2>


        <div class="details">


            <div class="detail">

                <label>Username</label>

                <strong>
                    <?= htmlspecialchars($user["username"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>Full Name</label>

                <strong>
                    <?= htmlspecialchars($user["name"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>User UID</label>

                <strong>
                    <?= htmlspecialchars($user["user_uid"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>Role</label>

                <strong>
                    <?= htmlspecialchars($role_name) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Email</label>

                <strong>
                    <?= htmlspecialchars($user["email"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>Mobile</label>

                <strong>
                    <?= htmlspecialchars($user["mobile"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>User Type</label>

                <strong>
                    <?= htmlspecialchars($user["user_type"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>Status</label>

                <strong class="status">
                    <?= htmlspecialchars($user["status"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>Account Created</label>

                <strong>
                    <?= htmlspecialchars($user["created_at"] ?? "N/A") ?>
                </strong>

            </div>


            <div class="detail">

                <label>Last Updated</label>

                <strong>
                    <?= htmlspecialchars($user["updated_at"] ?? "N/A") ?>
                </strong>

            </div>


        </div>


    </div>


    <!-- =========================================
         SCHOOL DETAILS
    ========================================== -->

    <div class="card">


        <div class="school-title">

            <h2>
                School Information
            </h2>

            <span class="school-status">

                <?= htmlspecialchars(
                    $user["school_status"] ?? "N/A"
                ) ?>

            </span>

        </div>


        <div class="details">


            <div class="detail">

                <label>School Name</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["school_name"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>School UID</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["school_uid"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>School Code</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["school_code"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Short Name</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["short_name"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Principal</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["principal_name"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>School Phone</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["school_phone"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Alternate Phone</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["alternate_phone"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>School Email</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["school_email"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Address</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["address"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>City</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["city"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>State</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["state"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Pincode</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["pincode"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Country</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["country"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>Affiliation No.</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["affiliation_no"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


            <div class="detail">

                <label>UDISE Code</label>

                <strong>
                    <?= htmlspecialchars(
                        $user["udise_code"] ?? "N/A"
                    ) ?>
                </strong>

            </div>


        </div>


        <!-- ADD STUDENT -->

        <div class="action-area">

            <a
                href="add_student.php"
                class="add-student-btn"
            >
                + Add Student
            </a>

        </div>


    </div>


    <!-- =========================================
         LOGOUT
    ========================================== -->

    <a
        href="../logout.php"
        class="logout"
    >
        Logout
    </a>


</div>


</body>

</html>