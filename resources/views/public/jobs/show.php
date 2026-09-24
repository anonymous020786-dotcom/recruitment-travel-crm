<?php
/** @var array<string,mixed> $job @var string $slug */

use App\Support\HtmlSanitizer;
use App\Support\PublicFormat;

$org = (string) config('seo.organization_name', config('app.name'));
$site = rtrim((string) config('app.url', ''), '/');
$place = $job['country_name'] . (!empty($job['city']) ? ', ' . $job['city'] : '');
$salary = PublicFormat::salary($job['salary_min'], $job['salary_max'], $job['currency']);
$this->layout('layouts.public', [
    'title' => $job['title'] . ' — ' . $job['country_name'],
    'description' => mb_substr($job['title'] . ' job in ' . $place . ($salary ? ' — ' . $salary : '') . '. ' . PublicFormat::excerpt($job['description_html'], 90) . ' Apply with ' . $org . '.', 0, 160),
    'canonical' => 'overseas-jobs/' . $slug,
]);
$this->start('head');
?>
    <script type="application/ld+json"><?= PublicFormat::json(PublicFormat::jobPostingLd($job, $org, $site, $site . '/overseas-jobs/' . $slug)) ?></script>
<?php
$this->stop();
$this->start('content');

$facts = array_filter([
    'Country' => $place,
    'Vacancies' => (string) (int) $job['vacancies'],
    'Salary' => $salary,
    'Contract' => (int) $job['contract_duration_months'] > 0 ? (int) $job['contract_duration_months'] . ' months' : null,
    'Experience' => $job['experience_required'] ?: null,
    'Qualification' => $job['qualification'] ?: null,
    'Age' => $job['age_min'] || $job['age_max'] ? trim(($job['age_min'] ?: '') . ($job['age_min'] && $job['age_max'] ? ' – ' : '') . ($job['age_max'] ?: ''), ' ') . ' years' : null,
    'Gender' => in_array($job['gender_requirement'], ['male', 'female'], true) ? ucfirst((string) $job['gender_requirement']) . ' only' : null,
    'Working hours' => $job['working_hours'] ?: null,
    'Overtime' => $job['overtime'] ?: null,
    'Interview' => $job['interview_type'] ? ucfirst(str_replace('_', ' ', (string) $job['interview_type'])) : null,
    'Apply before' => $job['deadline'] ? date('j M Y', strtotime((string) $job['deadline'])) : null,
]);
?>
<article class="mx-auto max-w-3xl px-4 py-12">
    <p class="text-sm"><a href="/overseas-jobs" class="text-brand-600 hover:underline">← All overseas jobs</a></p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900"><?= e($job['title']) ?></h1>
    <p class="mt-1 text-slate-600"><?= e($place) ?></p>

    <div class="mt-5 flex flex-wrap gap-3">
        <a href="/overseas-jobs/<?= e_attr($slug) ?>/apply" class="btn btn-primary">Apply for this job</a>
        <a href="/contact" class="btn btn-secondary">Ask a question</a>
    </div>

    <h2 class="mt-8 text-base font-semibold text-slate-900">Job details</h2>
    <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <?php foreach ($facts as $label => $value): ?>
            <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= e($label) ?></dt><dd class="text-sm text-slate-900"><?= e($value) ?></dd></div>
        <?php endforeach ?>
    </dl>

    <?php if (PublicFormat::benefits($job) !== []): ?>
        <h2 class="mt-8 text-base font-semibold text-slate-900">Benefits</h2>
        <ul class="mt-2 list-disc pl-5 text-sm text-slate-700">
            <?php foreach (PublicFormat::benefits($job) as $b): ?><li><?= e($b) ?></li><?php endforeach ?>
        </ul>
    <?php endif ?>

    <?php if ($job['requirements'] !== []): ?>
        <h2 class="mt-8 text-base font-semibold text-slate-900">Requirements</h2>
        <ul class="mt-2 list-disc pl-5 text-sm text-slate-700">
            <?php foreach ($job['requirements'] as $r): ?><li><?= e($r) ?></li><?php endforeach ?>
        </ul>
    <?php endif ?>

    <?php if (!empty($job['description_html'])): ?>
        <h2 class="mt-8 text-base font-semibold text-slate-900">About the role</h2>
        <div class="mt-2 space-y-3 text-sm text-slate-700"><?= HtmlSanitizer::clean((string) $job['description_html']) ?></div>
    <?php endif ?>

    <div class="mt-10 card card-body">
        <p class="font-medium text-slate-900">Interested?</p>
        <p class="mt-1 text-sm text-slate-600">Send us your name and phone number and our team will call you about this vacancy. There is no fee to apply.</p>
        <p class="mt-3"><a href="/overseas-jobs/<?= e_attr($slug) ?>/apply" class="btn btn-primary">Apply for this job</a></p>
    </div>
</article>
<?php $this->stop(); ?>
