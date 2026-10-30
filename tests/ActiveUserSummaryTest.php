<?php

$source = file_get_contents(__DIR__ . '/../sugarutils.php');
$methods = '';
foreach (['getActiveUserCount', 'displayWarnings'] as $name) {
    if (!preg_match('/    private function ' . $name . '\(\) \{.*?\n    \}/s', $source, $match)) {
        throw new RuntimeException('Missing method: ' . $name);
    }
    $methods .= $match[0];
}
eval('class ActiveUserSummaryHarness {
    public $PDO;
    public $Subscription = ["quantity_c" => 1, "product" => "Sell", "subscription_id" => "test"];
    private $ActiveUserCount = null;
    private $ActiveAdminCount = null;
    public $output = "";
    public function echoc($text, $color) { $this->output .= $text; }
    public function run() { $this->displayWarnings(); }
' . $methods . '}');

// Translate only MySQL column discovery; exercise the actual aggregate on SQLite.
class UserSummaryPDO extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if ($query === 'SHOW COLUMNS FROM users') {
            $query = 'SELECT name FROM pragma_table_info("users")';
        }
        return parent::query($query);
    }
}
$db = new UserSummaryPDO('sqlite::memory:');
$db->exec('CREATE TABLE users (deleted INT, status TEXT, portal_only INT, is_group INT, is_admin INT)');
$db->exec("INSERT INTO users VALUES
    (0, 'Active', 0, 0, 1), (0, 'Active', 0, 0, 0), (0, 'Active', NULL, NULL, NULL),
    (1, 'Active', 0, 0, 1), (0, 'Inactive', 0, 0, 0),
    (0, 'Active', 1, 0, 0), (0, 'Active', 0, 1, 0)");
$test = new ActiveUserSummaryHarness();
$test->PDO = $db;
$test->run();
if (!str_contains($test->output, '3 active users (1 admin, 2 non-admin), 1 licensed (2 over)')) {
    throw new RuntimeException('Incorrect license summary: ' . $test->output);
}
$db->exec('ALTER TABLE users DROP COLUMN is_admin');
$test = new ActiveUserSummaryHarness();
$test->PDO = $db;
$test->run();
if (!str_contains($test->output, '3 active users (admin/non-admin breakdown unavailable)')) {
    throw new RuntimeException('Missing admin column was not handled.');
}
echo "Active user summary tests passed.\n";
