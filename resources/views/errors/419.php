<?php $this->layout('layouts.error', ['status' => 419, 'title' => 'Your session expired', 'body' => 'For your security the page timed out. Refresh and sign in again.', 'ref' => $ref ?? '']); ?>
<?php $this->start('actions'); ?><a href="/login" class="btn btn-primary">Sign in</a><?php $this->stop(); ?>
