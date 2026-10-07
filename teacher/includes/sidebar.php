<?php
/* ======================================================================
 * TEACHER PANEL - SHARED SIDEBAR (CoreUI)
 * ======================================================================
 * UI shell only. Loaded by teacher/includes/header.php.
 * Same CoreUI dark fixed sidebar look as the reference panel.
 * Menu links point to the existing teacher modules (no logic here).
 *
 * Required variables (set by the including page before header.php):
 *     $UI_ROOT    - relative path from the current page to teacher/ dir
 *                   e.g. '' for teacher/dashboard.php,
 *                        '../' for teacher/attendance/index.php
 *     $UI_ACTIVE  - active menu key: dashboard|attendance|classes|
 *                   students|homework|marks|exams|timetable|notices|profile
 * ====================================================================== */

if (!isset($UI_ROOT)) {
    $UI_ROOT = '';
}

if (!isset($UI_ACTIVE)) {
    $UI_ACTIVE = '';
}

$t_ui_menu = [
    'dashboard'  => ['label' => 'Dashboard',    'url' => $UI_ROOT . 'dashboard.php'],
    'attendance' => ['label' => 'Attendance',   'url' => $UI_ROOT . 'attendance/index.php'],
    'classes'    => ['label' => 'My Classes',   'url' => $UI_ROOT . 'classes/index.php'],
    'students'   => ['label' => 'My Students',  'url' => $UI_ROOT . 'students/index.php'],
    'homework'   => ['label' => 'Homework',     'url' => $UI_ROOT . 'homework/index.php'],
    'marks'      => ['label' => 'Marks Entry',  'url' => $UI_ROOT . 'marks/index.php'],
    'exams'      => ['label' => 'Exams',        'url' => $UI_ROOT . 'exams/index.php'],
    'timetable'  => ['label' => 'Timetable',    'url' => $UI_ROOT . 'timetable/index.php'],
    'notices'    => ['label' => 'Notices',      'url' => $UI_ROOT . 'notices/index.php'],
    'profile'    => ['label' => 'Profile',      'url' => $UI_ROOT . 'profile/index.php'],
];
?>
<div class="sidebar sidebar-dark sidebar-fixed border-end" id="sidebar">
  <div class="sidebar-header border-bottom">
    <div class="sidebar-brand me-auto">
      <svg
        role="img"
        aria-label="School Management Software Logo"
        class="sidebar-brand-full"
        width="200"
        height="50"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 312 115">
        <image
          href="<?php echo htmlspecialchars($UI_ROOT . '../incl/assets/img/sanvira-white-logo.png', ENT_QUOTES, 'UTF-8'); ?>"
          x="0"
          y="0"
          width="312"
          height="115"
          preserveAspectRatio="xMidYMid meet" />
      </svg>
      <svg
        role="img"
        aria-label="School Management Software Logo"
        class="sidebar-brand-narrow"
        width="55"
        height="40"
        viewBox="0 0 100 100"
        xmlns="http://www.w3.org/2000/svg">
        <image
          href="<?php echo htmlspecialchars($UI_ROOT . '../incl/SGS-icon.png', ENT_QUOTES, 'UTF-8'); ?>"
          x="5"
          y="5"
          width="90"
          height="90"
          preserveAspectRatio="xMidYMid meet" />
      </svg>
    </div>
    <button class="btn-close d-lg-none" type="button" data-coreui-theme="dark" aria-label="Close" onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()"></button>
  </div>

  <ul class="sidebar-nav" data-coreui="navigation" data-simplebar>
    <li class="nav-title">Teacher Panel</li>
    <?php foreach ($t_ui_menu as $t_ui_key => $t_ui_item): ?>
    <li class="nav-item">
      <a class="nav-link<?php echo ($UI_ACTIVE === $t_ui_key) ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($t_ui_item['url'], ENT_QUOTES, 'UTF-8'); ?>">
        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
          <path fill="var(--ci-primary-color, currentcolor)" d="M425.706 142.294A240 240 0 0 0 16 312v88h144v-32H48v-56c0-114.691 93.309-208 208-208s208 93.309 208 208v56H352v32h144v-88a238.43 238.43 0 0 0-70.294-169.706" class="ci-primary" />
          <path fill="var(--ci-primary-color, currentcolor)" d="M80 264h32v32H80zm160-136h32v32h-32zm-104 40h32v32h-32zm264 96h32v32h-32zm-102.778 71.1 69.2-144.173-28.85-13.848-69.183 144.135a64.141 64.141 0 1 0 28.833 13.886M256 416a32 32 0 1 1 32-32 32.036 32.036 0 0 1-32 32" class="ci-primary" />
        </svg>
        <?php echo htmlspecialchars($t_ui_item['label'], ENT_QUOTES, 'UTF-8'); ?>
      </a>
    </li>
    <?php endforeach; ?>
    <li class="nav-divider"></li>
    <li class="nav-title">Account</li>
    <li class="nav-item">
      <a class="nav-link" href="<?php echo htmlspecialchars($UI_ROOT . '../logout.php', ENT_QUOTES, 'UTF-8'); ?>">
        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
          <path fill="var(--ci-primary-color, currentcolor)" d="M77.155 272.034H351.75v-32.001H77.155l75.053-75.053v-.001l-22.628-22.626-113.681 113.68.001.001h-.001L129.58 369.715l22.628-22.627v-.001z" class="ci-primary" />
          <path fill="var(--ci-primary-color, currentcolor)" d="M160 16v32h304v416H160v32h336V16z" class="ci-primary" />
        </svg>
        Logout
      </a>
    </li>
  </ul>
  <div class="sidebar-footer border-top d-none d-md-flex">
    <button class="sidebar-toggler" type="button" data-coreui-toggle="unfoldable"></button>
  </div>
</div>

