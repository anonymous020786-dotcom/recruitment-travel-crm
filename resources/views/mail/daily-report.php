<?php
/**
 * @var string $name @var string $audience @var string $date
 * @var array<string,int> $counts @var array<string,array<string,array{count:int,total:string}>> $money
 */
$this->layout('mail.layout');
$this->start('content');
?>
<p>Hello <?= e($name) ?>,</p>
<p>Here is what happened on <strong><?= e($date) ?></strong> — <?= e($audience) ?>:</p>

<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:14px;min-width:280px">
    <?php foreach ($counts as $label => $n): ?>
        <tr style="border-bottom:1px solid #e2e8f0"><td><?= e($label) ?></td><td style="text-align:right;font-weight:600"><?= (int) $n ?></td></tr>
    <?php endforeach ?>
</table>

<?php $anyMoney = false; foreach ($money as $rows) { $anyMoney = $anyMoney || $rows !== []; } ?>
<?php if ($anyMoney): ?>
    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:14px;min-width:280px">
        <?php foreach ($money as $label => $byCurrency): ?>
            <?php foreach ($byCurrency as $cur => $f): ?>
                <tr style="border-bottom:1px solid #e2e8f0">
                    <td><?= e($label) ?> <span style="color:#94a3b8">(<?= (int) $f['count'] ?>)</span></td>
                    <td style="text-align:right;font-weight:600"><?= e($cur . ' ' . number_format((float) $f['total'], 2)) ?></td>
                </tr>
            <?php endforeach ?>
        <?php endforeach ?>
    </table>
<?php endif ?>

<p><a href="<?= e_attr($appUrl) ?>/dashboard">Open the dashboard</a> · <a href="<?= e_attr($appUrl) ?>/reports">Reports</a></p>
<?php $this->stop(); ?>
