<?php
/** @var string $name @var string $code @var int $ttlMinutes */
$this->layout('mail.layout');
$this->start('content');
?>
<p>Hello <?= e($name) ?>,</p>
<p>Your <?= e($appName) ?> verification code is:</p>
<p style="font-size:26px;letter-spacing:4px;font-weight:700;margin:16px 0"><?= e($code) ?></p>
<p>It expires in <?= (int) $ttlMinutes ?> minutes. If you did not request it, change your password immediately.</p>
<?php $this->stop(); ?>
