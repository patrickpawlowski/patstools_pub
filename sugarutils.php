#! /usr/bin/env php
<?php
/*
 * Download and run:
 * wget --https-only https://wupgrade.wsysnet.com/patstools/sugarutils.php -O sugarutils
 * chmod 700 sugarutils
 * ./sugarutils
 *
 * 
 * TODO:
 * Add sql queries:
 *      select * from job_queue group by running and queued
 *      show processlist;
 *      show full processlist;
 *      record counts and table sizes;
 *      select fts_queue; total and grouped by mdoule;
 *      get license info from config table;
 *      Disable all non-admin users after backing up the users table.
 *          create table users_bak_20230705 select * from users;
 *          update users set status = 'Inactive' where not is_admin;
 *          Maybe add a restore function as well.
 * 
 * - [ ] Data Integrity Scripts - t3hc (Tier 3 Health Check)
 *     - [ ] Add a check for config*.php:  'upload_wrapper_class' => 'SugarUploadS3',
 * 
 * 
 * 
 * 
 * 
 * @todo Add an email configuration safety workflow without embedding credentials.
 */

class sugarutils {

    private $Menu = "sc = Search Custom Folder\nsu = Search Upgrades\nsd = StartDiscovery\ngm = Generate Message\nlu = List Users\nflf = Find Large Files\nq = Quit";
    private $Defaults = array("Recipients", "AccountName", "InstanceNames", "Issues", "Files", "LinkToHelpArticle", "PackageDate", "PackageName", "PackageAuthor", "PackageManifestDetails", "Sender");
    private $Details = array();
    private $Options;
    private $SugarConfig;
    private $PDO = false;
    private $ReadOnlyPDO = false;
    private $ReadOnlyConnectionName = '';
    private $InstanceInfo = array();
    private $Subscription = array();
    private $ShowMenu = false;
    private $StartupSummaryShown = false;
    private $ActiveUserCount = null;
    private $ForkedCoreModules = null;
    private $Commands = array();
    
    const DATE_CMU = 'Y-m-d H:i T';
    const DATE_CMU_SECONDS = 'Y-m-d H:i:s T';
    const DEFAULT_LANG_EXT_FOLDER_MAX_SIZE_MB = 10.0;

    public function __construct() {
        umask(0077);
        ini_set('display_errors', 1);
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

        if (!file_exists('config.php') || !file_exists('custom') || !file_exists('cache')) {
            $this->echoc("This does not appear to be the root of a Sugar instance. Missing config.php file, custom folder, or cache folder.\n", 'red');
            exit();
        }
        if (!file_exists('config.php')) {
//            $this->echoc("This does not appear to be the root of a Sugar instance. Missing config.php file\n", 'red');
//            exit();
        }
        if (!file_exists('custom')) {
//            $this->echoc("This does not appear to be the root of a Sugar instance. Missing custom folder\n", 'red');
//            exit();
        }
        if (!file_exists('modules')) {
//            $this->echoc("This does not appear to be the root of a Sugar instance. Missing modules folder.\n", 'red');
//            exit();
        }
        require 'config.php';
        require 'config_override.php';
        $this->Options = getopt('pstla');
        $this->SugarConfig = $sugar_config;
        $this->initCommandRegistry();
        $this->testSQL();
        $this->loadInstnceInfo();
        $this->loadLicenseInfo();

//        
//        require_once "{$this->InstanceInfo['TEMPLATE']}/include/utils/autoloader.php";
//
//        require_once "{$this->InstanceInfo['TEMPLATE']}/modules/Administration/QuickRepairAndRebuild.php";
//
//        if(!defined("sugarEntry")) define("sugarEntry",true);
//
//        require_once "{$this->InstanceInfo['TEMPLATE']}/include/entryPoint.php";
    }

    public function run() {
        $this->backupConfigs();
        $this->displayMenu();
    }

    public function __destruct() {
        $ScriptPath = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $WorkingDirectory = realpath(getcwd());
        if ($ScriptPath !== false
                && $WorkingDirectory !== false
                && dirname($ScriptPath) === $WorkingDirectory
                && basename($ScriptPath) === 'sugarutils') {
            unlink($ScriptPath);
        }
    }
    
    private function backupConfigs() {
        $this->ensureCloudSupportFolder();
        $Filename = "configs_" . date('YmdHmis') . ".zip";
        $ArchivePath = "cloud_support/{$Filename}";
        $ConfigFiles = array_values(array_filter(glob('config*.php') ?: array(), 'is_file'));
        if (!$ConfigFiles) {
            $this->echoc("No config files found to back up.\n", 'yellow');
            return;
        }
        $Command = 'zip ' . escapeshellarg($ArchivePath) . ' '
            . implode(' ', array_map('escapeshellarg', $ConfigFiles));
        $this->echoc("Backing up config files . . .\n", 'label');
        $this->echoc($Command.PHP_EOL, 'command');
        system($Command, $ExitCode);
        if ($ExitCode !== 0 || !is_file($ArchivePath)) {
            $this->echoc("Config backup failed.\n", 'red');
            return;
        }
        chmod($ArchivePath, 0600);
    }

    private function displayInfo() {
        $this->echoc("[Subscription Info]\n", 'section');
        $this->echoc(str_pad("Account", 20), 'label');
        $this->echoc(" = ", 'red');
        $this->echoc("{$this->Subscription['account_name']}\n", 'data');

        $this->echoc(str_pad("Account Link", 20), 'label');
        $this->echoc(" = ", 'red');
        $this->echoc("https://cloudsi.sugarondemand.com/#Accounts/{$this->Subscription['account_id']}\n", 'url');

        $this->echoc(str_pad("Dashboard Link", 20), 'label');
        $this->echoc(" = ", 'red');
        $this->echoc("https://wupgrade.wsysnet.com/cloud_support/dashboard/instances.php?account_id={$this->Subscription['account_id']}\n", 'url');

        $this->echoc(str_pad("License Key", 20), 'label');
        $this->echoc(" = ", 'red');
        $this->echoc("{$this->Subscription['subscription_id']}\n", 'data');

        $this->echoc(str_pad("License Count", 20), 'label');
        $this->echoc(" = ", 'red');
        $this->echoc("{$this->Subscription['quantity_c']}\n", 'data');

        $ActiveUserCount = $this->getActiveUserCount();
        if ($ActiveUserCount !== null) {
            $this->echoc(str_pad("Active Users", 20), 'label');
            $this->echoc(" = ", 'red');
            $this->echoc("{$ActiveUserCount}\n", 'data');
        }

        $this->echoc(str_pad("Product", 20), 'label');
        $this->echoc(" = ", 'red');
        $this->echoc("{$this->Subscription['product']}\n", 'data');

        $this->echoc(str_pad("Addons", 20), 'label');
        $this->echoc(" = ", 'red');
        $l = 0;
        foreach ($this->Subscription['addons'] as $Addon) {
            if ($l++) {
                $this->echoc(str_repeat(' ', 23) . "{$Addon['product_name']}\n", 'data');
            } else {
                $this->echoc("{$Addon['product_name']}\n", 'data');
            }
        }

        $this->echoc("[Instance Info]\n", 'section');
        foreach ($this->InstanceInfo as $Name => $Value) {
            $this->echoc(str_pad("$Name", 20), 'label');
            $this->echoc(" = ", 'red');
            $this->echoc("$Value\n", 'data');
        }
    }
    
    private function displayWarnings() {
        if(!$this->Subscription['quantity_c']){
            $this->echoc("*** License count is 0 ***\n", "red");
        }
        if(!$this->Subscription['product']){
            $this->echoc("*** License has no products ***\n", "red");
        }
        if(!$this->Subscription['subscription_id']){
            $this->echoc("*** License not detected ***\n", "red");
        }
        $ActiveUserCount = $this->getActiveUserCount();
        $LicenseCount = isset($this->Subscription['quantity_c'])
            ? (int) $this->Subscription['quantity_c']
            : 0;
        if ($ActiveUserCount !== null && $LicenseCount > 0 && $ActiveUserCount > $LicenseCount) {
            $Overage = $ActiveUserCount - $LicenseCount;
            $this->echoc(
                "*** License exceeded: {$ActiveUserCount} active users, {$LicenseCount} licensed ({$Overage} over) ***\n",
                'red'
            );
        }
    }

    private function displayStartupSummary() {
        $this->echoc(str_pad("---<=== Sugar Utilities Summary ===>---", 116, ' ', STR_PAD_BOTH), 'brightblue');
        echo PHP_EOL . PHP_EOL;
        $this->displayInfo();
        $this->displayWarnings();
        $this->displayForkedCoreModuleStatus();
        $this->StartupSummaryShown = true;
    }

    private function getActiveUserCount() {
        if ($this->ActiveUserCount !== null) {
            return $this->ActiveUserCount;
        }
        if (!$this->PDO instanceof PDO) {
            return null;
        }

        try {
            $Columns = $this->PDO->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            $Columns = array_map('strtolower', $Columns ?: array());
            $Conditions = array("deleted = 0", "status = 'Active'");
            if (in_array('portal_only', $Columns, true)) {
                $Conditions[] = 'COALESCE(portal_only, 0) = 0';
            }
            if (in_array('is_group', $Columns, true)) {
                $Conditions[] = 'COALESCE(is_group, 0) = 0';
            }
            $SQL = 'SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $Conditions);
            $this->ActiveUserCount = (int) $this->PDO->query($SQL)->fetchColumn();
            return $this->ActiveUserCount;
        } catch (PDOException $Exception) {
            return null;
        }
    }

    private function displayForkedCoreModuleStatus() {
        $ForkedModules = $this->findForkedCoreModuleFolders();
        if ($ForkedModules === null) {
            $this->echoc("Forked core module check could not be completed because a modules folder was not found.\n", 'yellow');
            return;
        }
        if (!$ForkedModules) {
            $this->echoc("Forked core module check: no matching module folders found.\n", 'green');
            return;
        }

        $this->echoc(
            '*** Forked core module folders detected (' . count($ForkedModules) . ") ***\n",
            'red'
        );
        foreach ($ForkedModules as $Module) {
            $this->echoc("    - {$Module}\n", 'red');
        }
    }

    private function findForkedCoreModuleFolders() {
        if ($this->ForkedCoreModules !== null) {
            return $this->ForkedCoreModules;
        }

        $TemplateRoot = rtrim((string) ($this->InstanceInfo['TEMPLATE'] ?? ''), '/');
        $ShadowRoot = rtrim((string) ($this->InstanceInfo['SHADOW'] ?? ''), '/');
        $TemplateModules = $TemplateRoot . '/modules';
        $ShadowModules = $ShadowRoot . '/modules';
        if (!is_dir($TemplateModules) || !is_dir($ShadowModules)) {
            return null;
        }

        $TemplateNames = $this->immediateDirectoryNames($TemplateModules);
        $ShadowNames = $this->immediateDirectoryNames($ShadowModules);
        $this->ForkedCoreModules = array_values(array_intersect($ShadowNames, $TemplateNames));
        natcasesort($this->ForkedCoreModules);
        $this->ForkedCoreModules = array_values($this->ForkedCoreModules);
        return $this->ForkedCoreModules;
    }

    private function immediateDirectoryNames($Path) {
        $Names = array();
        foreach (scandir($Path) ?: array() as $Name) {
            if ($Name === '.' || $Name === '..') {
                continue;
            }
            if (is_dir($Path . DIRECTORY_SEPARATOR . $Name)) {
                $Names[] = $Name;
            }
        }
        return $Names;
    }

    private function initCommandRegistry() {
        $this->Commands = array(
            'sc' => array('label' => 'Search custom Folder', 'method' => 'searchCustomFolder', 'section' => 'Search'),
            'sdl' => array('label' => 'Search Dropdown List', 'method' => 'searchDropdownLists', 'section' => 'Search'),
            'sp' => array('label' => 'Search Package Source', 'method' => 'searchPackageSource', 'section' => 'Search'),
            'su' => array('label' => 'Search upgrades/module Folder', 'method' => 'searchUpgradesFolder', 'section' => 'Search'),
            'suz' => array('label' => 'Search upgrades/module Folder zips', 'method' => 'searchPackagesForString', 'section' => 'Search'),
            'sm' => array('label' => 'Search Manifests', 'method' => 'searchManifests', 'section' => 'Search'),
            'fa' => array('label' => 'Scan for Font Awesome uses', 'method' => 'scanForFontAwesomeIcons', 'section' => 'Search'),

            'lu' => array('label' => 'List Users', 'method' => 'listUsers', 'section' => 'Database / Instance Info'),
            'lau' => array('label' => 'List Admin Users', 'method' => 'getAdminUsers', 'section' => 'Database / Instance Info'),
            'ljbs' => array('label' => 'List Jobs by Status', 'method' => 'listJobsByStatus', 'section' => 'Database / Instance Info'),
            'cftsq' => array('label' => 'Check FTS Queue', 'method' => 'checkFTSQueue', 'section' => 'Database / Instance Info'),
            'spl' => array('label' => 'Show Process List', 'method' => 'showProcessList', 'section' => 'Database / Instance Info'),
            'ws' => array('label' => 'Watch SQL', 'method' => 'watchSQL', 'section' => 'Database / Instance Info'),
            'dbms' => array('label' => 'Database Manage Space', 'method' => 'dbManageSpace', 'section' => 'Database / Instance Info'),
            'dus' => array('label' => 'Create Data Usage Snapshot JSON', 'method' => 'createDataUsageSnapshot', 'section' => 'Database / Instance Info'),
            'cc' => array('label' => 'Check Collation', 'method' => 'checkCollation', 'section' => 'Database / Instance Info'),

            'scu' => array('label' => 'Sugar Checkup', 'method' => 'runSugarCheckup', 'section' => 'Maintenance / Repairs'),
            'hc' => array('label' => 'Health Check', 'method' => 'runHealthCheck', 'section' => 'Maintenance / Repairs'),
            'lea' => array('label' => 'Language Extension Assessment', 'method' => 'assessLanguageExtensions', 'section' => 'Maintenance / Repairs'),
            'qrr' => array('label' => 'Quick Repair and Rebuild', 'method' => 'runQuickRepairandRebuild', 'section' => 'Maintenance / Repairs'),
            'rdis' => array('label' => 'Run Data Integrity Scripts', 'method' => 'runDataIntegrityScripts', 'section' => 'Maintenance / Repairs'),
            'pm' => array('label' => 'Parse Manifest', 'method' => 'parseManifest', 'section' => 'Maintenance / Repairs'),
            'suh' => array('label' => 'Show Upgrade History', 'method' => 'showUpgradeHistory', 'section' => 'Maintenance / Repairs'),
            'ci' => array('label' => 'Check Imports', 'method' => 'checkImports', 'section' => 'Maintenance / Repairs'),
            'wt' => array('label' => "What's this", 'method' => 'whatsThis', 'section' => 'Maintenance / Repairs'),
            'bft' => array('label' => 'Brute Force Troubleshooting', 'method' => 'runBruteForceTroubleshooting', 'section' => 'Troubleshooting', 'dangerous' => true),

            'bcmf' => array('label' => 'Backup Custom and Modules Folders', 'method' => 'backupCustomAndModulesFolders', 'section' => 'Destructive / Changes Data or Files'),
            'dnu' => array('label' => 'Deactivate Non-admin Users', 'method' => 'deactivateNonAdminUsers', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),
            'fc' => array('label' => 'Fix Collation', 'method' => 'fixCollation', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),
            'huh' => array('label' => 'Hide Upgrade History', 'method' => 'hideUpgradeHistory', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),
            'uuh' => array('label' => 'Unhide Upgrade History', 'method' => 'unhideUpgradeHistory', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),
            'mrcj' => array('label' => 'Manually Remove Customer Journey', 'method' => 'manuallyRemoveCustomerJourney', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),
            'rfcm' => array('label' => 'Remove Forked Core Modules', 'method' => 'removeForkedCoreModules', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),
            'mu' => array('label' => 'Migrate Uploads', 'method' => 'migrateUploads', 'section' => 'Destructive / Changes Data or Files', 'dangerous' => true),

            'sd' => array('label' => 'Start Discovery', 'method' => 'startDiscovery', 'section' => 'Message / Workflow Helpers'),
            'gm' => array('label' => 'Generate Message', 'method' => 'generateMessage', 'section' => 'Message / Workflow Helpers'),
            'flf' => array('label' => 'Find Large Files', 'method' => 'findLargeFiles', 'section' => 'File / Package Helpers'),
            'cu' => array('label' => 'Check Uploads', 'method' => 'checkUploads', 'section' => 'File / Package Helpers'),
            'ps' => array('label' => 'Package Scan', 'method' => 'packageScan', 'section' => 'File / Package Helpers'),
            'adt' => array('label' => 'Archive Document Templates', 'method' => 'archiveDocumentTemplates', 'section' => 'File / Package Helpers'),

            'cfi95922' => array('label' => '[SugarBPM] Relationship Change Start Event issue 95922', 'method' => 'checkForIssue95922', 'section' => 'Special Issues'),
            'cfi95830' => array('label' => 'Issue 95830: Opportunity/RLI navigation breakage', 'method' => 'checkForIssue95830', 'section' => 'Special Issues'),
            'cfi95840' => array('label' => 'Alias for cfi95830', 'method' => 'checkForIssue95830', 'section' => 'Special Issues', 'hidden' => true),
        );
    }

    private function displayMenu() {
        $LabelWidth = 48;
        $CommandWidth = 10;
        $TotalWidth = ($LabelWidth + $CommandWidth) * 2;
        while (true) {
            if ($this->ShowMenu) {
                $this->echoc(str_pad("---<=== Sugar Utilities ===>---", $TotalWidth, ' ', STR_PAD_BOTH), 'brightblue');
                echo PHP_EOL . PHP_EOL;
                $this->displayCommandMenu($LabelWidth, $CommandWidth, $TotalWidth);
                $this->displayWarnings();
                $Command = $this->ask("Enter Command: ");
            } else {
                if (!$this->StartupSummaryShown) {
                    $this->displayStartupSummary();
                } else {
                    $this->displayWarnings();
                }
                $Command = $this->ask("Enter Command or press enter to display the menu: ");
            }
            $this->ShowMenu = true;
            $this->dispatchCommand($Command);
        }
    }

