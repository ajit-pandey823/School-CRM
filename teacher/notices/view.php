<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - NOTICES : NOTICE DETAILS
 |--------------------------------------------------------------------------
 | Opening a notice marks it as read for this teacher (a single
 | notice_reads row). Nothing else is ever written, and a notice is never
 | edited or deleted from here.
 */

require_once __DIR__ . "/includes/notice_common.php";

$notice_uid = trim($_GET["uid"] ?? "");

$notice = ($notice_uid !== "" && $has_assignments)
    ? noticesFetchOne($conn, $school_uid, $teacher_uid, $notice_class_uids, $notice_section_uids, $notice_uid)
    : null;

$notice_missing = ($notice === null);

/* the message shown when the notice cannot be opened */
$missing_reason = ($notice_uid === "")
    ? "No notice was selected."
    : "Notice not found, or it is not published for your class, section or you.";

/*
 | Opening a notice marks it read. Done only when it was still unread so the
 | original "read at" timestamp is never overwritten.
 */
$auto_marked = false;

if ($notice !== null && !noticesIsRead($notice)) {

    if (noticesSetRead($conn, $school_uid, $teacher_uid, $notice_uid, true)) {
        $auto_marked = true;
    }

    /* refresh the row so the page shows the new state */
    $notice = noticesFetchOne($conn, $school_uid, $teacher_uid, $notice_class_uids, $notice_section_uids, $notice_uid);
}

$attachments = ($notice !== null)
    ? noticesFetchAttachments($conn, $school_uid, $notice_uid)
    : [];

$is_read     = ($notice !== null) ? noticesIsRead($notice) : false;
$is_expired  = ($notice !== null) ? noticesIsExpired($notice) : false;
$publish_dt  = ($notice !== null)
    ? trim(noticesDateLabel($notice["publish_date"]) . " " . noticesTimeLabel($notice["publish_time"]))
    : "-";

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Notice Details</title>

<style>
<?= noticeModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <div class="card no-print">

        <a class="back-link" href="index.php">&larr; Back to Notices</a>

        <h1>Notice Details</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <?php if ($notice !== null): ?>
            <div class="toolbar" style="margin-top:14px;">
                <a class="btn btn-outline" href="index.php">📋 Back to Notice List</a>

                <form method="post" action="mark.php" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="uid" value="<?= htmlspecialchars($notice_uid) ?>">
                    <input type="hidden" name="state" value="<?= $is_read ? "unread" : "read" ?>">
                    <input type="hidden" name="back" value="view.php?uid=<?= urlencode($notice_uid) ?>">
                    <button type="submit" class="btn btn-green">
                        <?= $is_read ? "🔖 Mark as Unread" : "✅ Mark as Read" ?>
                    </button>
                </form>

                <a class="btn" href="view.php?uid=<?= urlencode($notice_uid) ?>" onclick="window.print(); return false;">🖨️ Print</a>
            </div>
        <?php endif; ?>

    </div>

<?php if ($notice_missing): ?>

    <div class="card">
        <div class="empty"><?= htmlspecialchars($missing_reason) ?></div>
    </div>

<?php else: ?>

    <?php if ($auto_marked): ?>
        <div class="notice-msg ok">This notice has been marked as read.</div>
    <?php endif; ?>

    <!-- ================= NOTICE HEADER ================= -->

    <div class="card">

        <h2><?= htmlspecialchars((string)$notice["title"]) ?></h2>

        <p class="muted"><?= htmlspecialchars((string)$notice["short_description"]) ?></p>

        <p style="margin-bottom:0;">
            <span class="badge <?= htmlspecialchars(noticesPriorityCss($notice["priority"])) ?>"><?= htmlspecialchars((string)$notice["priority"]) ?></span>
            <span class="chip"><?= htmlspecialchars((string)$notice["category"]) ?></span>
            <?php if ($is_read): ?>
                <span class="badge badge-read">Read</span>
            <?php else: ?>
                <span class="badge badge-unread">Unread</span>
            <?php endif; ?>
            <?php if ($is_expired): ?>
                <span class="badge badge-expired">Expired</span>
            <?php endif; ?>
        </p>

    </div>

    <!-- ================= NOTICE INFORMATION ================= -->

    <div class="card">

        <h2>Notice Information</h2>

        <div class="detail-grid">

            <div class="detail-item">
                <span>Published Date &amp; Time</span>
                <strong><?= htmlspecialchars($publish_dt) ?></strong>
            </div>

            <div class="detail-item">
                <span>Published By</span>
                <strong>
                    <?= htmlspecialchars(
                        (($notice["author_name"] ?? "") !== "") ? $notice["author_name"]
                        : ((string)$notice["published_by_name"])
                    ) ?>
                </strong>
            </div>

            <div class="detail-item">
                <span>Applicable Class / Section</span>
                <strong><?= htmlspecialchars(noticesTargetLabel($notice)) ?></strong>
            </div>

            <div class="detail-item">
                <span>Priority</span>
                <strong><?= htmlspecialchars((string)$notice["priority"]) ?></strong>
            </div>

            <div class="detail-item">
                <span>Notice Expiry Date</span>
                <strong>
                    <?= htmlspecialchars(
                        (trim((string)$notice["expiry_date"]) === "")
                            ? "No Expiry"
                            : noticesDateLabel($notice["expiry_date"])
                    ) ?>
                </strong>
            </div>

        </div>

    </div>

    <!-- ================= FULL CONTENT ================= -->

    <div class="card">

        <h2>Notice Content</h2>

        <div class="notice-content"><?= htmlspecialchars(trim((string)$notice["content"])) ?></div>

    </div>



    <!-- ================= ATTACHMENTS ================= -->

    <div class="card">

        <h2>Attachments</h2>

        <?php if (empty($attachments)): ?>

            <div class="empty">No attachment has been added to this notice.</div>

        <?php else: ?>

            <?php foreach ($attachments as $attach): ?>

                <div class="attach-row">

                    <span class="attach-icon"><?= htmlspecialchars(noticesFileIcon($attach["file_type"])) ?></span>

                    <div class="attach-info">
                        <div class="attach-name"><?= htmlspecialchars((string)$attach["file_name"]) ?></div>
                        <div class="attach-meta">
                            <?= htmlspecialchars(noticesFileTypeLabel($attach["file_type"])) ?>
                            &middot; <?= htmlspecialchars(noticesFileSize($attach["file_size"])) ?>
                        </div>
                    </div>

                    <a
                        class="btn btn-outline btn-sm"
                        href="download.php?uid=<?= urlencode($notice_uid) ?>&amp;file=<?= urlencode((string)$attach["attachment_uid"]) ?>&amp;mode=view"
                        target="_blank">
                        👁️ View
                    </a>

                    <a
                        class="btn btn-sm"
                        href="download.php?uid=<?= urlencode($notice_uid) ?>&amp;file=<?= urlencode((string)$attach["attachment_uid"]) ?>&amp;mode=download">
                        ⬇️ Download
                    </a>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

<?php endif; ?>

</div>

</body>

</html>

