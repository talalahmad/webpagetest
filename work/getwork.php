<?php
if(extension_loaded('newrelic')) { 
  newrelic_add_custom_tracer('GetUpdate');
  newrelic_add_custom_tracer('GetJob');
  newrelic_add_custom_tracer('GetTestJob');
  newrelic_add_custom_tracer('CheckCron');
  newrelic_add_custom_tracer('ProcessTestShard');
  newrelic_add_custom_tracer('GetTesters');
  newrelic_add_custom_tracer('LockLocation');
  newrelic_add_custom_tracer('GetLocationInfo');
  newrelic_add_custom_tracer('LockTest');
  newrelic_add_custom_tracer('UpdateTester');
  newrelic_add_custom_tracer('GetTesterIndex');
  newrelic_add_custom_tracer('StartTest');
  newrelic_add_custom_tracer('TestToJSON');
  newrelic_add_custom_tracer('logTestMsg');
}

chdir('..');
include 'common.inc';
error_reporting(0);
set_time_limit(600);
$is_json = array_key_exists('f', $_GET) && $_GET['f'] == 'json';
$locations = explode(',', $_GET['location']);
$key = array_key_exists('key', $_GET) ? $_GET['key'] : '';
$recover = array_key_exists('recover', $_GET) ? $_GET['recover'] : '';
$pc = array_key_exists('pc', $_GET) ? $_GET['pc'] : '';
$ec2 = array_key_exists('ec2', $_GET) ? $_GET['ec2'] : '';
$screenwidth = array_key_exists('screenwidth', $_GET) ? $_GET['screenwidth'] : '';
$screenheight = array_key_exists('screenheight', $_GET) ? $_GET['screenheight'] : '';
$winver = isset($_GET['winver']) ? $_GET['winver'] : '';
$isWinServer = isset($_GET['winserver']) ? $_GET['winserver'] : '';
$isWin64 = isset($_GET['is64bit']) ? $_GET['is64bit'] : '';
$tester = null;
if (strlen($ec2))
  $tester = $ec2;
elseif (strlen($pc))
  $tester = $pc . '-' . trim($_SERVER['REMOTE_ADDR']);
else
  $tester = trim($_SERVER['REMOTE_ADDR']);
  
$dnsServers = '';
if (array_key_exists('dns', $_REQUEST))
  $dnsServers = str_replace('-', ',', $_REQUEST['dns']);
$supports_sharding = false;
if (GetSetting('shard_tests', true) && array_key_exists('shards', $_REQUEST) && $_REQUEST['shards'])
  $supports_sharding = true;

$is_done = false;
if (isset($locations) && is_array($locations) && count($locations) &&
    (!array_key_exists('freedisk', $_GET) || (float)$_GET['freedisk'] > 0.1)) {
  shuffle($locations);
  $location = trim($locations[0]);
  if (!$is_done && array_key_exists('reboot', $_GET) && GetSetting('allowReboot'))
    $is_done = GetReboot();
  if (!$is_done && array_key_exists('ver', $_GET))
    $is_done = GetUpdate();
  foreach ($locations as $loc) {
    $location = trim($loc);
    if (!$is_done && strlen($location)) {
      $is_done = GetJob();
    }
    // see if there are fallbacks specified for the given location (for idle)
    if (!$is_done) {
      $fallbacks = GetLocationFallbacks($location);
      if (is_array($fallbacks) && count($fallbacks)) {
        foreach($fallbacks as $fallback) {
          $location = trim($fallback);
          if (!$is_done && strlen($location))
            $is_done = GetJob();
        }
      }
    }
  }
} elseif (isset($_GET['freedisk']) && (float)$_GET['freedisk'] <= 0.1) {
  if (isset($_GET['reboot']) && GetSetting("lowDiskReboot")) {
    header('Content-type: text/plain');
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    echo "Reboot";
    $is_done = true;
  }
}

// kick off any cron work we need to do asynchronously
CheckCron();

