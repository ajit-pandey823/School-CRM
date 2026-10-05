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
// GET ASSIGNED CLASSES
// =====================================================

$classes = [];

if (!empty($teacher_uid)) {

    $sql = "
        SELECT DISTINCT
            ts.class_uid,
            ts.section_uid,
            ts.academic_year_uid,
            c.class_name,
            s.section_name
        FROM teacher_subjects ts

        INNER JOIN classes c
            ON c.class_uid = ts.class_uid

        INNER JOIN sections s
            ON s.section_uid = ts.section_uid

        WHERE ts.teacher_uid = ?
        AND c.status = 'active'
        AND s.status = 'active'

        ORDER BY c.numeric_order ASC, s.section_name ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Class query error: " . $conn->error);
    }

    $stmt->bind_param("s", $teacher_uid);

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }

    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Classes</title>

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

        .title-box {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .title-box h2 {
            margin: 0 0 8px;
        }

        .title-box p {
            margin: 0;
            color: #6b7280;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }

        .class-card {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            text-decoration: none;
            color: #111827;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: 0.2s;
        }

        .class-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.10);
        }

        .class-icon {
            font-size: 35px;
            margin-bottom: 15px;
        }

        .class-card h3 {
            margin: 0 0 8px;
        }

        .class-card p {
            margin: 5px 0;
            color: #6b7280;
        }

        .view {
            display: inline-block;
            margin-top: 15px;
            color: #2563eb;
            font-weight: 600;
        }

        .empty {
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #6b7280;
        }

    </style>

</head>

<body>


<div class="header">

    <h1>My Classes</h1>

</div>


<div class="container">

    <a href="../dashboard.php" class="back">
        ← Back to Dashboard
    </a>


    <div class="title-box">

        <h2>Assigned Classes</h2>

        <p>
            Classes assigned to you for teaching.
        </p>

    </div>


    <?php if (count($classes) > 0): ?>

        <div class="grid">

            <?php foreach ($classes as $class): ?>

                <a
                    href="view.php?id=<?php echo urlencode($class['class_uid']); ?>&section=<?php echo urlencode($class['section_uid']); ?>&year=<?php echo urlencode($class['academic_year_uid']); ?>"
                    class="class-card"
                >

                    <div class="class-icon">
                        📚
                    </div>

                    <h3>
                        <?php echo htmlspecialchars($class["class_name"]); ?>
                    </h3>

                    <p>
                        Section:
                        <?php echo htmlspecialchars($class["section_name"]); ?>
                    </p>

                    <p>
                        Academic Year:
                        <?php echo htmlspecialchars($class["academic_year_uid"]); ?>
                    </p>

                    <span class="view">
                        View Class →
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">

            <h3>No Classes Assigned</h3>

            <p>
                No active class assignment was found for this teacher.
            </p>

        </div>

    <?php endif; ?>


</div>

</body>

</html>