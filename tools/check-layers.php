#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Enforces the dependency rule of the NotificationPublisher bounded context:
 *   Domain <- Application <- Infrastructure, UserInterface   (arrow = "may import")
 * Domain must not import framework or outer layers; Application must not import Infrastructure, UserInterface,
 * Doctrine or HTTP types. Run: docker compose exec -T app php tools/check-layers.php
 */

$root = dirname(__DIR__);
$context = 'src/NotificationPublisher';

// Doctrine\Common\Collections is a standalone collection library (no ORM dependency); the ORM swaps in
// PersistentCollection behind the same Collection interface, which is what makes a mapped one-to-many
// possible on an otherwise framework-free aggregate. See DECISIONS §1.5.
$allowed = [
    'Domain' => ['Doctrine\\Common\\Collections\\'],
];

$forbidden = [
    'Domain' => [
        'Symfony\\',
        'Doctrine\\',
        'Monolog\\',
        'App\\NotificationPublisher\\Application\\',
        'App\\NotificationPublisher\\Infrastructure\\',
        'App\\NotificationPublisher\\UserInterface\\',
    ],
    'Application' => [
        'Doctrine\\',
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\',
        'Symfony\\Component\\Mailer\\',
        'Symfony\\Contracts\\HttpClient\\',
        'Symfony\\Component\\RateLimiter\\',
        'App\\NotificationPublisher\\Infrastructure\\',
        'App\\NotificationPublisher\\UserInterface\\',
    ],
];

$errors = [];
foreach ($forbidden as $layer => $prefixes) {
    $dir = "{$root}/{$context}/{$layer}";
    if (!is_dir($dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        preg_match_all('/^use\s+(?:function\s+|const\s+)?([A-Za-z0-9_\\\\]+)/m', $code, $uses);
        preg_match_all('/(?<![A-Za-z0-9_\\\\$>])\\\\((?:Symfony|Doctrine|Monolog|App)\\\\[A-Za-z0-9_\\\\]+)/', $code, $inline);
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        foreach ([...$uses[1], ...$inline[1]] as $import) {
            foreach ($allowed[$layer] ?? [] as $prefix) {
                if (str_starts_with($import, $prefix)) {
                    continue 2;
                }
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($import, $prefix)) {
                    $errors[] = "{$relative}: {$layer} layer must not depend on {$import}";
                }
            }
        }
    }
}

if ([] !== $errors) {
    fwrite(\STDERR, "check-layers: FAILED\n  - " . implode("\n  - ", array_unique($errors)) . "\n");
    exit(1);
}

echo "check-layers: OK\n";
