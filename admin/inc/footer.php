<?php
// admin/inc/footer.php
// Close tags opened in header and include scripts
?>
        <!-- end main content -->
      </main>
    </div>
  </div>

  <footer class="text-center small text-muted py-3">
    <div class="container">© <?= date('Y') ?> <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> — Admin</div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // small helper to confirm destructive actions
    document.addEventListener('click', function(e){
      const btn = e.target.closest('[data-confirm]');
      if (!btn) return;
      const msg = btn.getAttribute('data-confirm') || 'Bạn có chắc?';
      if (!confirm(msg)) e.preventDefault();
    });
  </script>
</body>
</html>
