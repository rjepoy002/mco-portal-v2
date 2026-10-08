<?php

/**
 * Static guard for application SQL. Database privileges must enforce the same
 * boundary in deployed environments.
 */
$root = dirname(__DIR__);
$writableTables = ['users', 'email_verifications', 'password_resets', 'paleco_accounts'];
$files = [];
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    static fn (SplFileInfo $entry) => !$entry->isDir()
        || !in_array($entry->getFilename(), ['vendor', 'tests', '.git'], true)
);
$iterator = new RecursiveIteratorIterator($filter);
foreach ($iterator as $entry) {
    if (!$entry->isFile() || !in_array(strtolower($entry->getExtension()), ['php', 'sql'], true)) {
        continue;
    }
    $files[] = $entry->getPathname();
}
$violations = [];

foreach ($files as $file) {
    $source = file_get_contents($file);
    $sqlFragments = str_ends_with(strtolower($file), '.sql')
        ? [[$source, 1]]
        : array_filter(token_get_all($source), static fn ($token) => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING);
    foreach ($sqlFragments as $token) {
        $sql = str_ends_with(strtolower($file), '.sql') ? $token[0] : $token[1];
        $line = str_ends_with(strtolower($file), '.sql') ? 1 : $token[2];
        preg_match_all(
            '/\b(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|CREATE\s+TABLE)\s+(?:`?(?<schema>[a-z_][a-z_0-9]*)`?\s*\.\s*)?`?(?<table>[a-z_][a-z_0-9]*)`?/i',
            $sql,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $schema = strtolower($match['schema'] ?? '');
            $table = strtolower($match['table']);
            if (($schema !== '' && $schema !== 'mco_portal') || !in_array($table, $writableTables, true)) {
                $violations[] = basename($file) . ':' . $line . ' targets ' . ($schema !== '' ? $schema . '.' : '') . $table;
            }
        }
    }
}

if ($violations) {
    fwrite(STDERR, "Non-portal table write detected:\n" . implode("\n", $violations) . "\n");
    exit(1);
}

echo "PALECO read-only contract check passed.\n";
