<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Team member names are part of the translation, so they can be written in each language.
$teamMembers = translation_entry('shop.about.team', current_locale()) ?? translation_entry('shop.about.team', 'en');
$teamMembers = is_array($teamMembers) ? array_values(array_filter($teamMembers, 'is_string')) : [];

page_header(t('shop.about.title'), ['about.css']);
?>
    <div class="page-container about-page">
        <article class="about-card">
            <h1 class="about-title"><?= e(t('shop.about.title')) ?></h1>

            <section aria-labelledby="about-project">
                <h2 id="about-project"><?= e(t('shop.about.project_heading')) ?></h2>
                <p><?= t_markup('shop.about.project_text', ['supervisor' => '<strong>' . e(t('shop.about.supervisor')) . '</strong>']) ?></p>
            </section>

            <section aria-labelledby="about-team">
                <h2 id="about-team"><?= e(t('shop.about.team_heading')) ?></h2>
                <ul class="about-team" role="list">
<?php foreach ($teamMembers as $member): ?>
                    <li class="about-member"><strong><?= e($member) ?></strong></li>
<?php endforeach; ?>
                </ul>
            </section>

            <section aria-labelledby="about-university">
                <h2 id="about-university"><?= e(t('shop.about.university_heading')) ?></h2>
                <p><?= t_markup('shop.about.university_text', ['university' => '<strong>' . e(t('shop.about.university')) . '</strong>']) ?></p>
            </section>

            <p class="about-credit"><?= e(t('shop.about.credit')) ?></p>
        </article>
    </div>
<?php
page_footer();
