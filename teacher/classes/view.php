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

$user_id = $_SESSION["user_id"];


// =====================================================
// GET SELECTED CLASS
// =====================================================

$class_uid = isset($_GET["id"]) ? trim($_GET["id"]) : "";
$section_uid = isset($_GET["section"]) ? trim($_GET["section"]) : "";
$academic_year_uid = isset($_GET["year"]) ? trim($_GET["year"]) : "";

if ($class_uid === "" || $section_uid === "") {
    header("Location: index.php");
    exit();
}


// =====================================================
// GET TEACHER UID
// =====================================================
//
// teachers.user_uid VARCHAR column hai (jaise "USR00000003"),
// isliye session ka numeric user_id nahi,
// session ka user_uid use karna hai.
//

$user_uid = $_SESSION["user_uid"] ?? "";

$teacher_uid = "";

$sql_teacher = "
    SELECT teacher_uid
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
// CHECK CLASS ASSIGNMENT
// =====================================================

if ($teacher_uid === "") {
    die("Teacher profile not found.");
}

$sql_class = "
    SELECT DISTINCT
        ts.class_uid,
        ts.section_uid,
        ts.academic_year_uid,
        c.class_name,
        s.section_name,
        s.class_teacher_uid,
        ay.year_name,
        ct.teacher_name AS class_teacher_name
    FROM teacher_subjects ts

    INNER JOIN classes c
        ON c.class_uid = ts.class_uid

    INNER JOIN sections s
        ON s.section_uid = ts.section_uid

    LEFT JOIN academic_years ay
        ON ay.academic_year_uid = ts.academic_year_uid

    LEFT JOIN teachers ct
        ON ct.teacher_uid = s.class_teacher_uid

    WHERE ts.teacher_uid = ?
    AND ts.class_uid = ?
    AND ts.section_uid = ?
    AND c.status = 'active'
    AND s.status = 'active'
    LIMIT 1
";

$stmt_class = $conn->prepare($sql_class);

if (!$stmt_class) {
    die("Class query error: " . $conn->error);
}

$stmt_class->bind_param(
    "sss",
    $teacher_uid,
    $class_uid,
    $section_uid
);

$stmt_class->execute();

$result_class = $stmt_class->get_result();

if ($result_class->num_rows === 0) {
    die("You are not assigned to this class.");
}

$class = $result_class->fetch_assoc();

$stmt_class->close();


// =====================================================
// CLASS DATA
// =====================================================

$class_name = $class["class_name"];
$section_name = $class["section_name"];
$academic_year = $class["academic_year_uid"];

$academic_year_name = trim($class["year_name"] ?? "");

if ($academic_year_name === "") {
    $academic_year_name = $academic_year;
}

$class_teacher_name = trim($class["class_teacher_name"] ?? "");

if ($class_teacher_name === "") {
    $class_teacher_name = "Not Assigned";
}


// =====================================================
// COUNT STUDENTS
// =====================================================

$student_count = 0;

$sql_students = "
    SELECT COUNT(*) AS total_students
    FROM student_academic
    WHERE class_uid = ?
    AND section_uid = ?
    AND status = 'active'
";

$stmt_students = $conn->prepare($sql_students);

if ($stmt_students) {

    $stmt_students->bind_param(
        "ss",
        $class_uid,
        $section_uid
    );

    $stmt_students->execute();

    $result_students = $stmt_students->get_result();

    if ($result_students->num_rows > 0) {

        $student_data = $result_students->fetch_assoc();

        $student_count = (int) $student_data["total_students"];
    }

    $stmt_students->close();
}


// =====================================================
// SUBJECTS ASSIGNED TO THIS CLASS
// =====================================================

$subjects = [];

$sql_subjects = "
    SELECT DISTINCT sub.subject_name
    FROM teacher_subjects ts

    INNER JOIN subjects sub
        ON sub.subject_uid = ts.subject_uid

    WHERE ts.class_uid = ?
    AND ts.section_uid = ?
    AND sub.status = 'active'

    ORDER BY sub.subject_name ASC
";

$stmt_subjects = $conn->prepare($sql_subjects);

