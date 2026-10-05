<?php

/*
 |--------------------------------------------------------------------------
 | TEACHER - NOTICES : SUMMARY + FILTERS + NOTICE LIST
 |--------------------------------------------------------------------------
 | Flow: Dashboard > Notices > Notice list > Notice details > Download
 |
 | Read only apart from the teacher's own read state (Mark as Read / Unread),
 | which writes one notice_reads row for that teacher only.
 |
 | A notice is never created, edited, deleted or published from here.
 |
 | Tables used: notices, notice_attachments, notice_reads
 |              (notice_module_setup.sql), teacher_subjects, classes, sections
 */

require_once __DIR__ . "/includes/notice_common.php";


/*
 |--------------------------------------------------------------------------
 | FILTER VALUES (GET, so a list can be deep linked / bookmarked)
 |--------------------------------------------------------------------------
 | Every value is checked against the real data, so an unknown value simply
 | returns nothing instead of an error.
 */

$filter_category = trim($_GET["category"] ?? "");
$filter_priority = trim($_GET["priority"] ?? "");
$filter_read     = strtolower(trim($_GET["read"] ?? ""));
$filter_from     = trim($_GET["date_from"] ?? "");
$filter_to       = trim($_GET["date_to"] ?? "");

$valid_categories = ["General", "Meeting", "Exam", "Holiday", "Event", "Circular", "Emergency", "Other"];
$valid_priorities = ["Normal", "Important", "Urgent"];

if ($filter_category !== "" && !in_array($filter_category, $valid_categories, true)) {
    $filter_category = "";
}

if ($filter_priority !== "" && !in_array($filter_priority, $valid_priorities, true)) {
    $filter_priority = "";
}

/* only "read" and "unread" are meaningful */
if ($filter_read !== "read" && $filter_read !== "unread") {
    $filter_read = "";
}

/* only a real YYYY-MM-DD value is accepted */
$is_valid_date = function ($value) {
    return (bool)preg_match("/^\d{4}-\d{2}-\d{2}$/", $value);
};

if (!$is_valid_date($filter_from)) { $filter_from = ""; }
if (!$is_valid_date($filter_to))   { $filter_to   = ""; }

if ($filter_from !== "" && $filter_to !== "" && $filter_from > $filter_to) {
    $swap        = $filter_from;
    $filter_from = $filter_to;
    $filter_to   = $swap;
}


/*
 |--------------------------------------------------------------------------
 | LOAD NOTICES
 |--------------------------------------------------------------------------
 | Active notices and expired notices are loaded separately so the expired
 | ones can be shown in their own section at the bottom.
 */

$active_rows  = [];
$expired_rows = [];

if ($has_assignments) {

    $active_rows  = noticesFetchList($conn, $school_uid, $teacher_uid, $notice_class_uids, $notice_section_uids, false);
    $expired_rows = noticesFetchList($conn, $school_uid, $teacher_uid, $notice_class_uids, $notice_section_uids, true);
}


/*
 |--------------------------------------------------------------------------
 | APPLY FILTERS (in PHP, so the summary cards can count the same rows)
 |--------------------------------------------------------------------------
 */

$matches_filters = function ($row) use (
    $filter_category,
    $filter_priority,
    $filter_read,
    $filter_from,
    $filter_to
) {
    if ($filter_category !== "" && $row["category"] !== $filter_category) {
        return false;
    }

    if ($filter_priority !== "" && $row["priority"] !== $filter_priority) {
        return false;
    }

    if ($filter_read === "read"   && !noticesIsRead($row)) {
        return false;
    }

    if ($filter_read === "unread" && noticesIsRead($row)) {
        return false;
    }

    $publish_date = (string)$row["publish_date"];

    if ($filter_from !== "" && $publish_date < $filter_from) {
        return false;
    }

    if ($filter_to !== "" && $publish_date > $filter_to) {
        return false;
    }

    return true;
};

/* every notice, active + expired, is what the summary counts */
$all_rows = array_merge($active_rows, $expired_rows);

$summary = [
    "total"      => 0,
    "unread"     => 0,
    "important"  => 0,
    "expired"    => 0,
    "attachment" => 0,
];

