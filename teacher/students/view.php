<?php

session_start();

require_once __DIR__ . "/../../config/db.php";

// =====================================================
// LOGIN CHECK
// =====================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

// =====================================================
// GET STUDENT UID
// =====================================================

$student_uid = isset($_GET["student"]) ? trim($_GET["student"]) : "";

if ($student_uid === "") {
    header("Location: index.php");
    exit();
}

// =====================================================
// GET TEACHER UID FROM LOGGED-IN USER
// =====================================================
//
// teachers.user_uid VARCHAR column hai (jaise "USR00000003"),
// isliye session ka numeric user_id nahi,
// session ka user_uid use karna hai.
//

$user_uid = $_SESSION["user_uid"] ?? "";

$teacher_uid = "";

$sql_teacher = "
    SELECT teacher_uid, teacher_name
    FROM teachers
    WHERE user_uid = ?
    AND status = 'active'
    LIMIT 1
";

$stmt_teacher = $conn->prepare($sql_teacher);

if (!$stmt_teacher) {
    die("Teacher query error: " . $conn->error);
}

$stmt_teacher->bind_param("s", $user_uid);
$stmt_teacher->execute();

$result_teacher = $stmt_teacher->get_result();

if ($result_teacher->num_rows > 0) {

    $teacher = $result_teacher->fetch_assoc();

    $teacher_uid = $teacher["teacher_uid"];
}

$stmt_teacher->close();


// =====================================================
// GET STUDENT + VERIFY HE STUDIES IN TEACHER'S CLASS
// =====================================================

$student = false;

if ($teacher_uid !== "") {

    $sql = "
        SELECT
            s.student_uid,
            s.admission_no,
            s.student_name,
            s.gender,
            s.dob,
            s.blood_group,
            s.father_name,
            s.mother_name,
            s.father_mobile,
            s.mother_mobile,
            s.email,
            s.address,
            s.city,
            s.state,
            s.pincode,
            s.admission_date,
            s.status AS student_status,
            sa.roll_no,
            c.class_name,
            sec.section_name,
            ay.year_name
        FROM student_academic sa

        INNER JOIN students s
            ON s.student_uid = sa.student_uid

        INNER JOIN classes c
            ON c.class_uid = sa.class_uid

        INNER JOIN sections sec
            ON sec.section_uid = sa.section_uid

        INNER JOIN academic_years ay
            ON ay.academic_year_uid = sa.academic_year_uid

        INNER JOIN teacher_subjects ts
            ON ts.teacher_uid = ?
            AND ts.class_uid = sa.class_uid
            AND ts.section_uid = sa.section_uid
            AND ts.academic_year_uid = sa.academic_year_uid

        WHERE sa.student_uid = ?

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Student query error: " . $conn->error);
    }

    $stmt->bind_param("ss", $teacher_uid, $student_uid);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $student = $result->fetch_assoc();
    }

    $stmt->close();
}


// =====================================================
// STUDENT NOT FOUND OR NOT ALLOWED
// =====================================================

if (!$student) {
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 40px;
            color: #1f2937;
        }

        .box {
            max-width: 520px;
            margin: auto;
            background: #ffffff;
            padding: 35px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .box h2 {
            margin: 0 0 10px;
        }

        .box p {
            color: #6b7280;
            margin: 0 0 20px;
        }

        .box a {
            display: inline-block;
            padding: 10px 18px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
        }
    </style>
</head>

<body>

    <div class="box">

        <h2>Student Not Found</h2>

        <p>
            This student profile was not found
            or it is not in your assigned classes.
        </p>

        <a href="index.php">← Back to My Students</a>

    </div>

</body>

</html>
    <?php
    exit();
}


// =====================================================
// HELPER FUNCTIONS
// =====================================================

function studentGuardianName($row)
{
    $guardian = trim($row["father_name"] ?? "");

    if ($guardian === "") {
        $guardian = trim($row["mother_name"] ?? "");
    }

    return $guardian !== "" ? $guardian : "-";
}

function studentContactNumber($row)
{
    $contact = trim($row["father_mobile"] ?? "");

    if ($contact === "") {
        $contact = trim($row["mother_mobile"] ?? "");
    }

    return $contact !== "" ? $contact : "-";
}

