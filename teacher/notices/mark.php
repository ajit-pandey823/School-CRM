<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - NOTICES : MARK AS READ / UNREAD
 |--------------------------------------------------------------------------
 | The ONLY write this module performs, and only on the teacher's own read
 | state (one notice_reads row). The notice itself is never changed.
 |
 | POST only, so a crafted link cannot flip a notice.
 */

require_once __DIR__ . "/includes/notice_common.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: index.php");
    exit();
}

/* CSRF - token galat/missing ho to 403 */
csrfVerify();

$notice_uid = trim($_POST["uid"] ?? "");
$state      = strtolower(trim($_POST["state"] ?? ""));
$back       = trim($_POST["back"] ?? "");

/*
 | The "back" value is only used when it is a plain relative link inside this
 | module, so it can never be turned into an open redirect.
 */
if ($back === "" || strpos($back, "..") !== false || preg_match("#^[a-zA-Z][a-zA-Z0-9+.-]*:#", $back)) {
    $back = "index.php";
}

if ($notice_uid === "" || ($state !== "read" && $state !== "unread") || !$has_assignments) {
    header("Location: " . $back);
    exit();
}

/* only a notice published for this teacher can be marked */
$notice = noticesFetchOne($conn, $school_uid, $teacher_uid, $notice_class_uids, $notice_section_uids, $notice_uid);

if ($notice === null) {
    header("Location: " . $back);
    exit();
}

noticesSetRead($conn, $school_uid, $teacher_uid, $notice_uid, ($state === "read"));

header("Location: " . $back);
exit();
