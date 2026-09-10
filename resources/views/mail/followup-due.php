<?php
/**
 * @var string $name
 * @var list<array{lead:string,number:string,due:string,channel:string,subject:string,url:string}> $items
 * @var int $overdue
 */
$this->layout('mail.layout');
$this->start('content');
?>
<p>Hello <?= e($name) ?>,</p>
<p>You have <strong><?= count($items) ?></strong> lead follow-up<?= count($items) === 1 ? '' : 's' ?> that need attention<?= $overdue > 0 ? ' (' . (int) $overdue . ' overdue)' : '' ?>:</p>
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:14px">
    <?php foreach ($items as $it): ?>
    <tr style="border-bottom:1px solid #e2e8f0">
        <td><a href="<?= e_attr($it['url']) ?>"><?= e($it['lead']) ?></a>
            <span style="color:#94a3b8"><?= e($it['number']) ?></span><br>
            <span style="color:#64748b"><?= e($it['subject'] !== '' ? $it['subject'] : $it['channel'] . ' follow-up') ?></span>
        </td>
        <td style="white-space:nowrap;color:#64748b">due <?= e($it['due']) ?></td>
    </tr>
    <?php endforeach ?>
</table>
<p><a href="<?= e_attr($appUrl) ?>/followups">Open your follow-ups</a></p>
<?php $this->stop(); ?>
