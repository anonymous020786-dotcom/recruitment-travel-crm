<?php
/**
 * @var \App\Models\Job $job @var bool $canApply
 * @var list<array{candidate:array{id:int,public_id:string,number:string,name:string,stage:string},result:\App\Domain\Matching\MatchResult}> $rows
 */
$this->layout('layouts.app', ['title' => 'Matches · ' . $job->title, 'currentPath' => '/jobs']);
$this->start('content');
$tone = static fn (float $s): string => $s >= 75 ? 'green' : ($s >= 50 ? 'amber' : 'slate');
?>
<?= component('page-header', [
    'title' => 'Matching candidates',
    'subtitle' => $job->jobNumber . ' · ' . $job->title,
    'breadcrumbs' => [['label' => 'Jobs', 'href' => '/jobs'], ['label' => $job->jobNumber, 'href' => '/jobs/' . $job->publicId], ['label' => 'Matches']],
]) ?>

<p class="mb-4 text-sm text-slate-500">
    Scores are computed on demand from each candidate's skills, experience, qualification, preferences, age, gender,
    salary expectation and passport, weighted by the rules in the matching configuration. Criteria that cannot be judged
    (missing data) are left out rather than counted against the candidate.
</p>

<?php if ($rows === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No candidates to match', 'message' => 'There are no active candidates in your branches yet.'])]) ?>
<?php else: ?>
    <div class="space-y-3">
        <?php foreach ($rows as $i => $row): $c = $row['candidate']; $res = $row['result']; ?>
            <div class="card card-body">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="text-xs text-slate-400">#<?= $i + 1 ?></span>
                        <a href="/candidates/<?= e_attr($c['public_id']) ?>" class="ml-1 font-medium text-slate-900"><?= e($c['name']) ?></a>
                        <span class="ml-1 font-mono text-xs text-slate-500"><?= e($c['number']) ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if (!$res->eligible): ?>
                            <?= component('badge', ['label' => 'Missing mandatory: ' . implode(', ', $res->missingMandatory), 'color' => 'red', 'dot' => true]) ?>
                        <?php endif ?>
                        <?= component('badge', ['label' => number_format($res->score, 1) . ' / 100', 'color' => $tone($res->score)]) ?>
                        <?php if (!empty($canApply)): ?>
                            <form method="post" action="/applications" class="inline" data-confirm="Create an application for <?= e_attr($c['name']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="candidate" value="<?= e_attr($c['public_id']) ?>">
                                <input type="hidden" name="job" value="<?= e_attr($job->publicId) ?>">
                                <button class="btn btn-secondary btn-sm">Apply</button>
                            </form>
                        <?php endif ?>
                    </div>
                </div>
                <details class="mt-1">
                    <summary class="cursor-pointer text-xs text-brand-600">Why this score?</summary>
                    <?= $this->partial('crm._match_breakdown', ['result' => $res]) ?>
                </details>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
