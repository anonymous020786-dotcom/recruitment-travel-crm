<?php
/** @var array<string,mixed> $input @var array{verdict:string,matches:list<array<string,mixed>>}|null $result */
$this->layout('layouts.app', ['title' => 'Test a request — Firewall', 'currentPath' => '/admin/security']);
$this->start('content');
$v = static fn (string $k, string $d = ''): string => (string) ($input[$k] ?? $d);
$tone = ['block' => 'red', 'log' => 'amber', 'allow' => 'green', 'pass' => 'green'];
$verdictText = ['block' => 'Blocked', 'allow' => 'Allowed by a rule', 'pass' => 'Let through'];
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'See what the firewall would do with a request — before you rely on a rule, or to understand why someone was blocked. Nothing is logged or banned.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'firewall']) ?>
<?= $this->partial('crm.admin.security._firewall-nav', ['active' => 'test']) ?>

<form method="post" action="/admin/security/firewall/test" class="card card-body mb-6 max-w-4xl space-y-3">
    <?= csrf_field() ?>
    <div class="grid gap-3 sm:grid-cols-[8rem_1fr]">
        <div><label class="form-label" for="t-m">Method</label><select id="t-m" class="form-select" name="method"><?php foreach (['GET', 'POST', 'PUT', 'DELETE', 'TRACE', 'CONNECT'] as $m): ?><option <?= $v('method', 'GET') === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach ?></select></div>
        <div><label class="form-label" for="t-u">Address (path and query)</label><input id="t-u" class="form-input font-mono" name="url" value="<?= e_attr($v('url')) ?>" placeholder="/overseas-jobs?q=1' OR '1'='1" required maxlength="5000" spellcheck="false"></div>
    </div>
    <div><label class="form-label" for="t-b">Form / body <span class="font-normal text-slate-400">(as a visitor who is not signed in)</span></label><textarea id="t-b" class="form-input font-mono text-xs" name="body" rows="3" maxlength="16384"><?= e($v('body')) ?></textarea></div>
    <div class="grid gap-3 sm:grid-cols-3">
        <div><label class="form-label" for="t-ua">User agent</label><input id="t-ua" class="form-input font-mono text-xs" name="user_agent" value="<?= e_attr($v('user_agent', 'Mozilla/5.0')) ?>" maxlength="1000"></div>
        <div><label class="form-label" for="t-ip">IP address</label><input id="t-ip" class="form-input font-mono" name="ip" value="<?= e_attr($v('ip', '203.0.113.1')) ?>" maxlength="45"></div>
        <div><label class="form-label" for="t-c">Country code</label><input id="t-c" class="form-input font-mono" name="country" value="<?= e_attr($v('country')) ?>" maxlength="2" placeholder="IN"></div>
    </div>
    <div><button type="submit" class="btn btn-primary">Test</button></div>
</form>

<?php if ($result !== null): ?>
    <section class="card card-body max-w-4xl" aria-live="polite" aria-label="Result">
        <p class="text-sm">Result: <?= component('badge', ['label' => $verdictText[$result['verdict']] ?? $result['verdict'], 'color' => $tone[$result['verdict']] ?? 'slate', 'dot' => true]) ?></p>
        <?php if ($result['matches'] === []): ?>
            <p class="mt-2 text-sm text-slate-600">No rule matched.</p>
        <?php else: ?>
            <ol class="mt-3 space-y-2 text-sm">
                <?php foreach ($result['matches'] as $m): ?>
                    <li class="rounded border border-slate-200 p-2"><?= component('badge', ['label' => ucfirst((string) $m['action']), 'color' => $tone[$m['action']] ?? 'slate']) ?> <strong><?= e((string) $m['label']) ?></strong>
                        <?php if ($m['sample'] !== ''): ?><p class="mt-1 font-mono text-xs text-slate-600"><?= e((string) $m['part']) ?>: <?= e((string) $m['sample']) ?></p><?php endif ?></li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>
    </section>
<?php endif ?>
<?php $this->stop(); ?>
