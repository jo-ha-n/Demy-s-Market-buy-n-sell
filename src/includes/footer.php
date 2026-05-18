<?php
  $hideFooter = $hideFooter ?? false;
?>
</main>
<?php if (!$hideFooter): ?>
<footer class="site-footer">
  <div class="footer-inner">
    <span class="footer-logo">Logo</span>
    <span class="footer-copy">© <?= date('Y') ?> — Buy &amp; Sell community marketplace</span>
    <div class="footer-links">
      <a href="/demys/pages/search.php">Browse</a>
      <a href="/demys/pages/sell.php">Sell</a>
      <a href="#">Privacy</a>
      <a href="#">Terms</a>
    </div>
  </div>
</footer>
<?php endif; ?>

<script src="/demys/assets/js/main.js"></script>
<?= $extraScript ?? '' ?>
</body>
</html>
