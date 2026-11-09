<?php

$source = file_get_contents(__DIR__ . '/../sugarutils.php');
$methods = '';
foreach (['databaseMarkdownTable', 'dbManageSpace', 'singleLineValue'] as $name) {
    if (!preg_match('/    private function ' . $name . '\([^\n]*\) \{.*?\n    \}/s', $source, $match)) {
        throw new RuntimeException('Missing method: ' . $name);
    }
    $methods .= $match[0] . "\n";
}
eval('class DatabaseReportHarness {
    public $PDO;
    public $SugarConfig = ["dbconfig" => ["db_name" => "db_clone"]];
    public $Subscription = ["account_name" => "Example Account"];
    public $InstanceInfo = ["INSTANCE" => "case123.sugarondemand.com", "VERSION" => "26.2.0"];
    public $output = "";
    public function echoc($text, $color) { $this->output .= $text; }
    public function run() { $this->dbManageSpace(); }
    public function table($rows) { return $this->databaseMarkdownTable($rows); }
' . $methods . '}');

class utils { public static function pressEnterToContinue() {} }
class DatabaseReportPDO {
    public $queries = [];
    public function query($sql) {
        $this->queries[] = $sql;
        if (substr_count($sql, ';') !== 1 || !str_starts_with($sql, 'SELECT')) {
            throw new RuntimeException('Expected one read-only SQL statement');
        }
        $rows = count($this->queries) === 1 ? [['Database Size in GB' => '580.0516']]
            : (count($this->queries) === 2 ? [] : [['Table' => 'contacts_audit', 'Size in GB' => '12.00']]);
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function fetchAll($mode) { return $this->rows; }
        };
    }
}
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$report = new DatabaseReportHarness();
$report->PDO = new DatabaseReportPDO();
$report->run();
check(str_starts_with($report->output, "case123.sugarondemand.com_database_analysis.md\n\nAccount Name: Example Account  \nInstance: case123.sugarondemand.com  \nVersion: 26.2.0\n"), 'Report identity missing or misplaced');
check(count($report->PDO->queries) === 8, 'Missing query');
check(str_contains($report->output, "| Database Size in GB |\n| --- |\n| 580.0516 |"), 'Missing Markdown result');
check(str_contains($report->output, '_No matching rows._'), 'Empty results missing');
check(substr_count($report->output, '```sql') === 8, 'SQL blocks missing');
check(substr_count($report->output, '```') === 16, 'Results should not be code-fenced');
check(str_contains($report->output, "large.\n```sql"), 'Audit SQL block missing newline');
$table = $report->table([['A|B' => "one|two\r\n<three>", 'Null' => null, 'Zero' => 0]]);
check(str_contains($table, 'A&#124;B'), 'Header pipe not escaped');
check(str_contains($table, 'one&#124;two<br>&lt;three&gt;'), 'Cell escaping failed');
check(str_contains($table, '| NULL | 0 |'), 'Null/zero not preserved');
$report->Subscription = [];
$report->InstanceInfo['VERSION'] = '';
$report->output = '';
$report->PDO = new DatabaseReportPDO();
$report->run();
check(str_contains($report->output, 'Account Name: Unknown Account'), 'Missing account fallback');
check(str_contains($report->output, 'Version: Unknown'), 'Missing version fallback');
echo "Database report tests passed.\n";
