<?php
/**
 * @var array<string,mixed> $e @var array<string,mixed> $meta @var bool $canConvert
 * @var list<array{id:int|string,name:string}> $branches @var ?int $primaryBranch
 */
$this->layout('layouts.app', ['title' => 'Enquiry from ' . $e['name'], 'currentPath' => '/enquiries']);
$this->start('content');

$color = ['new' => 'amber', 'reviewed' => 'blue', 'converted' => 'green', 'spam' => 'slate'];
$types = ['contact' => 'Contact form', 'job_apply' => 'Job application', 'travel_enquiry' => 'Package enquiry'];
$base = '/enquiries/' . (int) $e['id'];
$wa = preg_replace('/\D+/', '', (string) $e['phone']);
?>
<?= component('page-header', [
    'title' => $e['name'],
    'subtitle' => ($types[$e['type']] ?? $e['type']) . ' · received ' . date('d M Y H:i', strtotime((string) $e['created_at'] . ' UTC')) . ' UTC',
    'breadcrumbs' => [['label' => 'Website enquiries', 'href' => '/enquiries'], ['label' => $e['name']]],
]) ?>

<div class="mb-4"><?= component('badge', ['label' => ucfirst((string) $e['status']), 'color' => $color[$e['status']] ?? 'slate', 'dot' => true]) ?></div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <?= component('card', ['title' => 'Enquiry', 'body' => (function () use ($e, $wa, $types) {
            $rows = [
                'Name' => e($e['name']),
                'Phone' => '<a href="tel:' . e_attr((string) $e['phone']) . '" class="text-brand-600 hover:underline">' . e($e['phone']) . '</a>'
                    . ($wa !== '' && strlen($wa) >= 10 ? ' · <a href="https://wa.me/' . e_attr($wa) . '" target="_blank" rel="noopener" class="text-brand-600 hover:underline">WhatsApp</a>' : ''),
                'Email' => !empty($e['email']) ? '<a href="mailto:' . e_attr((string) $e['email']) . '" class="text-brand-600 hover:underline">' . e($e['email']) . '</a>' : '—',
                'Type' => e($types[$e['type']] ?? $e['type']),
            ];
            if (!empty($e['job_title'])) {
                $rows['Job'] = e($e['job_title']);
            }
            if (!empty($e['package_name'])) {
                $rows['Package'] = e($e['package_name']);
            }
            $html = '<dl class="grid gap-3 sm:grid-cols-2">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">' . e($k) . '</dt><dd class="text-sm text-slate-900">' . $v . '</dd></div>';
            }

            return $html . '</dl>';
        })()]) ?>

        <?= component('card', ['title' => 'Message', 'body' => !empty($e['message'])
            ? '<p class="whitespace-pre-line text-sm text-slate-800">' . e($e['message']) . '</p>'
            : '<p class="text-sm text-slate-500">No message was left.</p>']) ?>

        <?php if (!empty($meta['referer']) || !empty($meta['ua'])): ?>
            <?= component('card', ['title' => 'Where it came from', 'body' => '<dl class="space-y-1 text-xs text-slate-500">'
                . (!empty($meta['referer']) ? '<div><dt class="inline font-medium">Referrer:</dt> <dd class="inline break-all">' . e($meta['referer']) . '</dd></div>' : '')
                . (!empty($meta['ua']) ? '<div><dt class="inline font-medium">Browser:</dt> <dd class="inline break-all">' . e($meta['ua']) . '</dd></div>' : '')
                . '</dl>']) ?>
        <?php endif ?>
    </div>

    <div class="space-y-4">
        <?php if ($e['status'] === 'converted'): ?>
            <?= component('card', ['title' => 'Lead', 'body' => !empty($e['lead_public_id'])
                ? '<p class="text-sm text-slate-700">This enquiry is now lead <a class="font-mono text-brand-600 hover:underline" href="/leads/' . e_attr((string) $e['lead_public_id']) . '">' . e($e['lead_number']) . '</a>.</p>'
                : '<p class="text-sm text-slate-700">Converted to a lead that has since been removed.</p>']) ?>
        <?php else: ?>
            <?php if ($canConvert): ?>
                <?= component('card', ['title' => 'Turn into a lead', 'body' => (function () use ($base, $branches, $primaryBranch) {
                    $html = '<form method="post" action="' . $base . '/convert" data-once>' . csrf_field()
                        . '<p class="mb-3 text-sm text-slate-600">Creates a lead (source: Website). If this person is already a lead, the enquiry is linked to them instead.</p>';
                    if (count($branches) > 1) {
                        $html .= '<label class="form-label" for="branch_id">Branch</label><select class="form-select mb-3" id="branch_id" name="branch_id">';
                        foreach ($branches as $b) {
                            $html .= '<option value="' . (int) $b['id'] . '"' . ((int) $b['id'] === (int) $primaryBranch ? ' selected' : '') . '>' . e($b['name']) . '</option>';
                        }
                        $html .= '</select>';
                    } elseif (count($branches) === 1) {
                        $html .= '<input type="hidden" name="branch_id" value="' . (int) $branches[0]['id'] . '">';
                    }

                    return $html . '<button type="submit" class="btn btn-primary w-full">Create lead</button></form>';
                })()]) ?>
            <?php endif ?>

            <?= component('card', ['title' => 'Triage', 'body' => (function () use ($base, $e, $canConvert) {
                if (!$canConvert) {
                    return '<p class="text-sm text-slate-500">You can view this enquiry but not change it.</p>';
                }
                $btn = static fn (string $to, string $label, string $cls) => '<form method="post" action="' . $base . '/status" class="inline">' . csrf_field()
                    . '<input type="hidden" name="from" value="' . e_attr((string) $e['status']) . '"><input type="hidden" name="to" value="' . e_attr($to) . '">'
                    . '<button type="submit" class="btn ' . $cls . ' btn-sm">' . e($label) . '</button></form>';
                $out = '<div class="flex flex-wrap gap-2">';
                $out .= $e['status'] !== 'reviewed' ? $btn('reviewed', 'Mark reviewed', 'btn-secondary') : '';
                $out .= $e['status'] !== 'spam' ? $btn('spam', 'Mark as spam', 'btn-secondary') : '';
                $out .= $e['status'] !== 'new' ? $btn('new', 'Back to new', 'btn-ghost') : '';

                return $out . '</div>';
            })()]) ?>
        <?php endif ?>
    </div>
</div>
<?php $this->stop(); ?>
