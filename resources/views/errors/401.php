<?php $this->layout('layouts.error', ['status' => 401, 'title' => 'Sign in required', 'body' => 'You need to sign in to continue.', 'ref' => $ref ?? '']); ?>
<?php $this->start('actions'); ?><a href="/login" class="btn btn-primary">Sign in</a><?php $this->stop(); ?>
