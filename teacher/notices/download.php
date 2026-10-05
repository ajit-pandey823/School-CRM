<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - NOTICES : ATTACHMENT VIEW / DOWNLOAD
 |--------------------------------------------------------------------------
 | Serves the file only after re-checking that the teacher session is valid
 | AND that the notice is published for this teacher, so an attachment can
 | never be fetched by guessing a file name.
 |
 | The stored file_path is always resolved inside the project root, so a
 | crafted value such as "../../config/db.php" cannot escape the uploads
 | folder.
 */

require_once __DIR__ . "/includes/notice_common.php";

$notice_uid = trim($_GET["uid"] ?? "");
$file_uid   = trim($_GET["file"] ?? "");
$mode       = (($_GET["mode"] ?? "view") === "download") ? "download" : "view";

/* the file must be inside the project root */
$project_root = realpath(__DIR__ . "/../..");
$uploads_root = realpath($project_root . DIRECTORY_SEPARATOR . "uploads");

if ($project_root === false || $uploads_root === false) {
    header("HTTP/1.0 500 Internal Server Error");
    exit("Upload folder is not available.");
}

if ($notice_uid === "" || $file_uid === "" || !$has_assignments) {
    header("HTTP/1.0 404 Not Found");
    exit("Attachment not found.");
}

/* the notice must be published for this teacher */
$notice = noticesFetchOne($conn, $school_uid, $teacher_uid, $notice_class_uids, $notice_section_uids, $notice_uid);

if ($notice === null) {
    header("HTTP/1.0 404 Not Found");
    exit("Attachment not found.");
}

/* the attachment must belong to that notice */
$sql = "SELECT file_name, file_path, file_type, mime_type
        FROM notice_attachments
        WHERE school_uid = ?
          AND notice_uid = ?
          AND attachment_uid = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Attachment query failed: " . $conn->error);
}

$stmt->bind_param("sss", $school_uid, $notice_uid, $file_uid);
$stmt->execute();
$attach = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attach) {
    header("HTTP/1.0 404 Not Found");
    exit("Attachment not found.");
}

/*
 | Resolve the stored relative path and make sure the real file still lives
 | inside uploads/ - this blocks any attempt to read a file outside it.
 */
$real_file = realpath($project_root . DIRECTORY_SEPARATOR . $attach["file_path"]);

if (
    $real_file === false
    || !is_file($real_file)
    || strpos($real_file, $uploads_root . DIRECTORY_SEPARATOR) !== 0
) {
    header("HTTP/1.0 404 Not Found");
    exit("The attachment file is missing on the server.");
}

/* a sane download name, without any path characters */
$download_name = preg_replace("/[^A-Za-z0-9._-]/", "_", (string)$attach["file_name"]);

if ($download_name === "" || $download_name === null) {
    $download_name = "attachment";
}

$browser_never_shows = [
    "php", "php3", "php4", "php5", "php7", "phtml", "phar", "exe", "bat",
    "cmd", "com", "cgi", "pl", "js", "htm", "html", "svg",
];

$extension = strtolower((string)pathinfo($real_file, PATHINFO_EXTENSION));

if (in_array($extension, $browser_never_shows, true)) {
    header("HTTP/1.0 403 Forbidden");
    exit("This file type cannot be opened.");
}

/* let the browser decide how to show it, unless the teacher asked to save it */
if ($mode === "download") {
    header("Content-Description: File Transfer");
    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename=\"" . $download_name . "\"");
    header("Content-Transfer-Encoding: binary");
} else {

    $mime = trim((string)$attach["mime_type"]);

    if ($mime === "") {

        /* fall back to a type based on the extension */
        $fallback = [
            "pdf"  => "application/pdf",
            "png"  => "image/png",
            "jpg"  => "image/jpeg",
            "jpeg" => "image/jpeg",
            "gif"  => "image/gif",
            "webp" => "image/webp",
            "txt"  => "text/plain",
            "doc"  => "application/msword",
            "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "xls"  => "application/vnd.ms-excel",
            "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ];

        $mime = $fallback[$extension] ?? "application/octet-stream";
    }

    header("Content-Type: " . $mime);
    header("Content-Disposition: inline; filename=\"" . $download_name . "\"");
}

header("Content-Length: " . filesize($real_file));
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, max-age=0, must-revalidate");

readfile($real_file);
exit();
