#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Verifies that the agent reference documents describe the code that actually exists:
 *  - every class/interface/enum under src/ has a row in docs/agent/CODE_MAP.md
 *  - every CODE_MAP row points to an existing file and an existing test (or "-")
 *  - every App\ class or src/ path mentioned in docs/agent/RECIPES.md exists (template sections are skipped)
 *  - every non-pending row in docs/REQUIREMENTS_TRACE.md references existing classes, tests and paths
 * Exit code 1 on any problem. Run: docker compose exec -T app php tools/check-docs.php
 */

$root = dirname(__DIR__);
$errors = [];

/** @return array<string, string> FQCN => relative path */
function declaredClasses(string $root): array
{
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (!preg_match('/^namespace\s+([^;]+);/m', $code, $ns)) {
            continue;
        }
        if (!preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $code, $name)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $classes[$ns[1] . '\\' . $name[1]] = $relative;
    }
    ksort($classes);

    return $classes;
}

/** @return list<list<string>> table rows (header and separator rows removed) */
function tableRows(string $markdown): array
{
    $rows = [];
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        $line = trim($line);
        if ('' === $line || '|' !== $line[0]) {
            continue;
        }
        $cells = array_map(static fn(string $c): string => trim($c, " `\t"), explode('|', trim($line, '|')));
        if ([] === $cells || str_starts_with($cells[0], '---') || in_array(strtolower($cells[0]), ['path', 'requirement', '#', 'id'], true)) {
            continue;
        }
        $rows[] = $cells;
    }

    return $rows;
}

function testExists(string $root, string $name): bool
{
    $name = trim($name);
    if (in_array(strtolower($name), ['-', '—', 'manual', 'n/a', 'none', ''], true)) {
        return true;
    }
    $short = substr($name, (int) strrpos('\\' . $name, '\\'));
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getFilename() === $short . '.php') {
            return true;
        }
    }

    return false;
}

$classes = declaredClasses($root);
$classPaths = array_flip($classes);

// 1. CODE_MAP.md (mandatory)
$codeMapFile = $root . '/docs/agent/CODE_MAP.md';
if (!is_file($codeMapFile)) {
    $errors[] = 'docs/agent/CODE_MAP.md is missing';
} else {
    $mappedPaths = [];
    foreach (tableRows((string) file_get_contents($codeMapFile)) as $row) {
        $path = $row[0];
        if (!str_starts_with($path, 'src/')) {
            continue;
        }
        $mappedPaths[$path] = true;
        if (!is_file($root . '/' . $path)) {
            $errors[] = "CODE_MAP: row points to missing file {$path}";
        }
        $testCell = $row[count($row) - 1];
        foreach (explode(',', $testCell) as $test) {
            if (!testExists($root, $test)) {
                $errors[] = "CODE_MAP: {$path} references missing test {$test}";
            }
        }
    }
    foreach ($classes as $fqcn => $path) {
        if (!isset($mappedPaths[$path])) {
            $errors[] = "CODE_MAP: no row for {$fqcn} ({$path})";
        }
    }
}

// 2. RECIPES.md (optional): validate references outside "template" sections
$recipesFile = $root . '/docs/agent/RECIPES.md';
if (is_file($recipesFile)) {
    $inTemplate = false;
    foreach (preg_split('/\R/', (string) file_get_contents($recipesFile)) ?: [] as $lineNo => $line) {
        if (preg_match('/^#{1,6}\s+(.*)$/', $line, $h)) {
            $inTemplate = false !== stripos($h[1], 'template');
            continue;
        }
        if ($inTemplate) {
            continue;
        }
        preg_match_all('/\bApp\\\\[A-Za-z0-9_\\\\]+/', $line, $fqcns);
        foreach ($fqcns[0] as $fqcn) {
            $fqcn = rtrim($fqcn, '\\');
            if (!isset($classes[$fqcn]) && !str_ends_with($fqcn, '\\Model') && !str_ends_with($fqcn, '\\Port')) {
                $errors[] = sprintf('RECIPES line %d: unknown class %s', $lineNo + 1, $fqcn);
            }
        }
        preg_match_all('#\b(?:src|tests|config|tools)/[A-Za-z0-9_./-]+\.(?:php|xml|yaml|neon)#', $line, $paths);
        foreach ($paths[0] as $path) {
            if (!file_exists($root . '/' . $path)) {
                $errors[] = sprintf('RECIPES line %d: missing path %s', $lineNo + 1, $path);
            }
        }
    }
}

// 3. REQUIREMENTS_TRACE.md (optional): non-pending rows must reference real things
$traceFile = $root . '/docs/REQUIREMENTS_TRACE.md';
if (is_file($traceFile)) {
    foreach (tableRows((string) file_get_contents($traceFile)) as $row) {
        $status = strtolower($row[count($row) - 1]);
        if (str_contains($status, 'pending') || str_contains($status, 'out of scope')) {
            continue;
        }
        $line = implode(' ', $row);
        preg_match_all('/\bApp\\\\[A-Za-z0-9_\\\\]+/', $line, $fqcns);
        foreach ($fqcns[0] as $fqcn) {
            if (!isset($classes[$fqcn])) {
                $errors[] = "TRACE: unknown class {$fqcn} in row '{$row[0]}'";
            }
        }
        preg_match_all('/\b([A-Z][A-Za-z0-9_]*Test)\b/', $line, $tests);
        foreach (array_unique($tests[1]) as $test) {
            if (!testExists($root, $test)) {
                $errors[] = "TRACE: missing test {$test} in row '{$row[0]}'";
            }
        }
    }
}

if ([] !== $errors) {
    fwrite(\STDERR, "check-docs: FAILED\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}

echo sprintf("check-docs: OK (%d classes mapped)\n", count($classes));
