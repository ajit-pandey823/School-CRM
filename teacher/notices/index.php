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
<?php
$UI_ROOT         = '../';
$UI_ACTIVE       = 'notices';
$UI_TITLE        = 'Notices';
$UI_CRUMBS       = [];
$UI_PROFILE_NAME = $teacher_name;
include __DIR__ . '/../includes/header.php';
?>

<style>
    /* keep the existing priority / read / pin / attachment colours on Bootstrap badges */
    .badge.priority-normal    { background:#6c757d; }
    .badge.priority-important { background:#fd7e14; }
    .badge.priority-urgent    { background:#dc3545; }
    .badge.badge-read   { background:#adb5bd; }
    .badge.badge-unread { background:#dc3545; }
    .badge.badge-pin    { background:#0d6efd; }
    .badge.badge-att    { background:#198754; }
    .chip { display:inline-block; padding:4px 10px; border-radius:6px; font-size:12px; background:#eef1f6; color:#333; border:1px solid #e0e4ec; white-space:nowrap; }
    table.notices-table tr.is-unread { background:#f0f7ff; }
    table.notices-table tr.priority-urgent-row td:first-child { box-shadow: inset 4px 0 0 #dc3545; }
    table.notices-table tr.priority-important-row td:first-child { box-shadow: inset 4px 0 0 #fd7e14; }
    .notice-title { font-weight:bold; color:#1a1a1a; text-decoration:none; }
    .notice-title:hover { text-decoration:underline; }
    .notice-desc { color:#555; font-size:13px; margin-top:3px; }
    .who { color:#6b7280; font-size:12px; margin-top:3px; }
    @media print { .no-print { display:none !important; } }
</style>

    <!-- ================= HEADER ================= -->

    <div class="card mb-4">

        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Notices</strong>
            <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a>
        </div>

        <div class="card-body">

            <p class="text-body-secondary mb-2">
                Teacher: <strong><?= htmlspecialchars($teacher_name) ?></strong>
            </p>

            <div class="alert alert-info mb-0">
                You can read the notices issued by the school and download any
                attachment. Creating, editing, deleting or publishing a notice is
                managed by the school administrator.
            </div>

        </div>

    </div>

<?php if (!$has_assignments): ?>

    <div class="alert alert-warning" role="alert">
        No class or section has been assigned to you yet, so no notice can
        be matched to you. Please contact the school administrator.
    </div>

<?php else: ?>

    <!-- ================= 1. NOTICE SUMMARY ================= -->

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Total Notices</div>
                    <div class="fs-4 fw-semibold text-primary"><?= (int)$summary["total"] ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">New / Unread</div>
                    <div class="fs-4 fw-semibold text-danger"><?= (int)$summary["unread"] ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Important</div>
                    <div class="fs-4 fw-semibold text-warning"><?= (int)$summary["important"] ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-body-secondary small text-uppercase">Expired</div>
                    <div class="fs-4 fw-semibold text-secondary"><?= (int)$summary["expired"] ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= 2. FILTERS ================= -->

    <div class="card mb-4">

        <div class="card-header"><strong>Filters</strong></div>

        <div class="card-body">

            <form method="get" action="index.php">

                <div class="row g-3">

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="category">Category</label>
                        <select class="form-select" name="category" id="category">
                            <option value="">All Categories</option>
                            <?php foreach ($valid_categories as $notice_cat): ?>
                                <option value="<?= htmlspecialchars($notice_cat) ?>" <?= ($filter_category === $notice_cat) ? "selected" : "" ?>><?= htmlspecialchars($notice_cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="priority">Priority</label>
                        <select class="form-select" name="priority" id="priority">
                            <option value="">All Priorities</option>
                            <?php foreach ($valid_priorities as $notice_pri): ?>
                                <option value="<?= htmlspecialchars($notice_pri) ?>" <?= ($filter_priority === $notice_pri) ? "selected" : "" ?>><?= htmlspecialchars($notice_pri) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="read">Read Status</label>
                        <select class="form-select" name="read" id="read">
                            <option value="">All</option>
                            <option value="unread" <?= ($filter_read === "unread") ? "selected" : "" ?>>Unread</option>
                            <option value="read"   <?= ($filter_read === "read")   ? "selected" : "" ?>>Read</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-3 col-xl-3">
                        <label class="form-label" for="date_from">Date From</label>
                        <input type="date" class="form-control" name="date_from" id="date_from" value="<?= htmlspecialchars($filter_from) ?>">
                    </div>

                    <div class="col-6 col-md-3 col-xl-3">
                        <label class="form-label" for="date_to">Date To</label>
                        <input type="date" class="form-control" name="date_to" id="date_to" value="<?= htmlspecialchars($filter_to) ?>">
                    </div>

                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
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


    <div class="card mb-4">

        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?= $is_expired_section ? "Expired Notices" : "Notices" ?></strong>
            <span class="badge bg-secondary"><?= count($rows) ?></span>
        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover align-middle notices-table mb-0">

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
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-primary btn-sm" href="view.php?uid=<?= urlencode((string)$notice_row["notice_uid"]) ?>">👁️ View</a>

                            <form method="post" action="mark.php" class="d-inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="uid" value="<?= htmlspecialchars((string)$notice_row["notice_uid"]) ?>">
                                <input type="hidden" name="state" value="<?= $notice_read ? "unread" : "read" ?>">
                                <input type="hidden" name="back" value="index.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <?= $notice_read ? "Mark Unread" : "Mark Read" ?>
                                </button>
                            </form>
                        </div>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

            </table>

            </div>

        </div>

    </div>

    <?php
};

$render_notice_table($display_active, false);
$render_notice_table($display_expired, true);

if (empty($display_active) && empty($display_expired)) {
    ?>

    <div class="card mb-4">
        <div class="card-body">
            <div class="alert alert-warning mb-0">
                <?= $filters_applied
                        ? "No notices match the selected filters. Try changing or clearing them."
                        : "No notice has been published for you yet." ?>
            </div>
        </div>
    </div>

    <?php
}

?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

