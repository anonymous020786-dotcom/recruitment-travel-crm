<?php $this->layout('layouts.error', ['status' => 403, 'title' => 'Access denied', 'body' => 'You do not have permission to view this page. If you think this is a mistake, contact an administrator.', 'ref' => $ref ?? '']); ?>
<?php $this->start('actions'); ?><a href="/dashboard" class="btn btn-primary">Go to dashboard</a><?php $this->stop(); ?>
