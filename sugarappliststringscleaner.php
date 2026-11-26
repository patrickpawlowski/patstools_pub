#!/usr/bin/env php
<?php
/** Application language cleanup. Run through shadowy on shadowed instances.
 * Usage: shadowy /absolute/path/sugarappliststringscleaner.php /absolute/instance/root
 * Never runs database repair or changes the Health Check threshold.
 */
const LANGUAGE_CLEANER_VERSION = '20261123.150000';

function languageCleanerValues(string $path): array
{
    $app_strings = [];
    $app_list_strings = [];
    require $path;
    if (!is_array($app_strings) || !is_array($app_list_strings)) {
        throw new RuntimeException("Non-array language values in {$path}");
    }
    return [$app_strings, $app_list_strings];
}

function languageCleanerBytes(string $path): int
{
    $bytes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $bytes += $file->getSize();
    }
    return $bytes;
}

function runLanguageCleaner(string $root, callable $rebuild): void
{
    $source = $root . '/custom/Extension/application/Ext/Language';
    $compiled = $root . '/custom/application/Ext/Language';
    if (!is_dir($source) || !is_dir($compiled) || is_link($source)) {
        throw new RuntimeException('Missing language directories or symlinked source directory; no cleanup performed.');
    }
    $languages = [];
    foreach (scandir($source) as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!is_file("{$source}/{$name}") || is_link("{$source}/{$name}")
            || !preg_match('/^([a-z]{2,3}_[a-z]{2})\..*php$/iD', $name, $match)) {
            throw new RuntimeException("Unexpected source entry {$name}; review it before cleanup.");
        }
        $languages[$match[1]] = true;
    }
    if (!$languages) throw new RuntimeException('No source languages found; restore source files before cleanup.');
    $stamp = gmdate('Y_m_d_H_i_s') . '_' . bin2hex(random_bytes(3));
    $archive = $root . '/Language_' . $stamp . '.zip';
    $original = $source . '.original_' . $stamp;
    $staging = $source . '.staging_' . $stamp;
    $before = [languageCleanerBytes($source), languageCleanerBytes($compiled)];
    echo "Backing up source and compiled files to {$archive}\n";
    $command = 'cd ' . escapeshellarg($root) . ' && zip -qr ' . escapeshellarg($archive)
        . ' custom/Extension/application/Ext/Language custom/application/Ext/Language';
    system($command, $code);
    if ($code !== 0 || !is_file($archive) || filesize($archive) === 0) throw new RuntimeException('Backup failed; source untouched.');
    system('unzip -tq ' . escapeshellarg($archive), $code);
    if ($code !== 0) throw new RuntimeException('Backup verification failed; source untouched.');
    echo "Rebuilding extensions before consolidation.\n";
    $rebuild();
    if (!mkdir($staging, fileperms($source) & 0777)) throw new RuntimeException('Cannot create staging directory.');
    $expected = [];
    foreach (array_keys($languages) as $language) {
        $input = "{$compiled}/{$language}.lang.ext.php";
        if (!is_readable($input)) throw new RuntimeException("Missing compiled language {$language}; source untouched.");
        $values = languageCleanerValues($input);
        $expected[$language] = hash('sha256', serialize($values));
        $output = "<?php\n\$app_strings = array_merge(\$app_strings ?? [], " . var_export($values[0], true) . ");\n";
        $output .= '$app_list_strings = array_merge($app_list_strings ?? [], ' . var_export($values[1], true) . ");\n";
        $file = "{$staging}/{$language}.lang.php";
        if (file_put_contents($file, $output) !== strlen($output)) throw new RuntimeException('Incomplete staging write; source untouched.');
        if (hash('sha256', serialize(languageCleanerValues($file))) !== $expected[$language]) throw new RuntimeException('Staged values differ; source untouched.');
    }
    if (!rename($source, $original)) throw new RuntimeException('Cannot retain original source directory.');
    if (!rename($staging, $source)) {
        rename($original, $source);
        throw new RuntimeException('Cannot install consolidated files.');
    }
    echo "Original source retained: {$original}\n";
    try {
        $rebuild();
        foreach ($expected as $language => $hash) {
            $generated = file_get_contents("{$compiled}/{$language}.lang.ext.php");
            $consolidated = trim(substr(file_get_contents("{$source}/{$language}.lang.php"), 5));
            if ($generated === false || !str_contains($generated, $consolidated)) {
                throw new RuntimeException("Compiled language was not regenerated from consolidated source: {$language}");
            }
            if (hash('sha256', serialize(languageCleanerValues("{$compiled}/{$language}.lang.ext.php"))) !== $hash) {
                throw new RuntimeException("Effective language values changed: {$language}");
            }
        }
    } catch (Throwable $error) {
        throw new RuntimeException("Cleanup written; rebuild/verification incomplete. Backup: {$archive}; originals: {$original}. " . $error->getMessage(), 0, $error);
    }
    printf("Cleanup and value verification complete (%d languages).\nSource: %.2f -> %.2f MiB\nCompiled: %.2f -> %.2f MiB\n",
        count($expected), $before[0] / 1048576, languageCleanerBytes($source) / 1048576,
        $before[1] / 1048576, languageCleanerBytes($compiled) / 1048576);
    echo "Review dropdowns and run Health Check. Threshold was not changed.\n";
}

if (!defined('LANGUAGE_CLEANER_LIBRARY_ONLY')) {
    try {
        $rootArgument = defined('LANGUAGE_CLEANER_ROOT') ? LANGUAGE_CLEANER_ROOT : ($argv[1] ?? '');
        $root = $rootArgument !== '' ? realpath($rootArgument) : false;
        if (!$root || !is_file($root . '/config.php') || !is_dir($root . '/custom') || !chdir($root)) {
            throw new RuntimeException('Pass the explicit Sugar instance root.');
        }
        echo 'Language cleaner ' . LANGUAGE_CLEANER_VERSION . "\nInstance root: {$root}\n";
        $sugar_config = [];
        require $root . '/config.php';
        if (is_file($root . '/config_override.php')) require $root . '/config_override.php';
        echo 'Instance URL: ' . ($sugar_config['site_url'] ?? '(unknown)') . "\n";
        echo "This backs up and replaces application language extensions and rebuilds extensions twice.\nRun only when no other customization/repair work is in progress. Type CLEAN to continue: ";
        if (trim((string) fgets(STDIN)) !== 'CLEAN') { echo "Cancelled.\n"; exit(2); }
        $lock = fopen($root . '/.language-cleaner.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another cleaner is running.');
        if (!defined('sugarEntry')) define('sugarEntry', true);
        require_once 'include/entryPoint.php';
        require_once 'modules/Administration/QuickRepairAndRebuild.php';
        $current_user = new User();
        $current_user->getSystemUser();
        runLanguageCleaner($root, static function (): void {
            $repair = new RepairAndClear();
            $repair->rebuildExtensions();
        });
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