function studentStatusBadge($status)
{
    if ($status === "active") {
        return "active";
    }

    if ($status === "inactive") {
        return "inactive";
    }

    return "other";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Profile</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        .header {
            background: #ffffff;
            padding: 20px 25px;
            border-bottom: 1px solid #e5e7eb;
        }

        .header h1 {
            margin: 0;
            font-size: 26px;
        }

        .container {
            padding: 25px;
        }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            color: #2563eb;
            font-weight: 600;
        }

        .profile-card {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .profile-head {
            display: flex;
            align-items: center;
            gap: 18px;
            padding-bottom: 22px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 22px;
        }

        .avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #2563eb;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .profile-head h2 {
            margin: 0 0 8px;
        }

        .profile-head .sub {
            margin: 8px 0 0;
            color: #6b7280;
            font-size: 14px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge.active {
            background: #dcfce7;
            color: #15803d;
        }

        .badge.inactive {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge.other {
            background: #fef3c7;
            color: #b45309;
        }

        .section-title {
            margin: 0 0 15px;
            font-size: 17px;
            color: #374151;
        }

        .fields {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .field {
            background: #f8f9fa;
            padding: 13px 15px;
            border-radius: 8px;
        }

        .field label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .field strong {
            font-size: 15px;
            color: #111827;
            word-break: break-word;
        }

    </style>

</head>

<body>

<div class="header">

    <h1>Student Profile</h1>

</div>

<div class="container">

    <a href="index.php" class="back">
        ← Back to My Students
    </a>


    <?php

    $student_name = trim($student["student_name"]);

    $initial = strtoupper(substr($student_name, 0, 1));

    $dob_text = "-";

    if (!empty($student["dob"])) {
        $dob_text = date("d M Y", strtotime($student["dob"]));
    }

    $admission_text = "-";

    if (!empty($student["admission_date"])) {
        $admission_text = date(
            "d M Y",
            strtotime($student["admission_date"])
        );
    }

    $address_text = trim($student["address"] ?? "");

    if (trim($student["city"] ?? "") !== "") {
        $address_text .= ($address_text !== "" ? ", " : "")
            . trim($student["city"]);
    }

    if (trim($student["state"] ?? "") !== "") {
        $address_text .= ($address_text !== "" ? ", " : "")
            . trim($student["state"]);
    }

    if (trim($student["pincode"] ?? "") !== "") {
        $address_text .= ($address_text !== "" ? " - " : "")
            . trim($student["pincode"]);
    }

    if ($address_text === "") {
        $address_text = "-";
    }

    ?>


    <div class="profile-card">

        <div class="profile-head">

            <div class="avatar">
                <?php echo htmlspecialchars($initial); ?>
            </div>

            <div>

                <h2>
                    <?php echo htmlspecialchars($student_name); ?>
                </h2>

                <span class="badge <?php echo studentStatusBadge($student['student_status']); ?>">
                    <?php echo htmlspecialchars(ucfirst($student["student_status"])); ?>
                </span>

                <p class="sub">
                    Class
                    <?php echo htmlspecialchars($student["class_name"]); ?>
                    -
                    Section
                    <?php echo htmlspecialchars($student["section_name"]); ?>
                    &nbsp;|&nbsp;
                    Academic Year:
                    <?php echo htmlspecialchars($student["year_name"]); ?>
                </p>

            </div>

        </div>

        <h3 class="section-title">Student Details</h3>

        <div class="fields">

            <div class="field">

                <label>Student ID / Admission No.</label>

                <strong>
                    <?php echo htmlspecialchars($student["admission_no"]); ?>
                </strong>

            </div>


            <div class="field">

                <label>Roll No.</label>

                <strong>
                    <?php echo htmlspecialchars($student["roll_no"] ?? "-"); ?>
                </strong>

            </div>


            <div class="field">

                <label>Class</label>

                <strong>
                    <?php echo htmlspecialchars($student["class_name"]); ?>
                </strong>

            </div>


            <div class="field">

                <label>Section</label>

                <strong>
                    <?php echo htmlspecialchars($student["section_name"]); ?>
                </strong>

            </div>


            <div class="field">

                <label>Academic Year</label>

                <strong>
                    <?php echo htmlspecialchars($student["year_name"]); ?>
                </strong>

            </div>


            <div class="field">

                <label>Gender</label>

                <strong>
                    <?php echo htmlspecialchars(ucfirst($student["gender"] ?? "-")); ?>
                </strong>

            </div>


            <div class="field">

                <label>Date of Birth</label>

                <strong>
                    <?php echo htmlspecialchars($dob_text); ?>
                </strong>

            </div>


            <div class="field">

                <label>Blood Group</label>

                <strong>
                    <?php echo htmlspecialchars(trim($student["blood_group"] ?? "") !== "" ? $student["blood_group"] : "-"); ?>
                </strong>

            </div>


            <div class="field">

                <label>Parent / Guardian Name</label>

                <strong>
                    <?php echo htmlspecialchars(studentGuardianName($student)); ?>
                </strong>

            </div>


            <div class="field">

                <label>Contact Number</label>

                <strong>
                    <?php echo htmlspecialchars(studentContactNumber($student)); ?>
                </strong>

            </div>


            <div class="field">

                <label>Mother Name</label>

                <strong>
                    <?php echo htmlspecialchars(trim($student["mother_name"] ?? "") !== "" ? $student["mother_name"] : "-"); ?>
                </strong>

            </div>


            <div class="field">

                <label>Mother Contact</label>

                <strong>
                    <?php echo htmlspecialchars(trim($student["mother_mobile"] ?? "") !== "" ? $student["mother_mobile"] : "-"); ?>
                </strong>

            </div>


            <div class="field">

                <label>Email</label>

                <strong>
                    <?php echo htmlspecialchars(trim($student["email"] ?? "") !== "" ? $student["email"] : "-"); ?>
                </strong>

            </div>


            <div class="field">

                <label>Address</label>

                <strong>
                    <?php echo htmlspecialchars($address_text); ?>
                </strong>

            </div>


            <div class="field">

                <label>Admission Date</label>

                <strong>
                    <?php echo htmlspecialchars($admission_text); ?>
                </strong>

            </div>

        </div>

    </div>

</div>

</body>

</html>