// Send back a blank result if we didn't have anything.
if (!$is_done) {
  header('Content-type: text/plain');
  header("Cache-Control: no-cache, must-revalidate");
  header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

function GetTesterIndex($locInfo, &$testerIndex, &$testerCount, &$offline) {
  global $pc;
  global $ec2;
  global $tester;
  global $location;
  $now = time();

  // get the count of testers for this lication and the index of the current tester for affinity checking
  $testerIndex = null;
  if (function_exists('apcu_fetch') || function_exists('apc_fetch')) {
    if (function_exists("apcu_fetch"))
      $testers = apcu_fetch("testers_$location");
    elseif (function_exists("apc_fetch"))
      $testers = apc_fetch("testers_$location");
    if (!isset($testers) || !is_array($testers))
      $testers = array();
    $testers[$pc] = $now;
    $max_tester_time = min(max(GetSetting('max_tester_minutes', 60), 5), 120) * 60;
    $earliest = $now - $max_tester_time;
    $index = 0;
    foreach($testers as $name => $last_check) {
      if ($name == $pc)
        $testerIndex = $index;
      if ($last_check < $earliest) {
        unset($testers[$name]);
      } else {
        $index++;
      }
    }
    $testerCount = count($testers);
    if (function_exists("apcu_store"))
      apcu_store("testers_$location", $testers);
    elseif (function_exists("apc_store"))
      apc_store("testers_$location", $testers);
  }

  // If it is an EC2 auto-scaling location, make sure the agent isn't marked as offline      
  $offline = false;
  if (GetSetting('ec2_key') && !isset($testerIndex) || isset($locInfo['ami'])) {
    $testers = GetTesters($location, true);

    // make sure the tester isn't marked as offline (usually when shutting down EC2 instances)                
    $testerCount = isset($testers['testers']) ? count($testers['testers']) : 0;
    if ($testerCount) {
      if (strlen($ec2)) {
        foreach($testers['testers'] as $index => $testerInfo) {
          if (isset($testerInfo['ec2']) && $testerInfo['ec2'] == $ec2 &&
              isset($testerInfo['offline']) && $testerInfo['offline'])
            $offline = true;
            break;
        }
      }
      foreach($testers['testers'] as $index => $testerInfo)
        if ($testerInfo['id'] == $tester) {
          $testerIndex = $index;
          break;
        }
    }
  }
}

function StartTest($testId, $time) {
  $testPath = './' . GetTestPath($testId);
  $waiting_file = "$testPath/test.waiting";
  @unlink($waiting_file);

  // flag the test with the start time
  $ini = file_get_contents("$testPath/testinfo.ini");
  if (stripos($ini, 'startTime=') === false) {
    $start = "[test]\r\nstartTime=" . gmdate("m/d/y G:i:s", $time);
    $out = str_replace('[test]', $start, $ini);
    file_put_contents("$testPath/testinfo.ini", $out);
  }
}

function TestToJSON($testInfo) {
  $testJson = array();
  $script = '';
  $isScript = false;
  $lines = explode("\r\n", $testInfo);
  foreach($lines as $line) {
    if( strlen(trim($line)) ) {
      if( $isScript ) {
        if( strlen($script) )
          $script .= "\r\n";
        $script .= $line;
      } elseif( !strcasecmp($line, '[Script]') ) {
        $isScript = true;
      } else {
        $pos = strpos($line, '=');
        if( $pos !== false ) {
          $key = trim(substr($line, 0, $pos));
          $value = trim(substr($line, $pos + 1));
          if( strlen($key) && strlen($value) ) {
            if ($key == 'customMetric') {
              $pos = strpos($value, ':');
              if ($pos !== false) {
                $metric = trim(substr($value, 0, $pos));
                $code = base64_decode(substr($value, $pos+1));
                if ($code !== false && strlen($metric) && strlen($code)) {
                  if (!isset($testJson['customMetrics']))
                    $testJson['customMetrics'] = array();
                  $testJson['customMetrics'][$metric] = $code;
                }
              }
            } elseif ($key == 'injectScript') {
              $testJson['injectScript'] = base64_decode($value);
            } elseif( filter_var($value, FILTER_VALIDATE_INT) !== false ) {
              $testJson[$key] = intval($value);
            } elseif( filter_var($value, FILTER_VALIDATE_FLOAT) !== false ) {
              $testJson[$key] = floatval($value);
            } else {
              $testJson[$key] = $value;
            }
          }
        }
      }
    }
  }
  if( strlen($script) )
      $testJson['script'] = $script;
  // See if we need to include apk information
  if (isset($_REQUEST['apk']) && is_file(__DIR__ . '/update/apk.dat')) {
    $apk_info = json_decode(file_get_contents(__DIR__ . '/update/apk.dat'), true);
    if (isset($apk_info) && is_array($apk_info) && isset($apk_info['packages']) && is_array($apk_info['packages'])) {
      $protocol = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_SSL']) && $_SERVER['HTTP_SSL'] == 'On')) ? 'https' : 'http';
      $update_path = dirname($_SERVER['PHP_SELF']) . '/update/';
      $base_uri = "$protocol://{$_SERVER['HTTP_HOST']}$update_path";
      foreach ($apk_info['packages'] as $package => $info)
        $apk_info['packages'][$package]['apk_url'] = "$base_uri{$apk_info['packages'][$package]['file_name']}?md5={$apk_info['packages'][$package]['md5']}";
      $testJson['apk_info'] = $apk_info;
    }
  }
  return $testJson;
}

/**
* Get an actual task to complete
* 
*/
function GetJob() {
  $is_done = false;

  global $location;
  global $key;
  global $pc;
  global $ec2;
  global $tester;
  global $recover;
  global $is_json;
  global $dnsServers;
  global $screenwidth;
  global $screenheight;
  global $winver;
  global $isWinServer;
  global $isWin64;

  $workDir = "./work/jobs/$location";
  $locInfo = GetLocationInfo($location);
  $locKey = '';
  if (isset($locInfo) && is_array($locInfo) && isset($locInfo['key']))
    $locKey = $locInfo['key'];
  if (strpos($location, '..') == false &&
      strpos($location, '\\') == false &&
      strpos($location, '/') == false &&
      (!strlen($locKey) || !strcmp($key, $locKey))) {
    
    GetTesterIndex($locInfo, $testerIndex, $testerCount, $offline);
    
    if (!$offline) {
      if (!isset($testerIndex))
        $testerIndex = 0;
      if (!$testerCount)
        $testerCount = 1;
      $testInfo = GetTestJob($location, $fileName, $workDir, $priority, $pc, $testerIndex, $testerCount);
      if (isset($testInfo)) {
        $original_test_info = $testInfo;
        $is_done = true;
        $delete = true;
        
        if ($is_json)
          header ("Content-type: application/json");
        else
          header('Content-type: text/plain');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

        // send the test info to the test agent
        $newline = strpos($testInfo, "\n", 2);
        if ($newline) {
          $newline++;
          $after = substr($testInfo, $newline);
          $testInfo = substr($testInfo, 0, $newline);
          $software = GetSetting('software');
          if ($software)
            $testInfo .= "software=$software\r\n";
          if (GetSetting('enable_agent_processing'))
            $testInfo .= "processResults=1\r\n";
          $testInfo .= $after;
        }

        // extract the test ID from the job file
        if( preg_match('/Test ID=([^\r\n]+)\r/i', $testInfo, $matches) )
          $testId = trim($matches[1]);

        if( isset($testId) ) {
          $time = time();
          StartTest($testId, $time);
          $lock = LockTest($testId);
          if ($lock) {
            $testInfoJson = GetTestInfo($testId);
            if ($testInfoJson) {
              if (!array_key_exists('tester', $testInfoJson) || !strlen($testInfoJson['tester']))
                $testInfoJson['tester'] = $tester;
              if (isset($dnsServers) && strlen($dnsServers))
                $testInfoJson['testerDNS'] = $dnsServers;
              if (!array_key_exists('started', $testInfoJson) || !$testInfoJson['started']) {
                $testInfoJson['started'] = $time;
                logTestMsg($testId, "Starting test (initiated by tester $tester)");
              }
              if (!array_key_exists('test_runs', $testInfoJson))
                $testInfoJson['test_runs'] = array();
              for ($run = 1; $run <= $testInfoJson['runs']; $run++) {
                if (!array_key_exists($run, $testInfoJson['test_runs']))
                  $testInfoJson['test_runs'][$run] = array('done' => false);
              }
              $dotPos = stripos($testId, ".");
              $testInfoJson['id'] = $dotPos === false ? $testId : substr($testId, $dotPos + 1);
              ProcessTestShard($testInfoJson, $testInfo, $delete, $priority);
              SaveTestInfo($testId, $testInfoJson);
            }
            UnlockTest($lock);
          }
        }

        if ($delete) {
          if (isset($fileName)) {
            @unlink("$workDir/$fileName");
          }
        } else {
          AddTestJobHead($location, $original_test_info, $workDir, $fileName, $priority, true);
        }
        
        if ($is_json) {
          $testJson = TestToJSON($testInfo);
          echo json_encode($testJson);
        } else {
          echo $testInfo;
        }
        $ok = true;
      }

      // keep track of the last time this location reported in
      $testerInfo = array();
      $testerInfo['ip'] = $_SERVER['REMOTE_ADDR'];
      $testerInfo['pc'] = $pc;
      $testerInfo['ec2'] = $ec2;
      $testerInfo['ver'] = array_key_exists('version', $_GET) ? $_GET['version'] : $_GET['ver'];
      $testerInfo['freedisk'] = @$_GET['freedisk'];
      $testerInfo['upminutes'] = @$_GET['upminutes'];
      $testerInfo['ie'] = @$_GET['ie'];
      $testerInfo['dns'] = $dnsServers;
      $testerInfo['video'] = @$_GET['video'];
      $testerInfo['GPU'] = @$_GET['GPU'];
      $testerInfo['screenwidth'] = $screenwidth;
      $testerInfo['screenheight'] = $screenheight;
      $testerInfo['winver'] = $winver;
      $testerInfo['isWinServer'] = $isWinServer;
      $testerInfo['isWin64'] = $isWin64;
      $testerInfo['test'] = '';
      if (isset($testId))
        $testerInfo['test'] = $testId;
      UpdateTester($location, $tester, $testerInfo);
    }
  }
  
  return $is_done;
}

/**
* See if there is a software update
* 
*/
function GetUpdate() {
  global $location;
  global $tester;
  $ret = false;

  // see if the client sent a version number
  if ($_GET['ver']) {
    $fileBase = '';
    if( isset($_GET['software']) && strlen($_GET['software']) )
      $fileBase = trim($_GET['software']);
    
    $update = null;
    if (function_exists('apcu_fetch')) {
      $update = apcu_fetch("update-$fileBase");
      if ($update === FALSE)
        unset($update);
    }
    
    $updateDir = './work/update';
    if (is_dir("$updateDir/$location"))
      $updateDir = "$updateDir/$location";

    if (!isset($update)) {
      // see if we have any software updates
      if (is_file("$updateDir/{$fileBase}update.ini") && is_file("$updateDir/{$fileBase}update.zip")) {
        $update = parse_ini_file("$updateDir/{$fileBase}update.ini");
      }
      if (isset($update) && function_exists('apcu_store')) {
        apcu_store("update-$fileBase", $update, 60);
      }
    }
    
    if (isset($update)) {
      // Check for inequality allows both upgrade and quick downgrade
      if ($update['ver'] && intval($update['ver']) !== intval($_GET['ver'])) {
        header('Content-Type: application/zip');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

        readfile_chunked("$updateDir/{$fileBase}update.zip");
        $ret = true;
      }
    }
  }
  
  return $ret;
}

/**
* Send a quick http request locally if we need to process cron events (to each of the cron entry points)
* 
* This only runs events on 15-minute intervals and tries to keep it close to the clock increments (00, 15, 30, 45)
* 
*/
function CheckCron() {
  // open and lock the cron job file - abandon quickly if we can't get a lock
  $should_run = false;
  $minutes15 = false;
  $minutes60 = false;
  $cron_lock = Lock("Cron Check", false, 1200);
  if (isset($cron_lock)) {
    $last_run = 0;
    if (is_file('./tmp/wpt_cron.dat'))
      $last_run = file_get_contents('./tmp/wpt_cron.dat');
    $now = time();
    $elapsed = $now - $last_run;
    if (!$last_run) {
        $should_run = true;
        $minutes15 = true;
        $minutes60 = true;
    } elseif ($elapsed > 120) {
      if ($elapsed > 1200) {
        // if it has been over 20 minutes, run regardless of the wall-clock time
        $should_run = true;
      } else {
        $minute = gmdate('i', $now) % 5;
        if ($minute < 2) {
          $should_run = true;
          $minute = gmdate('i', $now) % 15;
          if ($minute < 2)
            $minutes15 = true;
          $minute = gmdate('i', $now) % 60;
          if ($minute < 2)
            $minutes60 = true;
        }
      }
    }
    if ($should_run)
      file_put_contents('./tmp/wpt_cron.dat', $now);
    Unlock($cron_lock);
  }
  
  // send the cron requests
  if ($should_run) {
    if (is_file('./settings/benchmarks/benchmarks.txt') && 
        is_file('./benchmarks/cron.php'))
      SendAsyncRequest('/benchmarks/cron.php');
    SendAsyncRequest('/cron/5min.php');
    if (is_file('./jpeginfo/cleanup.php'))
      SendAsyncRequest('/jpeginfo/cleanup.php');
    if ($minutes15)
      SendAsyncRequest('/cron/15min.php');
    if ($minutes60)
      SendAsyncRequest('/cron/hourly.php');
  }
}

/**
* Process a sharded test
* 
* @param mixed $testInfo
*/
function ProcessTestShard(&$testInfo, &$test, &$delete, $priority) {
  global $supports_sharding;
  global $tester;
  if (array_key_exists('shard_test', $testInfo) && $testInfo['shard_test']) {
    if ((array_key_exists('type', $testInfo) && $testInfo['type'] == 'traceroute') || !$supports_sharding) {
      $testInfo['shard_test'] = 0;
    } else {
      $done = true;
      $assigned_run = 0;
      
      // find a run to assign to a tester
      for ($run = 1; $run <= $testInfo['runs']; $run++) {
        if (!array_key_exists('tester', $testInfo['test_runs'][$run])) {
          $testInfo['test_runs'][$run]['tester'] = $tester;
          $testInfo['test_runs'][$run]['started'] = time();
          $testInfo['test_runs'][$run]['done'] = false;
          $assigned_run = $run;
          break;
        }
      }
      
      // go through again and see if all tests have been assigned
      for ($run = 1; $run <= $testInfo['runs']; $run++) {
        if (!array_key_exists('tester', $testInfo['test_runs'][$run])) {
          $done = false;
          break;
        }
      }
      
      if ($assigned_run) {
        logTestMsg($testInfo['id'], "Run $assigned_run assigned to $tester");
        $append = "run=$assigned_run\r\n";

        // Figure out if this test needs to be discarded
        $index = $assigned_run;
        if (array_key_exists('discard', $testInfo)) {
          if ($index <= $testInfo['discard']) {
            $append .= "discardTest=1\r\n";
            $index = 1;
            $done = true;
            $testInfo['test_runs'][$assigned_run]['discarded'] = true;
          } else {
            $index -= $testInfo['discard'];
          }
        }
        $append .= "index=$index\r\n";
        
        $insert = strpos($test, "\nurl");
        if ($insert !== false) {
          $test = substr($test, 0, $insert + 1) . 
                  $append . 
                  substr($test, $insert + 1);
        } else {
          $test = "run=$assigned_run\r\n" + $test;
        }
      }

      if (!$done)
        $delete = false;
    }
  }
}

/**
* See if we need to reboot this tester
* 
*/
function GetReboot() {
  global $location;
  global $pc;
  global $ec2;
  global $tester;
  $reboot = false;
  $name = @strlen($ec2) ? $ec2 : $pc;
  if (isset($name) && strlen($name) && isset($location) && strlen($location)) {
    $rebootFile = "./work/jobs/$location/$name.reboot";
    if (is_file($rebootFile)) {
      unlink($rebootFile);
      $reboot = true;
    }
  }
  // If we have a 100% error rate for the current PC, send it a reboot
  if (!$reboot) {
    $testers = GetTesters($location);
    foreach ($testers as $t) {
      if ($t['id'] == $tester && !$rebooted) {
        if ($t['errors'] >= 100) {
          UpdateTester($location, $tester, null, null, null, true);
          $reboot = true;
        }
        break;
      }
    }
  }

  if ($reboot) {
    header('Content-type: text/plain');
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    echo "Reboot";
  }
  return $reboot;
}
?>
