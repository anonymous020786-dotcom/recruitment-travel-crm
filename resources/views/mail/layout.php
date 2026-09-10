<?php /** @var string $appName @var string $appUrl */ ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0">
  <tr><td align="center">
    <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px">
      <tr><td style="padding:20px 24px;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:15px;color:#111827">
        <?= e($appName) ?>
      </td></tr>
      <tr><td style="padding:24px;font-size:14px;line-height:1.6">
        <?= $this->yield('content') ?>
      </td></tr>
      <tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af">
        This is an automated message from <?= e($appName) ?>. Please do not reply.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