/* notices that carry at least one attachment, for the "Attachment" column */
$notice_has_attachment = function ($notice_uid) use ($conn, $school_uid) {
    static $cache = null;

    if ($cache === null) {

        $sql = "SELECT notice_uid
                FROM notice_attachments
                WHERE school_uid = ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $school_uid);
        $stmt->execute();

        /* fetched in one go: get_result() can only be consumed once */
        $attachment_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $cache = [];

        foreach ($attachment_rows as $attachment_row) {
            $cache[$attachment_row["notice_uid"]] = true;
        }

        $stmt->close();
    }

    return isset($cache[$notice_uid]);
};

foreach ($all_rows as $row) {

    $summary["total"]++;

    if (!noticesIsRead($row)) {
        $summary["unread"]++;
    }

    if ($row["priority"] === "Important" || $row["priority"] === "Urgent") {
        $summary["important"]++;
    }

    if ($notice_has_attachment($row["notice_uid"])) {
        $summary["attachment"]++;
    }
}

$summary["expired"] = count($expired_rows);

/* now filter both lists for display */
$display_active  = array_values(array_filter($active_rows,  $matches_filters));
$display_expired = array_values(array_filter($expired_rows, $matches_filters));

$filters_applied = (
    $filter_category !== ""
    || $filter_priority !== ""
    || $filter_read !== ""
    || $filter_from !== ""
    || $filter_to !== ""
);

/* link that clears every filter */
$clear_filters_url = "index.php";

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Notices</title>

<style>
<?= noticeModuleStyles() ?>
</style>

</head>

<body>

<div class="container">

    <!-- ================= HEADER ================= -->

    <div class="card">

        <a class="back-link" href="../dashboard.php">&larr; Back to Dashboard</a>

        <h1>Notices</h1>

        <div class="teacher-info">
            Teacher:
            <strong><?= htmlspecialchars($teacher_name) ?></strong>
        </div>

        <div class="notice-msg">
            You can read the notices issued by the school and download any
            attachment. Creating, editing, deleting or publishing a notice is
            managed by the school administrator.
        </div>

    </div>

<?php if (!$has_assignments): ?>

    <div class="card">
        <div class="empty">
            No class or section has been assigned to you yet, so no notice can
            be matched to you. Please contact the school administrator.
        </div>
    </div>

