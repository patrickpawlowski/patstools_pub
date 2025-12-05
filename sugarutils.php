#! /usr/bin/env php
<?php
/*
 * 
  wget https://wupgrade.wsysnet.com/patstools/sugarutils.php -O /usr/local/bin/sugarutils; chmod +xr /usr/local/bin/sugarutils
  wget https://wupgrade.wsysnet.com/patstools/sugarutils.php -O sugarutils; chmod +xr sugarutils; ./sugarutils
 * 
  curl https://wupgrade.wsysnet.com/patstools/sugarutils.php | php
 * 
 * 
 * 
wget -q https://wsugardev1.w-systems.com/patstools/sugarutils -O sugarutils; php ./sugarutils
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
[ ] Add Shawn's Email Configuration Safety
delete from outbound_email;
DELETE FROM config WHERE category = 'notify';
INSERT INTO `config` (`category`, `name`, `value`, `platform`)
VALUES
    ('notify', 'allow_default_outbound', '2', ''),
    ('notify', 'fromaddress', 'do_not_reply@example.com', NULL),
    ('notify', 'fromname', 'SugarCRM', NULL),
    ('notify', 'on', '1', NULL),
    ('notify', 'send_by_default', '1', NULL),
    ('notify', 'send_from_assigning_user', '0', NULL);
INSERT INTO `outbound_email` (`id`, `eapm_id`, `name`, `type`, `user_id`, `email_address_id`, `authorized_account`, `mail_authtype`, `reply_to_name`, `reply_to_email_address_id`, `mail_sendtype`, `mail_smtptype`, `mail_smtpserver`, `mail_smtpport`, `mail_smtpuser`, `mail_smtppass`, `mail_smtpauth_req`, `mail_smtpssl`, `preferred_sending_account`, `deleted`, `team_id`, `team_set_id`, `acl_team_set_id`)
VALUES
    ('e9c761a8-4d8e-11ee-9c8c-0684a87b501c', NULL, 'SugarCRMSupport', 'system', '1', '858904f6-56ff-11ee-9d93-02a5a97c2d5e', NULL, NULL, NULL, NULL, 'SMTP', 'other', 'sandbox.smtp.mailtrap.io', 587, '23d76f6eeeee9d47c', 'gal2WeeeeeZgjjZPmbA==', 1, 2, 0, 0, '1', '1', NULL),
    ('ea35b2de-4d8e-11ee-8976-0684a87b501c', NULL, 'Administrator', 'system-override', '1', 'ea6039f0-4d8e-11ee-a253-0684a87b501c', NULL, NULL, NULL, NULL, 'SMTP', 'other', 'sandbox.smtp.mailtrap.io', 587, '23d76eeef6ec59d47c', 'eeeee+ebsfOe6bb', 1, 2, 0, 0, 'ea2a4afc-4d8e-11ee-bca9-0684a87b501c', 'ea2a4afc-4d8e-11ee-bca9-0684a87b501c', NULL);
 * 
 * 
 * 
 */

class sugarutils {

    private $Menu = "sc = Search Custom Folder\nsu = Search Upgrades\nsd = StartDiscovery\ngm = Generate Message\nlu = List Users\nflf = Find Large Files\nq = Quit";
    private $Defaults = array("Recipients", "AccountName", "InstanceNames", "Issues", "Files", "LinkToHelpArticle", "PackageDate", "PackageName", "PackageAuthor", "PackageManifestDetails", "Sender");
    private $Details = array();
    private $Options;
    private $SugarConfig;
    private $PDO = false;
    private $InstanceInfo = array();
    private $Subscription = array();
    private $ShowMenu = true;
    
    const DATE_CMU = 'Y-m-d H:i T';
    const DATE_CMU_SECONDS = 'Y-m-d H:i:s T';

    public function __construct() {
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
        exec("rm sugarutils");
    }
    
    private function backupConfigs() {
        $Filename = "configs_" . date('YmdHmis') . ".zip";
        $Command = "zip cloud_support/{$Filename} config*.php";
        $this->echoc("Backing up config files . . .\n", 'label');
        $this->echoc($Command.PHP_EOL, 'command');
        system($Command);
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
    }

