<?php

/**
 * =====================================================
 * SUPER ADMIN DASHBOARD
 * =====================================================
 *
 * Sirf super_admin role ke liye.
 * Agar koi aur role is page par aaya to usko uske apne
 * dashboard par redirect kar diya jata hai.
 */

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../auth.php";


/* =====================================================
   1. AUTH GUARD (login + super_admin role)
===================================================== */

authRequireType($conn, ["super_admin"], "../login.php", "../");


/* =====================================================
   2. COUNTS
===================================================== */

function superAdminCount($conn, $table)
{
    $allowed = ["users", "teachers", "students", "schools"];

    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    $stmt = executeStatement($conn, "SELECT COUNT(*) AS total FROM `$table`");

    $row = $stmt ? fetchSingleRow($stmt) : false;

    return $row ? (int)$row["total"] : 0;
}

$total_users    = superAdminCount($conn, "users");
$total_teachers = superAdminCount($conn, "teachers");
$total_students = superAdminCount($conn, "students");
$total_schools  = superAdminCount($conn, "schools");


/* =====================================================
   3. RECENT LOGINS (login_user table)
===================================================== */

$recent_logins = [];

$login_stmt = executeStatement(
    $conn,
    "SELECT login_uid, username, role_name, ip_address, device,
            login_time, logout_time, status
     FROM login_user
     ORDER BY id DESC
     LIMIT 10"
);

if ($login_stmt) {
    $recent_logins = fetchAllRows($login_stmt);
}


/* =====================================================
   4. USER INFO
===================================================== */

$display_name = trim($_SESSION["name"] ?? "");

if ($display_name === "") {
    $display_name = $_SESSION["username"] ?? "Super Admin";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; background: #f5f6fa; color: #344767; }
        .topbar { background: #1e293b; color: #fff; padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { font-size: 20px; font-weight: 600; }
        .topbar .user { font-size: 14px; opacity: .85; }
        .topbar a { color: #fff; background: #dc3545; text-decoration: none; padding: 8px 18px; border-radius: 6px; font-size: 14px; }
        .topbar a:hover { background: #bb2d3b; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; margin-bottom: 30px; }
        .card { background: #fff; border-radius: 10px; padding: 22px; box-shadow: 0 2px 6px rgba(0,0,0,.07); }
        .card .label { font-size: 13px; color: #8898aa; text-transform: uppercase; }
        .card .value { font-size: 30px; font-weight: 700; margin-top: 8px; color: #1e293b; }
        .panel { background: #fff; border-radius: 10px; padding: 22px; box-shadow: 0 2px 6px rgba(0,0,0,.07); }
        .panel h2 { font-size: 17px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #eef0f4; }
        th { color: #8898aa; font-weight: 600; font-size: 12px; text-transform: uppercase; }
        .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; }
        .badge-active { background: #d1e7dd; color: #0f5132; }
        .badge-out { background: #e2e3e5; color: #41464b; }
        .empty { color: #8898aa; font-size: 14px; padding: 10px 0; }
    </style>
</head>
<body>

    <div class="topbar">
        <h1>Super Admin Dashboard</h1>
        <div style="display:flex; align-items:center; gap:18px;">
            <span class="user"><?php echo htmlspecialchars($display_name); ?></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">

        <div class="cards">
            <div class="card">
                <div class="label">Total Users</div>
                <div class="value"><?php echo $total_users; ?></div>
            </div>
            <div class="card">
                <div class="label">Total Teachers</div>
                <div class="value"><?php echo $total_teachers; ?></div>
            </div>
            <div class="card">
                <div class="label">Total Students</div>
                <div class="value"><?php echo $total_students; ?></div>
            </div>
            <div class="card">
                <div class="label">Total Schools</div>
                <div class="value"><?php echo $total_schools; ?></div>
            </div>
        </div>

        <div class="panel">
            <h2>Recent Logins</h2>

            <?php if (empty($recent_logins)): ?>
                <div class="empty">No login records yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Login UID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>IP</th>
                            <th>Device</th>
                            <th>Login Time</th>
                            <th>Logout Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logins as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log["login_uid"]); ?></td>
                                <td><?php echo htmlspecialchars($log["username"]); ?></td>
                                <td><?php echo htmlspecialchars($log["role_name"] ?? "-"); ?></td>
                                <td><?php echo htmlspecialchars($log["ip_address"]); ?></td>
                                <td><?php echo htmlspecialchars($log["device"] ?? "-"); ?></td>
                                <td><?php echo htmlspecialchars($log["login_time"]); ?></td>
                                <td><?php echo htmlspecialchars($log["logout_time"] ?? "-"); ?></td>
                                <td>
                                    <?php if ($log["status"] === "active"): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-out">
                                            <?php echo htmlspecialchars($log["status"]); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>


</body>
</html>
