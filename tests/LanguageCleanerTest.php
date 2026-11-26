<?php
define('LANGUAGE_CLEANER_LIBRARY_ONLY', true);
require __DIR__ . '/../sugarappliststringscleaner.php';
function checkCleaner($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$root = sys_get_temp_dir() . '/language-cleaner-test-' . bin2hex(random_bytes(6));
$source = $root . '/custom/Extension/application/Ext/Language';
$compiled = $root . '/custom/application/Ext/Language';
mkdir($source, 0777, true);
mkdir($compiled, 0777, true);
$rebuilds = 0;
$rebuild = static function () use ($source, $compiled, &$rebuilds): void {
    $rebuilds++;
    $text = '';
    foreach (glob($source . '/en_us.*.php') as $file) $text .= substr(file_get_contents($file), 5);
    file_put_contents($compiled . '/en_us.lang.ext.php', '<?php' . $text);
};
try {
    file_put_contents($source . '/en_us.a.php', '<?php $app_strings["hello"]="old"; $app_list_strings["choices"]=["a"=>"First"];');
    file_put_contents($source . '/en_us.b.php', '<?php $app_strings["hello"]="new"; $app_list_strings["choices"]["b"]="Second";');
    file_put_contents($compiled . '/en_us.lang.ext.php', '<?php $app_strings["stale"]="stale";');
    runLanguageCleaner($root, $rebuild);
    checkCleaner($rebuilds === 2, 'Must rebuild before and after');
    checkCleaner(count(glob($source . '/*.php')) === 1, 'Must consolidate');
    checkCleaner(count(glob($root . '/Language_*.zip')) === 1, 'Must retain zip');
    checkCleaner(count(glob($source . '.original_*')) === 1, 'Must retain originals');
    checkCleaner(languageCleanerValues($compiled . '/en_us.lang.ext.php') === [['hello'=>'new'], ['choices'=>['a'=>'First','b'=>'Second']]], 'Effective values must survive');
    $calls = 0;
    try {
        runLanguageCleaner($root, static function () use (&$calls, $rebuild): void {
            if (++$calls === 2) throw new RuntimeException('Simulated failure');
            $rebuild();
        });
        throw new RuntimeException('Expected rebuild failure');
    } catch (RuntimeException $error) {
        checkCleaner(str_contains($error->getMessage(), 'Cleanup written; rebuild/verification incomplete'), 'Must clearly report incomplete rebuild');
    }
    try {
        runLanguageCleaner($root, static function () use ($compiled): void {
            file_put_contents($compiled . '/en_us.lang.ext.php', '<?php $app_strings=["hello"=>"new"]; $app_list_strings=["choices"=>["a"=>"First","b"=>"Second"]];');
        });
        throw new RuntimeException('Expected stale compilation failure');
    } catch (RuntimeException $error) {
        checkCleaner(str_contains($error->getMessage(), 'not regenerated'), 'Equal effective values alone must not hide stale compiled files');
    }
    file_put_contents($source . '/unexpected.txt', 'preserve');
    try {
        runLanguageCleaner($root, $rebuild);
        throw new RuntimeException('Expected preflight failure');
    } catch (RuntimeException $error) {
        checkCleaner(str_contains($error->getMessage(), 'Unexpected source entry'), 'Must refuse unknown files');
        checkCleaner(file_get_contents($source . '/unexpected.txt') === 'preserve', 'Must preserve unexpected files');
    }
    $utils = file_get_contents(__DIR__ . '/../sugarutils.php');
    checkCleaner(str_contains($utils, hash_file('sha256', __DIR__ . '/../sugarappliststringscleaner.php')), 'Pinned checksum must match release');
    checkCleaner(str_contains($utils, "refs/tags/language-cleaner-"), 'Must use release tag');
    echo "Language cleaner tests passed.\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($root);
}