<?php else: ?>

    <!-- ================= 1. NOTICE SUMMARY ================= -->

    <div class="card">

        <h2>Notice Summary</h2>

        <p class="muted">
            Counts cover every notice published for you, including the expired ones.
        </p>

        <div class="summary">

            <div class="summary-card accent-total">
                <span>Total Notices</span>
                <strong><?= (int)$summary["total"] ?></strong>
            </div>

            <div class="summary-card accent-unread">
                <span>New / Unread</span>
                <strong><?= (int)$summary["unread"] ?></strong>
            </div>

            <div class="summary-card accent-important">
                <span>Important</span>
                <strong><?= (int)$summary["important"] ?></strong>
            </div>

            <div class="summary-card accent-expired">
                <span>Expired</span>
                <strong><?= (int)$summary["expired"] ?></strong>
            </div>

        </div>

    </div>

    <!-- ================= 2. FILTERS ================= -->

    <div class="card">

        <h2>Filters</h2>

        <form method="get" action="index.php">

            <div class="filter-grid">

                <div>
                    <label for="category">Category</label>
                    <select name="category" id="category">
                        <option value="">All Categories</option>
                        <?php foreach ($valid_categories as $notice_cat): ?>
                            <option value="<?= htmlspecialchars($notice_cat) ?>" <?= ($filter_category === $notice_cat) ? "selected" : "" ?>><?= htmlspecialchars($notice_cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="priority">Priority</label>
                    <select name="priority" id="priority">
                        <option value="">All Priorities</option>
                        <?php foreach ($valid_priorities as $notice_pri): ?>
                            <option value="<?= htmlspecialchars($notice_pri) ?>" <?= ($filter_priority === $notice_pri) ? "selected" : "" ?>><?= htmlspecialchars($notice_pri) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="read">Read Status</label>
                    <select name="read" id="read">
                        <option value="">All</option>
                        <option value="unread" <?= ($filter_read === "unread") ? "selected" : "" ?>>Unread</option>
                        <option value="read"   <?= ($filter_read === "read")   ? "selected" : "" ?>>Read</option>
                    </select>
                </div>

                <div>
                    <label for="date_from">Date From</label>
                    <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($filter_from) ?>">
                </div>

                <div>
                    <label for="date_to">Date To</label>
                    <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($filter_to) ?>">
                </div>

            </div>

            <div class="toolbar">
                <button type="submit" class="btn">Apply Filters</button>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($clear_filters_url) ?>">Clear Filters</a>
            </div>

        </form>

    </div>

<?php

/*
 | The notice rows themselves. Rendered by a closure so the active and the
 | expired table stay identical.
 */
$render_notice_table = function ($rows, $is_expired_section) use ($notice_has_attachment) {

    if (empty($rows)) {
        return;
    }
    ?>


    <div class="card">

        <h2>
            <?= $is_expired_section ? "Expired Notices" : "Notices" ?>
            <span class="muted">(<?= count($rows) ?>)</span>
        </h2>

        <table>

            <thead>
                <tr>
                    <th>Notice</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($rows as $notice_row): ?>

                <?php
                $notice_read     = noticesIsRead($notice_row);
                $notice_attached = $notice_has_attachment($notice_row["notice_uid"]);
                $notice_priority = (string)$notice_row["priority"];
                ?>

                <tr class="<?= $notice_read ? "" : "is-unread " ?><?= ($notice_priority === "Urgent") ? "priority-urgent-row" : (($notice_priority === "Important") ? "priority-important-row" : "") ?>">

                    <td>

                        <a class="notice-title" href="view.php?uid=<?= urlencode((string)$notice_row["notice_uid"]) ?>">
                            <?= htmlspecialchars((string)$notice_row["title"]) ?>
                        </a>

                        <div class="notice-desc">
                            <?= htmlspecialchars((string)$notice_row["short_description"]) ?>
                        </div>

                        <div class="who">
                            By <?= htmlspecialchars((string)$notice_row["published_by_name"]) ?>
                            &middot; Target: <?= htmlspecialchars(noticesTargetLabel($notice_row)) ?>
                            <?php if ((int)$notice_row["is_pinned"] === 1): ?>
                                &middot; <span class="badge badge-pin">Pinned</span>
                            <?php endif; ?>
                        </div>

                    </td>

                    <td><?= htmlspecialchars(noticesDateLabel($notice_row["publish_date"])) ?></td>

                    <td><span class="chip"><?= htmlspecialchars((string)$notice_row["category"]) ?></span></td>

                    <td>
                        <span class="badge <?= htmlspecialchars(noticesPriorityCss($notice_priority)) ?>">
                            <?= htmlspecialchars($notice_priority) ?>
                        </span>
                    </td>

                    <td>
                        <?php if ($notice_read): ?>
                            <span class="badge badge-read">Read</span>
                        <?php else: ?>
                            <span class="badge badge-unread">Unread</span>
                        <?php endif; ?>

                        <?php if ($notice_attached): ?>
                            <br><span class="badge badge-att">Attachment</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <a class="btn btn-outline btn-sm" href="view.php?uid=<?= urlencode((string)$notice_row["notice_uid"]) ?>">👁️ View</a>

                        <form method="post" action="mark.php" style="display:inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="uid" value="<?= htmlspecialchars((string)$notice_row["notice_uid"]) ?>">
                            <input type="hidden" name="state" value="<?= $notice_read ? "unread" : "read" ?>">
                            <input type="hidden" name="back" value="index.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <?= $notice_read ? "Mark Unread" : "Mark Read" ?>
                            </button>
                        </form>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php
};

$render_notice_table($display_active, false);
$render_notice_table($display_expired, true);

if (empty($display_active) && empty($display_expired)) {
    ?>

    <div class="card">
        <div class="empty">
            <?= $filters_applied
                    ? "No notices match the selected filters. Try changing or clearing them."
                    : "No notice has been published for you yet." ?>
        </div>
    </div>

    <?php
}

?>

<?php endif; ?>

</div>

</body>

</html>

