<?php $headerClass = 'relative'; $site = (string) config('site_name'); ?>
<main class="cf-form-page page-pad-top">
    <div class="container">
        <section class="cf-form-shell" aria-labelledby="contact-title">
            <header class="cf-form-head">
                <p class="cf-form-kicker">Support</p>
                <h1 id="contact-title" class="cf-form-title">Contact us</h1>
                <p class="cf-form-lead">Questions, feedback, or issues with <?= e($site) ?> — send a short message and we’ll get back when we can.</p>
            </header>

            <?php if (!empty($sent)): ?>
                <div class="cf-form-alert cf-form-alert--ok" role="status">
                    <i class="uil uil-check-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Message received</strong>
                        <span>Thanks — we’ll review it soon.</span>
                    </div>
                </div>
            <?php endif; ?>

            <form class="cf-form" method="post" action="<?= e(url('/contact')) ?>" autocomplete="on">
                <label class="cf-field">
                    <span class="cf-field-label">Name</span>
                    <input class="cf-field-input" type="text" name="name" required maxlength="120" placeholder="Your name" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Email</span>
                    <input class="cf-field-input" type="email" name="email" required maxlength="180" placeholder="you@email.com" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Message</span>
                    <textarea class="cf-field-input cf-field-input--area" name="message" rows="5" required maxlength="2000" placeholder="How can we help?"></textarea>
                </label>

                <button type="submit" class="cf-form-submit">
                    <i class="uil uil-envelope-alt" aria-hidden="true"></i>
                    Send message
                </button>
            </form>
        </section>
    </div>
</main>
