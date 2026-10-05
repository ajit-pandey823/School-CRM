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
// GET CLASS / SECTION / YEAR
// =====================================================

$class_uid = isset($_GET["class"]) ? trim($_GET["class"]) : "";
$section_uid = isset($_GET["section"]) ? trim($_GET["section"]) : "";
$academic_year_uid = isset($_GET["year"]) ? trim($_GET["year"]) : "";


// =====================================================
// VALIDATION
// =====================================================

if ($class_uid === "" || $section_uid === "") {
    header("Location: index.php");
    exit();
}


// =====================================================
// GET TEACHER UID
// =====================================================

$user_id = $_SESSION["user_id"];

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

$stmt_teacher->bind_param("s", $user_id);
$stmt_teacher->execute();

$result_teacher = $stmt_teacher->get_result();

if ($result_teacher->num_rows > 0) {

    $teacher = $result_teacher->fetch_assoc();

    $teacher_uid = $teacher["teacher_uid"];
}

$stmt_teacher->close();


// =====================================================
// VERIFY TEACHER CLASS ASSIGNMENT
// =====================================================

if ($teacher_uid === "") {
    die("Teacher profile not found.");
}

$sql_verify = "
    SELECT id
    FROM teacher_subjects
    WHERE teacher_uid = ?
    AND class_uid = ?
    AND section_uid = ?
    LIMIT 1
";

$stmt_verify = $conn->prepare($sql_verify);

if (!$stmt_verify) {
    die("Assignment query error: " . $conn->error);
}

$stmt_verify->bind_param(
    "sss",
    $teacher_uid,
    $class_uid,
    $section_uid
);

$stmt_verify->execute();

$result_verify = $stmt_verify->get_result();

if ($result_verify->num_rows === 0) {
    die("You are not assigned to this class.");
}

$stmt_verify->close();


// =====================================================
// GET CLASS NAME + SECTION NAME
// =====================================================

$class_name = "Class";
$section_name = "Section";

$sql_class = "
    SELECT
        c.class_name,
        s.section_name
    FROM classes c
    INNER JOIN sections s
        ON s.section_uid = ?
    WHERE c.class_uid = ?
    LIMIT 1
";

$stmt_class = $conn->prepare($sql_class);

if ($stmt_class) {

    $stmt_class->bind_param(
        "ss",
        $section_uid,
        $class_uid
    );

    $stmt_class->execute();

    $result_class = $stmt_class->get_result();

    if ($result_class->num_rows > 0) {

        $class_data = $result_class->fetch_assoc();

        $class_name = $class_data["class_name"];
        $section_name = $class_data["section_name"];
    }

    $stmt_class->close();
}


// =====================================================
// GET STUDENTS
// =====================================================

$students = [];

$sql_students = "
    SELECT
        sa.student_uid,
        sa.roll_no,
        sa.status,
        s.*
    FROM student_academic sa

    INNER JOIN students s
        ON s.student_uid = sa.student_uid

    WHERE sa.class_uid = ?
    AND sa.section_uid = ?
";

if ($academic_year_uid !== "") {

    $sql_students .= "
        AND sa.academic_year_uid = ?
    ";

}

$sql_students .= "
    AND sa.status = 'active'
    ORDER BY sa.roll_no ASC
";


$stmt_students = $conn->prepare($sql_students);

if (!$stmt_students) {
    die("Students query error: " . $conn->error);
}


if ($academic_year_uid !== "") {

    $stmt_students->bind_param(
        "sss",
        $class_uid,
        $section_uid,
        $academic_year_uid
    );

} else {

    $stmt_students->bind_param(
        "ss",
        $class_uid,
        $section_uid
    );

}


$stmt_students->execute();

$result_students = $stmt_students->get_result();


while ($row = $result_students->fetch_assoc()) {

    $students[] = $row;

}

$stmt_students->close();


// =====================================================
// HELPER - GET STUDENT NAME
// =====================================================

function getStudentName($student)
{
    $possible_names = [
        "student_name",
        "name",
        "full_name",
        "first_name"
    ];

    foreach ($possible_names as $field) {

        if (
            isset($student[$field]) &&
            trim((string)$student[$field]) !== ""
        ) {
            return $student[$field];
        }
    }

    return $student["student_uid"] ?? "Student";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Students -
        <?php echo htmlspecialchars($class_name); ?>
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

        .info {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .info h2 {
            margin: 0 0 8px;
        }

        .info p {
            margin: 5px 0;
            color: #6b7280;
        }

        .count {
            display: inline-block;
            margin-top: 10px;
            font-weight: bold;
            color: #111827;
        }

        .table-box {
            background: #ffffff;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f9fafb;
            font-weight: 600;
        }

        tr:hover {
            background: #fafafa;
        }

        .student-name {
            font-weight: 600;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e8f5e9;
            color: #2e7d32;
            font-size: 13px;
        }

        .empty {
            text-align: center;
            padding: 45px 20px;
            color: #6b7280;
        }

    </style>

</head>

<body>


<!-- =====================================================
     HEADER
===================================================== -->

<div class="header">

    <h1>
        Students -
        <?php echo htmlspecialchars($class_name); ?>
        <?php echo htmlspecialchars($section_name); ?>
    </h1>

</div>


<!-- =====================================================
     CONTENT
===================================================== -->

<div class="container">

    <a
        href="view.php?id=<?php echo urlencode($class_uid); ?>&section=<?php echo urlencode($section_uid); ?>&year=<?php echo urlencode($academic_year_uid); ?>"
        class="back"
    >
        ← Back to Class
    </a>


    <!-- CLASS INFO -->

    <div class="info">

        <h2>
            <?php echo htmlspecialchars($class_name); ?>
            -
            <?php echo htmlspecialchars($section_name); ?>
        </h2>

        <p>
            Students assigned to this class and section.
        </p>

        <span class="count">
            Total Students: <?php echo count($students); ?>
        </span>

    </div>


    <!-- STUDENT TABLE -->

    <div class="table-box">

        <?php if (count($students) > 0): ?>

            <table>

                <thead>

                    <tr>

                        <th>Roll No.</th>

                        <th>Student Name</th>

                        <th>Student UID</th>

                        <th>Status</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($students as $student): ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $student["roll_no"] ?? "-"
                                );
                                ?>
                            </td>

                            <td class="student-name">
                                <?php
                                echo htmlspecialchars(
                                    getStudentName($student)
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $student["student_uid"] ?? "-"
                                );
                                ?>
                            </td>

                            <td>

                                <span class="status">
                                    <?php
                                    echo htmlspecialchars(
                                        $student["status"] ?? "Active"
                                    );
                                    ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php else: ?>

            <div class="empty">

                <h3>No Students Found</h3>

                <p>
                    No active students are assigned to this class and section.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


</body>

</html>