    private function displayMenu() {
//        system('clear');
//        $Option = false;
        $LabelWidth = 40;
        $CommandWidth = 6;
        $TotalWidth = ($LabelWidth + $CommandWidth) * 4;
        while (true) {
            if ($this->ShowMenu) {
                $this->echoc(str_pad("---<=== Sugar Utilities ===>---", $TotalWidth, ' ', STR_PAD_BOTH), 'brightblue');

                echo PHP_EOL . PHP_EOL;
                $this->displayInfo();

                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("---<=== Commands ===>---", $TotalWidth, '-', STR_PAD_BOTH) . str_repeat(PHP_EOL, 1), 'brightblue');

                $this->echoc(str_pad('sc', $CommandWidth), 'data');
                $this->echoc(str_pad('Search custom Folder', $LabelWidth), 'label');

                $this->echoc(str_pad('sdl', $CommandWidth), 'data');
                $this->echoc(str_pad("Search Dropdown List", $LabelWidth), 'label');

                $this->echoc(str_pad('su', $CommandWidth), 'data');
                $this->echoc(str_pad('Search upgrades/module Folder', $LabelWidth), 'label');

                $this->echoc(str_pad('suz', $CommandWidth), 'data');
                $this->echoc(str_pad('Search upgrades/module Folder zipz', $LabelWidth), 'label');

                echo PHP_EOL;
                $this->echoc(str_pad('scu', $CommandWidth), 'data');
                $this->echoc(str_pad("Sugar Checkup", $LabelWidth), 'label');

                $this->echoc(str_pad('pm', $CommandWidth), 'data');
                $this->echoc(str_pad("Parse Manifest", $LabelWidth), 'label');

                $this->echoc(str_pad('dnu', $CommandWidth), 'data');
                $this->echoc(str_pad("Deactivate Non-admin Users", $LabelWidth), 'label');

                echo PHP_EOL;
                $this->echoc(str_pad('sd', $CommandWidth), 'data');
                $this->echoc(str_pad("Start Discovery", $LabelWidth), 'label');

                $this->echoc(str_pad('gm', $CommandWidth), 'data');
                $this->echoc(str_pad('Generate Message', $LabelWidth), 'label');

                $this->echoc(str_pad('lu', $CommandWidth), 'data');
                $this->echoc(str_pad('List Users', $LabelWidth), 'label');

                $this->echoc(str_pad('cftsq', $CommandWidth), 'data');
                $this->echoc(str_pad('Check FTS Queue', $LabelWidth), 'label');

                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("---<=== Section ===>---", $TotalWidth, '-', STR_PAD_RIGHT) . str_repeat(PHP_EOL, 1), 'brightblue');

                $this->echoc(str_pad('lau', $CommandWidth), 'data');
                $this->echoc(str_pad("List Admin Users", $LabelWidth), 'label');

                $this->echoc(str_pad('flf', $CommandWidth), 'data');
                $this->echoc(str_pad('Find Large Files', $LabelWidth), 'label');

                $this->echoc(str_pad('ljbs', $CommandWidth), 'data');
                $this->echoc(str_pad('List Jobs by Status', $LabelWidth), 'label');

                $this->echoc(str_pad('rdis', $CommandWidth), 'data');
                $this->echoc(str_pad('Run Data Integrity Scripts', $LabelWidth), 'label');

                echo PHP_EOL;
                $this->echoc(str_pad('mrcj', $CommandWidth), 'data');
                $this->echoc(str_pad('Manually Remove Customer Journey', $LabelWidth), 'label');

                $this->echoc(str_pad('spl', $CommandWidth), 'data');
                $this->echoc(str_pad('Show Process List', $LabelWidth), 'label');

                $this->echoc(str_pad('rfcm', $CommandWidth), 'data');
                $this->echoc(str_pad('Remove Forked Core Modules', $LabelWidth), 'label');

                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("Section ===>---", $TotalWidth, '-', STR_PAD_RIGHT) . str_repeat(PHP_EOL, 1), 'brightblue');

                $this->echoc(str_pad('bcmf', $CommandWidth), 'data');
                $this->echoc(str_pad('Backup Custom and Modules Folders', $LabelWidth), 'label');

                $this->echoc(str_pad('suh', $CommandWidth), 'data');
                $this->echoc(str_pad('Show Upgrade History', $LabelWidth), 'label');

                $this->echoc(str_pad('huh', $CommandWidth), 'data');
                $this->echoc(str_pad('Hide Upgrade History', $LabelWidth), 'label');

