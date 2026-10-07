<?php
/* ======================================================================
 * TEACHER PANEL - SHARED PAGE HEADER (CoreUI shell)
 * ======================================================================
 * UI shell only. Every teacher page sets these BEFORE including this file:
 *
 *     $UI_ROOT         - relative path from the page to teacher/ dir
 *                        ''     for teacher/*.php
 *                        '../'  for teacher/attendance/*.php etc.
 *     $UI_ACTIVE       - sidebar menu key
 *     $UI_TITLE        - page title (head title + breadcrumb)
 *     $UI_CRUMBS       - optional breadcrumb trail
 *     $UI_PROFILE_NAME - optional teacher display name
 *
 * Same CoreUI head/sidebar/header look as the reference panel.
 * ====================================================================== */

if (!isset($UI_ROOT)) {
    $UI_ROOT = '';
}

if (!isset($UI_ACTIVE)) {
    $UI_ACTIVE = '';
}

$t_ui_head_title = 'Teacher Panel';

if (isset($UI_TITLE) && trim((string) $UI_TITLE) !== '') {
    $t_ui_head_title = (string) $UI_TITLE;
}

$t_ui_asset = rtrim($UI_ROOT, '/');

if ($t_ui_asset === '') {
    $t_ui_asset = '../incl';
} else {
    $t_ui_asset = $t_ui_asset . '/../incl';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
  <meta name="description" content="School Management Software - Teacher Panel">
  <title><?php echo htmlspecialchars($t_ui_head_title . ' | Teacher Panel', ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="apple-touch-icon" sizes="57x57" href="<?php echo htmlspecialchars($t_ui_asset . '/assets/favicon/apple-icon-57x57.png', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($t_ui_asset . '/assets/favicon/apple-icon-180x180.png', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars($t_ui_asset . '/assets/favicon/favicon-32x32.png', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?php echo htmlspecialchars($t_ui_asset . '/assets/favicon/favicon-16x16.png', ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="theme-color" content="#ffffff">
  <!-- Vendors styles-->
  <link rel="stylesheet" href="<?php echo htmlspecialchars($t_ui_asset . '/vendors/simplebar/css/simplebar.css', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($t_ui_asset . '/css/vendors/simplebar.css', ENT_QUOTES, 'UTF-8'); ?>">
  <!-- Main styles for this application-->
  <link href="<?php echo htmlspecialchars($t_ui_asset . '/css/style.css', ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
  <!-- We use those styles to show code examples, you should remove them in your application.-->
  <link href="<?php echo htmlspecialchars($t_ui_asset . '/css/examples.css', ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/js/config.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/js/color-modes.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <link href="<?php echo htmlspecialchars($t_ui_asset . '/vendors/@coreui/chartjs/css/coreui-chartjs.css', ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
</head>

<body>
<?php include __DIR__ . '/sidebar.php'; ?>
  <div class="wrapper d-flex flex-column min-vh-100">
<?php include __DIR__ . '/navbar.php'; ?>
    <div class="body flex-grow-1">
      <div class="container-lg px-4">

