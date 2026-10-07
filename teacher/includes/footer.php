<?php
/* ======================================================================
 * TEACHER PANEL - SHARED PAGE FOOTER (CoreUI shell)
 * ======================================================================
 * UI shell only. Closes the body/container opened by header.php and
 * loads the same scripts as the reference panel footer.
 * ====================================================================== */

if (!isset($UI_ROOT)) {
    $UI_ROOT = '';
}

$t_ui_asset = rtrim($UI_ROOT, '/');

if ($t_ui_asset === '') {
    $t_ui_asset = '../incl';
} else {
    $t_ui_asset = $t_ui_asset . '/../incl';
}
?>
      </div>
    </div>

    <footer class="footer px-4">
      <div>
        <a href="#">School Management Software</a>
        &copy; 2026 creativeLabs.
      </div>
      <div class="ms-auto">
        Powered by&nbsp;
        <a href="#">Teacher Panel</a>
      </div>
    </footer>
  </div>
  <!-- CoreUI and necessary plugins-->
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/vendors/@coreui/coreui/js/coreui.bundle.min.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/vendors/simplebar/js/simplebar.min.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script>
    const header = document.querySelector("header.header");

    document.addEventListener("scroll", () => {
      if (header) {
        header.classList.toggle("shadow-sm", document.documentElement.scrollTop > 0);
      }
    });
  </script>
  <!-- Plugins and scripts required by this view-->
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/vendors/chart.js/js/chart.umd.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/vendors/@coreui/chartjs/js/coreui-chartjs.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/vendors/@coreui/utils/js/index.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script src="<?php echo htmlspecialchars($t_ui_asset . '/js/main.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>

</html>

