<?php

// Load only the assessment methods, without initializing Sugar or connecting to a database.
$source = file_get_contents(__DIR__ . '/../sugarutils.php');
$methods = '';
foreach (['assessLanguageExtensions', 'recommendedLanguageThreshold', 'languageExtensionFolderPaths',
    'formatConfigurationNumber', 'analyzeLanguageExtensionFolder', 'analyzeCompiledLanguageDefinitions',
    'escapeMarkdownTableCell', 'singleLineValue', 'languageCodeFromFilename'] as $name) {
    if (!preg_match('/    private function ' . $name . '\([^\n]*\) \{.*?\n    \}/s', $source, $match)) {
        throw new RuntimeException('Missing method: ' . $name);
    }
    $methods .= $match[0] . "\n";
}
eval('class LanguageAssessmentHarness {
    const DEFAULT_LANG_EXT_FOLDER_MAX_SIZE_MB = 10;
    public $SugarConfig = [];
    public $Subscription = [];
    public $InstanceInfo = [];
    public $ShowMenu = true;
    public function recommend($size, $current) { return $this->recommendedLanguageThreshold($size, $current); }
    public function paths(&$error) { return $this->languageExtensionFolderPaths($error); }
    public function report() { ob_start(); $this->assessLanguageExtensions(); return ob_get_clean(); }
' . $methods . '}');

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$scan = new LanguageAssessmentHarness();
foreach ([[19.17, 20, 25], [18.51, 10, 25], [19.17, 30, 30], [0, 10, 10], [10, 10, 15]] as $case) {
    check($scan->recommend($case[0], $case[1]) == $case[2], 'Incorrect recommendation');
}
$root = sys_get_temp_dir() . '/lea-test-' . bin2hex(random_bytes(8));
$cwd = getcwd();
try {
    mkdir($root);
    chdir($root);
    $error = '';
    check(count($scan->paths($error)) === 2, 'Missing standard paths');
    $module = 'custom/Extension/modules/Accounts/Ext/Language';
    $compiled = 'custom/application/Ext/Language';
    mkdir($module, 0777, true);
    mkdir($compiled, 0777, true);
    file_put_contents($module . '/en_us.php', '<?php');
    $handle = fopen($module . '/data.txt', 'w');
    ftruncate($handle, (int) (19.17 * 1048576));
    fclose($handle);
    file_put_contents($compiled . '/en_us.php', "<?php\n\$app_list_strings['test'] = [];\n\$app_list_strings['test'] = [];\n");
    mkdir('custom/unrelated/Language', 0777, true);
    file_put_contents('custom/unrelated/Language/test.php', '<?php');
    $paths = $scan->paths($error);
    check(in_array($module, $paths, true), 'Module folder not discovered');
    check(count($paths) === 3, 'Unexpected or duplicated paths');
    check($error === '', 'Unexpected discovery error');
    $scan->SugarConfig = ['lang_ext_folder_max_size_mb' => 20];
    $report = $scan->report();
    check(str_contains($report, '**Current**'), 'Missing current label');
    check(str_contains($report, '**Recommended**'), 'Missing recommended label');
    check(str_contains($report, "= 20;"), 'Current value missing');
    check(str_contains($report, "= 25;"), 'Duplicates suppressed recommendation');
    check(str_contains($report, '1 additional list definitions'), 'Duplicate fixture not detected');
    check(str_contains($report, $module), 'Module absent from report');
    check(str_contains($report, '10% headroom'), 'Incorrect headroom text');
    echo "Language assessment tests passed.\n";
} finally {
    chdir($cwd);
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
