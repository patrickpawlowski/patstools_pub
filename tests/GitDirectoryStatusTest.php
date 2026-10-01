<?php

// Isolate the startup check from Sugar initialization and database access.
$source = file_get_contents(__DIR__ . '/../sugarutils.php');
if (!preg_match('/    private function displayGitDirectoryStatus\(\) \{.*?\n    \}/s', $source, $match)) {
    throw new RuntimeException('Startup scan method not found.');
}
eval('class GitDirectoryStatusHarness {
    public $InstanceInfo = [];
    public $output = "";
    public function echoc($text, $color) { $this->output .= $text; }
    public function run() { $this->output = ""; $this->displayGitDirectoryStatus(); }
' . $match[0] . '}');

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/git-scan-test-' . bin2hex(random_bytes(8));
$instance = $root . "/customer's instance";
try {
    mkdir($instance, 0777, true);
    $scan = new GitDirectoryStatusHarness();
    $scan->InstanceInfo['SHADOW'] = $instance;
    $scan->run();
    check(str_starts_with($scan->output, "Checking for .git directories . . .\n"), 'Missing scan progress message.');
    check(strpos($scan->output, 'no .git directories found') !== false, 'Clean scan failed.');
    foreach (['/.git', '/custom/.git', '/modules/example/.git', '/custom/.github'] as $path) {
        mkdir($instance . $path, 0777, true);
        file_put_contents($instance . $path . '/index', 'fixture');
    }
    mkdir($root . '/outside/.git', 0777, true);
    symlink($root . '/outside', $instance . '/external');
    $scan->run();
    check(strpos($scan->output, 'detected (3)') !== false, 'Wrong directory count.');
    foreach (['.git', 'custom/.git', 'modules/example/.git'] as $path) {
        check(strpos($scan->output, "    - {$path}\n") !== false, 'Missing relative path.');
        check(is_file($instance . '/' . $path . '/index'), 'Scan modified metadata.');
    }
    check(strpos($scan->output, 'external') === false, 'Scan followed a symlink.');
    $scan->InstanceInfo['SHADOW'] = $root . '/missing';
    $scan->run();
    check(strpos($scan->output, 'could not be completed') !== false, 'Missing root was reported as clean.');
    check(strpos($source, '$this->displayGitDirectoryStatus();') !== false, 'Startup call missing.');
    echo "Git directory startup scan tests passed.\n";
} finally {
    exec('rm -rf -- ' . escapeshellarg($root));
}