                $this->echoc(str_pad('uuh', $CommandWidth), 'data');
                $this->echoc(str_pad('Unhide Upgrade History', $LabelWidth), 'label');

                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("---<=== Section ===>---", $TotalWidth, '-', STR_PAD_BOTH) . str_repeat(PHP_EOL, 1), 'brightblue');

                $this->echoc(str_pad('cc', $CommandWidth), 'data');
                $this->echoc(str_pad('Check Collation', $LabelWidth), 'label');

                $this->echoc(str_pad('fc', $CommandWidth), 'data');
                $this->echoc(str_pad('Fix Collation', $LabelWidth), 'label');

                $this->echoc(str_pad('ps', $CommandWidth), 'data');
                $this->echoc(str_pad('Package Scan', $LabelWidth), 'label');

                $this->echoc(str_pad('hc', $CommandWidth), 'data');
                $this->echoc(str_pad('Health Check', $LabelWidth), 'label');

                echo PHP_EOL;

                $this->echoc(str_pad('qrr', $CommandWidth), 'data');
                $this->echoc(str_pad('Quick Repair and Rebuild', $LabelWidth), 'label');

                $this->echoc(str_pad('fa', $CommandWidth), 'data');
                $this->echoc(str_pad('Scan for Font Awesome uses', $LabelWidth), 'label');

                $this->echoc(str_pad('wt', $CommandWidth), 'data');
                $this->echoc(str_pad('What\'s this', $LabelWidth), 'label');

                $this->echoc(str_pad('mu', $CommandWidth), 'data');
                $this->echoc(str_pad('Migrate Uploads', $LabelWidth), 'label');

                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("---<=== Section ===>---", $TotalWidth, '-', STR_PAD_BOTH) . str_repeat(PHP_EOL, 1), 'brightblue');
                $this->echoc(str_pad('ci', $CommandWidth), 'data');
                $this->echoc(str_pad('Check Imports', $LabelWidth), 'label');
                
                $this->echoc(str_pad('sm', $CommandWidth), 'data');
                $this->echoc(str_pad('Search Manifests', $LabelWidth), 'label');
                
                $this->echoc(str_pad('ws', $CommandWidth), 'data');
                $this->echoc(str_pad('Watch SQL', $LabelWidth), 'label');
                
                $this->echoc(str_pad('dbms', $CommandWidth), 'data');
                $this->echoc(str_pad('Database Manage Space', $LabelWidth), 'label');
                
                $this->echoc(str_repeat(PHP_EOL, 2) . str_pad("---<=== Special Issues ===>---", $TotalWidth, '-', STR_PAD_BOTH) . str_repeat(PHP_EOL, 1), 'brightblue');
                $this->echoc("cfi95922 ", 'data');
                $this->echoc("[SugarBPM] Using Relationship Change in Start Event results in BPM being triggered despite no relationship change\n", 'label');
                
                echo PHP_EOL;
                $this->echoc("cfi95840 ", 'data');
                $this->echoc("95840 Changing to Opportunities only and then clicking save in Navigation Bar and Subpanels breaks the recordview of every module in which RLI appeared\n", 'label');
                
                echo PHP_EOL;
                $this->echoc(str_pad('q', $CommandWidth), 'data');
                $this->echoc(str_pad('Quit', $LabelWidth), 'label');
                echo PHP_EOL;
                $this->displayWarnings();
                $Command = $this->ask("Enter Command: ");
            } else {
                $this->displayWarnings();
                $Command = $this->ask("Enter Command or press enter to display the menu: ");
            }

            $this->ShowMenu = true;

            $Option = strtolower(explode(' ', $Command)[0]);
