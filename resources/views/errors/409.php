<?php $this->layout('layouts.error', ['status' => 409, 'title' => 'This record changed', 'body' => 'Someone else updated this record since you opened it. Reload the page and reapply your changes.', 'ref' => $ref ?? '']); ?>
<?php $this->start('actions'); ?><a href="javascript:location.reload()" class="btn btn-primary">Reload</a><?php $this->stop(); ?>
