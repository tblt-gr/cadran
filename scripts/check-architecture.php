<?php

declare(strict_types=1);

const ALLOWED_LAYER_DEPENDENCIES = [
    'Domain' => ['Domain'],
    'Application' => ['Domain', 'Application'],
    'Infrastructure' => ['Domain', 'Application', 'Infrastructure'],
    'UI' => ['Domain', 'Application', 'UI'],
];

$sourceDirectory = $argv[1] ?? null;

if (null === $sourceDirectory || !is_dir($sourceDirectory)) {
    fwrite(STDERR, "Usage: php scripts/check-architecture.php <source-directory>\n");
    exit(2);
}

$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory));

foreach ($iterator as $file) {
    if (!$file->isFile() || 'php' !== $file->getExtension()) {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    if (1 !== preg_match('~/Module/[^/]+/(Domain|Application|Infrastructure|UI)/~', $path, $sourceMatch)) {
        continue;
    }

    $sourceLayer = $sourceMatch[1];
    $contents = file_get_contents($file->getPathname());
    if (false === $contents) {
        fwrite(STDERR, sprintf("Cannot read %s\n", $path));
        exit(2);
    }

    // Request plumbing lives in Foundation\UI\Http (JsonRequestEnvelope, BodyLimit, Identifier).
    if ('UI' === $sourceLayer && str_contains($path, '/UI/Http/') && !str_ends_with($path, '/Foundation/UI/Http/JsonRequestEnvelope.php')) {
        if (1 === preg_match('/\bconst\s+(?:\w+\s+)?MAX_BODY_BYTES\b/', $contents)) {
            $violations[] = sprintf('%s: declare the body ceiling in BodyLimit, not MAX_BODY_BYTES', $path);
        }
        if (1 === preg_match('/\bfunction\s+(identifier|isIdentifier|body)\s*\(/', $contents, $helper)) {
            $violations[] = sprintf('%s: %s() duplicates the shared JsonRequestEnvelope or Identifier', $path, $helper[1]);
        }
    }

    foreach (token_get_all($contents) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $reference = ltrim($token[1], '\\');
        if (1 !== preg_match('/^App\\\\Module\\\\[^\\\\]+\\\\(Domain|Application|Infrastructure|UI)(?:\\\\|$)/', $reference, $targetMatch)) {
            continue;
        }

        $targetLayer = $targetMatch[1];
        if (!in_array($targetLayer, ALLOWED_LAYER_DEPENDENCIES[$sourceLayer], true)) {
            $violations[] = sprintf(
                '%s: %s must not depend on %s (%s)',
                $path,
                $sourceLayer,
                $targetLayer,
                $reference,
            );
        }
    }
}

if ([] !== $violations) {
    fwrite(STDERR, implode("\n", array_unique($violations))."\n");
    exit(1);
}

fwrite(STDOUT, "Architecture boundaries are valid.\n");