//            $this->echoc($Option.PHP_EOL, 'red');
            switch ($Option) {
                case 'sc':
                    $this->searchCustomFolder($Command);
                    break;

                case 'sdl':
                    $this->searchDropdownLists($Command);
                    break;

                case 'su':
                    $this->searchUpgradesFolder($Command);
                    break;

                case 'suz':
                    $this->searchPackagesForString($Command);
                    break;

                case 'pm':
                    $this->parseManifest($Command);
                    break;

                case 'sd':
                    $this->startDiscovery();
                    break;

                case 'gm':
                    $this->generateMessage();
                    break;

                case 'lu':
                    $this->listUsers();
                    break;

                case 'lau':
                    $this->getAdminUsers();
                    break;

                case 'flf':
                    $this->findLargeFiles();
                    break;

                case 'ljbs':
                    $this->listJobsByStatus();
                    break;

                case 'mrcj':
                    $this->manuallyRemoveCustomerJourney();
                    break;

                case 'rfcm':
                    $this->removeForkedCoreModules();
                    break;

                case 'spl':
                    $this->showProcessList();
                    break;

                case 'bcmf':
                    $this->backupCustomAndModulesFolders();
                    break;

                case 'suh':
                    $this->showUpgradeHistory();
                    break;

                case 'huh':
                    $this->hideUpgradeHistory();
                    break;

                case 'uuh':
                    $this->unhideUpgradeHistory();
                    break;

                case 'cc':
                    $this->checkCollation();
                    break;

                case 'fc':
                    $this->fixCollation();
                    break;

                case 'ps':
                    $this->packageScan();
                    break;

                case 'hc':
                    $this->runHealthCheck();
                    break;

                case 'wt':
                    $this->whatsThis();
                    break;

                case 'cftsq':
                    $this->checkFTSQueue();
                    break;

                case 'rdis':
                    $this->runDataIntegrityScripts();
                    break;

                case 'qrr':
                    $this->runQuickRepairandRebuild();
                    break;

                case 'fa':
                    $this->scanForFontAwesomeIcons();
                    break;

                case 'dnu':
                    $this->deactivateNonAdminUsers();
                    break;

                case 'mu':
                    $this->migrateUploads();
                    break;

                case 'ci':
                    $this->checkImports();
                    break;

                case 'ws':
                    $this->watchSQL($Command);
                    break;

                case 'scu':
                    $this->runSugarCheckup($Command);
                    break;

                case 'sm':
                    $this->searchManifests($Command);
                    break;

                case 'dbms':
                    $this->dbManageSpace($Command);
                    break;

                case 'cfi95922':
                    $this->checkForIssue95922();
                    break;

                case 'cfi95830':
                    $this->checkForIssue95830();
                    break;

                case 'q':
                case 'exit':
                    exit();
                    break;

                default:
                    $this->echoc("Command '{$Command}' not found!\n", 'red');
                    $Command = false;
                    break;
            }
        }
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
        
        $this->ShowMenu = false;
    }
    
    private function dbManageSpace() {
        $this->echoc("{$this->InstanceInfo['INSTANCE']}_database_analysis.md\n\n", 'data');
        $this->echoc("\n#### Entire Database\n", 'label');
        $this->echoc("This is the base size of the entire database. \n", 'data');
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
        $this->echoc("These are all the tables that are .9 GB and larger\n", 'data');
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
     round(((data_length + index_length) / 1024 / 1024 / 1024 / {$DatabaseSize}) * 100, 2) `Percentage`
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
        $SQL = "SELECT 
     round((SUM(data_length + index_length) / 1024 / 1024 / 1024), 4) `Size in GB`,
     round(((data_length + index_length) / 1024 / 1024 / 1024 / {$DatabaseSize}) * 100, 2) `Percentage`
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
    
    private function searchManifests($Command) {
        $SearchString = trim(substr($Command, 2));
        utils::echoc("Searching manifests in upgrade_history for '{$SearchString}' . . . \n", 'label');
        $SQL = "SELECT * FROM upgrade_history ORDER BY date_modified DESC;";
        foreach($this->PDO->query($SQL) as $Row){
            $this->echoc("{$Row['name']} v{$Row['version']}\n", 'data');
            $Manifest = json_encode(unserialize(base64_decode($Row['manifest'])), JSON_PRETTY_PRINT);
            if(substr_count(strtoupper($Manifest), strtoupper($SearchString))){
                $this->echoc("Manifest:\n", 'label');
                $this->echoc($Manifest, 'data');
            }
        }
        $this->ShowMenu = false;
    }    
    private function checkForEnumFieldsMissingList($Command) {
        utils::echoc("Checking fields_meta_data for enum fields missing lists . . . ", 'label');
        $SQL = "SELECT * FROM fields_meta_data WHERE TYPE LIKE '%enum%' AND (ifnull(ext1, '') = '');";
        $Result = $this->PDO->query($SQL);
        $Rows = $Result->fetch(PDO::FETCH_ASSOC);
        if(count($Rows)){
            utils::print_rc(" 🛑\n");
            utils::print_rc($Rows);
        }else{
            utils::print_rc(" ✅\n");
        }
    }
    
    private function watchSQL($Command) {
//        $this->echoc("[Users]\n\n", 'brightblue');
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SQL = trim(implode(' ', $CommandArray));
        if (!$SQL) {
            $SQL = $this->ask("Query to watch: ");
        }

        $SQL2 = $this->ask("If you would also like to watch a secoond query then please enter it here");
        $Interval = $this->ask("Enter the number of seconds to wait before rerunning the command. The default is 120 ");
        $Interval = $Interval ? $Interval : 120;
//        if($this->askYN("Would like to set up monitoring?")){
//            echo "Yes I would\n";
//        }else{
//            echo "No, I wouldn't\n";
//        }
//        $SQL = "SELECT user_name, last_login, license_type FROM users WHERE NOT deleted AND status = 'Active' ORDER BY 2;";

        while (true) {
            $this->echoc($SQL . PHP_EOL, 'magenta');
            $Result = $this->PDO->query($SQL);
            $Rows = $Result->fetchAll(PDO::FETCH_ASSOC);
            file_put_contents('./cloud_support/watch_sql.log', date("Y-m-d H:i:s e") . " | " . $this->InstanceInfo['INSTANCE'] . " | " . gethostname(), FILE_APPEND);
            file_put_contents('./cloud_support/watch_sql.log', json_encode($Rows, JSON_PRETTY_PRINT), FILE_APPEND);
            file_put_contents('./cloud_support/watch_sql.log', PHP_EOL, FILE_APPEND);
            Utils::print_t($Rows);
            if ($SQL2) {
                $this->echoc($SQL2 . PHP_EOL, 'magenta');
                $Result2 = $this->PDO->query($SQL2);
                $Rows2 = $Result2->fetchAll(PDO::FETCH_ASSOC);
                file_put_contents('./cloud_support/watch_sql.log', json_encode($Rows2, JSON_PRETTY_PRINT), FILE_APPEND);
                Utils::print_t($Rows2);
            }

            echo date("Y-m-d H:i:s e"), " | ", $this->InstanceInfo['INSTANCE'], " | ", gethostname(), PHP_EOL;
            $this->echoc("Use Ctrl-C to exit\n", 'label', PHP_EOL);
            $this->echoc("Waiting {$Interval} seconds\n", 'label');
            sleep($Interval);
        }
//        foreach ($this->PDO->query($SQL) as $Row) {
//            $UserName = str_pad($Row['user_name'], 35);
//            $LastLogin = str_pad($Row['last_login'], 20);
//            $this->echoc("{$UserName}\t{$LastLogin}\t{$Row['license_type']}\n", 'magenta');
//        }±±
//        echo "\n";
//        $SQL2 = "SELECT count(*) `Count` FROM users WHERE NOT deleted AND status = 'Active';";
//        $this->echoc($SQL2 . PHP_EOL, 'magenta');
//        foreach ($this->PDO->query($SQL2) as $Row) {
//            $UserCount = $Row['Count'];
//            $this->echoc("Total active users: {$UserCount}\n", 'data');
//        }
        $this->ShowMenu = false;
    }

    private function searchPackagesForString($Command) {
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SearchString = trim(implode(' ', $CommandArray));
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching zip files in upgrades/modules for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'data');
        $Cmd = "grep -r '{$SearchString}' custom";
        $Cmd = "for z in upgrades/module/*.zip; do unzip -l \"\$z\" | grep  \"{$SearchString}\" | awk -F \"\" -v \"z=\$z\" \"{print $1 z} \"; done";
        $this->echoc($Cmd . PHP_EOL, 'magenta');
        system($Cmd);
        $this->ShowMenu = false;
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
        $ExpectedFilesAndFolders = array('sugarutils', '.', '..', 'cache', 'custom', 'modules', 'config.php', 'config_override.php', 'upgrades', 'upload', 'portal2');
        $FilesAndFolders = scandir('.');
        foreach ($FilesAndFolders as $File) {
            if (in_array($File, $ExpectedFilesAndFolders) || strtoupper(substr($File, -4)) === '.LOG') {
                continue;
            }
            $this->echoc("What's this -> ", 'red');
            if (is_dir($File)) {
                $this->echoc($File . PHP_EOL, 'blue');
                $FilesInFolder = array();
                exec("find \"./{$File}\" -type f", $FilesInFolder);
                foreach ($FilesInFolder as $FileInFolder) {
                    $this->echoc("\t{$FileInFolder}\n", 'brightred');
                }
            } else {
                $this->echoc($File . PHP_EOL, 'brightblue');
            }
        }
        $this->ShowMenu = false;
    }

    private function packageScan() {
        $Cmd = "package-scan -i {$this->InstanceInfo['INSTANCE']}";
        $this->echoc($Cmd . PHP_EOL, 'command');
        system($Cmd);
        $this->ShowMenu = false;
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
//        $Command = "mysql -u'{$this->SugarConfig['dbconfig']['db_user_name']}' -p'{$this->SugarConfig['dbconfig']['db_password']}' -h'{$this->SugarConfig['dbconfig']['db_host_name']}' {$this->SugarConfig['dbconfig']['db_name']} -e\"SELECT type, name, status, enabled, date_entered, date_modified FROM upgrade_history WHERE NOT deleted ORDER BY date_modified;\"";
        $Output = array();
//        exec($Command, $Output);
//        print_r($Output);
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
        $Command = "mysql -u'{$this->SugarConfig['dbconfig']['db_user_name']}' -p'{$this->SugarConfig['dbconfig']['db_password']}' -h'{$this->SugarConfig['dbconfig']['db_host_name']}' {$this->SugarConfig['dbconfig']['db_name']} -e\"SELECT type, name, status, enabled, date_entered, date_modified FROM upgrade_history WHERE NOT deleted ORDER BY date_modified;\"";
        $Output = array();
//        exec($Command, $Output);
//        print_r($Output);
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

    private function searchDropdownLists($Command) {
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SearchString = trim(implode(' ', $CommandArray));
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching custom/Extension/application/Ext/Language/ folder for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'date');
        $Cmd = "grep -rl '{$SearchString}' custom/Extension/application/Ext/Language/";
        $this->echoc($Cmd . PHP_EOL, 'magenta');
        system($Cmd);

        $Files = array();
        exec($Cmd, $Files);
//        print_r($Files);
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
        $Cmd = "grep -r '{$SearchString}' custom";
        $this->echoc($Cmd . PHP_EOL, 'magenta');
        system($Cmd);
        $this->ShowMenu = false;
    }

    private function searchUpgradesFolder($Command) {
        $CommandArray = explode(' ', $Command);
        $CommandArray[0] = '';
        $SearchString = trim(implode(' ', $CommandArray));
        if (!$SearchString) {
            $SearchString = $this->ask("String to search for: ");
        }
        $this->echoc("Searching upgrades folder for: ", 'label');
        $this->echoc($SearchString . PHP_EOL, 'date');
        $Cmd = "grep -rl '{$SearchString}' upgrades/module/ | sed 's/.*/\"&\"/' | xargs ls -haltr ";

        $this->echoc($Cmd . PHP_EOL, 'magenta');
        system($Cmd);
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
        $Size = $this->ask("Enter the minimum size as either xxxM or xxxG: ");
        $Cmd = "find custom -type f -size +{$Size} | xargs ls -halS\n";
        $this->echoc($Cmd, 'magenta');
        system($Cmd);
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
        $SQL2 = "SELECT count(*) `Count`, license_type FROM users WHERE NOT deleted AND status = 'Active' group by license_type order by 1 desc;";
        $this->echoc($SQL2 . PHP_EOL, 'magenta');
        $this->echoc(str_pad("Count  ", 10, ' ', STR_PAD_LEFT), 'label');
        $this->echoc(str_pad("License Type", 50).PHP_EOL, 'label');
        foreach ($this->PDO->query($SQL2) as $Row) {
            $this->echoc(str_pad($Row['Count'].'  ', 10, ' ', STR_PAD_LEFT), 'data');
            $this->echoc(str_pad($Row['license_type'], 50).PHP_EOL, 'data');
        }

        echo "\n";
        $SQL3 = "SELECT count(*) `Count` FROM users WHERE NOT deleted AND status = 'Active';";
        $this->echoc($SQL3 . PHP_EOL, 'magenta');
        foreach ($this->PDO->query($SQL3) as $Row) {
            $UserCount = $Row['Count'];
            $this->echoc("Total active users: ", 'label');
            $this->echoc("{$UserCount}\n", 'data');
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
        $Answer = $this->ask("Please enter the version of the Health Check you would like to run.\n12.0.4, 13.0.0, 13.0.2, 13.0.3, 13.1.0, 13.2.0, 13.3.0, 14.0.0, 14.1.0, 14.2.0, 25.1.0, 25.2.0 (default 25.2.0)");
        $HealthCheckVersion = $Answer ? $Answer:'25.2.0';
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
        $Command2 = "shadowy /mnt/sugar/{$HealthCheckVersion}/sortinghat-{$HealthCheckVersion}.phar .";
        $this->echoc($Command2 . PHP_EOL, 'command');
        system($Command2);
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
        passthru("mysql -u{$this->SugarConfig['dbconfig']['db_user_name']} -p{$this->SugarConfig['dbconfig']['db_password']} -h{$this->SugarConfig['dbconfig']['db_host_name']} {$this->SugarConfig['dbconfig']['db_name']} -e 'show processlist;'");
//        foreach($this->PDO->query("show processlist;") as $Row){
//            $this->Subscription = json_decode($Row[0]);
//            $Status = str_pad($Row['status'], 20);
//            $Count = str_pad($Row['count'], 10);
//            $this->echoc("{$Status}\t{$Count}\n", 'magenta');
//        }
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
        return readline("{$this->Subscription['account_name']}->{$this->InstanceInfo['GROUP']} >"); 
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

    private function generateMessage() {
        echo "Hello {$this->Details['Recipients']}﻿﻿,

Failed Health Check 
We use a Health Check wizard to evaluate whether an instance is suitable for an upgrade. During the health check, various issues may be detected that can affect an instance's ability to upgrade. 

Recently, in preparation for upcoming upgrades, ﻿﻿'s instance ﻿﻿ failed the health check with the following error:

Failed Upgrade
A recent attempt to upgrade {$this->Details['AccountName']}﻿﻿'s instance(s) {$this->Details['InstanceNames']}﻿ has failed with the following issue(s):

{$this->Details['Issues']}
 ﻿﻿ 

The following file(s) appear to be causing the issue(s).

﻿﻿{$this->Details['Files']}

Related Support Article
﻿﻿{$this->Details['LinkToHelpArticle']}

For us to solve this problem, we need your help.  

The file appears to have been installed on {$this->Details['PackageDate']} as part of the {$this->Details['PackageName']}﻿﻿ package. Can you contact {$this->Details['PackageAuthor']}﻿﻿ and ask them to update the package to resolve the issue?

Details from the package manifest
{$this->Details['PackageManifestDetails']}﻿﻿ 

Thanks for helping us out with this,
{$this->Details['Sender']}﻿\n\n ";
    }

    private function removeForkedCoreModules() {
        $Continue = $this->ask("Running this will remove any directory in the shadow copy moudules directory that is also in the template modules folder. It will create a backup first. Type 'yes' to continue.");
        if ($Continue != 'yes') {
            $this->echoc("NOT removing forked modules\n", 'red');
            $this->ask('Press enter to continue');
            return;
        }
        $Cmd1 = "tar -czf custom_and_modules_$(date +%Y-%m-%d_%H-%M).tar.gz custom/ modules/";
        $this->echoc("Backing up custom and modules folders . . .\n", 'label');
        $this->echoc($Cmd1 . PHP_EOL, 'command');
        system($Cmd1);

        $Cmd1 = "ls -hal custom_and_modules_*.tar.gz";
        system($Cmd1);
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

        $Cmd5 = "cat ../core_modules.txt | xargs rm -rv 2> /dev/null | tee ~/forked_modules_removed_$(date +%Y-%m-%d_%H-%M).log";
        $this->echoc("Removing any modules on the list of core modules . . .\n", 'label');
        $this->echoc($Cmd5 . PHP_EOL, 'command');
        system($Cmd5);

        $Cmd6 = "cd ~";
        $this->echoc("Switching back to the shadow folder . . .\n", 'label');
        $this->echoc($Cmd6 . PHP_EOL, 'command');
        chdir("{$this->InstanceInfo['SHADOW']}");
//        system($Cmd6);

        $this->echoc("Forked Core Modules have been removed.", 'label');
        
        $this->echoc("Cleaning up files from versions < Sugar 14\n", 'label');
        $Cmd7 = 'rm -r "include/FCKeditor/editor/filemanager/browser/default/connectors/php/config.php" "include/FCKeditor/editor/filemanager/upload/php/config.php" "include/javascript/tiny_mce/plugins/spellchecker/config.php" "include/SubPanel/SubPanelTilesTabs.php" "include/SugarObjects/templates/basic/Dashlets/Dashlet/m-n-Dashlet.php" "include/SugarObjects/templates/company/config.php" "include/tcpdf/config/tcpdf_config.php" "modules/Administration/System.php" "modules/Administration/views/view.themesettings.php" "modules/Connectors/connectors/sources/ext/rest/dnb/config.php" "modules/Connectors/connectors/sources/ext/rest/linkedin/config.php" "modules/Connectors/connectors/sources/ext/rest/zoominfocompany/config.php" "modules/Connectors/connectors/sources/ext/rest/zoominfoperson/config.php" "modules/Connectors/connectors/sources/ext/soap/hoovers/config.php" "modules/DCEClients/dce_config.php" "modules/disabled/" "modules/Disabled/" "modules/EditCustomFields/" "modules/EmailMan/config.php" "modules/Emails/views/view.classic.config.php" "modules/Feeds/Feed.php" "modules/Forecasts/clients/base/layouts/config/config.php" "modules/ForecastSchedule/" "modules/iFrames/" "modules/Import/config.php" "modules/Import/ImportMap.php" "modules/Import/ImportStep4.php" "modules/KBDocumentRevisions/KBDocumentRevision.php" "modules/KBDocuments/EditView.php" "modules/KBTags/" "modules/MergeRecords/MergeRecord.php" "modules/Studio/config.php" "modules/Studio/wizards/ManageBackups.php" "modules/SugarFeed/SugarFeed.php" "modules/Sync/config.php" "modules/Temp/" "modules/temp/" "modules/Users/UserSignature.php" "portal/include/language/_.lang.php" "portal/sugar_version.php"';
        $this->echoc($Cmd7 . PHP_EOL, 'command');
        system($Cmd7);
        
        $RemoveCustomerJourney = $this->ask("If you like to also Manually Remove Customer Journey files please enter 'yes'\n");
        if ($RemoveCustomerJourney === 'yes') {
            $this->manuallyRemoveCustomerJourney(true);
        } else {
            $this->ask("Press enter to continue\n");
        }
    }

    private function backupCustomAndModulesFolders() {
        $Filename = "custom_and_modules_" . date('Y-m-d_H-i') . ".tar.gz";
        $Cmd1 = "tar -czf {$Filename} custom/ modules/";
        $this->echoc("Backing up custom and modules folders . . .\n", 'label');
        $this->echoc($Cmd1 . PHP_EOL, 'command');
        system($Cmd1);
        system("ls -hal {$Filename}");
        $this->ShowMenu = false;
    }

    private function manuallyRemoveCustomerJourney($SkipBackup = false) {
        $Continue = $this->ask('Are you sure you want to delete all of the Customer Journey files from the system? y|N');
        if (strtoupper(substr($Continue, 0, 1)) !== 'Y') {
            return;
        }
        if (!$SkipBackup) {
            $Command1 = 'tar -czf custom_and_modules_$(date +%Y-%m-%d_%H-%M).tar.gz custom/ modules/';
            $this->echoc("Backing up the custom and modules folders\n", 'label');
            $this->echoc("" . $Command1 . PHP_EOL, 'command');
            system($Command1);
        }

        $this->echoc("Deleting files . . .", 'label');

        system('rm -r custom/Extension/application/Ext/Include/addoptify-customer-journey.php
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
');
        $Command2 = 'find custom -name "*customer-journey*" | xargs rm -r';
        $this->echoc($Command2 . PHP_EOL, 'command');
        system($Command2);

        $Command3 = 'grep -ril customer_journey custom';
        $this->echoc($Command3 . PHP_EOL, 'command');
        system($Command3);

        $Command4 = 'find custom -name "DRI_*" | xargs rm -r';
        $this->echoc($Command4 . PHP_EOL, 'command');
        system($Command4);

        $Command5 = 'find custom -name "CJ_*" | xargs rm -r';
        $this->echoc($Command5 . PHP_EOL, 'command');
        system($Command5);

        $Command6 = "grep -i 'cj_\|dri_' custom/application/Ext/Include/modules.ext.php";
        $this->echoc($Command6 . PHP_EOL, 'command');
        system($Command6);

        $this->echoc("\n\nIf the above command found any CJ_ or DRI_ then edit custom/application/Ext/Include/modules.ext.php and remove all the reference to CJ or DRI\n", 'red');
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

    public static function getUserFullName() {
        switch ($_SERVER['REMOTE_USER']) {
            case 'patpawlowski':
                return 'Patrick Pawlowski';
                break;

            case 'jshannon':
                return 'John Shannon';
                break;

            case 'atranca':
                return 'Alex Tranca';
                break;

            case 'murucu':
                return 'Marius-Cristian Urucu';
                break;

            default:
                return $_SERVER['REMOTE_USER'];
                break;
        }
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

