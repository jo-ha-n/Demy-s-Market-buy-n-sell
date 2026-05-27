<?php
  $hideFooter = $hideFooter ?? false;
?>
</main>
<?php if (!$hideFooter): ?>
<footer class="site-footer">
  <div class="footer-inner">
    <a href="/GitHub/Demy-s-Market-buy-n-sell/src/index.html" class="footer-logo-img">Demy's</a>
    <span class="footer-copy">© <?= date('Y') ?> — Buy &amp; Sell community marketplace</span>
    <div class="footer-links">
      <a href="../pages/search.php">Browse</a>
      <a href="../pages/sell.php">Sell</a>
      <a href="#">Privacy</a>
      <a href="#">Terms</a>
    </div>
  </div>
</footer>
<?php endif; ?>

<script src="../assets/js/main.js"></script>
<?= $extraScript ?? '' ?>
</body>
</html>
