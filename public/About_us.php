<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$teamMembers = ['Ahmed Musaed', 'Nawaf Alzahrani', 'Hasan Alharbi', 'Salman Alfakih', 'Diffallah Noman'];

page_header('About Us', ['about.css']);
?>
    <div class="page-container about-page">
        <article class="about-card">
            <h1 class="about-title">About Us</h1>

            <section aria-labelledby="about-project">
                <h2 id="about-project">Welcome to Our Project</h2>
                <p>We are a group of Computer Science students at King Faisal University. This project is part of our "Fundamentals of Web Programming" course, supervised by <strong>Dr. Khaled Riad.</strong> It represents our combined effort to learn and showcase essential web development concepts.</p>
            </section>

            <section aria-labelledby="about-team">
                <h2 id="about-team">Meet the Team</h2>
                <ul class="about-team" role="list">
<?php foreach ($teamMembers as $member): ?>
                    <li class="about-member"><strong><?= e($member) ?></strong></li>
<?php endforeach; ?>
                </ul>
            </section>

            <section aria-labelledby="about-university">
                <h2 id="about-university">Our University</h2>
                <p>This project was developed at <strong>King Faisal University</strong>. We are proud to represent our institution and apply the knowledge we’ve gained to create meaningful projects.</p>
            </section>

            <p class="about-credit">&copy; 2024 King Faisal University - Fundamentals of Web Programming</p>
        </article>
    </div>
<?php
page_footer();
