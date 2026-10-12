<?php
// Exercise only dus in subprocesses: no Sugar bootstrap or remote connections.
$source = file_get_contents(__DIR__ . '/../sugarutils.php');
preg_match('/    private function createDataUsageSnapshot\([^\n]*\) \{.*?\n    \}/s', $source, $match);
if (!$match) throw new RuntimeException('Snapshot method missing');
preg_match('/    private function dispatchCommand\([^\n]*\) \{.*?\n    \}/s', $source, $dispatch);
if (!$dispatch) throw new RuntimeException('Dispatcher missing');
if (isset($argv[1])) {
    class utils { public static function pressEnterToContinue() { echo "CONTINUE\n"; } }
    eval('class SnapshotHarness {
        public $PDO = true;
        public $InstanceInfo = ["INSTANCE" => "fixture"];
        public $SugarConfig = [];
        public $Commands = ["dus" => ["method" => "createDataUsageSnapshot"]];
        public function echoc($text, $color) { echo $text; }
        private function getLicenseConfigRows() { return ["key" => "key"]; }
        private function parseLicenseSubscription($config) { return []; }
        private function redactLicenseConfigForSnapshot($config) { return []; }
        private function collectDatabaseUsage() { if ($GLOBALS["argv"][1] === "query-failure") throw new RuntimeException("Query failed"); return []; }
        private function collectFileUsage($path) { return []; }
        private function getDataUsageOutputDir() { return $GLOBALS["argv"][2]; }
        private function sanitizeFilename($value) { return $value; }
        public function run() { $this->dispatchCommand("dus"); echo "RETURNED TO MENU\n"; }
        ' . $match[0] . $dispatch[0] . '}');
    $harness = new SnapshotHarness();
    if ($argv[1] === 'no-db') $harness->PDO = false;
    $harness->run();
    exit;
}
$dir = sys_get_temp_dir() . '/dus-test-' . bin2hex(random_bytes(6));
mkdir($dir);
try {
    foreach (['success', 'no-db', 'write-failure', 'query-failure'] as $mode) {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $mode . ' '
            . escapeshellarg($mode === 'write-failure' ? $dir . '/missing' : $dir) . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        if ($code !== 0) throw new RuntimeException($text);
        if ($mode === 'success') {
            if (!str_contains($text, 'snapshot created') || str_contains($text, 'RETURNED TO MENU') || str_contains($text, 'CONTINUE')) throw new RuntimeException($text);
            if (count(glob($dir . '/*_data_usage.json')) !== 1) throw new RuntimeException('No snapshot');
        } elseif (!str_contains($text, 'RETURNED TO MENU')) throw new RuntimeException($text);
    }
    echo "dus exits on success and stays open on failure: passed\n";
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
