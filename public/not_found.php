<?php

declare(strict_types=1);

/*
 * Error document for URLs the web server answers itself (no such file, or a
 * denied path): shows the site's own translated 404/403 page instead of the
 * server's English default. Configured in docker/apache/site.conf.
 */

// An error document never sees the original query string, so a language link
// back to the same URL could not switch: link to the home page instead.
const LOCALE_SWITCH_TO_HOME = true;

require __DIR__ . '/../src/bootstrap.php';

abort((int) ($_SERVER['REDIRECT_STATUS'] ?? 404) === 403 ? 403 : 404);
