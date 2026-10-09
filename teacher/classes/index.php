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
$teacher_name = "";

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
    $teacher_name = $teacher["teacher_name"] ?? "";

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

<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'classes';
$UI_TITLE        = 'My Classes';
$UI_CRUMBS       = [['label' => 'Classes', 'url' => 'index.php']];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header">
        <strong>My Classes</strong>
    </div>
    <div class="card-body">

        <p class="text-body-secondary">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </p>

        <p class="text-body-secondary">
            Classes assigned to you for teaching.
        </p>

        <?php if (empty($classes)): ?>

            <div class="alert alert-warning mb-0" role="alert">
                No active class assignment was found for this teacher.
            </div>

        <?php else: ?>

            <?php foreach ($classes as $class): ?>

                <div class="card mb-3">
                    <div class="card-body">

                    <h5 class="card-title">Assigned Class</h5>

                    <div class="mb-1 text-body-secondary">
                        Class:
                        <strong>
                            <?= htmlspecialchars($class["class_name"]) ?>
                        </strong>
                    </div>

                    <div class="mb-1 text-body-secondary">
                        Section:
                        <strong>
                            <?= htmlspecialchars($class["section_name"]) ?>
                        </strong>
                    </div>

                    <div class="mb-1 text-body-secondary">
                        Academic Year:
                        <strong>
                            <?= htmlspecialchars($class["academic_year_uid"]) ?>
                        </strong>
                    </div>

                    <a
                        class="btn btn-primary mt-2 me-2"
                        href="view.php?id=<?= urlencode($class['class_uid']) ?>&section=<?= urlencode($class['section_uid']) ?>&year=<?= urlencode($class['academic_year_uid']) ?>"
                    >
                        View Class
                    </a>

                    <a
                        class="btn btn-outline-primary mt-2"
                        href="student.php?class=<?= urlencode($class['class_uid']) ?>&section=<?= urlencode($class['section_uid']) ?>&year=<?= urlencode($class['academic_year_uid']) ?>"
                    >
                        View Students
                    </a>

                    </div>
                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>