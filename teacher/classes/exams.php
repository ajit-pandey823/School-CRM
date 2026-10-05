<?php

/*
|--------------------------------------------------------------------------
| TEACHER - CLASSES : EXAMS SHORTCUT (bridge only)
|--------------------------------------------------------------------------
| teacher/classes/view.php shows an "Exams" tile that links to this file
| with the current class / section / session.
|
| Instead of duplicating the exam list, this small bridge forwards those
| values to the read only Teacher > Exams module.
|
| Nothing is read or written here - it only redirects.
*/

$class_uid         = trim($_GET["class"] ?? ($_GET["class_uid"] ?? ""));
$section_uid       = trim($_GET["section"] ?? ($_GET["section_uid"] ?? ""));
$academic_year_uid = trim($_GET["year"] ?? ($_GET["academic_year_uid"] ?? ""));

$target = "../exams/index.php";

$query = [];

if ($class_uid !== "") {
    $query["class_uid"] = $class_uid;
}

if ($section_uid !== "") {
    $query["section_uid"] = $section_uid;
}

if ($academic_year_uid !== "") {
    $query["academic_year_uid"] = $academic_year_uid;
}

if (!empty($query)) {
    $target .= "?" . http_build_query($query);
}

header("Location: " . $target);
exit();
