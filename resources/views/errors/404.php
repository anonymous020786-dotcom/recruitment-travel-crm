<?php $this->layout('layouts.error', ['status' => 404, 'title' => 'Page not found', 'body' => "The page you're looking for doesn't exist or has moved.", 'ref' => $ref ?? '']); ?>
<?php $this->start('actions'); ?>
<a href="/" class="btn btn-primary">Return home</a>
<a href="javascript:history.back()" class="btn btn-secondary">Go back</a>
<?php $this->stop(); ?>
