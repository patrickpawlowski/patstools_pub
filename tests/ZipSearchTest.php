<?php

// Exercise the actual methods without bootstrapping Sugar or connecting to a database.
$source = file_get_contents(__DIR__ . '/../sugarutils.php');
$methods = '';
foreach (['searchPackagesForString', 'findZipEntryMatches', 'displayZipEntryMatches', 'getCommandArgument'] as $name) {
    if (!preg_match('/    private function ' . $name . '\(.*?\n    \}/s', $source, $match)) {
        throw new RuntimeException('Missing method: ' . $name);
    }
    $methods .= $match[0];
}
eval('class ZipSearchHarness {
    public $output = "";
    public $ShowMenu = true;
    public function echoc($text, $color) { $this->output .= $text; }
    public function ask($prompt) { throw new RuntimeException("Unexpected prompt"); }
    public function run($command) { $this->output = ""; $this->searchPackagesForString($command); }
' . $methods . '}');

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/zip-search-test-' . bin2hex(random_bytes(8));
$cwd = getcwd();
try {
    mkdir($root, 0700);
    chdir($root);
    $scan = new ZipSearchHarness();
    $scan->run('suz needle');
    check(str_contains($scan->output, 'does not exist'), 'Missing directory not explained.');
    mkdir('upgrades/module', 0700, true);
    $scan->run('suz needle');
    check(str_contains($scan->output, 'Archives found (upgrades/module/*.zip, non-recursive): 0'), 'Empty scan not explained.');
    $entry = "Customer's File [0].php";
    file_put_contents($entry, 'content-only-needle');
    $archive = "upgrades/module/Customer's Package.zip";
    exec('zip -q ' . escapeshellarg($archive) . ' ' . escapeshellarg($entry), $output, $code);
    check($code === 0, 'Cannot create fixture ZIP.');
    file_put_contents('upgrades/module/broken.zip', 'not a ZIP');
    $scan->run("suz customer's file [0].php");
    foreach (['Search term: "customer\'s file [0].php"', 'Working directory: ' . getcwd(),
        $archive . ': ' . $entry, 'Command: unzip -Z1', 'Entries searched: 1; matches: 1',
        'Could not list upgrades/module/broken.zip', '1 archives searched, 1 failed, 1 entries, 1 matches'] as $expected) {
        check(str_contains($scan->output, $expected), 'Missing output: ' . $expected);
    }
    $scan->run('suz content-only-needle');
    check(str_contains($scan->output, '1 entries, 0 matches'), 'Search unexpectedly matched contents.');
    $scan->run('suz 0');
    check(str_contains($scan->output, $archive . ': ' . $entry), 'Literal zero was lost.');
    check(is_file($entry) && is_file($archive), 'Search modified input files.');
    echo "ZIP search tests passed.\n";
} finally {
    chdir($cwd);
    exec('rm -rf -- ' . escapeshellarg($root));
}
