<?php $this->layout('layouts.error', ['status' => 422, 'title' => 'Could not save', 'body' => 'Please review the highlighted fields and try again.', 'ref' => $ref ?? '']); ?>
<?php $this->start('actions'); ?><a href="javascript:history.back()" class="btn btn-primary">Go back</a><?php $this->stop(); ?>
