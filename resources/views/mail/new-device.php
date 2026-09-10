<?php
/** @var string $name @var string $ip @var string $device @var string $when @var string $via */
$this->layout('mail.layout');
$this->start('content');
?>
<p>Hello <?= e($name) ?>,</p>
<p>Your <?= e($appName) ?> account was just signed in from a device we haven't seen before:</p>
<table role="presentation" cellpadding="4" style="font-size:13px;color:#374151;margin:12px 0">
  <tr><td style="color:#9ca3af">When</td><td><?= e($when) ?> UTC</td></tr>
  <tr><td style="color:#9ca3af">IP address</td><td><?= e($ip) ?></td></tr>
  <tr><td style="color:#9ca3af">Device</td><td><?= e($device) ?></td></tr>
  <tr><td style="color:#9ca3af">Method</td><td><?= e($via) ?></td></tr>
</table>
<p>If this was you, you can ignore this email. If not, <a href="<?= e_attr($appUrl) ?>/account/security">review your security settings</a> and change your password right away.</p>
<?php $this->stop(); ?>
