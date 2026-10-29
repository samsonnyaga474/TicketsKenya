</main>

  <section class="tk-footer-cta" aria-labelledby="footCta">
    <img class="tk-footer-cta-bg" src="<?= tk_e(tk_asset('images/hero-bg.jpg')) ?>" alt="" width="1920" height="1372" loading="lazy" decoding="async">
    <div class="tk-footer-cta-inner">
      <h2 id="footCta">Join the movement. Let’s hustle!</h2>
      <p>Find us on any browser, iPhone and Android.</p>
      <div class="tk-hero-cta">
        <a href="/organisers.php" class="btn btn-solid">Launch An Event</a>
        <a href="/events/" class="btn btn-dark">Explore Events</a>
      </div>
    </div>
  </section>

  <footer class="tk-footer">
    <div class="tk-footer-panel">
      <div class="footer-grid">
        <div class="footer-brand">
          <a href="<?= tk_e(tk_base_path()) ?>/" class="logo">
            <img src="<?= tk_e(tk_asset('images/logo.png')) ?>" alt="" width="38" height="38" loading="lazy" />
            <span>TicketsKenya</span>
          </a>
          <p>Your trusted partner for extraordinary events in Kenya.</p>
          <ul class="footer-contact">
            <li><a href="/contact.php">Send us a message</a></li>
            <li><a href="mailto:<?= tk_e(TK_SUPPORT_EMAIL) ?>"><?= tk_e(TK_SUPPORT_EMAIL) ?></a></li>
            <li><a href="/help.php#faq">Read the FAQ</a></li>
          </ul>
          <?php
          $social = array_filter([
              'Instagram' => getenv('TK_SOCIAL_INSTAGRAM') ?: '', 'X' => getenv('TK_SOCIAL_X') ?: '',
              'YouTube' => getenv('TK_SOCIAL_YOUTUBE') ?: '', 'LinkedIn' => getenv('TK_SOCIAL_LINKEDIN') ?: '',
              'Facebook' => getenv('TK_SOCIAL_FACEBOOK') ?: '', 'TikTok' => getenv('TK_SOCIAL_TIKTOK') ?: '',
          ], fn($u) => str_starts_with((string)$u, 'https://'));
          if ($social): ?>
          <ul class="footer-social" aria-label="Social media">
            <?php foreach ($social as $name => $url): ?>
              <li><a href="<?= tk_e($url) ?>" rel="noopener noreferrer" target="_blank" aria-label="TicketsKenya on <?= tk_e($name) ?>"><?= tk_e(tk_substr($name, 0, 2)) ?></a></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
        <nav class="footer-col" aria-label="Company">
          <h2>Company</h2>
          <ul>
            <li><a href="/about.php" class="footer-link">About Us</a></li>
            <li><a href="/organisers.php" class="footer-link">For Organisers</a></li>
            <li><a href="/events/" class="footer-link">Explore Events</a></li>
            <li><a href="/contact.php" class="footer-link">Contact Us</a></li>
          </ul>
        </nav>
        <nav class="footer-col" aria-label="Learn more">
          <h2>Learn More</h2>
          <ul>
            <li><a href="/pricing.php" class="footer-link">Pricing</a></li>
            <li><a href="/help.php" class="footer-link">Help &amp; FAQ</a></li>
            <li><a href="/events/?when=weekend" class="footer-link">This Weekend</a></li>
          </ul>
        </nav>
        <nav class="footer-col" aria-label="Legal">
          <h2>Legal</h2>
          <ul>
            <li><a href="/terms.php" class="footer-link">Terms of Use</a></li>
            <li><a href="/privacy.php" class="footer-link">Privacy Policy</a></li>
            <li><a href="/refunds.php" class="footer-link">Refund Policy</a></li>
            <li><a href="/cookies.php" class="footer-link">Cookie Policy</a></li>
            <li><a href="/privacy-settings.php" class="footer-link">Privacy settings</a></li>
          </ul>
        </nav>
      </div>
      <div class="footer-bottom">
        <p>© <?= date('Y') ?> TicketsKenya. All rights reserved.</p>
        <p>Buy tickets on TicketsKenya. Payment confirmation activates when your provider is approved.</p>
      </div>
    </div>
  </footer>

  <?php require_once TK_ROOT . '/modules/site-activity/adapters/ticketskenya.php'; tk_activity_footer(); ?>
  <script src="<?= tk_e(tk_asset('js/main.js')) ?>" defer></script>
</body>
</html>
