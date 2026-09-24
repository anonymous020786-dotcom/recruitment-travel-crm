<?php
/**
 * @var list<array{currency:string,invoices:int,current:string,d1_30:string,d31_60:string,d61_90:string,d90_plus:string,total:string}> $aging
 * @var list<array{person_id:int,customer_name:string,currency:string,invoices:int,outstanding:string,overdue:string,oldest_due:?string}> $debtors
 */
$this->layout('layouts.app', ['title' => 'Receivables ageing', 'currentPath' => '/invoices']);
$this->start('content');
$m = static fn (string $cur, string $v): string => e($cur . ' ' . number_format((float) $v, 2));
?>
<?= component('page-header', [
    'title' => 'Receivables ageing',
    'subtitle' => 'What customers owe, by how long it is past due',
    'breadcrumbs' => [['label' => 'Invoices', 'href' => '/invoices'], ['label' => 'Ageing']],
]) ?>

<?php if ($aging === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'Nothing outstanding', 'message' => 'Every issued invoice in your branches is paid.'])]) ?>
<?php else: ?>
    <div class="table-wrap mb-6">
        <table class="data">
            <thead>
            <tr><th>Currency</th><th class="text-right">Invoices</th><th class="text-right">Not yet due</th><th class="text-right">1–30 days</th><th class="text-right">31–60</th><th class="text-right">61–90</th><th class="text-right">90+</th><th class="text-right">Total owed</th></tr>
            </thead>
            <tbody>
            <?php foreach ($aging as $a): ?>
                <tr>
                    <td class="font-medium"><?= e($a['currency']) ?></td>
                    <td class="text-right"><?= (int) $a['invoices'] ?></td>
                    <td class="whitespace-nowrap text-right"><?= $m($a['currency'], $a['current']) ?></td>
                    <td class="whitespace-nowrap text-right"><?= $m($a['currency'], $a['d1_30']) ?></td>
                    <td class="whitespace-nowrap text-right <?= (float) $a['d31_60'] > 0 ? 'text-amber-700' : '' ?>"><?= $m($a['currency'], $a['d31_60']) ?></td>
                    <td class="whitespace-nowrap text-right <?= (float) $a['d61_90'] > 0 ? 'text-red-600' : '' ?>"><?= $m($a['currency'], $a['d61_90']) ?></td>
                    <td class="whitespace-nowrap text-right <?= (float) $a['d90_plus'] > 0 ? 'font-semibold text-red-700' : '' ?>"><?= $m($a['currency'], $a['d90_plus']) ?></td>
                    <td class="whitespace-nowrap text-right font-semibold"><?= $m($a['currency'], $a['total']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <?= component('card', ['title' => 'Who owes the most', 'body' => (function () use ($debtors, $m) {
        $html = '<div class="table-wrap"><table class="data"><thead><tr><th>Customer</th><th class="text-right">Invoices</th><th class="text-right">Owes</th><th class="text-right">Of which overdue</th><th>Oldest due</th></tr></thead><tbody>';
        foreach ($debtors as $d) {
            $html .= '<tr><td>' . e($d['customer_name']) . '</td><td class="text-right">' . (int) $d['invoices'] . '</td>'
                . '<td class="whitespace-nowrap text-right font-medium">' . $m($d['currency'], $d['outstanding']) . '</td>'
                . '<td class="whitespace-nowrap text-right ' . ((float) $d['overdue'] > 0 ? 'text-red-600' : 'text-slate-400') . '">' . $m($d['currency'], $d['overdue']) . '</td>'
                . '<td class="whitespace-nowrap text-slate-600">' . e($d['oldest_due'] ?? '—') . '</td></tr>';
        }

        return $html . '</tbody></table></div><p class="mt-3 text-xs text-slate-400">Open the <a href="/invoices?due=overdue" class="text-brand-600 hover:underline">overdue invoices</a> to chase them.</p>';
    })()]) ?>
<?php endif ?>
<?php $this->stop(); ?>