    private function displayCommandMenu($LabelWidth, $CommandWidth, $TotalWidth) {
        $CurrentSection = '';
        $Column = 0;
        foreach ($this->Commands as $Command => $Config) {
            if (!empty($Config['hidden'])) {
                continue;
            }
            $Section = isset($Config['section']) ? $Config['section'] : 'Commands';
            if ($Section !== $CurrentSection) {
                if ($Column !== 0) {
                    echo PHP_EOL;
                    $Column = 0;
                }
                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("---<=== {$Section} ===>---", $TotalWidth, '-', STR_PAD_BOTH) . PHP_EOL, 'brightblue');
                $CurrentSection = $Section;
            }
            $Label = !empty($Config['dangerous']) ? "{$Config['label']} *" : $Config['label'];
            $this->echoc(str_pad($Command, $CommandWidth), 'data');
            $this->echoc(str_pad($Label, $LabelWidth), 'label');
            $Column++;
            if ($Column >= 2) {
                echo PHP_EOL;
                $Column = 0;
            }
        }
        if ($Column !== 0) {
            echo PHP_EOL;
        }
        $this->echoc(str_repeat(PHP_EOL, 1) . str_pad('q', $CommandWidth), 'data');
        $this->echoc(str_pad('Quit', $LabelWidth), 'label');
        echo PHP_EOL;
        $this->echoc("* Potentially destructive command. A confirmation prompt will be shown before it runs.\n", 'bad');
    }

    private function dispatchCommand($Command) {
        $Option = strtolower(explode(' ', trim($Command))[0]);
        if ($Option === '') {
            return;
        }
        if ($Option === 'q' || $Option === 'exit') {
            $this->echoc("Leaving sugarutils; returning to the shell.\n", 'brightmagenta');
            exit();
        }
        if (empty($this->Commands[$Option])) {
            $this->echoc("Command '{$Command}' not found!\n", 'red');
            return;
        }
        $Config = $this->Commands[$Option];
        $Method = $Config['method'];
        if (!method_exists($this, $Method)) {
            $this->echoc("Command '{$Option}' is configured for missing method '{$Method}'.\n", 'bad');
            return;
        }
        if (!empty($Config['dangerous']) && !$this->confirmDangerousCommand($Option, $Config)) {
            $this->echoc("Command '{$Option}' cancelled.\n", 'bad');
            $this->ShowMenu = false;
            return;
        }
        $Reflection = new ReflectionMethod($this, $Method);
        if ($Reflection->getNumberOfParameters() > 0) {
            $this->$Method($Command);
        } else {
            $this->$Method();
        }
    }

    private function confirmDangerousCommand($Option, $Config) {
        $this->echoc("\n*** WARNING ***\n", 'bad');
        $this->echoc("'{$Option}' - {$Config['label']} is potentially destructive.\n", 'bad');
        $this->echoc("This command may change data, modify files, remove files, or make the Sugar instance unusable until follow-up work is completed.\n", 'bad');
        return $this->askYes("Type 'yes' to continue.");
    }
    private function runSugarExplorer() {
        if (!defined('sugarEntry')){
            define('sugarEntry', true);
        }
        define('ENTRY_POINT_TYPE', 'api');
        require_once('include/entryPoint.php');

        echo "VARIABLES\n\n";
        $Vars = get_defined_vars();
        foreach ($Vars as $Key => $Value) {
            echo "\t$Key\n";
        }
//
////$InfoKeys = ['sugar_version', 'sugar_flavor', 'sugar_mar_verslsion', 'current_user', 'current_entity', 'sugar_db_version', 'sugar_build'];
//$InfoKeys = ['sugar_version', 'sugar_flavor', 'sugar_mar_verslsion', 'current_entity', 'sugar_db_version', 'sugar_build'];
//foreach ($InfoKeys as $Key){
//    echo "$Key: {$Vars[$Key]}\n";
//}
//print_r($Vars['sugar_config']);
        print_r($Vars['moduleList']);
        print_r($Vars['beanList']);
    }

    private function runSugarCheckup($Command) {
        $this->echoc("Running Sugar Checkup . . .\n", 'label');
        $this->checkForEnumFieldsMissingList($Command);
        $this->quickcheckCollation();
        
        $this->whatsThis();
        $this->ShowMenu = false;
    }

    private function assessLanguageExtensions() {
        $ThresholdConfigured = array_key_exists('lang_ext_folder_max_size_mb', $this->SugarConfig);
        $ThresholdMiB = $ThresholdConfigured
            ? (float) $this->SugarConfig['lang_ext_folder_max_size_mb']
            : self::DEFAULT_LANG_EXT_FOLDER_MAX_SIZE_MB;
        if ($ThresholdMiB <= 0) {
            $ThresholdMiB = self::DEFAULT_LANG_EXT_FOLDER_MAX_SIZE_MB;
            $ThresholdConfigured = false;
        }

        $Folders = array(
            $this->analyzeLanguageExtensionFolder('custom/Extension/application/Ext/Language'),
            $this->analyzeLanguageExtensionFolder('custom/application/Ext/Language'),
        );
        $CompiledDefinitions = $this->analyzeCompiledLanguageDefinitions('custom/application/Ext/Language');
        $ExceedingFolders = array_values(array_filter($Folders, function ($Folder) use ($ThresholdMiB) {
            return $Folder['Exists'] && $Folder['LogicalMiB'] > $ThresholdMiB;
        }));
        $ExistingFolders = array_values(array_filter($Folders, function ($Folder) {
            return $Folder['Exists'];
        }));

        $AccountName = $this->singleLineValue($this->Subscription['account_name'] ?? 'Unknown Account');
        $InstanceName = $this->singleLineValue($this->InstanceInfo['INSTANCE'] ?? 'Unknown');
        $Version = $this->singleLineValue($this->InstanceInfo['VERSION'] ?? 'Unknown');
        $ReportDate = gmdate('Y-m-d H:i T');
        $Report = array(
            '#### Language Extension Assessment for ' . $AccountName,
            '',
            "Instance: {$InstanceName}  Version: {$Version}  Report Date: {$ReportDate}",
            '',
        );
        if (!$ExistingFolders) {
            $Report[] = 'The standard Sugar language extension folders were not found in this instance.';
        } elseif ($ExceedingFolders) {
            $FolderCount = count($ExceedingFolders);
            $FolderWord = $FolderCount === 1 ? 'folder exceeds' : 'folders exceed';
            $Report[] = "The Sugar Health Check is expected to report this instance because {$FolderCount} language extension {$FolderWord} the "
                . number_format($ThresholdMiB, 2) . ' MiB size limit. The measurements below use logical file size, which is the value evaluated by the Health Check.';
        } else {
            $Report[] = 'The measured language extension folders are within the Sugar Health Check limit of '
                . number_format($ThresholdMiB, 2) . ' MiB. The measurements below use logical file size, which is the value evaluated by the Health Check.';
        }

        if ($CompiledDefinitions['ExtraDefinitions'] > 0) {
            $Report[] = 'The compiled language files contain '
                . number_format($CompiledDefinitions['ExtraDefinitions'])
                . ' additional list definitions beyond the first definition in the same language, affecting '
                . number_format($CompiledDefinitions['RepeatedPairs'])
                . ' language/list combinations. The highest observed count is '
                . number_format($CompiledDefinitions['MaximumDefinitions'])
                . ' definitions of one list in one language. This is consistent with accumulated duplicate or overriding language customizations and warrants cleanup with supported tooling.';
        } elseif ($CompiledDefinitions['Exists']) {
            $Report[] = 'No list name was defined more than once within the same compiled language file.';
        }

        $Report[] = '';
        $Report[] = '| Folder | Logical Size | Allocated File Space | Files | Languages | Health Check |';
        $Report[] = '| --- | ---: | ---: | ---: | ---: | --- |';
        foreach ($Folders as $Folder) {
            if (!$Folder['Exists']) {
                $Report[] = '| `' . $this->escapeMarkdownTableCell($Folder['Path']) . '` | Not found | - | - | - | Not evaluated |';
                continue;
            }
            $Status = $Folder['LogicalMiB'] > $ThresholdMiB
                ? 'Exceeds limit by ' . number_format($Folder['LogicalMiB'] - $ThresholdMiB, 2) . ' MiB'
                : 'Within limit';
            $Report[] = '| `' . $this->escapeMarkdownTableCell($Folder['Path']) . '` | '
                . number_format($Folder['LogicalMiB'], 2) . ' MiB | '
                . number_format($Folder['AllocatedMiB'], 2) . ' MiB | '
                . number_format($Folder['Files']) . ' | '
                . number_format($Folder['Languages']) . ' | '
                . $Status . ' |';
        }

        $SourceFolder = $Folders[0];
        if ($SourceFolder['Exists'] && $SourceFolder['Languages'] > 0) {
            $FilesPerLanguage = $SourceFolder['Files'] / $SourceFolder['Languages'];
            $Report[] = '';
            $Report[] = 'The source extension folder averages '
                . number_format($FilesPerLanguage, 1)
                . ' files per language. Allocated file space may be substantially larger than logical size when the folder contains thousands of small files; the Health Check uses logical size.';
        }

        if ($CompiledDefinitions['Exists']) {
            $Report[] = '';
            $Report[] = '##### Compiled Language Definitions';
            $Report[] = '';
            $Report[] = '- Compiled files analyzed: ' . number_format($CompiledDefinitions['Files']);
            $Report[] = '- Compiled lines analyzed: ' . number_format($CompiledDefinitions['Lines']);
            $Report[] = '- Total list assignments: ' . number_format($CompiledDefinitions['Assignments']);
            $Report[] = '- Language/list combinations defined more than once: ' . number_format($CompiledDefinitions['RepeatedPairs']);
            $Report[] = '- Additional definitions beyond the first: ' . number_format($CompiledDefinitions['ExtraDefinitions']);

            if ($CompiledDefinitions['TopRepeated']) {
                $Report[] = '';
                $Report[] = '| Language | List | Definitions | Additional Definitions |';
                $Report[] = '| --- | --- | ---: | ---: |';
                foreach ($CompiledDefinitions['TopRepeated'] as $Repeated) {
                    $Report[] = '| ' . $this->escapeMarkdownTableCell($Repeated['Language'])
                        . ' | `' . $this->escapeMarkdownTableCell($Repeated['List']) . '` | '
                        . number_format($Repeated['Count']) . ' | '
                        . number_format($Repeated['Count'] - 1) . ' |';
                }
            }
        }

        $Report[] = '';
        if ($CompiledDefinitions['ExtraDefinitions'] > 0) {
            $Report[] = '**Assessment note:** Repeated assignments are evidence of duplication or overrides, but not proof that every assigned array is identical. Sugar uses the final effective definition, so files should not be removed blindly. Preserve the current effective values and use the Sugar-provided cleanup utility when available.';
        } else {
            $Report[] = '**Assessment note:** Language definitions may intentionally override earlier values. Preserve the current effective values and use supported cleanup tooling rather than removing files blindly.';
        }
        $Report[] = '';
        $Report[] = '_Health Check threshold: ' . number_format($ThresholdMiB, 2) . ' MiB ('
            . ($ThresholdConfigured ? 'configured in this instance' : 'default value') . ')._';

        echo implode(PHP_EOL, $Report) . PHP_EOL;
        $this->ShowMenu = false;
    }

    private function analyzeLanguageExtensionFolder($Path) {
        $Result = array(
            'Path' => $Path,
            'Exists' => is_dir($Path),
            'Files' => 0,
            'Languages' => 0,
            'LogicalMiB' => 0.0,
            'AllocatedMiB' => 0.0,
        );
        if (!$Result['Exists']) {
            return $Result;
        }

        $LogicalBytes = 0;
        $AllocatedBytes = 0;
        $Languages = array();
        try {
            $Files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($Path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($Files as $File) {
                if (!$File->isFile()) {
                    continue;
                }
                $Result['Files']++;
                $LogicalBytes += $File->getSize();
                $Stats = @stat($File->getPathname());
                if (is_array($Stats) && isset($Stats['blocks'])) {
                    $AllocatedBytes += ((int) $Stats['blocks']) * 512;
                }
                if (preg_match('/^([a-z]{2}_[A-Za-z]{2})/', $File->getBasename(), $Matches)) {
                    $Languages[strtolower($Matches[1])] = true;
                }
            }
        } catch (UnexpectedValueException $Exception) {
            $Result['Error'] = $Exception->getMessage();
        }

        $Result['Languages'] = count($Languages);
        $Result['LogicalMiB'] = $LogicalBytes / 1048576;
        $Result['AllocatedMiB'] = $AllocatedBytes / 1048576;
        return $Result;
    }

    private function analyzeCompiledLanguageDefinitions($Path) {
        $Result = array(
            'Exists' => is_dir($Path),
            'Files' => 0,
            'Lines' => 0,
            'Assignments' => 0,
            'RepeatedPairs' => 0,
            'ExtraDefinitions' => 0,
            'MaximumDefinitions' => 0,
            'TopRepeated' => array(),
        );
        if (!$Result['Exists']) {
            return $Result;
        }

        $DefinitionCounts = array();
        try {
            $Files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($Path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($Files as $File) {
                if (!$File->isFile() || strtolower($File->getExtension()) !== 'php') {
                    continue;
                }
                $Result['Files']++;
                $FilePath = $File->getPathname();
                $Handle = @fopen($FilePath, 'r');
                if (!$Handle) {
                    continue;
                }
                while (($Line = fgets($Handle)) !== false) {
                    $Result['Lines']++;
                    if (!preg_match_all(
                        '/\\$app_list_strings\\s*\\[\\s*([\'\"])([^\'\"]+)\\1\\s*\\]\\s*=/',
                        $Line,
                        $Matches,
                        PREG_SET_ORDER
                    )) {
                        continue;
                    }
                    foreach ($Matches as $Match) {
                        $Result['Assignments']++;
                        $Key = $FilePath . "\0" . $Match[2];
                        if (!isset($DefinitionCounts[$Key])) {
                            $DefinitionCounts[$Key] = array(
                                'File' => $FilePath,
                                'Language' => $this->languageCodeFromFilename($File->getBasename()),
                                'List' => $Match[2],
                                'Count' => 0,
                            );
                        }
                        $DefinitionCounts[$Key]['Count']++;
                    }
                }
                fclose($Handle);
            }
        } catch (UnexpectedValueException $Exception) {
            $Result['Error'] = $Exception->getMessage();
        }

        $Repeated = array();
        foreach ($DefinitionCounts as $Definition) {
            $Result['MaximumDefinitions'] = max($Result['MaximumDefinitions'], $Definition['Count']);
            if ($Definition['Count'] <= 1) {
                continue;
            }
            $Result['RepeatedPairs']++;
            $Result['ExtraDefinitions'] += $Definition['Count'] - 1;
            $Repeated[] = $Definition;
        }
        usort($Repeated, function ($Left, $Right) {
            if ($Left['Count'] === $Right['Count']) {
                $LanguageComparison = strcmp($Left['Language'], $Right['Language']);
                return $LanguageComparison !== 0
                    ? $LanguageComparison
                    : strcmp($Left['List'], $Right['List']);
            }
            return $Right['Count'] <=> $Left['Count'];
        });
        $Result['TopRepeated'] = array_slice($Repeated, 0, 15);
        return $Result;
    }

    private function languageCodeFromFilename($Filename) {
        if (preg_match('/^([a-z]{2}_[A-Za-z]{2})/', $Filename, $Matches)) {
            return $Matches[1];
        }
        return $Filename;
    }

    private function escapeMarkdownTableCell($Value) {
        return str_replace(array('|', "\r", "\n"), array('\\|', ' ', ' '), (string) $Value);
    }

    private function singleLineValue($Value) {
        return trim(str_replace(array("\r", "\n"), ' ', (string) $Value));
    }
    
    private function dbManageSpace() {
        $this->echoc("{$this->InstanceInfo['INSTANCE']}_database_analysis.md\n\n", 'data');
        $this->echoc("\n#### Entire Database\n", 'label');
        $this->echoc("Shows the total size of the database by summing all tables, giving a high-level view of overall storage usage.\n", 'data');
        $SQL = "SELECT 
     round((SUM(data_length + index_length) / 1024 / 1024 / 1024), 4) `Database Size in GB`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}';";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        $DatabaseSize = $Rows[0]['Database Size in GB'];
        
        $this->echoc("\n#### Large Tables\n", 'label');
        $this->echoc("Identifies any individual tables over a defined threshold (0.9 GB) to highlight potential problem tables that consume disproportionate space.\n", 'data');
        $SQL = "SELECT 
     table_schema AS `Database`, 
     TABLE_NAME AS `Table`, 
     round(((data_length + index_length) / 1024 / 1024 / 1024), 2) `Size in GB`,
     round((((data_length + index_length) / 1024 / 1024 / 1024) / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
    AND round(((data_length + index_length) / 1024 / 1024 / 1024), 2) > .9
ORDER BY (data_length + index_length) DESC;
 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}';";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        $this->echoc("\n#### Audit Tables\n", 'label');
        $this->echoc("Audit tables store the history of field changes for records; this section shows how much space all *_audit tables collectively use and whether any are excessively large.", 'dta');
        $SQL = "SELECT 
     round((SUM(data_length + index_length) / 1024 / 1024 / 1024), 4) `Size in GB`,
     round(((SUM(data_length + index_length) / 1024 / 1024 / 1024) / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
     AND TABLE_NAME LIKE '%_audit';";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        
        $SQL = "SELECT 
     table_schema AS `Database`, 
     TABLE_NAME AS `Table`, 
     round(((data_length + index_length) / 1024 / 1024 / 1024), 2) `Size in GB`,
     round((((data_length + index_length) / 1024 / 1024 / 1024) / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
    AND TABLE_NAME LIKE '%_audit'
    AND round(((data_length + index_length) / 1024 / 1024 / 1024), 2) > .9
ORDER BY (data_length + index_length) DESC; 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}';";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        
        $this->echoc("\n#### Activity Stream Tables\n", 'label');
        $this->echoc("These tables track user activity posts, subscriptions, and visibility; this section shows how much space the Activity Stream subsystem consumes.\n", 'data');
        $SQL = "SELECT 
     round((SUM(data_length + index_length) / 1024 / 1024 / 1024), 4) `Size in GB`,
     round(((SUM(data_length + index_length) / 1024 / 1024 / 1024) / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
     AND TABLE_NAME IN ('activities','activities_users','subscriptions');";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        
        $SQL = "SELECT 
     table_schema AS `Database`, 
     TABLE_NAME AS `Table`, 
     round(((data_length + index_length) / 1024 / 1024 / 1024), 2) `Size in GB`,
     round(((data_length + index_length) / 1024 / 1024 / 1024 / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
    AND TABLE_NAME IN ('activities','activities_users','subscriptions')
ORDER BY (data_length + index_length) DESC;
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}';";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        $this->echoc("\n#### Other Usual Suspects\n", 'label');
        $this->echoc("Reports the size of common high-growth operational tables (job queue, workflow logs, audit events, email bodies) that often impact performance or storage, showing whether any of them are contributing significantly to database size.\n", 'data');
        $SQL = "SELECT 
     round((SUM(data_length + index_length) / 1024 / 1024 / 1024), 4) `Size in GB`,
     round((SUM(data_length + index_length) / 1024 / 1024 / 1024 / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
     AND TABLE_NAME IN ('job_queue','audit_events','pmse_bpm_flow','emails_text');";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        
        $SQL = "SELECT 
     table_schema AS `Database`, 
     TABLE_NAME AS `Table`, 
     round(((data_length + index_length) / 1024 / 1024 / 1024), 2) `Size in GB`,
     round(((data_length + index_length) / 1024 / 1024 / 1024 / {$DatabaseSize}) * 100, 2) `Percentage`
FROM information_schema.TABLES 
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}'
    AND TABLE_NAME IN ('job_queue','audit_events','pmse_bpm_flow','emails_text')
ORDER BY (data_length + index_length) DESC;
WHERE table_schema = '{$this->SugarConfig['dbconfig']['db_name']}';";
        $this->echoc("```sql\n{$SQL}\n```" . PHP_EOL, 'command');
        $this->echoc("```\n", 'label');
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        utils::print_t($Rows);
        $this->echoc("```\n", 'label');
        
        
        
        utils::pressEnterToContinue();
    }

    private function createDataUsageSnapshot($Command = '') {
        if (!$this->PDO) {
            $this->echoc("Database connection is not available; cannot create data usage snapshot.\n", 'bad');
            utils::pressEnterToContinue();
            return;
        }

        $Parts = preg_split('/\s+/', trim($Command));
        $UploadPath = isset($Parts[1]) && $Parts[1] !== '' ? $Parts[1] : 'upload';
        $GeneratedAt = gmdate('Y-m-d\TH:i:s\Z');
        $LicenseConfig = $this->getLicenseConfigRows();
        $Subscription = $this->parseLicenseSubscription($LicenseConfig);
        $LicenseKey = isset($LicenseConfig['key']) ? $LicenseConfig['key'] : 'unknown_license';
        $InstanceName = isset($this->InstanceInfo['INSTANCE']) ? $this->InstanceInfo['INSTANCE'] : gethostname();
        $Snapshot = array(
            'snapshot_version' => 1,
            'generated_at_utc' => $GeneratedAt,
            'instance' => array(
                'info' => $this->InstanceInfo,
                'sugar_config' => array(
                    'db_name' => isset($this->SugarConfig['dbconfig']['db_name']) ? $this->SugarConfig['dbconfig']['db_name'] : null,
                    'site_url' => isset($this->SugarConfig['site_url']) ? $this->SugarConfig['site_url'] : null,
                    'unique_key' => isset($this->SugarConfig['unique_key']) ? $this->SugarConfig['unique_key'] : null,
                ),
            ),
            'license' => array(
                'key' => $LicenseKey,
                'users' => isset($LicenseConfig['users']) ? (int) $LicenseConfig['users'] : null,
                'expire_date' => isset($LicenseConfig['expire_date']) ? $LicenseConfig['expire_date'] : null,
                'subscription_checked_at' => isset($LicenseConfig['subscription_checked_at']) ? $LicenseConfig['subscription_checked_at'] : null,
                'last_validation_success' => isset($LicenseConfig['last_validation_success']) ? $LicenseConfig['last_validation_success'] : null,
                'last_validation' => isset($LicenseConfig['last_validation']) ? $LicenseConfig['last_validation'] : null,
                'config' => $this->redactLicenseConfigForSnapshot($LicenseConfig),
                'subscription' => $Subscription,
            ),
            'database' => $this->collectDatabaseUsage(),
            'files' => $this->collectFileUsage($UploadPath),
        );

        $OutputDir = $this->getDataUsageOutputDir();
        $Filename = $this->sanitizeFilename($InstanceName) . '_' . $this->sanitizeFilename($LicenseKey) . '_' . gmdate('Ymd\THis\Z') . '_data_usage.json';
        $OutputPath = $OutputDir . '/' . $Filename;
        $JSON = json_encode($Snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($JSON === false) {
            $this->echoc("Failed to encode snapshot JSON: " . json_last_error_msg() . "\n", 'bad');
            utils::pressEnterToContinue();
            return;
        }

        file_put_contents($OutputPath, $JSON . PHP_EOL);
        $this->echoc("Data usage snapshot created:\n", 'label');
        $this->echoc($OutputPath . "\n", 'good');
        $this->echoc("Upload path scanned: {$UploadPath}\n", 'data');
        utils::pressEnterToContinue();
    }

    private function getLicenseConfigRows(): array {
        $Rows = array();
        $SQL = "SELECT name, value FROM config WHERE category = 'license' ORDER BY name";
        foreach ($this->PDO->query($SQL) as $Row) {
            $Rows[$Row['name']] = $Row['value'];
        }
        return $Rows;
    }

    private function redactLicenseConfigForSnapshot(array $LicenseConfig): array {
        foreach (array('validation_key') as $SensitiveName) {
            if (isset($LicenseConfig[$SensitiveName])) {
                $LicenseConfig[$SensitiveName] = '[redacted]';
            }
        }
        return $LicenseConfig;
    }

    private function parseLicenseSubscription(array $LicenseConfig) {
        if (empty($LicenseConfig['subscription'])) {
            return null;
        }
        $Decoded = json_decode($LicenseConfig['subscription'], true);
        if (!is_array($Decoded)) {
            return array(
                '_parse_error' => json_last_error_msg(),
                '_raw' => $LicenseConfig['subscription'],
            );
        }
        return $Decoded;
    }

    private function collectDatabaseUsage(): array {
        $DbName = $this->SugarConfig['dbconfig']['db_name'];
        $DbNameQuoted = $this->PDO->quote($DbName);
        $SummarySQL = "
SELECT
    COUNT(*) AS table_count,
    ROUND(SUM(data_length) / 1024 / 1024 / 1024, 4) AS data_gb,
    ROUND(SUM(index_length) / 1024 / 1024 / 1024, 4) AS index_gb,
    ROUND(SUM(data_length + index_length) / 1024 / 1024 / 1024, 4) AS total_gb
FROM information_schema.TABLES
WHERE table_schema = {$DbNameQuoted}";
        $Summary = $this->PDO->query($SummarySQL)->fetch(PDO::FETCH_ASSOC);

        $TableSQL = "
SELECT
    table_name,
    table_rows,
    ROUND(data_length / 1024 / 1024 / 1024, 4) AS data_gb,
    ROUND(index_length / 1024 / 1024 / 1024, 4) AS index_gb,
    ROUND((data_length + index_length) / 1024 / 1024 / 1024, 4) AS total_gb
FROM information_schema.TABLES
WHERE table_schema = {$DbNameQuoted}
ORDER BY data_length + index_length DESC, table_name";
        $Tables = $this->PDO->query($TableSQL)->fetchAll(PDO::FETCH_ASSOC);

        return array(
            'name' => $DbName,
            'summary' => $Summary,
            'tables' => $Tables,
        );
    }

    private function collectFileUsage(string $UploadPath): array {
        $Result = array(
            'path' => $UploadPath,
            'exists' => file_exists($UploadPath),
            'is_dir' => is_dir($UploadPath),
            'summary' => array(
                'file_count' => 0,
                'bytes' => 0,
                'gb' => 0,
            ),
            'modified_years' => array(),
            'error' => null,
        );

        if (!is_dir($UploadPath)) {
            $Result['error'] = "Upload path is not a directory.";
            return $Result;
        }

        $Command = "find " . escapeshellarg($UploadPath) . " -type f -printf '%TY %s\n' 2>/dev/null | awk '{count[$1]++; bytes[$1]+=$2} END {for (y in count) print y \"\\t\" count[y] \"\\t\" bytes[y]}'";
        exec($Command, $Output, $ReturnCode);
        if ($ReturnCode !== 0) {
            $Result['error'] = "File scan command returned {$ReturnCode}.";
            return $Result;
        }

        foreach ($Output as $Line) {
            $Parts = explode("\t", trim($Line));
            if (count($Parts) !== 3) {
                continue;
            }
            $Year = $Parts[0];
            $Count = (int) $Parts[1];
            $Bytes = (int) $Parts[2];
            $Result['modified_years'][$Year] = array(
                'file_count' => $Count,
                'bytes' => $Bytes,
                'gb' => round($Bytes / 1024 / 1024 / 1024, 4),
            );
            $Result['summary']['file_count'] += $Count;
            $Result['summary']['bytes'] += $Bytes;
        }

        ksort($Result['modified_years']);
        $Result['summary']['gb'] = round($Result['summary']['bytes'] / 1024 / 1024 / 1024, 4);
        return $Result;
    }

    private function getDataUsageOutputDir(): string {
        if (is_dir('cloud_support') && is_writable('cloud_support')) {
            return 'cloud_support';
        }
        return getcwd();
    }

    private function sanitizeFilename(string $Value): string {
        $Value = trim($Value);
        $Value = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $Value);
        $Value = trim($Value, '_');
        return $Value === '' ? 'unknown' : $Value;
    }
    
    private function searchManifests($Command) {
        $SearchString = $this->getCommandArgument($Command);
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        utils::echoc("Searching manifests in upgrade_history for '{$SearchString}' . . . \n", 'label');
        $Matches = $this->findManifestMatches($SearchString);
        if (!$Matches) {
            $this->echoc("No matching installed package manifests found.\n", 'green');
        } else {
            $this->displayManifestMatches($Matches);
        }
        $this->ShowMenu = false;
    }

    private function findManifestMatches(string $SearchString): array {
        $Matches = array();
        if ($SearchString === '') {
            return $Matches;
        }

        $SQL = "SELECT id, name, version, filename, date_modified, manifest
                  FROM upgrade_history
                 WHERE deleted = 0
                 ORDER BY date_modified DESC";
        foreach ($this->PDO->query($SQL, PDO::FETCH_ASSOC) as $Row) {
            $EncodedManifest = (string) ($Row['manifest'] ?? '');
            $DecodedManifest = base64_decode($EncodedManifest, true);
            if ($DecodedManifest === false) {
                $DecodedManifest = $EncodedManifest;
            }

            $ManifestData = @unserialize($DecodedManifest, array('allowed_classes' => false));
            $SearchableManifest = is_array($ManifestData)
                ? (string) json_encode($ManifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $DecodedManifest;
            $Searchable = implode("\n", array(
                (string) ($Row['name'] ?? ''),
                (string) ($Row['filename'] ?? ''),
                $SearchableManifest,
            ));
            if (stripos($Searchable, $SearchString) === false) {
                continue;
            }

            $Row['DecodedManifest'] = $SearchableManifest;
            $Matches[] = $Row;
        }

        return $Matches;
    }

    private function displayManifestMatches(array $Matches): void {
        foreach ($Matches as $Match) {
            $Name = trim((string) ($Match['name'] ?? '')) ?: 'Unnamed package';
            $Version = trim((string) ($Match['version'] ?? ''));
            $this->echoc("{$Name}" . ($Version !== '' ? " v{$Version}" : '') . "\n", 'data');
            if (!empty($Match['filename'])) {
                $this->echoc("FILE: {$Match['filename']}\n", 'command');
            }
            $this->echoc("MANIFEST:\n", 'label');
            $this->echoc(rtrim((string) ($Match['DecodedManifest'] ?? '')) . "\n", 'data');
        }
    }
    private function checkForEnumFieldsMissingList($Command) {
        utils::echoc("Checking fields_meta_data for enum fields missing lists . . . ", 'label');
        $SQL = "SELECT count(*) FROM fields_meta_data WHERE TYPE LIKE '%enum%' AND (ifnull(ext1, '') = '');";
//        $SQL = "SELECT * FROM users;";
        $Result = $this->PDO->query($SQL);
        $Count = $Result->fetchColumn();
        if($Count){
            utils::print_rc(" 🛑\n");
            utils::print_rc($Count);
        }else{
            utils::print_rc(" ✅\n");
        }
    }
    
    private function watchSQL($Command) {
        $ReadOnlyPDO = $this->getReadOnlyPDO();
        if (!$ReadOnlyPDO) {
            $this->echoc("Watch SQL requires the reports or listviews database connection. The primary connection will not be used.\n", 'red');
            $this->ShowMenu = false;
            return;
        }

        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SQL = trim(implode(' ', $CommandArray));
        if (!$SQL) {
            $SQL = $this->ask("Query to watch: ");
        }

        $SQL2 = $this->ask("If you would also like to watch a second query then please enter it here");
        foreach (array_filter(array($SQL, $SQL2), static fn($Query): bool => trim((string) $Query) !== '') as $Query) {
            if (!$this->isReadOnlyWatchQuery($Query)) {
                $this->echoc("Watch SQL accepts one SELECT, SHOW, DESCRIBE, DESC, or EXPLAIN statement at a time.\n", 'red');
                $this->ShowMenu = false;
                return;
            }
        }

        $Interval = $this->ask("Enter the number of seconds to wait before rerunning the command. The default is 120 ");
        $Interval = $Interval ? (int) $Interval : 120;
        if ($Interval < 1 || $Interval > 86400) {
            $this->echoc("The interval must be between 1 and 86400 seconds.\n", 'red');
            $this->ShowMenu = false;
            return;
        }

        $this->ensureCloudSupportFolder();
        $this->echoc("Using the {$this->ReadOnlyConnectionName} database connection.\n", 'label');

        while (true) {
            $this->echoc($SQL . PHP_EOL, 'magenta');
            $Result = $ReadOnlyPDO->query($SQL);
            $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
            file_put_contents('./cloud_support/watch_sql.log', date("Y-m-d H:i:s e") . " | " . $this->InstanceInfo['INSTANCE'] . " | " . gethostname(), FILE_APPEND);
            file_put_contents('./cloud_support/watch_sql.log', json_encode($Rows, JSON_PRETTY_PRINT), FILE_APPEND);
            file_put_contents('./cloud_support/watch_sql.log', PHP_EOL, FILE_APPEND);
            Utils::print_t($Rows);
            if ($SQL2) {
                $this->echoc($SQL2 . PHP_EOL, 'magenta');
                $Result2 = $ReadOnlyPDO->query($SQL2);
                $Rows2 = $Result2->fetchAll(PDO::FETCH_ASSOC);
                file_put_contents('./cloud_support/watch_sql.log', json_encode($Rows2, JSON_PRETTY_PRINT), FILE_APPEND);
                Utils::print_t($Rows2);
            }

            echo date("Y-m-d H:i:s e"), " | ", $this->InstanceInfo['INSTANCE'], " | ", gethostname(), PHP_EOL;
            $this->echoc("Use Ctrl-C to exit\n", 'label', PHP_EOL);
            $this->echoc("Waiting {$Interval} seconds\n", 'label');
            sleep($Interval);
        }
    }

    private function isReadOnlyWatchQuery(string $SQL): bool {
        $SQL = trim($SQL);
        if ($SQL === '' || strpos($SQL, "\0") !== false) {
            return false;
        }

        $WithoutTrailingTerminator = preg_replace('/;\s*$/', '', $SQL);
        if (strpos($WithoutTrailingTerminator, ';') !== false) {
            return false;
        }
        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $SQL)) {
            return false;
        }
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $SQL)) {
            return false;
        }

        return !preg_match('/\bLOAD_FILE\s*\(/i', $SQL);
    }

    private function searchPackagesForString($Command) {
        $SearchString = $this->getCommandArgument($Command);
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching zip files in upgrades/modules for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'data');
        $Matches = $this->findZipEntryMatches($SearchString);
        if (!$Matches) {
            $this->echoc("No matching files found inside upgrades/module ZIP archives.\n", 'green');
        } else {
            $this->displayZipEntryMatches($Matches);
        }
        $this->ShowMenu = false;
    }

    private function findZipEntryMatches(string $SearchString): array {
        $Matches = array();
        if ($SearchString === '' || !is_dir('upgrades/module')) {
            return $Matches;
        }

        $ZipFiles = glob('upgrades/module/*.zip') ?: array();
        sort($ZipFiles, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($ZipFiles as $ZipFile) {
            $Entries = array();
            $ReturnCode = 0;
            exec('unzip -Z1 ' . escapeshellarg($ZipFile) . ' 2>/dev/null', $Entries, $ReturnCode);
            if ($ReturnCode !== 0) {
                continue;
            }
            foreach ($Entries as $Entry) {
                if (stripos($Entry, $SearchString) !== false) {
                    $Matches[] = array('Archive' => $ZipFile, 'Entry' => $Entry);
                }
            }
        }

        return $Matches;
    }

    private function displayZipEntryMatches(array $Matches): void {
        foreach ($Matches as $Match) {
            $this->echoc("{$Match['Archive']}: {$Match['Entry']}\n", 'command');
        }
    }
    
    private function checkForIssue95830() {
        $this->echoc("Checking if they will be affected by Issue ", 'label');
        $this->echoc("95830 Changing to Opportunities only and then clicking save in Navigation Bar and Subpanels breaks the recordview of every module in which RLI appeared\n", 'data');
        $SQL1 = "select * from config where name = 'hide_subpanels';";
        $SQL2 = "UPDATE config SET `value` = REPLACE(`value`, '0', '\"revenuelineitems\"') WHERE NAME = 'hide_subpanels';";
        $this->echoc("{$SQL1}\n\n", 'command');
        $Response1 = $this->PDO->query($SQL1);
        Utils::print_rc($Response1->fetch(PDO::FETCH_ASSOC));
        
        $this->echoc("\n{$SQL2}\n", 'command');
        if(Utils::askYes("Would you like to apply the fix which will run the SQL above?")){
            $this->PDO->query($SQL2);
            $Response2 = $this->PDO->query($SQL1);
            Utils::print_rc($Response2->fetch(PDO::FETCH_ASSOC));
        }
        
        $this->ask("Press Enter to continue");
    }
    
    private function checkForIssue95922() {
        $this->echoc("Checking if they will be affected by Issue ", 'label');
        $this->echoc("95922 [SugarBPM] Using Relationship Change in Start Event results in BPM being triggered despite no relationship change\n", 'data');
        $SQL = "SELECT COUNT(*) Count FROM pmse_bpm_event_definition WHERE evn_params = 'relationshipchange' and not deleted;";
        $this->echoc("{$SQL}\n\n", 'command');
        $Response = $this->PDO->query($SQL);
        $Count = $Response->fetch()['Count'];
        if($Count > 0){
            $this->echoc("It appears that instance {$this->InstanceInfo['INSTANCE']} will be affected by Issue 95922 and has {$Count} records matching the criteria\n\n", 'bad');
            $SQL2 = "select 
                        ed.evn_status,
                        p.id,
                        p.name,
                        p.prj_status,
                        p.date_entered,
                        p.date_modified 
                        from 
                                pmse_bpm_event_definition ed 
                                join pmse_project p 
                                        on p.id = ed.prj_id 
                        where 
                                not ed.deleted
                                and not p.deleted
                                and evn_params = 'relationshipchange';";
            $this->echoc($SQL2.PHP_EOL, 'command');
//            $this->echoc(str_pad('id', 37), 'label');
//            $this->echoc(str_pad('name', 100), 'label');
//            $this->echoc(str_pad('evn_status', 12), 'label');
//            $this->echoc(str_pad('date_entered', 20), 'label');
//            $this->echoc(str_pad('date_modified', 20), 'label');
//            echo PHP_EOL;

            $this->echoc("Hello all,

We have just learned of an issue with Sugar 14.2.0 that might affect some of your Process Definitions. The Issue is described here: https://portal.sugarondemand.com/#supp_Bugs/95922

We have identified some events on the {$this->InstanceInfo['INSTANCE']} instance that may be affected and listed them below.\n\n", 'data');
            foreach($this->PDO->query($SQL2,PDO::FETCH_ASSOC) as $Row){
                $Row['link_to_process_definition'] = "https://{$this->InstanceInfo['INSTANCE']}/#pmse_Project/{$Row['id']}";
                Utils::print_rc($Row);
//                $this->echoc(str_pad($Row['id'], 37), 'data');
//                $this->echoc(str_pad($Row['name'], 100), 'data');
//                $this->echoc(str_pad($Row['evn_status'], 12), 'data');
//                $this->echoc(str_pad($Row['date_entered'], 20), 'data');
//                $this->echoc(str_pad($Row['date_modified'], 20), 'data');
//                echo PHP_EOL;
            }
        }else{
            $this->echoc("It appears that instance {$this->InstanceInfo['INSTANCE']} will NOT be affected by Issue 95922 as it has 0 records matching the criteria", 'good');
        }
        $this->ask("Press enter to continue");
    }

    private function deactivateNonAdminUsers() {
        if ($this->askYes("Would you like to mark all non-admin users as inactive in the database? \n"
                . "This is normally only done when importing an instance which has more active users than licneses and Sugar Identity won't install. \n"
                . "Enter 'yes' to proceed.")) {
            $BackupTableName = 'users_' . date('YmdHm') . '_bak';
            $SQL1 = "CREATE TABLE {$BackupTableName} SELECT * FROM users;";
            $this->echoc($SQL1 . PHP_EOL, 'command');
            $this->PDO->exec($SQL1);
            $SQL2 = "UPDATE users SET STATUS = 'Inactive' WHERE NOT is_admin;";
            $this->echoc($SQL2 . PHP_EOL, 'command');
            $this->PDO->exec($SQL2);
            $this->listUsers();
            $this->echoc("\n***** Be sure to notify the client/partner that all non-admin users have been set to Inactive because there were more users than licenses. *****\n\n", 'red');
        }else{
            $this->echoc("No changes made\n", 'red');
        }
        $this->ask("Press enter to continue\n");
    }

    private function runQuickRepairandRebuild() {
//        $Command1 = "wget -q https://wupgrade.wsysnet.com/patstools/repairOne.php -O repairOne; shadowy `pwd`/repairOne; rm repairOne";
        $Command1 = <<<'EOT'
                <?php error_reporting(E_ALL ^ E_WARNING);
                require_once "include/utils/autoloader.php";
                require_once "modules/Administration/QuickRepairAndRebuild.php";
                if (!defined("sugarEntry")) {
                    define("sugarEntry", true);
                }
                require_once "include/entryPoint.php";
                try {
                    $current_user = new User();
                    $current_user->getSystemUser();
                    $repair = new RepairAndClear();
                    $repair->repairAndClearAll(
                        ["clearAll"],
                        [translate("LBL_ALL_MODULES")],
                        true,
                        false
                    );
                    echo shell_exec("rm -r ~/cache/*") .
                        "Quick Repair and Rebuild Completed." .
                        PHP_EOL;
                } catch (Exception $e) {
                    echo "Error: " .
                        $e->getMessage() .
                        PHP_EOL .
                        "File: " .
                        $e->getFile() .
                        " on line " .
                        $e->getLine() .
                        PHP_EOL;
                }
                EOT;

        $this->echoc("- ▶️ Running a Quick Repair and Rebuild on {$this->InstanceInfo['INSTANCE']}\n", 'label');
        file_put_contents('quick_repair.php', $Command1);
        
//        $this->echoc($Command1 . PHP_EOL, 'command');
//        system($Command1);
        system('shadowy $(pwd)/quick_repair.php;');
        unlink('quick_repair.php');
        $this->echoc("- ✅ Quick Repair and Rebuild Completed on {$this->InstanceInfo['INSTANCE']}\n", 'label');
        $this->ShowMenu = false;
    }

    private function runDataIntegrityScripts() {
        $SQL1 = "select count(*) count from workflow where ifnull(parent_id,'') != '' and parent_id not in (select id from workflow);";
        foreach ($this->PDO->query($SQL1) as $Row) {
            $this->echoc("Orphaned workflow records where parent_id does not exist: ", 'label');
            $this->echoc("{$Row['count']}\n", 'red');
            $this->echoc("Try running:\nUPDATE workflow 
SET parent_id = NULL 
WHERE parent_id IS NOT NULL 
  AND parent_id != '' 
  AND parent_id NOT IN (
    SELECT id FROM (
      SELECT id FROM workflow
    ) AS w
  );", 'code');
        }

        $this->ShowMenu = false;
    }

    private function checkFTSQueue() {
        $SQL = "select bean_module, format(count(*),0) `count` from fts_queue group by bean_module order by count(*) desc;";
        $this->echoc("{$SQL}\n\n", 'command');
        $this->echoc("| ", 'label');
        $this->echoc(str_pad('Count', 12), 'label');
        $this->echoc(" | ", 'label');
        $this->echoc(str_pad('Module', 30), 'label');
        $this->echoc(" | ", 'label');
        $this->echoc("\n", 'data');
        foreach ($this->PDO->query($SQL) as $Row) {
            $this->echoc("| ", 'label');
            $this->echoc(str_pad($Row['count'], 12), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['bean_module'], 30), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc("\n", 'data');
        }
        
        $SQL2 = "select format(count(*),0) `count` from fts_queue;";
        $this->echoc("\n{$SQL2}\n", 'command');
        $Response = $this->PDO->query($SQL2);
        $Count = $Response->fetch()['count'];
        $this->echoc("Total fts_queue records: ", 'label');
        $this->echoc($Count.PHP_EOL, 'data');
        $this->echoc("Date and Time: ", 'label');
        $this->echoc(date('Y-m-d H:i'), 'data');

        echo "\n";
        $this->ShowMenu = false;
    }

    private function whatsThis() {
        $ExpectedFilesAndFolders = array(
            'sugarutils',
            '.',
            '..',
            'cache',
            'cloud_support',
            'custom',
            'modules',
            'config.php',
            'config_override.php',
            'mothership_data_for_analytics.json',
            'upgrades',
            'upload',
            'portal2',
        );
        $FilesAndFolders = scandir('.');
        foreach ($FilesAndFolders as $File) {
            if (in_array($File, $ExpectedFilesAndFolders) || strtoupper(substr($File, -4)) === '.LOG') {
                continue;
            }
            $this->echoc("What's this -> ", 'red');
            if (is_dir($File)) {
                $this->echoc($File . PHP_EOL, 'blue');
                try {
                    $Iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($File, FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($Iterator as $FileInfo) {
                        if ($FileInfo->isFile()) {
                            $this->echoc("\t{$FileInfo->getPathname()}\n", 'brightred');
                        }
                    }
                } catch (UnexpectedValueException $Exception) {
                    $this->echoc("\tUnable to inspect this directory.\n", 'red');
                }
            } else {
                $this->echoc($File . PHP_EOL, 'brightblue');
            }
        }
        $this->ShowMenu = false;
    }

    private function packageScan() {
        $Cmd = 'package-scan -i ' . escapeshellarg((string) $this->InstanceInfo['INSTANCE']);
        $this->echoc($Cmd . PHP_EOL, 'command');
        system($Cmd);
        $this->ShowMenu = false;
    }

    private function checkUploads() {
        $Commands = array(
            'ls upload/ | head',
            'ls upload/ | tail',
            'find upload/ -type f | wc -l',
            'du upload/ -sh',
        );

        foreach ($Commands as $Command) {
            $this->echoc($Command . PHP_EOL, 'command');
            system($Command);
            echo PHP_EOL;
        }

        $this->ShowMenu = false;
    }
    
    private function archiveDocumentTemplates() {
        $this->echoc("Archiving Document Templates . . .\n", 'label');
        $SQL = "SELECT id FROM document_templates WHERE deleted = 0;";
        $this->echoc($SQL . PHP_EOL, 'command');
        
        $Rows = $this->PDO->query($SQL)->fetchAll(PDO::FETCH_ASSOC);
        if (!$Rows) {
            $this->echoc("No active document templates found.\n", 'yellow');
            $this->ShowMenu = false;
            return;
        }
        
        $Files = array();
        $Missing = array();
        foreach ($Rows as $Row) {
            $ID = trim((string) ($Row['id'] ?? ''));
            if ($ID === '') {
                continue;
            }
            
            $UploadFolder = substr($ID, 5, 3);
            $Path = "upload/{$UploadFolder}/{$ID}";
            if (is_file($Path)) {
                $Files[] = $Path;
            } else {
                $Missing[] = $Path;
            }
        }
        
        if (!$Files) {
            $this->echoc("No matching upload files were found for active document templates.\n", 'red');
            if ($Missing) {
                $this->echoc("Missing files:\n", 'label');
                foreach ($Missing as $Path) {
                    $this->echoc("  {$Path}\n", 'data');
                }
            }
            $this->ShowMenu = false;
            return;
        }
        
        $Archive = "document_templates_" . date('Y-m-d_H-i-s') . ".tar.gz";
        $ListFile = tempnam(sys_get_temp_dir(), 'sugarutils_document_templates_');
        if ($ListFile === false) {
            $this->echoc("Unable to create temporary file list.\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        
        file_put_contents($ListFile, implode(PHP_EOL, $Files) . PHP_EOL);
        $Command = "tar -czf " . escapeshellarg($Archive) . " -T " . escapeshellarg($ListFile);
        $this->echoc($Command . PHP_EOL, 'command');
        system($Command, $ExitCode);
        unlink($ListFile);
        
        if ($ExitCode !== 0) {
            $this->echoc("Archive command failed with exit code {$ExitCode}.\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        
        $this->echoc("Archived " . count($Files) . " document template files to {$Archive}\n", 'green');
        if ($Missing) {
            $this->echoc(count($Missing) . " document template upload files were missing.\n", 'yellow');
        }
        $this->echoc("This archive unpacks back into upload/???/<document_template_id>.\n", 'data');
        $this->ShowMenu = false;
    }
    
    private function quickcheckCollation() {
        $this->echoc("Checking collation  . . . \n", 'label');
        $SQL = "SELECT COLLATION_NAME, count(*) COUNT FROM information_schema.COLUMNS
                    WHERE table_schema = database()
                    AND data_type IN ('char','varchar', 'text','tinytext','mediumtext','longtext')
                    GROUP BY COLLATION_NAME;";
        $Result = $this->PDO->query($SQL);
        $Collations = $Result->fetchAll(PDO::FETCH_ASSOC);
        if(count($Collations) === 0){
            $this->echoc("No collations found. Something is wrong. 🛑\n", 'red');
        }elseif(count($Collations) === 1){
            $this->echoc("Single collation found ✅\n", 'green');
            if($Collations[0]['COLLATION_NAME'] === 'utf8mb4_0900_ai_ci'){
                $this->echoc("Standard collation found ", 'label');
                $this->echoc($Collations[0]['COLLATION_NAME'] . "✅ \n", 'green');
            }else{
                $this->echoc("Nonstandard collation found ", 'label');
                $this->echoc($Collations[0]['COLLATION_NAME'] . " 🛑 \n", 'red');
            }
        }else{
            $this->echoc("Multiple collations found 🛑 \n", 'red');
            foreach ($Collations as $Collation){
                $this->echoc("{$Collation['COLLATION_NAME']}\n", 'red');
            }
        }
    }
    

    private function checkCollation() {
        $SQL = "SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS
                    WHERE table_schema = database()
                    AND collation_name != 'utf8mb4_0900_ai_ci'
                    AND data_type IN ('char','varchar', 'text','tinytext','mediumtext','longtext');";
        $this->echoc("{$SQL}\n\n", 'command');
        foreach ($this->PDO->query($SQL) as $Row) {
            $this->echoc("| ", 'label');
            $this->echoc(str_pad($Row['TABLE_NAME'], 50), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['COLUMN_NAME'], 50), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['CHARACTER_SET_NAME'], 30), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['COLLATION_NAME'], 30), 'data');
            $this->echoc(" |", 'label');
            $this->echoc("\n", 'data');
        }
        echo "\n";
        $SQL = "SELECT COLLATION_NAME, count(*) COUNT FROM information_schema.COLUMNS
                    WHERE table_schema = database()
                    AND data_type IN ('char','varchar', 'text','tinytext','mediumtext','longtext')
                    GROUP BY COLLATION_NAME;";
        $this->echoc("{$SQL}\n\n", 'command');
        foreach ($this->PDO->query($SQL) as $Row) {
            $this->echoc("| ", 'label');
            $this->echoc(str_pad($Row['COLLATION_NAME'], 30), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['COUNT'], 12), 'data');
            $this->echoc(" |", 'label');
            $this->echoc("\n", 'data');
        }
        echo "\n";
        $Command = 'grep collation config*.php';
        $this->echoc($Command.PHP_EOL, 'command');
        system($Command);
        echo "\n";
        Utils::echoc("You might need to add this to config_override.php if it is not already there or in the config.php file.\n", 'label');
        Utils::echoc("sugar_config['dbconfigoption']['collation'] = 'utf8mb4_0900_ai_ci';\n", 'data');
        $this->ShowMenu = false;
    }
    
    private function migrateUploads() {
        $Continue = $this->ask('Are you sure you want to move all the documents into subfolders? y|N');
        if (strtoupper(substr($Continue, 0, 1)) !== 'Y') {
            return;
        }
        $CMD = 'for i in $(find . -maxdepth 1 -type f -name "????????-????-????-????-????????????" ) do     j=$(echo $i | cut -d"-" -f1 | cut -b 8-)     mkdir -p $j     mv $i $j done;';
        $this->echoc($CMD, 'command');
        system($CMD);
        $this->ask("Press Enter to continue");
    }

    private function fixCollation() {
        
        $Revert = $this->askYN("Would you like to revert the collation back to utf8mb4_general_ci?");
        $this->echoc("*** WARNING: Continuing will start the collation update in the background. This will make the Sugar system unusuable and might run for several hours. Make sure this is what you want to do before pressing continuing ***\n\n", 'red');
        
        if(!$this->askYes("Type 'yes' to start the collation update.")){
            return;
        }
        if(file_exists('sugarconvertutf8mb4')){
            unlink('sugarconvertutf8mb4');
        }
        $Cmd1 = "wget https://raw.githubusercontent.com/patrickpawlowski/patstools_pub/refs/heads/main/sugarconvertutf8mb4";
        exec($Cmd1);
        if($Revert){
        system('./; nohup php ./sugarconvertutf8mb4 revert silent >> sugarconvertutf8mb4.log &');
        }else{
        system('./; nohup php ./sugarconvertutf8mb4 silent >> sugarconvertutf8mb4.log &');
        }
        $this->echoc("\n---<=== Fix Collation running in background ===>---\n", 'brightred');
        $this->echoc("To monitor run:\n", 'label');
        $this->echoc("tail -f sugarconvertutf8mb4.log\n\n", 'command');
        $this->echoc("But first, you should edit the config.php and change the collation to ", 'red');
        $this->echoc("utf8mb4_0900_ai_ci\n", 'data');
//        $this->displayInfo();
        die();
//        $this->ShowMenu = false;
    }
    
    private function checkImports() {
        $Cmd = "ls -haltr upload/IMPORT*";
        $this->echoc($Cmd.PHP_EOL, 'command');
        passthru($Cmd);
        $this->ask("Press Enter to continue");
    }

    private function parseManifest($Command) {
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $ManifestFile = trim(implode(' ', $CommandArray));
        if (!$ManifestFile) {
            $ManifestFile = $this->ask("Please enter the name of the manifest file");
        }
        if (file_exists($ManifestFile)) {
            require($ManifestFile);
            $this->echoc('- Package Name and Version: ', 'label');
            $this->echoc("{$manifest['name']} v{$manifest['version']}\n", 'data');

            $this->echoc('- Author: ', 'label');
            $this->echoc("{$manifest['author']}\n", 'data');

            $this->echoc('- Published Date: ', 'label');
            $this->echoc("{$manifest['published_date']}\n", 'data');

            $this->echoc('- Acceptable Sugar Versions: ', 'label');
//            $AcceptableSugarVersions = '';
//            if(isset($manifest['acceptable_sugar_versions']['exact_matches'])){
//                $AcceptableSugarVersions .= implode(',', $manifest['acceptable_sugar_versions']['exact_matches']);
//            }
//            if(isset($AcceptableSugarVersions['regex_matches'])){
//                $AcceptableSugarVersions .= implode(',', $manifest['acceptable_sugar_versions']['regex_matches']);
//            }
            $AcceptableSugarVersions = json_encode($manifest['acceptable_sugar_versions']);
            $this->echoc("`{$AcceptableSugarVersions}`\n", 'data');

            $this->echoc('- Description: ', 'label');
            $this->echoc("{$manifest['description']}\n", 'data');
        } else {
            $this->echoc("'{$ManifestFile}' not found!\n", 'red');
        }
        $this->ShowMenu = false;
    }

    private function hideUpgradeHistory() {
        $this->echoc("Checking for existing upgrade_history_bak table . . ", 'label');
        $SQL = "SHOW TABLES LIKE 'upgrade_history_bak';";
        foreach ($this->PDO->query($SQL) as $row) {
            $this->echoc("upgrade_history_bak table already exists. Aborting!\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        $this->echoc("Current upgrade_history\n", 'label');
        $this->showUpgradeHistory(true);
        $SQL1 = 'create table upgrade_history_bak select * from upgrade_history;';
        $this->echoc("{$SQL1}\n\n", 'command');
        $this->PDO->exec($SQL1);
        $SQL2 = 'truncate table upgrade_history;';
        $this->echoc("{$SQL2}\n\n", 'command');
        $this->PDO->exec($SQL2);
        $this->echoc("Truncated upgrade_history\n", 'label');
        $this->showUpgradeHistory(true);
        $this->echoc("Current upgrade_history_bak\n", 'label');
        $this->showUpgradeHistoryBak();
        $this->ShowMenu = false;
    }

    private function unhideUpgradeHistory() {
        $SQL = 'insert into upgrade_history select * from upgrade_history_bak where id not in (select id from upgrade_history);';
        $this->echoc("{$SQL}\n\n", 'command');
        $this->PDO->exec($SQL);
        $DateTime = date("YmdHi");
        $SQL2 = "RENAME TABLE upgrade_history_bak TO upgrade_history_bak_{$DateTime}";
        $this->echoc("{$SQL2}\n\n", 'command');
        $this->PDO->exec($SQL2);
        $this->echoc("Restored upgrade_history\n", 'label');
        $this->showUpgradeHistory(true);
        $this->ShowMenu = false;
    }

    private function showUpgradeHistory($ShowDeleted = false) {
        if ($ShowDeleted) {
            $SQL = 'SELECT type, name, version, status, enabled, date_entered, date_modified FROM upgrade_history ORDER BY date_modified;';
        } else {
            $SQL = 'SELECT type, name, version, status, enabled, date_entered, date_modified FROM upgrade_history WHERE NOT deleted ORDER BY date_modified;';
        }
        $this->echoc("{$SQL}\n\n", 'command');
        $Results = array();
        
        foreach ($this->PDO->query($SQL, PDO::FETCH_ASSOC) as $Row) {
            $Results[] = $Row;
        }
        Utils::print_t($Results);
//        foreach ($this->PDO->query($SQL) as $Row) {
//            $this->echoc("| ", 'label');
//            $this->echoc(str_pad($Row['type'], 10), 'data');
//            $this->echoc(" | ", 'label');
//            $this->echoc(str_pad($Row['name'], 90), 'data');
//            $this->echoc(" | ", 'label');
//            $this->echoc(str_pad($Row['version'], 10), 'data');
//            $this->echoc(" | ", 'label');
//            $this->echoc(str_pad($Row['status'], 10), 'data');
//            $this->echoc(" | ", 'label');
//            $this->echoc(str_pad($Row['enabled'], 3), 'data');
//            $this->echoc(" | ", 'label');
//            $this->echoc(str_pad($Row['date_entered'], 21), 'data');
//            $this->echoc(" | ", 'label');
//            $this->echoc(str_pad($Row['date_modified'], 21), 'data');
//            $this->echoc(" |", 'label');
//            $this->echoc("\n", 'data');
//        }
        echo "\n";
        $this->ShowMenu = false;
    }

    public function showUpgradeHistoryBak() {
        $SQL = 'SELECT type, name, status, enabled, date_entered, date_modified FROM upgrade_history_bak WHERE NOT deleted ORDER BY date_modified;';
        $this->echoc("{$SQL}\n\n", 'command');
        foreach ($this->PDO->query($SQL) as $Row) {
            $this->echoc("| ", 'label');
            $this->echoc(str_pad($Row['type'], 10), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['name'], 100), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['status'], 10), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['enabled'], 3), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['date_entered'], 21), 'data');
            $this->echoc(" | ", 'label');
            $this->echoc(str_pad($Row['date_modified'], 21), 'data');
            $this->echoc(" |", 'label');
            $this->echoc("\n", 'data');
        }
        echo "\n";
        $this->ShowMenu = false;
    }

    private function displaySQLResults($SQL) {
        $OutArray = array();
        foreach ($this->PDO->query($SQL) as $Row) {
            $OutArray[] = $Row;
        }
        $this->echoc(str_pad($Row['type'], 35), 'data');
        $this->echoc(str_pad($Row['name'], 35), 'data');
        $this->echoc(str_pad($Row['status'], 10), 'data');
        $this->echoc(str_pad($Row['enabled'], 3), 'data');
        $this->echoc(str_pad($Row['date_entered'], 21), 'data');
        $this->echoc(str_pad($Row['date_modified'], 21), 'data');
        $this->echoc("\n", 'data');
    }

    private function runBruteForceTroubleshooting() {
        if (!$this->isCaseCloneInstance()) {
            $this->echoc("\nBFT is intentionally limited to case clones.\n", 'red');
            $this->echoc("Detected instance/group: ", 'label');
            $this->echoc($this->getInstanceCloneGuardText() . PHP_EOL, 'data');
            $this->echoc("If this really is a case clone, verify instance-info output contains a case clone name like case582861a or ai_case582861a.\n", 'yellow');
            $this->ShowMenu = false;
            return;
        }

        $Session = $this->loadBftSession();
        if (!$Session) {
            $Session = $this->startBftSession();
        }

        while (true) {
            $this->displayBftStatus($Session);
            $IsInitialGate = $this->isBftInitialRelevanceGate($Session) && !is_array($Session['activeTest'] ?? null);
            if (is_array($Session['activeTest'] ?? null)) {
                $Prompt = "Please test for the issue.\nIs the issue fixed?\n[y] Yes, the issue DISAPPEARED\n[n] No, the issue is STILL BROKEN\n[u] Untestable: the instance is broken or worse than before\nOther options: [h]istory, [q]uit and resume later, [r]estore everything and keep BFT open, [a] restore all and exit";
            } elseif ($IsInitialGate) {
                $this->displayBftInitialRelevanceGate($Session);
                $Prompt = "Continue to archive/remove all custom js/css/tpl/hbs/less files for the first relevance test? [c]ontinue/[q]uit";
            } else {
                $this->displayBftChoices($Session);
                $Prompt = "Type a choice number to disable it, or [l]ist, [d]isable by prompt, [h]istory, [a] restore all, [q]uit BFT";
            }
            $Command = strtolower(trim($this->ask($Prompt)));

            switch ($Command) {
                case '':
                    break;

                case 'c':
                case 'continue':
                case 'yes':
                    if ($IsInitialGate) {
                        $Session = $this->disableBftChoice($Session, 1);
                    } else {
                        $this->echoc("Continue only applies to the initial relevance test. Type a choice number instead.\n", 'yellow');
                    }
                    break;

                case 'l':
                    if (!$IsInitialGate && !is_array($Session['activeTest'] ?? null)) {
                        $this->displayBftChoices($Session);
                    }
                    break;

                case 'd':
                    if ($IsInitialGate) {
                        $this->echoc("There is only one initial action here. Use c to continue or q to quit.\n", 'yellow');
                    } else {
                        $Session = $this->disableBftChoice($Session);
                    }
                    break;

                case 'y':
                    $Session = $this->recordBftResult($Session, 'issue_disappeared');
                    break;

                case 'n':
                    $Session = $this->recordBftResult($Session, 'still_broken');
                    break;

                case 'u':
                    $Session = $this->recordBftResult($Session, 'untestable');
                    break;

                case 'r':
                    $Session = $this->restoreBftActiveTest($Session, 'manual_restore');
                    break;

                case 'a':
                    if ($this->askYes("Restore all BFT archives and exit BFT? This returns the custom candidate files to the last archived state.")) {
                        $Session = $this->restoreAllBftArchives($Session);
                        $this->ShowMenu = false;
                        return;
                    }
                    break;

                case 'h':
                    $this->displayBftHistory($Session);
                    break;

                case 'q':
                case 'quit':
                case 'exit':
                    $this->ShowMenu = false;
                    return;

                default:
                    if (ctype_digit($Command)) {
                        $Session = $this->disableBftChoice($Session, (int) $Command);
                    } else {
                        $this->echoc("Unknown BFT command '{$Command}'.\n", 'red');
                    }
                    break;
            }
        }
    }

    private function isCaseCloneInstance(): bool {
        return (bool) preg_match('/(^|[_-])case\d+/i', $this->getInstanceCloneGuardText());
    }

    private function getInstanceCloneGuardText(): string {
        return trim(implode(' ', array_filter([
            $this->InstanceInfo['INSTANCE'] ?? '',
            $this->InstanceInfo['GROUP'] ?? '',
            getcwd(),
        ])));
    }

    private function startBftSession(): array {
        if (!is_dir('.bft')) {
            mkdir('.bft', 0775, true);
        }
        if (!is_dir('.bft/disabled')) {
            mkdir('.bft/disabled', 0775, true);
        }

        $this->echoc("Scanning custom for candidate files. This can take a bit on large instances . . .\n", 'label');
        $Candidates = $this->getBftCandidateFiles('custom');
        file_put_contents('.bft/candidates.txt', implode(PHP_EOL, $Candidates) . PHP_EOL);

        $Session = [
            'id' => date('Ymd_His'),
            'baseline' => 'issue_present',
            'mode' => 'Disable candidate files until the issue disappears.',
            'currentPath' => 'custom',
            'candidateCount' => count($Candidates),
            'activeTest' => null,
            'step' => 0,
            'history' => [],
        ];
        $this->saveBftSession($Session);
        $this->echoc("Started BFT session {$Session['id']} with {$Session['candidateCount']} candidate files.\n", 'green');
        $this->echoc("Start by disabling all custom candidate files as a broad relevance test. If that does not change the issue, BFT can stop.\n", 'label');
        $this->echoc("After that first test succeeds, BFT will narrow the issue by next-level folders/files.\n", 'yellow');
        return $Session;
    }

    private function loadBftSession(): ?array {
        if (!file_exists('.bft/session.json')) {
            return null;
        }
        $Session = json_decode(file_get_contents('.bft/session.json'), true);
        return is_array($Session) ? $Session : null;
    }

    private function saveBftSession(array $Session): void {
        if (!is_dir('.bft')) {
            mkdir('.bft', 0775, true);
        }
        file_put_contents('.bft/session.json', json_encode($Session, JSON_PRETTY_PRINT) . PHP_EOL);
        $Lines = [];
        foreach (($Session['history'] ?? []) as $Item) {
            $Lines[] = sprintf(
                '%03d %s %s %s',
                (int) ($Item['step'] ?? 0),
                (string) ($Item['symbol'] ?? ''),
                (string) ($Item['path'] ?? ''),
                (string) ($Item['note'] ?? '')
            );
        }
        file_put_contents('.bft/history.txt', implode(PHP_EOL, $Lines) . ($Lines ? PHP_EOL : ''));
    }

    private function displayBftStatus(array $Session): void {
        $this->echoc("\n---<=== Brute Force Troubleshooting ===>---\n", 'brightblue');
        $this->echoc("Session: ", 'label');
        $this->echoc(($Session['id'] ?? '') . PHP_EOL, 'data');
        $this->echoc("Baseline: ", 'label');
        $this->echoc("issue present\n", 'data');
        $this->echoc("Goal: ", 'label');
        $this->echoc("disable candidate files until the issue disappears\n", 'data');
        $this->echoc("Current path: ", 'label');
        $this->echoc(($Session['currentPath'] ?? 'custom') . PHP_EOL, 'data');
        $this->echoc("Meaning: ", 'label');
        $this->echoc("✅ disabled group is suspicious | 🛑 disabled group is cleared/not it | ⚠️ too broad/untestable\n", 'data');
        if (!empty($Session['stopReason'])) {
            $this->echoc("Stop reason: ", 'label');
            $this->echoc((string) $Session['stopReason'] . PHP_EOL, 'yellow');
        }
        $this->echoc("Next step: ", 'label');
        $this->echoc(
                $this->isBftInitialRelevanceGate($Session)
                        ? "continue to run the first relevance test, or quit.\n"
                        : "type a choice number to disable one listed choice, or use d to enter the number by prompt.\n",
                'data'
        );

        if (is_array($Session['activeTest'] ?? null)) {
            $this->echoc("Active disabled test: ", 'label');
            $this->echoc(($Session['activeTest']['path'] ?? '') . PHP_EOL, 'red');
            $this->echoc("Archive: ", 'label');
            $this->echoc(($Session['activeTest']['archive'] ?? '') . PHP_EOL, 'data');
        } else {
            $this->echoc("Active disabled test: ", 'label');
            $this->echoc("none\n", 'data');
        }
    }

    private function getBftCandidateFiles(string $Path): array {
        $Root = rtrim($Path, '/');
        if (!is_dir($Root) && !is_file($Root)) {
            return [];
        }

        $Extensions = ['js', 'css', 'tpl', 'hbs', 'less'];
        $Files = [];
        if (is_file($Root)) {
            $Ext = strtolower(pathinfo($Root, PATHINFO_EXTENSION));
            return in_array($Ext, $Extensions, true) ? [$Root] : [];
        }

        $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($Root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($Iterator as $FileInfo) {
            if (!$FileInfo->isFile()) {
                continue;
            }
            $PathName = str_replace('\\', '/', $FileInfo->getPathname());
            $Ext = strtolower(pathinfo($PathName, PATHINFO_EXTENSION));
            if (in_array($Ext, $Extensions, true)) {
                $Files[] = ltrim($PathName, './');
            }
        }
        sort($Files, SORT_NATURAL | SORT_FLAG_CASE);
        return $Files;
    }

    private function isBftInitialRelevanceGate(array $Session): bool {
        return (int) ($Session['step'] ?? 0) === 0
                && empty($Session['history'])
                && rtrim((string) ($Session['currentPath'] ?? 'custom'), '/') === 'custom';
    }

    private function getBftChoices(array $Session): array {
        $CurrentPath = rtrim((string) ($Session['currentPath'] ?? 'custom'), '/');
        $Candidates = $this->getBftCandidateFiles($CurrentPath);
        if ($this->isBftInitialRelevanceGate($Session) && $Candidates) {
            return [[
                'path' => 'custom',
                'type' => 'baseline',
                'count' => count($Candidates),
                'label' => 'ALL custom candidate files - first relevance test',
            ]];
        }
        $ChoicesByPath = [];

        foreach ($Candidates as $File) {
            $Relative = substr($File, strlen($CurrentPath));
            $Relative = ltrim((string) $Relative, '/');
            if ($Relative === '') {
                continue;
            }
            $Parts = explode('/', $Relative);
            $ChoicePath = count($Parts) === 1
                    ? $File
                    : $CurrentPath . '/' . $Parts[0];
            if (!isset($ChoicesByPath[$ChoicePath])) {
                $ChoicesByPath[$ChoicePath] = [
                    'path' => $ChoicePath,
                    'type' => is_dir($ChoicePath) ? 'folder' : 'file',
                    'count' => 0,
                ];
            }
            $ChoicesByPath[$ChoicePath]['count']++;
        }

        $Choices = array_values($ChoicesByPath);
        usort($Choices, function ($A, $B) {
            if ($A['type'] !== $B['type']) {
                return $A['type'] === 'folder' ? -1 : 1;
            }
            return strnatcasecmp($A['path'], $B['path']);
        });
        return $Choices;
    }

    private function getBftChoiceSymbol(array $Session, string $Path): string {
        $Symbol = '';
        foreach (($Session['history'] ?? []) as $Item) {
            if ((string) ($Item['path'] ?? '') === $Path) {
                $Symbol = (string) ($Item['symbol'] ?? '');
            }
        }
        return $Symbol;
    }

    private function displayBftInitialRelevanceGate(array $Session): void {
        $this->echoc("Scanning files under custom . . .\n", 'label');
        $Choices = $this->getBftChoices($Session);
        $Choice = $Choices[0] ?? null;
        if (!$Choice) {
            $this->echoc("No custom js/css/tpl/hbs/less candidate files found. There is nothing for BFT to test.\n", 'yellow');
            return;
        }
        $this->echoc("\nInitial relevance test:\n", 'label');
        $this->echoc("  ", 'data');
        $this->echoc((string) ($Choice['count'] ?? 0), 'data');
        $this->echoc(" custom js/css/tpl/hbs/less files will be archived and removed.\n", 'data');
        $this->echoc("  If the issue still exists after this, stop BFT and look elsewhere.\n", 'label');
        $this->echoc("  If the issue disappears, BFT will restore the files and start narrowing by folder.\n", 'label');
    }

    private function displayBftChoices(array $Session): array {
        if (!empty($Session['stopReason'])) {
            $this->echoc("BFT stop reason: " . (string) $Session['stopReason'] . PHP_EOL, 'yellow');
            $this->echoc("Restore all archives if needed, then quit BFT.\n", 'label');
            return [];
        }
        $this->echoc("Scanning files under {$Session['currentPath']} . . .\n", 'label');
        $Choices = $this->getBftChoices($Session);
        if (!$Choices) {
            $this->echoc("No candidate files remain under {$Session['currentPath']}.\n", 'yellow');
            return [];
        }

        $this->echoc("\nNext-level choices under {$Session['currentPath']}:\n", 'label');
        foreach ($Choices as $Index => $Choice) {
            $Symbol = $this->getBftChoiceSymbol($Session, (string) ($Choice['path'] ?? ''));
            $this->echoc(str_pad((string) ($Index + 1), 4), 'data');
            $this->echoc(str_pad($Symbol !== '' ? $Symbol : ' ', 4), $Symbol === '✅' ? 'green' : ($Symbol === '🛑' ? 'red' : ($Symbol === '⚠️' ? 'yellow' : 'data')));
            $this->echoc(str_pad($Choice['type'], 10), in_array($Choice['type'], ['folder', 'baseline'], true) ? 'label' : 'yellow');
            $this->echoc(str_pad((string) $Choice['count'], 6, ' ', STR_PAD_LEFT) . ' files  ', 'data');
            $this->echoc(($Choice['label'] ?? $Choice['path']) . PHP_EOL, 'data');
        }
        $this->echoc("Type a number directly, or use d and enter the number when prompted. This disables only that choice's candidate files.\n", 'label');
        return $Choices;
    }

    private function disableBftChoice(array $Session, ?int $ChoiceNumber = null): array {
        if (is_array($Session['activeTest'] ?? null)) {
            $this->echoc("There is already an active disabled test. Record [y], [n], [u], or [r]estore it first.\n", 'red');
            return $Session;
        }

        $Choices = $this->displayBftChoices($Session);
        if (!$Choices) {
            return $Session;
        }

        if ($ChoiceNumber === null) {
            $ChoiceNumber = (int) $this->ask("Choice number to disable. This will archive/remove only that listed choice");
        }
        $Choice = $Choices[$ChoiceNumber - 1] ?? null;
        if (!$Choice) {
            $this->echoc("Invalid choice.\n", 'red');
            return $Session;
        }

        $this->echoc("Scanning files to disable under {$Choice['path']} . . .\n", 'label');
        $Files = $this->getBftCandidateFiles($Choice['path']);
        if (!$Files) {
            $this->echoc("No candidate files found for {$Choice['path']}.\n", 'yellow');
            return $Session;
        }

        $Step = (int) ($Session['step'] ?? 0) + 1;
        $Archive = sprintf(
            '.bft/disabled/%03d_%s.zip',
            $Step,
            preg_replace('/[^A-Za-z0-9._-]+/', '_', $Choice['path'])
        );

        if (!$this->archiveAndRemoveBftFiles($Files, $Archive)) {
            $this->echoc("Failed to disable {$Choice['path']}.\n", 'red');
            return $Session;
        }

        $this->clearBftCache();
        $Session['step'] = $Step;
        $Session['activeTest'] = [
            'step' => $Step,
            'path' => $Choice['path'],
            'archive' => $Archive,
            'fileCount' => count($Files),
        ];
        $this->saveBftSession($Session);

        $this->echoc("Disabled {$Choice['path']} ({$Session['activeTest']['fileCount']} files).\n", 'green');
        $this->echoc("Now test in the browser, then return here with y/n/u.\n", 'label');
        return $Session;
    }

    private function archiveAndRemoveBftFiles(array $Files, string $Archive): bool {
        if (class_exists('ZipArchive')) {
            $Zip = new ZipArchive();
            if ($Zip->open($Archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return false;
            }
            foreach ($Files as $File) {
                if (is_file($File)) {
                    $Zip->addFile($File, $File);
                }
            }
            $Zip->close();

            foreach ($Files as $File) {
                if (is_file($File)) {
                    unlink($File);
                }
            }
            return true;
        }

        $ListFile = '.bft/ziplist_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.txt';
        file_put_contents($ListFile, implode(PHP_EOL, array_filter($Files, 'is_file')) . PHP_EOL);
        $Command = 'zip -q -m ' . escapeshellarg($Archive) . ' -@ < ' . escapeshellarg($ListFile);
        $this->echoc($Command . PHP_EOL, 'command');
        system($Command, $ExitCode);
        unlink($ListFile);
        return $ExitCode === 0 && file_exists($Archive);
    }

    private function recordBftResult(array $Session, string $Result): array {
        if (!is_array($Session['activeTest'] ?? null)) {
            $this->echoc("No active BFT test to record.\n", 'yellow');
            return $Session;
        }

        $Active = $Session['activeTest'];
        $IsInitialRelevanceTest = ((int) ($Active['step'] ?? 0) === 1 && (string) ($Active['path'] ?? '') === 'custom');
        $Symbol = '🛑';
        $Note = $IsInitialRelevanceTest
                ? 'still broken after disabling all custom candidates; BFT candidate files are unlikely to be the cause'
                : 'still broken; restored and moving to next sibling';
        $Descend = false;

        if ($Result === 'issue_disappeared') {
            $Symbol = '✅';
            $Note = $IsInitialRelevanceTest
                    ? 'issue disappeared after disabling all custom candidates; candidate files are relevant, restored and ready to narrow'
                    : 'issue disappeared; restored and descended into suspicious path';
            $Descend = true;
        } elseif ($Result === 'untestable') {
            $Symbol = '⚠️';
            $Note = 'untestable; restored and descended smaller';
            $Descend = true;
        }

        $Session['history'][] = [
            'step' => (int) ($Active['step'] ?? 0),
            'symbol' => $Symbol,
            'path' => (string) ($Active['path'] ?? ''),
            'result' => $Result,
            'note' => $Note,
            'archive' => (string) ($Active['archive'] ?? ''),
            'fileCount' => (int) ($Active['fileCount'] ?? 0),
            'recordedAt' => date('c'),
        ];

        $Session = $this->restoreBftActiveTest($Session, $Result, false);
        if ($IsInitialRelevanceTest && $Result === 'still_broken') {
            $Session['stopReason'] = 'Issue still exists after disabling all custom js/css/tpl/hbs/less candidates. BFT is probably not the right path for this issue.';
        } else {
            unset($Session['stopReason']);
        }
        if ($Descend && is_dir($Active['path'] ?? '')) {
            $Session['currentPath'] = (string) $Active['path'];
        }
        $this->saveBftSession($Session);
        return $Session;
    }

    private function restoreBftActiveTest(array $Session, string $Reason = 'restore', bool $WriteHistory = true): array {
        if (!is_array($Session['activeTest'] ?? null)) {
            $this->echoc("No active BFT test to restore.\n", 'yellow');
            return $Session;
        }

        $Active = $Session['activeTest'];
        $Archive = (string) ($Active['archive'] ?? '');
        if ($Archive && file_exists($Archive)) {
            $this->restoreBftArchive($Archive);
            $this->clearBftCache();
            $this->echoc("Restored {$Active['path']}.\n", 'green');
        }

        if ($WriteHistory) {
            $Session['history'][] = [
                'step' => (int) ($Active['step'] ?? 0),
                'symbol' => '↩️',
                'path' => (string) ($Active['path'] ?? ''),
                'result' => $Reason,
                'note' => 'manual restore',
                'archive' => $Archive,
                'fileCount' => (int) ($Active['fileCount'] ?? 0),
                'recordedAt' => date('c'),
            ];
        }

        $Session['activeTest'] = null;
        $this->saveBftSession($Session);
        return $Session;
    }

    private function restoreBftArchive(string $Archive): void {
        if (class_exists('ZipArchive')) {
            $Zip = new ZipArchive();
            if ($Zip->open($Archive) === true) {
                $Zip->extractTo('.');
                $Zip->close();
            }
            return;
        }

        $Command = 'unzip -oq ' . escapeshellarg($Archive);
        $this->echoc($Command . PHP_EOL, 'command');
        system($Command);
    }

    private function restoreAllBftArchives(array $Session): array {
        if (is_array($Session['activeTest'] ?? null)) {
            $Session = $this->restoreBftActiveTest($Session, 'restore_all', false);
        }

        foreach (glob('.bft/disabled/*.zip') ?: [] as $Archive) {
            $this->restoreBftArchive($Archive);
        }
        $this->clearBftCache();
        $Session['activeTest'] = null;
        $Session['history'][] = [
            'step' => (int) ($Session['step'] ?? 0),
            'symbol' => '↩️',
            'path' => 'all',
            'result' => 'restore_all',
            'note' => 'restored all BFT archives',
            'archive' => '.bft/disabled/*.zip',
            'fileCount' => 0,
            'recordedAt' => date('c'),
        ];
        $this->saveBftSession($Session);
        $this->echoc("All BFT archives restored.\n", 'green');
        return $Session;
    }

    private function clearBftCache(): void {
        $this->echoc("Clearing cache/*\n", 'command');
        foreach (glob('cache/*') ?: [] as $Path) {
            $this->removePath($Path);
        }
    }

    private function removePath(string $Path): void {
        if (is_dir($Path) && !is_link($Path)) {
            foreach (scandir($Path) ?: [] as $Child) {
                if ($Child === '.' || $Child === '..') {
                    continue;
                }
                $this->removePath($Path . DIRECTORY_SEPARATOR . $Child);
            }
            rmdir($Path);
            return;
        }
        if (file_exists($Path) || is_link($Path)) {
            unlink($Path);
        }
    }

    private function ensureCloudSupportFolder(): void {
        if (!is_dir('cloud_support')) {
            mkdir('cloud_support', 0700, true);
        }
        chmod('cloud_support', 0700);

        $AccessControl = "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
        file_put_contents('cloud_support/.htaccess', $AccessControl, LOCK_EX);
        chmod('cloud_support/.htaccess', 0600);
        foreach (glob('cloud_support/configs_*.zip') ?: array() as $ConfigArchive) {
            if (is_file($ConfigArchive)) {
                chmod($ConfigArchive, 0600);
            }
        }
    }

    private function backupCustomAndModulesToCloudSupport(): string {
        $this->ensureCloudSupportFolder();
        $Filename = "cloud_support/custom_and_modules_" . date('Y-m-d_H-i') . ".tar.gz";
        $Command = 'tar -czf ' . escapeshellarg($Filename) . ' custom/ modules/';
        $this->echoc("Backing up custom and modules folders . . .\n", 'label');
        $this->echoc($Command . PHP_EOL, 'command');
        system($Command);
        if (is_file($Filename)) {
            chmod($Filename, 0600);
        }
        system('ls -hal ' . escapeshellarg($Filename));
        return $Filename;
    }

    private function createSugarutilsCleanupLog(string $Name): string {
        $this->ensureCloudSupportFolder();
        $SafeName = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($Name));
        return "cloud_support/sugarutils_cleanup_{$SafeName}_" . date('Y-m-d_H-i-s') . ".log";
    }

    private function logCleanupLine(string $LogFile, string $Message): void {
        file_put_contents($LogFile, $Message . PHP_EOL, FILE_APPEND);
    }

    private function displayAndLogCleanupLine(string $LogFile, string $Message, string $Color = 'label'): void {
        $this->echoc($Message . PHP_EOL, $Color);
        $this->logCleanupLine($LogFile, $Message);
    }

    private function removeCleanupPaths(string $Title, array $Paths, string $LogFile): int {
        $Paths = array_values(array_unique(array_filter(array_map('trim', $Paths))));
        $ExistingPaths = [];
        foreach ($Paths as $Path) {
            if (file_exists($Path) || is_link($Path)) {
                $ExistingPaths[] = $Path;
            }
        }

        if (!$ExistingPaths) {
            $this->displayAndLogCleanupLine($LogFile, "{$Title}: All's Well. Nothing to remove.", 'green');
            return 0;
        }

        $this->displayAndLogCleanupLine($LogFile, "{$Title}: removing " . count($ExistingPaths) . " path(s).", 'yellow');
        foreach ($ExistingPaths as $Path) {
            $this->displayAndLogCleanupLine($LogFile, "Removing {$Path}", 'command');
            $this->removePath($Path);
        }

        return count($ExistingPaths);
    }

    private function findCleanupPaths(string $Root, string $Pattern): array {
        if (!is_dir($Root)) {
            return [];
        }

        $Paths = [];
        $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($Root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($Iterator as $Item) {
            if (fnmatch($Pattern, $Item->getFilename())) {
                $Paths[] = $Item->getPathname();
            }
        }

        return $Paths;
    }

    private function extractRmPathsFromCommands(string $Commands): array {
        $Paths = [];
        foreach (preg_split('/\R/', $Commands) ?: [] as $Line) {
            $Line = trim($Line);
            if ($Line === '' || strpos($Line, 'rm -r ') !== 0) {
                continue;
            }
            $Paths[] = trim(substr($Line, 6));
        }
        return $Paths;
    }

    private function logMatchingLines(string $Title, array $Files, string $Needle, string $LogFile, bool $QuietWhenEmpty = false): int {
        $Matches = [];
        foreach ($Files as $File) {
            if (!is_file($File) || !is_readable($File)) {
                continue;
            }
            $Lines = file($File);
            if ($Lines === false) {
                continue;
            }
            foreach ($Lines as $LineNumber => $Line) {
                if (stripos($Line, $Needle) !== false) {
                    $Matches[] = $File . ':' . ($LineNumber + 1) . ': ' . rtrim($Line);
                }
            }
        }

        if (!$Matches) {
            if ($QuietWhenEmpty) {
                return 0;
            }
            $this->displayAndLogCleanupLine($LogFile, "{$Title}: All's Well. No lingering references found.", 'green');
            return 0;
        }

        $this->displayAndLogCleanupLine($LogFile, "{$Title}: manual review needed for " . count($Matches) . " line(s).", 'yellow');
        foreach ($Matches as $Match) {
            $this->displayAndLogCleanupLine($LogFile, $Match, 'command');
        }
        return count($Matches);
    }

    private function displayBftHistory(array $Session): void {
        $History = $Session['history'] ?? [];
        if (!$History) {
            $this->echoc("No BFT history yet.\n", 'yellow');
            return;
        }
        $this->echoc("\nBFT History\n", 'label');
        foreach ($History as $Item) {
            $this->echoc(str_pad((string) ($Item['step'] ?? ''), 4), 'data');
            $this->echoc(str_pad((string) ($Item['symbol'] ?? ''), 5), 'label');
            $this->echoc((string) ($Item['path'] ?? ''), 'data');
            $this->echoc(' - ' . (string) ($Item['note'] ?? '') . PHP_EOL, 'label');
        }
    }

    private function searchDropdownLists($Command) {
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SearchString = trim(implode(' ', $CommandArray));
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching custom/Extension/application/Ext/Language/ folder for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'date');
        $Cmd = 'grep -rlF -- ' . escapeshellarg($SearchString)
            . ' custom/Extension/application/Ext/Language/';
        $this->echoc($Cmd . PHP_EOL, 'magenta');
        $Files = array();
        exec($Cmd, $Files, $ReturnCode);
        if ($ReturnCode > 1) {
            $this->echoc("Search failed with exit code {$ReturnCode}.\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        foreach ($Files as $File) {
            $this->echoc($File . PHP_EOL, 'command');
        }
        $this->echoc("Searching files for illegal characters. Skipping Order Mapping files\n", 'label');
        foreach ($Files as $File) {
            if (strpos($File, 'orderMapping') != false) {
                continue;
            }
            echo "\nFILE: {$File}\n";
            include $File;
            foreach ($app_list_strings[$SearchString] as $Name => $Value) {
                $Result = preg_replace('/[\w\d\s\.,\(\)]/', '', $Name);
                if ($Result) {
                    echo "ENTRY: {$Name}\nILLEGAL CHARACTER(S): {$Result}\n";
                }
            }
        }


        $this->ShowMenu = false;
    }

    private function searchCustomFolder($Command) {
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SearchString = trim(implode(' ', $CommandArray));
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching custom folder for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'date');
        $Cmd = 'grep -rF -- ' . escapeshellarg($SearchString) . ' custom';
        $this->echoc($Cmd . PHP_EOL, 'magenta');
        system($Cmd);
        $this->ShowMenu = false;
    }

    private function searchPackageSource($Command) {
        $Arguments = $this->getCommandArgument($Command);
        $ArgumentParts = preg_split('/\s+/', $Arguments, 2) ?: array();
        $FileName = trim((string) ($ArgumentParts[0] ?? ''));
        $ListName = trim((string) ($ArgumentParts[1] ?? ''));
        if ($FileName === '') {
            $FileName = trim($this->ask("File path or filename to locate: "));
        }
        if ($ListName === '') {
            $ListName = trim($this->ask("Options list name, if different from the filename (optional): "));
        }
        if ($FileName === '') {
            $this->echoc("A file path or filename is required.\n", 'red');
            $this->ShowMenu = false;
            return;
        }

        $BaseName = pathinfo(basename($FileName), PATHINFO_FILENAME);
        $SearchTerms = array_values(array_unique(array_filter(
            array($FileName, $BaseName, $ListName),
            static fn($Value): bool => trim((string) $Value) !== ''
        )));

        $this->echoc("[Package Source Search]\n", 'section');
        $this->echoc("File: {$FileName}\n", 'data');
        if ($ListName !== '') {
            $this->echoc("Options list: {$ListName}\n", 'data');
        }

        if (!$this->upgradeModuleFolderHasFiles()) {
            $this->echoc("No files were found in upgrades/module; skipping filesystem and ZIP searches.\n", 'yellow');
        } else {
            foreach ($SearchTerms as $SearchTerm) {
                $this->echoc("Searching upgrades/module files for: {$SearchTerm}\n", 'label');
                $Matches = $this->findUpgradeFolderMatches($SearchTerm);
                if ($Matches) {
                    $this->displayUpgradeFolderMatches($Matches);
                    $this->echoc("Package source candidate found. Search complete.\n", 'green');
                    $this->ShowMenu = false;
                    return;
                }
                $this->echoc("No matching unpacked package files found.\n", 'green');
            }

            foreach ($SearchTerms as $SearchTerm) {
                $this->echoc("Searching upgrades/module ZIP entries for: {$SearchTerm}\n", 'label');
                $Matches = $this->findZipEntryMatches($SearchTerm);
                if ($Matches) {
                    $this->displayZipEntryMatches($Matches);
                    $this->echoc("Package ZIP candidate found. Search complete.\n", 'green');
                    $this->ShowMenu = false;
                    return;
                }
                $this->echoc("No matching ZIP entries found.\n", 'green');
            }
        }

        foreach ($SearchTerms as $SearchTerm) {
            $this->echoc("Searching installed package manifests for: {$SearchTerm}\n", 'label');
            $Matches = $this->findManifestMatches($SearchTerm);
            if ($Matches) {
                $this->displayManifestMatches($Matches);
                $this->echoc("Installed package manifest candidate found. Search complete.\n", 'green');
                $this->ShowMenu = false;
                return;
            }
            $this->echoc("No matching installed package manifests found.\n", 'green');
        }

        $this->echoc("Could not locate a package containing this file.\n", 'yellow');
        $this->ShowMenu = false;
    }

    private function getCommandArgument($Command): string {
        $Parts = preg_split('/\s+/', trim((string) $Command), 2) ?: array();
        return trim((string) ($Parts[1] ?? ''));
    }

    private function upgradeModuleFolderHasFiles(): bool {
        if (!is_dir('upgrades/module')) {
            return false;
        }
        try {
            $Iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator('upgrades/module', FilesystemIterator::SKIP_DOTS)
            );
            foreach ($Iterator as $FileInfo) {
                if ($FileInfo->isFile()) {
                    return true;
                }
            }
        } catch (UnexpectedValueException $Exception) {
            return false;
        }
        return false;
    }

    private function findUpgradeFolderMatches(string $SearchString): array {
        if ($SearchString === '' || !is_dir('upgrades/module')) {
            return array();
        }

        $Matches = array();
        $ReturnCode = 0;
        $Command = 'grep -rIlF -- '
            . escapeshellarg($SearchString)
            . ' upgrades/module/ 2>/dev/null';
        exec($Command, $Matches, $ReturnCode);
        if ($ReturnCode !== 0 || !$Matches) {
            return array();
        }

        $Matches = array_values(array_unique(array_filter(array_map('trim', $Matches))));
        usort($Matches, static function (string $Left, string $Right): int {
            return ((int) @filemtime($Left)) <=> ((int) @filemtime($Right));
        });
        return $Matches;
    }

    private function displayUpgradeFolderMatches(array $Matches): void {
        foreach ($Matches as $File) {
            $Size = @filesize($File);
            $Modified = @filemtime($File);
            $Details = array();
            if ($Size !== false) {
                $Details[] = number_format($Size) . ' bytes';
            }
            if ($Modified !== false) {
                $Details[] = date('Y-m-d H:i:s T', $Modified);
            }
            $Suffix = $Details ? ' (' . implode(', ', $Details) . ')' : '';
            $this->echoc($File . $Suffix . "\n", 'command');
        }
    }

    private function searchUpgradesFolder($Command) {
        $SearchString = $this->getCommandArgument($Command);
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching upgrades folder for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'date');
        $Matches = $this->findUpgradeFolderMatches($SearchString);
        if (!$Matches) {
            $this->echoc("No matching unpacked package files found.\n", 'green');
        } else {
            $this->displayUpgradeFolderMatches($Matches);
        }
//        $this->ask("Press enter to continue");
        $this->ShowMenu = false;
    }

    private function startDiscovery() {
        foreach ($this->Defaults as $Name) {
            if (in_array($Name, array('Issues', 'Files', 'LinkToHelpArticle', 'PackageManifestDetails'))) {
                $this->Details[$Name] = $this->askm($Name);
            } else {
                $this->Details[$Name] = $this->ask($Name);
            }
        }
        print_r($this->Details);
    }

    private function findLargeFiles() {
        $Size = trim($this->ask("Enter the minimum size as either xxxM or xxxG: "));
        if (!preg_match('/^[1-9][0-9]*[MG]$/i', $Size)) {
            $this->echoc("Size must be a positive number followed by M or G, such as 100M or 2G.\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        $Cmd = 'find custom -type f -size ' . escapeshellarg('+' . strtoupper($Size))
            . ' -print0 | xargs -0 -r ls -halS';
        $this->echoc($Cmd . PHP_EOL, 'magenta');
        system($Cmd);
        $this->ShowMenu = false;
    }

    private function listUsers() {
        $this->echoc("[Users]\n\n", 'brightblue');
        $SQL = "SELECT id, user_name, last_login, license_type FROM users WHERE NOT deleted AND status = 'Active' ORDER BY last_login;";
        $this->echoc($SQL . PHP_EOL, 'magenta');
        foreach ($this->PDO->query($SQL) as $Row) {
            $ID = str_pad($Row['id'], 40);
            $UserName = str_pad($Row['user_name'], 35);
            $LastLogin = str_pad($Row['last_login'], 20);
            $this->echoc("{$ID}{$UserName}\t{$LastLogin}\t{$Row['license_type']}\n", 'magenta');
        }

        echo "\n";
        $SQL2 = "SELECT count(*) `Count`, is_admin, license_type FROM users WHERE NOT deleted AND status = 'Active' group by license_type order by 1 desc;";
        $this->echoc($SQL2 . PHP_EOL, 'magenta');
        $this->echoc(str_pad("Count  ", 10, ' ', STR_PAD_LEFT), 'label');
        $this->echoc(str_pad("License Type", 50).PHP_EOL, 'label');
        foreach ($this->PDO->query($SQL2) as $Row) {
            $this->echoc(str_pad($Row['Count'].'  ', 10, ' ', STR_PAD_LEFT), 'data');
            $this->echoc(str_pad($Row['license_type'], 50).PHP_EOL, 'data');
        }

        echo "\n";
        $SQL3 = "SELECT count(*) `Count`, sum(`is_admin`) AS Admins  FROM users WHERE NOT deleted AND status = 'Active';";
        $this->echoc($SQL3 . PHP_EOL, 'magenta');
        foreach ($this->PDO->query($SQL3) as $Row) {
            $UserCount = $Row['Count'];
            $this->echoc("Total active users: ", 'label');
            $this->echoc(str_pad("Total active users", 20), 'label');
            $this->echoc(" = ", 'red');
            $this->echoc("{$UserCount}\n", 'data');
//            $this->echoc("Total admin users: ", 'label');
            $this->echoc(str_pad("Total admin users", 20), 'label');
            $this->echoc(" = ", 'red');
            $this->echoc("{$Row['Admins']}\n", 'data');
            $this->echoc(str_pad("License Count", 20), 'label');
            $this->echoc(" = ", 'red');
            $this->echoc("{$this->Subscription['quantity_c']}\n", 'data');
        }
        system('date');
        $this->ShowMenu = false;
    }

    private function getAdminUsers() {
        $this->echoc("[Admin Users]\n\n", 'brightblue');
        foreach ($this->PDO->query("SELECT user_name FROM users WHERE NOT deleted AND is_admin AND status = 'Active' order by 1;") as $Row) {
            $this->echoc("{$Row['user_name']}\n", 'magenta');
        }
        echo "\n";
    }

    public function runHealthCheck() {
        $Answer = $this->ask("Please enter the version of the Health Check you would like to run.\n14.0.3, 14.2.0, 25.1.0, 25.2.0, 26.1.0 (default 26.1.0)");
        $HealthCheckVersion = $Answer ? $Answer:'26.1.0';
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $HealthCheckVersion)) {
            $this->echoc("Health Check version must use the numeric x.y.z format.\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        $HealthCheckPath = "/mnt/sugar/{$HealthCheckVersion}/sortinghat-{$HealthCheckVersion}.phar";
        if (!is_file($HealthCheckPath)) {
            $this->echoc("Health Check executable not found: {$HealthCheckPath}\n", 'red');
            $this->ShowMenu = false;
            return;
        }
        $this->echoc("{$HealthCheckVersion} ", 'data');
        $this->echoc("it is then.\n", 'label');
        $RunQuickRepairandRebuild = $this->ask("Would you like to run a Quick Repair and Rebuild first? Y/n");
        if (strtoupper(substr($RunQuickRepairandRebuild, 0, 1)) === 'Y' || !$RunQuickRepairandRebuild) {
            $this->runQuickRepairandRebuild();
        }
        $this->echoc("Gzipping any existing Health Check logs\n", 'label');
        $Command1 = "gzip -v healthcheck-*.log";
        $this->echoc($Command1 . PHP_EOL, 'command');
        system($Command1);
        $this->echoc("- ▶️ Running Sugar {$HealthCheckVersion} Health Check on {$this->InstanceInfo['INSTANCE']}\n", 'label');
        $Command2 = 'shadowy ' . escapeshellarg($HealthCheckPath) . ' .';
        $this->echoc($Command2 . PHP_EOL, 'command');
        system($Command2, $ReturnValue);
        if($ReturnValue === 0){
            $this->echoc("The Health Check appears to have completed normally with an exit code of: {$ReturnValue}\n", 'green');
        }else{
            $this->echoc("\n\n**** The Health Check appears to have finished abnormally with an exit code of: {$ReturnValue} ****\n\n", 'red');
        }
        $SystemScannerOutput = [];
        $Command3 = 'egrep "BUCKET => .: [0-9]{1,4}" healthcheck-*.log';
        $this->echoc($Command3 . PHP_EOL, 'command');
        exec($Command3, $SystemScannerOutput);
        $BucketFs = false;
        $BucketEs = false;
        foreach ($SystemScannerOutput as $Line) {
            if (substr_count($Line, 'BUCKET => F:')) {
                $this->echoc("$Line\n", 'red');
                $BucketFs = true;
            } elseif (substr_count($Line, 'BUCKET => E:')) {
                $this->echoc("$Line\n", 'yellow');
                $BucketEs = true;
            } else {
                $this->echoc("$Line\n", 'ok');
            }
        }
        if ($BucketFs) {
            $this->echoc("- 🛑 Sugar {$HealthCheckVersion} healthcheck failure for {$this->InstanceInfo['INSTANCE']}\n", 'alert');
            $Lines = [];
//            $Command4 = 'egrep "BUCKET => F" healthcheck-*.log';
            $Command4 = 'sed -n \'/BUCKET => F/,$p\' healthcheck-*.log';
            $this->echoc($Command4 . PHP_EOL, 'command');
            exec($Command4, $Lines);
            foreach ($Lines as $Line) {
                $this->echoc("$Line\n", 'red');
            }
        } else {
            $this->echoc("- ✅ Sugar {$HealthCheckVersion} healthcheck success for {$this->InstanceInfo['INSTANCE']}\n", 'green');
        }
        if ($BucketEs) {
            $ShowBucketEs = $this->ask("Would you like to see the Bucket E issues? y/N");
            if (strtoupper(substr($ShowBucketEs, 0, 1)) === 'Y') {
                $this->echoc("\nBucket E issues!!\n\n", 'yellow');
                $Lines = [];
                //            $Command4 = 'egrep "BUCKET => F" healthcheck-*.log';
                $Command4 = 'sed -n \'/BUCKET => E/,$p\' healthcheck-*.log';
                $this->echoc($Command4 . PHP_EOL, 'command');
                exec($Command4, $Lines);
                foreach ($Lines as $Line) {
                    $this->echoc("$Line\n", 'yellow');
                }
            }
        }
        echo "\n";
        $this->ShowMenu = false;
    }

    private function listJobsByStatus() {
        $this->echoc("[Running and Queued Jobs]\n\n", 'brightblue');
        foreach ($this->PDO->query("select status, format(count(*),0) count from job_queue group by status order by count(*);") as $Row) {
            $this->Subscription = json_decode($Row[0]);
            $Status = str_pad($Row['status'], 20);
            $Count = str_pad($Row['count'], 10);
            $this->echoc("{$Status}\t{$Count}\n", 'magenta');
        }
        echo "\n";
    }

    private function showProcessList() {
        $this->echoc("[Running and Queued Jobs]\n\n", 'brightblue');
        $Rows = $this->PDO->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
        Utils::print_t($Rows);
        echo "\n";
    }

    private function loadInstnceInfo() {
        exec('instance-info -a', $Output);
        foreach ($Output as $Line) {
            $NameValue = explode('=', $Line);
            $this->InstanceInfo[$NameValue[0]] = $NameValue[1];
        }
    }

    private function loadLicenseInfo() {
        foreach ($this->PDO->query("select value from config where category = 'license' and name = 'subscription';") as $Row) {
            $JSON = json_decode($Row[0], true);
            $this->Subscription = $JSON['subscription'];
        }
        echo "\n";
    }

    private function ask($RequestText) {
        echo "\n{$RequestText}\n";
//        return trim(fgets(STDIN)); // reads one line from STDIN  
        return readline($this->buildSugarutilsPrompt());
    }

    private function buildSugarutilsPrompt() {
        $AccountName = $this->terminalPromptValue($this->Subscription['account_name'] ?? 'Unknown Account');
        $InstanceName = $this->terminalPromptValue(
            $this->InstanceInfo['GROUP'] ?? $this->InstanceInfo['INSTANCE'] ?? 'Unknown Instance'
        );
        $ContextColor = "\001\033[36;1m\002";
        $SugarutilsColor = "\001\033[35;1m\002";
        $ResetColor = "\001\033[0m\002";
        return "{$ContextColor}{$AccountName}->{$InstanceName}{$ResetColor}: "
            . "{$SugarutilsColor}sugarutils{$ResetColor} > ";
    }

    private function terminalPromptValue($Value) {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $Value));
    }

    private function askm($RequestText) {
        echo "\n{$RequestText}\n";
        $Return = '';
        do {
            $Line = fgets(STDIN);
            $Return .= $Line . PHP_EOL;
        } while (trim($Line) !== '.');
        return trim(rtrim(trim($Return), '.')); // Remove the . that signifies the multiline input is complete
    }

    private function displayColors() {
        $this->echoc("-<= Color Chart =>-\n", 'label');
        $this->echoc("red\n", 'red');
        $this->echoc("brightred\n", 'brightred');
        $this->echoc("green\n", 'green');
        $this->echoc("brightgreen\n", 'brightgreen');
        $this->echoc("yellow\n", 'yellow');
        $this->echoc("brightyellow\n", 'brightyellow');
        $this->echoc("blue\n", 'blue');
        $this->echoc("brightblue\n", 'brightblue');
        $this->echoc("magenta\n", 'magenta');
        $this->echoc("brightmagenta\n", 'brightmagenta');
        $this->echoc("cyan\n", 'cyan');
        $this->echoc("brightcyan\n", 'brightcyan');
        $this->echoc("white\n", 'white');
        $this->echoc("brightwhite\n", 'brightwhite');
    }

    private function echoc($String, $Color) {
        switch ($Color) {
            case 'red':
            case 'failure':
            case 'bad':
            case 'alert':
                echo "\033[31m{$String}\033[0m";
                break;

            case 'green':
            case 'success':
            case 'good':
            case 'ok':
                echo "\033[36m{$String}\033[0m";
                break;

            case 'yellow':
            case 'label':
                echo "\033[33m{$String}\033[0m";
                break;

            case 'blue':
            case 'link':
            case 'url':
                echo "\033[34m{$String}\033[0m";
                break;

            case 'magenta':
            case 'command':
                echo "\033[35m{$String}\033[0m";
                break;

            case 'cyan':
            case 'data':
                echo "\033[36m{$String}\033[0m";
                break;

            case 'white':
                echo "\033[37m{$String}\033[0m";
                break;

            case 'brightred':
                echo "\033[31;1m{$String}\033[0m";
                break;

            case 'brightgreen':
                echo "\033[36;1m{$String}\033[0m";
                break;

            case 'brightyellow':
                echo "\033[33;1m{$String}\033[0m";
                break;

            case 'brightblue':
            case 'section':
                echo "\033[34;1m{$String}\033[0m";
                break;

            case 'brightmagenta':
                echo "\033[35;1m{$String}\033[0m";
                break;

            case 'brightcyan':
                echo "\033[36;1m{$String}\033[0m";
                break;

            case 'brightwhite':
                echo "\033[37;1m{$String}\033[0m";
                break;

            default:
                echo "\033[37m{$String}\033[0m";
                break;
        }
        /*  Black: \u001b[30m
          Red: \u001b[31m
          Green: \u001b[32m
          Yellow: \u001b[33m
          Blue: \u001b[34m
          Magenta: \u001b[35m
          Cyan: \u001b[36m
          White: \u001b[37m
          Reset: \u001b[0m
          Bright Black: \u001b[30;1m
          Bright Red: \u001b[31;1m
          Bright Green: \u001b[32;1m
          Bright Yellow: \u001b[33;1m
          Bright Blue: \u001b[34;1m
          Bright Magenta: \u001b[35;1m
          Bright Cyan: \u001b[36;1m
          Bright White: \u001b[37;1m
          Reset: \u001b[0m
         */
    }

    private function testSQL() {
        $db_port = isset($this->SugarConfig['dbconfig']['db_port']) ? ";port={$this->SugarConfig['dbconfig']['db_port']}" : "";
        $dsn = "mysql:host={$this->SugarConfig['dbconfig']['db_host_name']};dbname={$this->SugarConfig['dbconfig']['db_name']}{$db_port}";
        try {
            $this->PDO = new PDO($dsn, $this->SugarConfig['dbconfig']['db_user_name'], $this->SugarConfig['dbconfig']['db_password']);
            $stmt = $this->PDO->query('SELECT @@version');
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            echo 'Database connection failed: ' . $e->getMessage(), PHP_EOL;
            $this->PDO = false;
        }
    }

    private function getReadOnlyPDO() {
        if ($this->ReadOnlyPDO instanceof PDO) {
            return $this->ReadOnlyPDO;
        }

        foreach (array('reports', 'listviews') as $ConnectionName) {
            $Config = $this->SugarConfig['db'][$ConnectionName] ?? array();
            $RequiredKeys = array('db_host_name', 'db_user_name', 'db_password', 'db_name');
            $IsComplete = true;
            foreach ($RequiredKeys as $RequiredKey) {
                if (!isset($Config[$RequiredKey]) || trim((string) $Config[$RequiredKey]) === '') {
                    $IsComplete = false;
                    break;
                }
            }
            if (!$IsComplete) {
                continue;
            }

            $Port = isset($Config['db_port']) && trim((string) $Config['db_port']) !== ''
                ? ';port=' . trim((string) $Config['db_port'])
                : '';
            $Dsn = "mysql:host={$Config['db_host_name']};dbname={$Config['db_name']}{$Port};charset=utf8mb4";
            $Options = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            );
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $Options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            }

            try {
                $PDO = new PDO($Dsn, $Config['db_user_name'], $Config['db_password'], $Options);
                $PDO->query('SELECT 1')->fetchColumn();
                $this->ReadOnlyPDO = $PDO;
                $this->ReadOnlyConnectionName = $ConnectionName;
                return $this->ReadOnlyPDO;
            } catch (PDOException $Exception) {
                $this->echoc("Unable to connect using the {$ConnectionName} database configuration.\n", 'yellow');
            }
        }

        return false;
    }

    private function generateMessage() {
        echo "Hello {$this->Details['Recipients']},

Failed Health Check 
We use a Health Check wizard to evaluate whether an instance is suitable for an upgrade. During the health check, various issues may be detected that can affect an instance's ability to upgrade. 

Recently, in preparation for upcoming upgrades, 's instance  failed the health check with the following error:

Failed Upgrade
A recent attempt to upgrade {$this->Details['AccountName']}'s instance(s) {$this->Details['InstanceNames']} has failed with the following issue(s):

{$this->Details['Issues']}


The following file(s) appear to be causing the issue(s).

{$this->Details['Files']}

Related Support Article
{$this->Details['LinkToHelpArticle']}

For us to solve this problem, we need your help.  

The file appears to have been installed on {$this->Details['PackageDate']} as part of the {$this->Details['PackageName']} package. Can you contact {$this->Details['PackageAuthor']} and ask them to update the package to resolve the issue?

Details from the package manifest
{$this->Details['PackageManifestDetails']}

Thanks for helping us out with this,
{$this->Details['Sender']}\n\n ";
    }

    private function removeForkedCoreModules() {
        $Continue = $this->ask("Running this will remove any directory in the shadow copy moudules directory that is also in the template modules folder. It will create a backup first. Type 'yes' to continue.");
        if ($Continue != 'yes') {
            $this->echoc("NOT removing forked modules\n", 'red');
            $this->ask('Press enter to continue');
            return;
        }
        $this->backupCustomAndModulesToCloudSupport();
        $this->echoc("Before continuing please confirm that the backup file was created above and that it has data. i.e., it is not empty.\n", 'label');
        $Continue = $this->ask('Type "continue" and press enter to continue.');
        if ($Continue != 'continue') {
            $this->echoc("**** Cancelling, forked files and directories will not be removed. ****\n", 'red');
            $this->ask("Press enter to continue");
            return;
        }

        $Cmd2 = "cd {$this->InstanceInfo['TEMPLATE']}modules";
        $this->echoc("Switching to the template modules folder . . .\n", 'label');
        $this->echoc($Cmd2 . PHP_EOL, 'command');
        chdir("{$this->InstanceInfo['TEMPLATE']}/modules");
////        system($Cmd2);

        $Cmd3 = "ls -d1 */ > ~/core_modules.txt";
        $this->echoc("Generating a list of core modules . . .\n", 'label');
        $this->echoc($Cmd3 . PHP_EOL, 'command');
        system($Cmd3);

        $Cmd4 = "cd ~/modules/";
        $this->echoc("Switching to the shadow modules folder . . .\n", 'label');
        $this->echoc($Cmd4 . PHP_EOL, 'command');
        chdir("{$this->InstanceInfo['SHADOW']}/modules/");
//        system($Cmd4);

        $Cmd5 = "cat ../core_modules.txt | xargs rm -rv 2> /dev/null | tee ../cloud_support/forked_modules_removed_$(date +%Y-%m-%d_%H-%M).log";
        $this->echoc("Removing any modules on the list of core modules . . .\n", 'label');
        $this->echoc($Cmd5 . PHP_EOL, 'command');
        system($Cmd5);

        $Cmd6 = "cd ~";
        $this->echoc("Switching back to the shadow folder . . .\n", 'label');
        $this->echoc($Cmd6 . PHP_EOL, 'command');
        chdir("{$this->InstanceInfo['SHADOW']}");
//        system($Cmd6);

        $this->echoc("Forked Core Modules have been removed.", 'label');
        
        $this->cleanupPreSugar14Files();
        
        $RemoveCustomerJourney = $this->ask("If you like to also Manually Remove Customer Journey files please enter 'yes'\n");
        if ($RemoveCustomerJourney === 'yes') {
            $this->manuallyRemoveCustomerJourney(true);
        } else {
            $this->ask("Press enter to continue\n");
        }
    }

    private function backupCustomAndModulesFolders() {
        $this->backupCustomAndModulesToCloudSupport();
        $this->ShowMenu = false;
    }

    private function cleanupPreSugar14Files(): void {
        $this->echoc("Cleaning up files from versions < Sugar 14\n", 'label');
        $LogFile = $this->createSugarutilsCleanupLog('pre_sugar_14_files');
        $Paths = [
            'include/FCKeditor/editor/filemanager/browser/default/connectors/php/config.php',
            'include/FCKeditor/editor/filemanager/upload/php/config.php',
            'include/javascript/tiny_mce/plugins/spellchecker/config.php',
            'include/SubPanel/SubPanelTilesTabs.php',
            'include/SugarObjects/templates/basic/Dashlets/Dashlet/m-n-Dashlet.php',
            'include/SugarObjects/templates/company/config.php',
            'include/tcpdf/config/tcpdf_config.php',
            'modules/Administration/System.php',
            'modules/Administration/views/view.themesettings.php',
            'modules/Connectors/connectors/sources/ext/rest/dnb/config.php',
            'modules/Connectors/connectors/sources/ext/rest/linkedin/config.php',
            'modules/Connectors/connectors/sources/ext/rest/zoominfocompany/config.php',
            'modules/Connectors/connectors/sources/ext/rest/zoominfoperson/config.php',
            'modules/Connectors/connectors/sources/ext/soap/hoovers/config.php',
            'modules/DCEClients/dce_config.php',
            'modules/disabled/',
            'modules/Disabled/',
            'modules/EditCustomFields/',
            'modules/EmailMan/config.php',
            'modules/Emails/views/view.classic.config.php',
            'modules/Feeds/Feed.php',
            'modules/Forecasts/clients/base/layouts/config/config.php',
            'modules/ForecastSchedule/',
            'modules/iFrames/',
            'modules/Import/config.php',
            'modules/Import/ImportMap.php',
            'modules/Import/ImportStep4.php',
            'modules/KBDocumentRevisions/KBDocumentRevision.php',
            'modules/KBDocuments/EditView.php',
            'modules/KBTags/',
            'modules/MergeRecords/MergeRecord.php',
            'modules/Studio/config.php',
            'modules/Studio/wizards/ManageBackups.php',
            'modules/SugarFeed/SugarFeed.php',
            'modules/Sync/config.php',
            'modules/Temp/',
            'modules/temp/',
            'modules/Users/UserSignature.php',
            'portal/include/language/_.lang.php',
            'portal/sugar_version.php',
        ];

        $Removed = $this->removeCleanupPaths('Pre-Sugar 14 cleanup', $Paths, $LogFile);
        if ($Removed > 0) {
            $this->echoc("Pre-Sugar 14 cleanup log: {$LogFile}\n", 'label');
        }
    }

    private function manuallyRemoveCustomerJourney($SkipBackup = false) {
        $Continue = $this->ask('Are you sure you want to delete all of the Customer Journey files from the system? y|N');
        if (strtoupper(substr($Continue, 0, 1)) !== 'Y') {
            return;
        }
        if (!$SkipBackup) {
            $this->backupCustomAndModulesToCloudSupport();
        }

        $this->echoc("Deleting Customer Journey files . . .\n", 'label');

        $LogFile = $this->createSugarutilsCleanupLog('customer_journey');
        $CustomerJourneyPaths = $this->extractRmPathsFromCommands(<<<'TXT'
rm -r custom/Extension/application/Ext/Include/addoptify-customer-journey.php
rm -r custom/Extension/application/Ext/JSGroupings/customerJourneyGroupings.php
rm -r custom/Extension/application/Ext/Language/ar_SA.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/bg_BG.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/ca_ES.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/cs_CZ.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/da_DK.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/de_DE.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/el_EL.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/en_UK.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/en_us.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/es_ES.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/es_LA.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/et_EE.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/fi_FI.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/fr_FR.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/he_IL.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/hr_HR.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/hu_HU.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/it_it.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/ja_JP.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/ko_KR.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/lt_LT.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/lv_LV.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/nb_NO.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/nl_NL.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/pl_PL.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/pt_BR.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/pt_PT.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/ro_RO.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/ru_RU.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/sk_SK.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/sq_AL.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/sr_RS.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/sv_SE.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/th_TH.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/tr_TR.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/uk_UA.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/zh_CN.dri-customer-journey.php
rm -r custom/Extension/application/Ext/Language/zh_TW.dri-customer-journey.php
rm -r custom/Extension/application/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/application/Ext/ScheduledTasks/orderMapping.php
rm -r custom/Extension/application/Ext/Utils/dri-customer-journey.php
rm -r custom/Extension/application/Ext/WirelessModuleRegistry/addoptify-customer-journey.php
rm -r custom/Extension/modules/Accounts/Ext/LogicHooks/denorm_field_hook.php
rm -r custom/Extension/modules/Accounts/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Accounts/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/Accounts/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Accounts/Ext/clients/base/layouts/extra-info/dri-customer-journey.php
rm -r custom/Extension/modules/Accounts/Ext/clients/mobile/layouts/subpanels/customer-journey.php
rm -r custom/Extension/modules/Administration/Ext/Administration/dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/ar_SA.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/bg_BG.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/ca_ES.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/cs_CZ.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/da_DK.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/de_DE.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/el_EL.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/en_UK.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/en_us.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/es_ES.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/es_LA.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/et_EE.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/fi_FI.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/fr_FR.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/he_IL.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/hr_HR.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/hu_HU.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/it_it.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/ja_JP.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/ko_KR.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/lt_LT.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/lv_LV.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/nb_NO.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/nl_NL.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/pl_PL.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/pt_BR.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/pt_PT.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/ro_RO.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/ru_RU.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/sk_SK.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/sq_AL.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/sr_RS.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/sv_SE.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/th_TH.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/tr_TR.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/uk_UA.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/zh_CN.dri_customer_journey_settings.php
rm -r custom/Extension/modules/Administration/Ext/Language/zh_TW.dri_customer_journey_settings.php
rm -r custom/Extension/modules/CJ_Forms/
rm -r custom/Extension/modules/CJ_WebHooks/
rm -r custom/Extension/modules/Calls/Ext/Language/ar_SA.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/bg_BG.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/ca_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/cs_CZ.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/da_DK.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/de_DE.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/el_EL.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/en_UK.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/en_us.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/es_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/es_LA.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/et_EE.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/fi_FI.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/fr_FR.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/he_IL.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/hr_HR.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/hu_HU.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/it_it.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/ja_JP.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/ko_KR.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/lt_LT.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/lv_LV.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/nb_NO.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/nl_NL.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/pl_PL.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/pt_BR.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/pt_PT.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/ro_RO.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/ru_RU.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/sk_SK.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/sq_AL.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/sr_RS.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/sv_SE.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/th_TH.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/tr_TR.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/uk_UA.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/zh_CN.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Language/zh_TW.dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/clients/base/filters/default/dri-customer-journey.php
rm -r custom/Extension/modules/Calls/Ext/clients/base/views/record/dri-customer-journey.php
rm -r custom/Extension/modules/Cases/Ext/LogicHooks/denorm_field_hook.php
rm -r custom/Extension/modules/Cases/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Cases/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/Cases/Ext/Vardefs/denorm_account_name.php
rm -r custom/Extension/modules/Cases/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Cases/Ext/Vardefs/orderMapping.php
rm -r custom/Extension/modules/Cases/Ext/clients/base/layouts/extra-info/dri-customer-journey.php
rm -r custom/Extension/modules/Cases/Ext/clients/base/views/record/dri-customer-journey.php
rm -r custom/Extension/modules/Cases/Ext/clients/mobile/layouts/subpanels/customer-journey.php
rm -r custom/Extension/modules/Contacts/Ext/LogicHooks/denorm_field_hook.php
rm -r custom/Extension/modules/Contacts/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Contacts/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/Contacts/Ext/Vardefs/denorm_account_name.php
rm -r custom/Extension/modules/Contacts/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Contacts/Ext/Vardefs/orderMapping.php
rm -r custom/Extension/modules/Contacts/Ext/clients/base/layouts/extra-info/dri-customer-journey.php
rm -r custom/Extension/modules/Contacts/Ext/clients/mobile/layouts/subpanels/customer-journey.php
rm -r custom/Extension/modules/DRI_SubWorkflow_Templates/
rm -r custom/Extension/modules/DRI_SubWorkflows/
rm -r custom/Extension/modules/DRI_Workflow_Task_Templates/
rm -r custom/Extension/modules/DRI_Workflow_Templates/
rm -r custom/Extension/modules/DRI_Workflows/
rm -r custom/Extension/modules/Dashboards/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/DataPrivacy/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/ForecastManagerWorksheets/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/ForecastWorksheets/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/Leads/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Leads/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Leads/Ext/clients/base/layouts/extra-info/dri-customer-journey.php
rm -r custom/Extension/modules/Leads/Ext/clients/base/layouts/record-dashboard/dri-customer-journey.php
rm -r custom/Extension/modules/Leads/Ext/clients/mobile/layouts/subpanels/customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/ar_SA.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/bg_BG.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/ca_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/cs_CZ.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/da_DK.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/de_DE.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/el_EL.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/en_UK.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/en_us.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/es_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/es_LA.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/et_EE.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/fi_FI.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/fr_FR.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/he_IL.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/hr_HR.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/hu_HU.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/it_it.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/ja_JP.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/ko_KR.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/lt_LT.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/lv_LV.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/nb_NO.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/nl_NL.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/pl_PL.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/pt_BR.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/pt_PT.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/ro_RO.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/ru_RU.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/sk_SK.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/sq_AL.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/sr_RS.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/sv_SE.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/th_TH.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/tr_TR.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/uk_UA.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/zh_CN.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Language/zh_TW.dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/clients/base/filters/default/dri-customer-journey.php
rm -r custom/Extension/modules/Meetings/Ext/clients/base/views/record/dri-customer-journey.php
rm -r custom/Extension/modules/Opportunities/Ext/LogicHooks/denorm_field_hook.php
rm -r custom/Extension/modules/Opportunities/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Opportunities/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/Opportunities/Ext/Vardefs/denorm_account_name.php
rm -r custom/Extension/modules/Opportunities/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Opportunities/Ext/Vardefs/orderMapping.php
rm -r custom/Extension/modules/Opportunities/Ext/clients/base/layouts/extra-info/dri-customer-journey.php
rm -r custom/Extension/modules/Opportunities/Ext/clients/mobile/layouts/subpanels/customer-journey.php
rm -r custom/Extension/modules/RevenueLineItems/Ext/LogicHooks/denorm_field_hook.php
rm -r custom/Extension/modules/RevenueLineItems/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/RevenueLineItems/Ext/Vardefs/denorm_account_name.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/ar_SA.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/bg_BG.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/ca_ES.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/cs_CZ.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/da_DK.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/de_DE.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/el_EL.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/en_UK.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/en_us.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/es_ES.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/es_LA.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/et_EE.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/fi_FI.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/fr_FR.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/he_IL.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/hr_HR.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/hu_HU.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/it_it.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/ja_JP.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/ko_KR.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/lt_LT.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/lv_LV.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/nb_NO.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/nl_NL.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/pl_PL.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/pt_BR.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/pt_PT.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/ro_RO.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/ru_RU.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/sk_SK.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/sq_AL.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/sr_RS.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/sv_SE.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/th_TH.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/tr_TR.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/uk_UA.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/zh_CN.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/Language/zh_TW.update_momentum_cj.php
rm -r custom/Extension/modules/Schedulers/Ext/ScheduledTasks/checkCJPLatestVersion.php
rm -r custom/Extension/modules/Schedulers/Ext/ScheduledTasks/orderMapping.php
rm -r custom/Extension/modules/Schedulers/Ext/ScheduledTasks/updateMomentumCJ.php
rm -r custom/Extension/modules/Tasks/Ext/Language/ar_SA.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/bg_BG.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/ca_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/cs_CZ.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/da_DK.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/de_DE.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/el_EL.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/en_UK.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/en_us.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/es_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/es_LA.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/et_EE.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/fi_FI.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/fr_FR.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/he_IL.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/hr_HR.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/hu_HU.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/it_it.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/ja_JP.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/ko_KR.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/lt_LT.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/lv_LV.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/nb_NO.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/nl_NL.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/pl_PL.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/pt_BR.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/pt_PT.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/ro_RO.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/ru_RU.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/sk_SK.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/sq_AL.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/sr_RS.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/sv_SE.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/th_TH.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/tr_TR.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/uk_UA.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/zh_CN.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Language/zh_TW.dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/LogicHooks/dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/clients/base/filters/default/dri-customer-journey.php
rm -r custom/Extension/modules/Tasks/Ext/clients/base/views/record/dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/ar_SA.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/bg_BG.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/ca_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/cs_CZ.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/da_DK.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/de_DE.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/el_EL.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/en_UK.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/en_us.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/es_ES.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/es_LA.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/et_EE.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/fi_FI.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/fr_FR.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/he_IL.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/hr_HR.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/hu_HU.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/it_it.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/ja_JP.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/ko_KR.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/lt_LT.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/lv_LV.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/nb_NO.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/nl_NL.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/pl_PL.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/pt_BR.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/pt_PT.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/ro_RO.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/ru_RU.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/sk_SK.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/sq_AL.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/sr_RS.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/sv_SE.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/th_TH.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/tr_TR.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/uk_UA.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/zh_CN.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/Language/zh_TW.dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/LogicHooks/orderMapping.php
rm -r custom/Extension/modules/Users/Ext/Vardefs/dri-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/clients/base/filters/basic/addoptify-customer-journey.php
rm -r custom/Extension/modules/Users/Ext/clients/base/filters/default/addoptify-customer-journey.php
rm -r custom/application/Ext/WirelessModuleRegistry/
rm -r custom/clients/base/fields/cj_fieldset_for_date_in_populate_fields/
rm -r custom/clients/base/fields/cj_fieldset_for_date_in_populate_fields/cj_fieldset_for_date_in_populate_fields.js
rm -r custom/clients/base/fields/cj_momentum_bar/
rm -r custom/clients/base/fields/cj_populate_fields/
rm -r custom/clients/base/fields/cj_progress_bar/
rm -r custom/clients/base/fields/cj_select_to/
rm -r custom/clients/base/fields/cj_time/
rm -r custom/clients/base/fields/cj_widget_config_toggle_field/
rm -r custom/clients/base/layouts/dri-workflows-widget-configuration/
rm -r custom/clients/base/layouts/dri-workflows/
rm -r custom/clients/base/views/cj-as-a-dashlet/cj-as-a-dashlet.hbs
rm -r custom/clients/base/views/cj-as-a-dashlet/cj-as-a-dashlet.js
rm -r custom/clients/base/views/cj-as-a-dashlet/cj-as-a-dashlet.php
rm -r custom/clients/base/views/cj-as-a-dashlet/noaccess.hbs
rm -r custom/clients/base/views/cj-webhook-dashlet/cj-webhook-dashlet.hbs
rm -r custom/clients/base/views/cj-webhook-dashlet/cj-webhook-dashlet.js
rm -r custom/clients/base/views/cj-webhook-dashlet/cj-webhook-dashlet.php
rm -r custom/clients/base/views/cj-webhook-dashlet/error.hbs
rm -r custom/clients/base/views/cj-webhook-dashlet/invalid-license.hbs
rm -r custom/clients/base/views/cj-webhook-dashlet/loader.hbs
rm -r custom/clients/base/views/dri-customer-journey-dashlet/
rm -r custom/clients/base/views/dri-license-errors/
rm -r custom/clients/base/views/dri-workflow/
rm -r custom/clients/base/views/dri-workflow/error.hbs
rm -r custom/clients/base/views/dri-workflow/invalid-license.hbs
rm -r custom/clients/base/views/dri-workflows-header/
rm -r custom/clients/base/views/dri-workflows-widget-configuration/
rm -r custom/data/acl/SugarACLCustomerJourney.php
rm -r custom/history/modules/CJ_Forms/
rm -r custom/history/modules/CJ_WebHooks/
rm -r custom/history/modules/DRI_SubWorkflow_Templates/
rm -r custom/history/modules/DRI_SubWorkflows/
rm -r custom/history/modules/DRI_Workflow_Task_Templates/
rm -r custom/history/modules/DRI_Workflow_Templates/
rm -r custom/history/modules/DRI_Workflows/
rm -r custom/include/CustomerJourney/
rm -r custom/include/SugarObjects/implements/customer_journey_parent/
rm -r custom/modules/Accounts/CustomerJourney/
rm -r custom/modules/Accounts/CustomerJourney/EnumManager.php
rm -r custom/modules/CJ_Forms/
rm -r custom/modules/CJ_WebHooks/
rm -r custom/modules/CJ_WebHooks/Ext/
rm -r custom/modules/CJ_WebHooks/Ext/Vardefs/
rm -r custom/modules/CJ_WebHooks/Ext/Vardefs/vardefs.ext.php
rm -r custom/modules/Cases/CustomerJourney/
rm -r custom/modules/Cases/CustomerJourney/EnumManager.php
rm -r custom/modules/Contacts/CustomerJourney/
rm -r custom/modules/DRI_SubWorkflow_Templates/
rm -r custom/modules/DRI_SubWorkflows/
rm -r custom/modules/DRI_Workflow_Task_Templates/
rm -r custom/modules/DRI_Workflow_Templates/
rm -r custom/modules/DRI_Workflows/
rm -r custom/modules/Leads/CustomerJourney/
rm -r custom/modules/Leads/LogicHook/DRICustomerJourney.php
rm -r custom/modules/Opportunities/CustomerJourney/
rm -r custom/modules/Users/clients/base/views/customer-journey-config-users/
rm -r custom/src/CustomerJourney/
rm -r custom/themes/default/images/CJ_Forms_32.png
rm -r custom/themes/default/images/CJ_WebHooks_32.png
rm -r custom/themes/default/images/DRI_SubWorkflow_Templates.gif
rm -r custom/themes/default/images/DRI_SubWorkflow_Templates_32.png
rm -r custom/themes/default/images/DRI_SubWorkflows_32.png
rm -r custom/themes/default/images/DRI_Workflow_Task_Templates.gif
rm -r custom/themes/default/images/DRI_Workflow_Task_Templates_32.png
rm -r custom/themes/default/images/DRI_Workflow_Templates.gif
rm -r custom/themes/default/images/DRI_Workflow_Templates_32.png
rm -r custom/themes/default/images/DRI_Workflows_32.png
rm -r custom/themes/default/images/customer_journey_configure_modules.png
rm -r custom/themes/default/images/customer_journey_configure_record_view_display.png
rm -r custom/themes/default/images/customer_journey_plugin_update.png
rm -r custom/themes/default/images/customer_journey_settings.png
rm -r custom/themes/default/images/customer_journey_workflow_templates.png
rm -r custom/themes/default/less/dri-customer-journey.less
rm -r modules/CJ_Forms/
rm -r modules/CJ_WebHooks/
rm -r modules/DRI_SubWorkflow_Templates/
rm -r modules/DRI_SubWorkflows/
rm -r modules/DRI_Workflow_Task_Templates/
rm -r modules/DRI_Workflow_Templates/
rm -r modules/DRI_Workflows/
TXT);
        $CustomerJourneyPaths = array_merge(
            $CustomerJourneyPaths,
            $this->findCleanupPaths('custom', '*customer-journey*'),
            $this->findCleanupPaths('custom', 'DRI_*'),
            $this->findCleanupPaths('custom', 'CJ_*')
        );

        $Removed = $this->removeCleanupPaths('Customer Journey cleanup', $CustomerJourneyPaths, $LogFile);

        $CustomFiles = [];
        if (is_dir('custom')) {
            $Iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator('custom', FilesystemIterator::SKIP_DOTS)
            );
            foreach ($Iterator as $Item) {
                if ($Item->isFile()) {
                    $CustomFiles[] = $Item->getPathname();
                }
            }
        }

        $ReferenceMatches = 0;
        $ReferenceMatches += $this->logMatchingLines('Customer Journey customer_journey reference scan', $CustomFiles, 'customer_journey', $LogFile, true);
        $ReferenceMatches += $this->logMatchingLines('Customer Journey cj_/dri_ module registry scan', ['custom/application/Ext/Include/modules.ext.php'], 'cj_', $LogFile, true);
        $ReferenceMatches += $this->logMatchingLines('Customer Journey dri_ module registry scan', ['custom/application/Ext/Include/modules.ext.php'], 'dri_', $LogFile, true);

        if ($Removed > 0 && $ReferenceMatches === 0) {
            $this->displayAndLogCleanupLine($LogFile, 'Customer Journey reference scan: All\'s Well. No lingering references found.', 'green');
        }

        if ($Removed > 0 || $ReferenceMatches > 0) {
            $this->echoc("Customer Journey cleanup log: {$LogFile}\n", 'label');
        }
        exit();
    }

    private function scanForFontAwesomeIcons() {
        $this->echoc("Scanning for Font Awesome icon use summary . . .\n", 'label');
        $CMD1 = "grep -rF \" 'icon' => 'fa-\" | awk --field-separator \":\" '{print $2}' | sed -e 's/^[ \t]*//' |  sort | uniq -c";
        $this->echoc($CMD1 . PHP_EOL, 'command');
        system($CMD1);
        $ShowDetails = $this->ask("Would you like to scan for the deailed uses? Y/n");
        if (strtoupper(substr($ShowDetails, 0, 1)) != 'N') {
            $this->echoc("Scanning for Font Awesome icon deailed uses . . .\n", 'label');
            $CMD2 = "grep -rF \" 'icon' => 'fa-\" ";
            $this->echoc($CMD2 . PHP_EOL, 'command');
            system($CMD2);
        }
    }

    private function askYN($RequestText, $Default = 'N') {
        echo "\n{$RequestText}\n";
//        return trim(fgets(STDIN)); // reads one line from STDIN  
        $Prompt = $Default === 'N' ? 'y/N: ' : 'Y/n: ';
        $Answer = strtoupper(substr(readline($Prompt), 0, 1));
        if (!in_array($Answer, array('Y', 'N'))) {
            $Answer = $Default;
        }
        return $Answer === 'Y';
    }

    private function askYes($RequestText) {
        echo "\n{$RequestText}\n";
        $Answer = readline('yes/NO: ');
        return $Answer === 'yes';
    }

}

class Utils {

    public static function getUsername() {
        return $_SERVER['REMOTE_USER'];
    }

    public static function getSugarAccountID(object $Case) {
        if ($Case->accounts_cases_1->account_type === 'Customer') {
            return $Case->accounts_cases_1->id;
        } else {
            return $Case->account_id;
        }
    }

    public static function getSugarPartnerID(object $Case) {
        if ($Case->accounts_cases_1->account_type === 'Partner') {
            return $Case->accounts_cases_1->id;
        } else {
            return $Case->account_id;
        }
    }

    public static function getSugarAccountName(object $Case) {
        if ($Case['accounts_cases_1']['account_type'] === 'Customer') {
            return $Case['accounts_cases_1']['name'];
        } else {
            return $Case['account_name'];
        }
    }

    public static function getSugarPartnerName(object $Case) {
        if ($Case->accounts_cases_1->account_type === 'Partner') {
            return $Case->accounts_cases_1->name;
        } else {
            return $Case->account_name;
        }
    }

    public static function getTemworkProjectUrlFromSugarCase($Case) {
        foreach (explode("\n", $Case->description) as $Line) {
            if (strpos($Line, 'Teamwork Project:') !== false) {
                return trim(str_replace('Teamwork Project:', '', $Line));
            }
        }
        return '';
    }

    public static function getSugarCaseIdFromTeamworkProject($Project) {
        foreach (explode("\n", $Project['description']) as $Line) {
            if (strpos($Line, 'SugarCaseID:') !== false) {
                return trim(str_replace('SugarCaseID:', '', $Line));
            }
        }
        return '';
    }

    public static function getLocalDateTimeString($DateTime, $IncludeDateDif = true) {
        if ($DateTime === null) {
            return '';
        }
        $Settings = json_decode(file_get_contents('/etc/sugartoolsconfig.json'), true);
        $Username = Utils::getUsername();
        $LocalTimeZone = $Settings["TimeZone_{$Username}"];
        if (!$LocalTimeZone) {
            $LocalTimeZone = 'America/Denver';
        }
        $LocalDateTime = new DateTime($DateTime);
        $LocalDateTime->setTimezone(new DateTimeZone($LocalTimeZone));
//            $LocalDate = date('l, Y-m-d H:i T', $LocalDateTime->setTimezone(new DateTimeZone('America/New_York')));
//            $LocalDate = $LocalDateTime->format('Y-m-d H:i:sP')->setTimezone(new DateTimeZone('America/New_York'));
        $LocalDate = $LocalDateTime->format('l, Y-m-d H:i T');
        $Date = date('l, Y-m-d H:i T', strtotime($DateTime));
        $DaysSince = Utils::getDaysSince($DateTime);
        return "{$Date}<br>{$LocalDate}<br><b>({$DaysSince} days)</b>";
    }

    public static function getDaysSince($Date) {
        $DueDate = new DateTime($Date);
        $Today = new DateTime();
        $Interval = $Today->diff($DueDate);
        return $Interval->days; // Output: Number of days between today and the target date
    }
    
    public static function print_t(array $Data) {
        if (empty($Data)) {
            echo "No data to display." . PHP_EOL;
            return;
        }

        // Determine column widths
        $columnWidths = [];
        foreach ($Data as $row) {
            foreach ($row as $key => $value) {
                $columnWidths[$key] = max($columnWidths[$key] ?? 0, strlen($key), strlen($value));
            }
        }
        
        $separatorRow = "+";
        foreach ($columnWidths as $width) {
            $separatorRow .= str_repeat("-", $width + 2) . "+";
        }
//        echo $separatorRow . PHP_EOL;
        self::echoc($separatorRow . PHP_EOL, 'border');

        // Output headers
        $headerRow = "|";
        self::echoc("|", 'border');
        foreach ($columnWidths as $header => $width) {
            $headerRow .= " " . str_pad($header, $width) . " |";
            self::echoc(" " . str_pad($header, $width), 'label');
            self::echoc(" |", 'border');
        }
        echo PHP_EOL;

        // Output separator
        self::echoc($separatorRow . PHP_EOL, 'border');

        // Output data rows
        foreach ($Data as $row) {
//            $dataRow = "|";
            self::echoc("|", 'border');
            foreach ($columnWidths as $key => $width) {
//                $dataRow .= " " . str_pad($row[$key] ?? '', $width) . " |";
                self::echoc( " " . str_pad($row[$key] ?? '', $width), 'data');
                self::echoc( " |", 'border');
            }
            echo PHP_EOL;
        }

        // Output footer separator
        self::echoc($separatorRow . PHP_EOL, 'border');
    }

    public static function print_rc($Array) {
        $Output = str_replace("]", "\033[0m]", str_replace('[', "[\033[33m", print_r($Array, true)));
        $Output = str_replace('=>', "\033[31m=>\033[36m", $Output);
        $Output = str_replace("\n", "\033[0m\n", $Output);
        echo $Output . PHP_EOL;
    }
    
//public static function getDaysSince($Date) {
//        $DueDate = new DateTime($Date);
//        $Today = new DateTime();
//        $Interval = $Today->diff($DueDate);
//        return $Interval->days; // Output: Number of days between today and the target date
//    }
//    
    public static function ask($RequestText) {
        self::echoc("\n{$RequestText}\n", 'question');
//        return trim(fgets(STDIN)); // reads one line from STDIN            
        return readline('>');            
    }
    
    public static function askYes($RequestText, $Prompt = 'yes/NO>') {
        echo "\n{$RequestText}\n";
//        return trim(fgets(STDIN)); // reads one line from STDIN  
        $Answer = strtoupper(readline($Prompt));
        return $Answer === 'YES';
    }

    public static function pressEnterToContinue() {
        echo "\nPress Enter to continue\n";
        readline('Enter>');
    }
    
    
    
    public static function echoc($String, $Color) {
        switch ($Color) {
            case 'red':
            case 'failure':
            case 'bad':
                echo "\033[31m{$String}\033[0m";
                break;

            case 'green':
            case 'success':
            case 'good':
            case 'ok':
                echo "\033[36m{$String}\033[0m";
                break;

            case 'yellow':
            case 'label':
                echo "\033[33m{$String}\033[0m";
                break;

            case 'blue':
            case 'link':
            case 'url':
                echo "\033[34m{$String}\033[0m";
                break;

            case 'magenta':
            case 'command':
                echo "\033[35m{$String}\033[0m";
                break;

            case 'cyan':
            case 'data':
                echo "\033[36m{$String}\033[0m";
                break;

            case 'white':
                echo "\033[37m{$String}\033[0m";
                break;

            case 'brightred':
                echo "\033[31;1m{$String}\033[0m";
                break;

            case 'brightgreen':
                echo "\033[36;1m{$String}\033[0m";
                break;

            case 'brightyellow':
                echo "\033[33;1m{$String}\033[0m";
                break;

            case 'brightblue':
            case 'question':
                echo "\033[34;1m{$String}\033[0m";
                break;

            case 'brightmagenta':
            case 'border':
                echo "\033[35;1m{$String}\033[0m";
                break;

            case 'brightcyan':
                echo "\033[36;1m{$String}\033[0m";
                break;

            case 'brightwhite':
                echo "\033[37;1m{$String}\033[0m";
                break;

            default:
                echo "\033[37m{$String}\033[0m";
                break;
        }
        /*  Black: \u001b[30m
          Red: \u001b[31m
          Green: \u001b[32m
          Yellow: \u001b[33m
          Blue: \u001b[34m
          Magenta: \u001b[35m
          Cyan: \u001b[36m
          White: \u001b[37m
          Reset: \u001b[0m
          Bright Black: \u001b[30;1m
          Bright Red: \u001b[31;1m
          Bright Green: \u001b[32;1m
          Bright Yellow: \u001b[33;1m
          Bright Blue: \u001b[34;1m
          Bright Magenta: \u001b[35;1m
          Bright Cyan: \u001b[36;1m
          Bright White: \u001b[37;1m
          Reset: \u001b[0m
         */
    }

}

$sugarutils = new sugarutils();
$sugarutils->run();
