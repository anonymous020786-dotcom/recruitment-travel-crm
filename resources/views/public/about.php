<?php
$this->layout('layouts.public', [
    'title' => 'About us',
    'description' => 'About ' . (string) config('seo.organization_name', config('app.name')) . ' — overseas recruitment and travel services.',
    'canonical' => 'about',
]);
$this->start('content');
?>
<section class="mx-auto max-w-3xl px-4 py-16">
    <h1 class="text-2xl font-bold text-slate-900">About <?= e((string) config('seo.organization_name', config('app.name'))) ?></h1>
    <div class="prose prose-slate mt-4 max-w-none text-slate-600">
        <p>We are an overseas recruitment and travel agency. Our team supports candidates
           and employers through every stage of international hiring, and arranges travel
           for the people we place and the customers we serve.</p>
        <p>This page is a placeholder — replace it with your agency's story, licences and team.</p>
    </div>
    <div class="mt-6"><a href="/contact" class="btn btn-primary">Contact us</a></div>
</section>
<?php $this->stop(); ?>
