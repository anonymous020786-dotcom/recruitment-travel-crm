<?php
/**
 * @var \App\Models\Employer $employer @var list<\App\Models\EmployerContact> $contacts
 * @var bool $canEdit @var bool $canDelete @var bool $canContacts
 * @var list<\App\Models\Job> $jobs @var bool $canPostJob
 */
$this->layout('layouts.app', ['title' => $employer->companyName, 'currentPath' => '/employers']);
$this->start('content');

$statusColor = ['active' => 'green', 'prospect' => 'blue', 'suspended' => 'amber', 'blacklisted' => 'red', 'inactive' => 'slate'];
$actions = '';
if ($canEdit) {
    $actions .= '<a href="/employers/' . e_attr($employer->publicId) . '/edit" class="btn btn-primary btn-sm">Edit</a> ';
}
if ($canDelete) {
    $actions .= '<form method="post" action="/employers/' . e_attr($employer->publicId) . '" class="inline" data-confirm="Delete this employer?">'
        . csrf_field() . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
}
?>
<?= component('page-header', [
    'title' => $employer->companyName,
    'subtitle' => $employer->employerNumber,
    'breadcrumbs' => [['label' => 'Employers', 'href' => '/employers'], ['label' => $employer->employerNumber]],
    'actions' => $actions,
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $employer->statusLabel(), 'color' => $statusColor[$employer->status] ?? 'slate', 'dot' => true]) ?>
    <?php if ($employer->licenseExpiry !== null && $employer->licenseExpiry < gmdate('Y-m-d')): ?>
        <?= component('badge', ['label' => 'License expired', 'color' => 'red', 'dot' => true]) ?>
    <?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Profile', 'body' => (function () use ($employer) {
            $rows = [
                'Country' => e($employer->country),
                'City' => e($employer->city ?? '—'),
                'Address' => e($employer->address ?? '—'),
                'Industry' => e($employer->industry ?? '—'),
                'Website' => $employer->website ? '<a href="' . e_attr($employer->website) . '" rel="noopener noreferrer" target="_blank" class="text-brand-600 hover:underline">' . e($employer->website) . '</a>' : '—',
                'License number' => e($employer->licenseNumber ?? '—'),
                'License expiry' => e($employer->licenseExpiry ?? '—'),
                'Account owner' => e($employer->accountOwnerName ?? '—'),
                'Created' => e(substr($employer->createdAt, 0, 16)),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($employer->notes) {
                $html .= '<p class="mt-3 whitespace-pre-line text-sm text-slate-600">' . e($employer->notes) . '</p>';
            }

            return $html;
        })()]) ?>

        <div id="contacts">
            <?= component('card', ['title' => 'Contacts', 'body' => (function () use ($employer, $contacts, $canContacts) {
                $html = '';
                $base = '/employers/' . e_attr($employer->publicId) . '/contacts';

                if ($canContacts) {
                    $html .= '<form method="post" action="' . $base . '" class="mb-4 grid gap-2 sm:grid-cols-4" data-once>'
                        . csrf_field()
                        . '<input type="text" name="name" required maxlength="120" placeholder="Name" aria-label="Contact name" class="form-input">'
                        . '<input type="text" name="designation" maxlength="120" placeholder="Designation" aria-label="Designation" class="form-input">'
                        . '<input type="email" name="email" maxlength="180" placeholder="Email" aria-label="Email" class="form-input">'
                        . '<input type="text" name="phone" maxlength="30" placeholder="Phone" aria-label="Phone" class="form-input">'
                        . '<label class="flex items-center gap-1.5 text-xs text-slate-600 sm:col-span-3"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1"> Primary contact</label>'
                        . '<div><button class="btn btn-secondary btn-sm">Add contact</button></div></form>';
                }

                if ($contacts === []) {
                    return $html . '<p class="text-sm text-slate-500">No contacts yet.</p>';
                }

                $html .= '<ul class="divide-y divide-slate-100">';
                foreach ($contacts as $c) {
                    $meta = implode(' · ', array_filter([
                        $c->designation ? e($c->designation) : null,
                        $c->email ? '<a href="mailto:' . e_attr($c->email) . '" class="text-brand-600">' . e($c->email) . '</a>' : null,
                        $c->phone ? '<a href="tel:' . e_attr($c->phone) . '">' . e($c->phone) . '</a>' : null,
                    ]));
                    $html .= '<li class="py-2.5 text-sm"><div class="flex items-start justify-between gap-2">'
                        . '<div><p class="font-medium text-slate-900">' . e($c->name)
                        . ($c->isPrimary ? ' ' . component('badge', ['label' => 'Primary', 'color' => 'indigo']) : '') . '</p>'
                        . ($meta !== '' ? '<p class="text-xs text-slate-500">' . $meta . '</p>' : '') . '</div>';
                    if ($canContacts) {
                        $html .= '<form method="post" action="' . $base . '/' . $c->id . '" data-confirm="Remove this contact?">'
                            . csrf_field() . '<input type="hidden" name="_method" value="DELETE">'
                            . '<button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
                    }
                    $html .= '</div>';
                    if ($canContacts) {
                        $html .= '<details class="mt-1.5"><summary class="cursor-pointer text-xs text-brand-600">Edit</summary>'
                            . '<form method="post" action="' . $base . '/' . $c->id . '" class="mt-2 grid gap-2 sm:grid-cols-4">'
                            . csrf_field() . '<input type="hidden" name="_method" value="PUT">'
                            . '<input type="text" name="name" required maxlength="120" value="' . e_attr($c->name) . '" aria-label="Contact name" class="form-input">'
                            . '<input type="text" name="designation" maxlength="120" value="' . e_attr($c->designation ?? '') . '" aria-label="Designation" class="form-input">'
                            . '<input type="email" name="email" maxlength="180" value="' . e_attr($c->email ?? '') . '" aria-label="Email" class="form-input">'
                            . '<input type="text" name="phone" maxlength="30" value="' . e_attr($c->phone ?? '') . '" aria-label="Phone" class="form-input">'
                            . '<label class="flex items-center gap-1.5 text-xs text-slate-600 sm:col-span-3"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1"' . ($c->isPrimary ? ' checked' : '') . '> Primary contact</label>'
                            . '<div><button class="btn btn-secondary btn-sm">Save</button></div></form></details>';
                    }
                    $html .= '</li>';
                }

                return $html . '</ul>';
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Jobs', 'body' => (function () use ($employer, $jobs, $canPostJob) {
            $html = '';
            if ($canPostJob) {
                $html .= '<a href="/jobs/create?employer=' . e_attr($employer->publicId) . '" class="btn btn-secondary btn-sm mb-3">New job</a>';
            }
            if ($jobs === []) {
                return $html . '<p class="text-sm text-slate-500">No jobs posted yet.</p>';
            }
            $html .= '<ul class="divide-y divide-slate-100">';
            foreach ($jobs as $j) {
                $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><a href="/jobs/' . e_attr($j->publicId) . '" class="font-medium text-slate-900">' . e($j->title) . '</a>'
                    . component('badge', ['label' => $j->statusLabel(), 'color' => $j->status === 'open' ? 'green' : 'slate']) . '</li>';
            }

            return $html . '</ul>';
        })()]) ?>
    </div>
</div>
<?php $this->stop(); ?>
