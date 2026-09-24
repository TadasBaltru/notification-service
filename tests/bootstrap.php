<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// SlidingWindow reads microtime() in its own namespace. That override exists only if it is
// registered before the class file is compiled. withClockMock(null) keeps production time
// until a test opts in.
Symfony\Bridge\PhpUnit\ClockMock::register(Symfony\Component\RateLimiter\Policy\SlidingWindow::class);
Symfony\Bridge\PhpUnit\ClockMock::register(Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter::class);

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}
