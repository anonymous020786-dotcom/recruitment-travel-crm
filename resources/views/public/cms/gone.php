<?php
$this->layout('layouts.public', ['title' => 'This page has been removed', 'robots' => 'noindex,follow']);
$this->start('content');
?>
<section class="mx-auto max-w-2xl px-4 py-16 text-center">
    <h1 class="text-3xl font-bold text-slate-900">This page has been removed</h1>
    <p class="mt-3 text-slate-600">What used to be here is no longer available.</p>
    <p class="mt-6 flex flex-wrap justify-center gap-2"><a class="btn btn-primary" href="/">Home</a><a class="btn btn-secondary" href="/overseas-jobs">Browse jobs</a><a class="btn btn-ghost" href="/contact">Contact us</a></p>
</section>
<?php $this->stop(); ?>
