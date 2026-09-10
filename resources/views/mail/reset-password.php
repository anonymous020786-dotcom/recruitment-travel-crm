<?php
/** @var string $name @var string $url @var int $expireMinutes */
$this->layout('mail.layout');
$this->start('content');
?>
<p>Hello <?= e($name) ?>,</p>
<p>We received a request to reset your <?= e($appName) ?> password. This link expires in
   <?= (int) $expireMinutes ?> minutes and can be used once.</p>
<p style="margin:20px 0">
  <a href="<?= e_attr($url) ?>" style="background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">Reset your password</a>
</p>
<p style="font-size:12px;color:#6b7280">If the button doesn't work, paste this into your browser:<br><?= e($url) ?></p>
<p>If you did not request this, no action is needed.</p>
<?php $this->stop(); ?>