if ($stmt_subjects) {

    $stmt_subjects->bind_param(
        "ss",
        $class_uid,
        $section_uid
    );

    $stmt_subjects->execute();

    $result_subjects = $stmt_subjects->get_result();

    while ($row_subject = $result_subjects->fetch_assoc()) {
        $subjects[] = $row_subject["subject_name"];
    }

    $stmt_subjects->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?php echo htmlspecialchars($class_name); ?>
        -
        <?php echo htmlspecialchars($section_name); ?>
    </title>

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

        .class-info {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .class-info h2 {
            margin: 0 0 15px;
        }

        .details {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
        }

        .detail {
            color: #6b7280;
        }

        .detail strong {
            display: block;
            color: #111827;
            font-size: 18px;
            margin-top: 5px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .card {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            text-decoration: none;
            color: #111827;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: 0.2s;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.10);
        }

        .icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        .card h3 {
            margin: 0 0 8px;
        }

        .card p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }

        .student-count {
            margin-top: 20px;
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
        }

        .student-count h3 {
            margin: 0;
        }

        .number {
            font-size: 32px;
            font-weight: bold;
            margin-top: 8px;
        }

        .subjects {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .subjects h4 {
            margin: 0 0 12px;
            color: #374151;
            font-size: 15px;
        }

        .subject-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .subject-tag {
            display: inline-block;
            background: #e7f1ff;
            color: #2563eb;
            padding: 7px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .subject-tag.none {
            background: #f3f4f6;
            color: #6b7280;
            font-weight: normal;
        }

    </style>

</head>

<body>


<!-- =====================================================
     HEADER
===================================================== -->

<div class="header">

    <h1>
        <?php echo htmlspecialchars($class_name); ?>
        -
        <?php echo htmlspecialchars($section_name); ?>
    </h1>

</div>


<!-- =====================================================
     CONTENT
===================================================== -->

<div class="container">

    <a href="index.php" class="back">
        ← Back to Classes
    </a>


    <!-- CLASS OVERVIEW -->

    <div class="class-info">

        <h2>Class Overview</h2>

        <div class="details">

            <div class="detail">

                Class

                <strong>
                    <?php echo htmlspecialchars($class_name); ?>
                </strong>

            </div>


            <div class="detail">

                Section

                <strong>
                    <?php echo htmlspecialchars($section_name); ?>
                </strong>

            </div>


            <div class="detail">

                Academic Year

                <strong>
                    <?php echo htmlspecialchars($academic_year_name); ?>
                </strong>

            </div>


            <div class="detail">

                Class Teacher

                <strong>
                    <?php echo htmlspecialchars($class_teacher_name); ?>
                </strong>

            </div>


            <div class="detail">

                Total Students

                <strong>
                    <?php echo (int) $student_count; ?>
                </strong>

            </div>

        </div>


        <!-- SUBJECTS ASSIGNED -->

        <div class="subjects">

            <h4>Subjects assigned</h4>

            <div class="subject-list">

                <?php if (empty($subjects)): ?>

                    <span class="subject-tag none">
                        No subjects assigned yet.
                    </span>

                <?php else: ?>

                    <?php foreach ($subjects as $subject): ?>

                        <span class="subject-tag">
                            <?php echo htmlspecialchars($subject); ?>
                        </span>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- MODULES -->

    <div class="grid">


        <a
            href="students.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">👨‍🎓</div>

            <h3>Students</h3>

            <p>
                View students of this class.
            </p>

        </a>


        <a
            href="attendance.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">📋</div>

            <h3>Attendance</h3>

            <p>
                Manage class attendance.
            </p>

        </a>


        <a
            href="homework.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">📝</div>

            <h3>Homework</h3>

            <p>
                Manage class homework.
            </p>

        </a>


        <a
            href="marks.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">📊</div>

            <h3>Marks & Performance</h3>

            <p>
                Manage student marks.
            </p>

        </a>


        <a
            href="timetable.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">🕐</div>

            <h3>Timetable</h3>

            <p>
                View class timetable.
            </p>

        </a>


        <a
            href="notices.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">📢</div>

            <h3>Notices</h3>

            <p>
                View class notices.
            </p>

        </a>


        <a
            href="exams.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">📚</div>

            <h3>Exams</h3>

            <p>
                View class exams.
            </p>

        </a>


        <a
            href="reports.php?class=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year); ?>"
            class="card"
        >

            <div class="icon">📈</div>

            <h3>Reports</h3>

            <p>
                View class reports.
            </p>

        </a>

    </div>


    <!-- STUDENT COUNT -->

    <div class="student-count">

        <h3>Total Students</h3>

        <div class="number">
            <?php echo $student_count; ?>
        </div>

    </div>


</div>

</body>

</html>