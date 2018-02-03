<?php 
include 'common.inc';
require_once('page_data.inc');

if (array_key_exists('f', $_REQUEST) && $_REQUEST['f'] == 'json') {
    include 'jsonResult.php';
} elseif (array_key_exists('f', $_REQUEST) && $_REQUEST['f'] == 'xml') {
    include 'xmlResult.php';
} else {
    $pageData = loadAllPageData($testPath);

    // if we don't have an url, try to get it from the page results
    if( !strlen($url) && isset($pageData[1][0]['URL']))
        $url = $pageData[1][0]['URL'];
    if (isset($test['testinfo']['spam']) && $test['testinfo']['spam']) {
        include 'resultSpam.inc.php';
    } else {
        if( (isset($test['test']) && ( $test['test']['batch'] || $test['test']['batch_locations'] )) ||
            (!array_key_exists('test', $test) && array_key_exists('testinfo', $test) && $test['testinfo']['batch']) ) {
            include 'resultBatch.inc';
        } elseif( isset($test['testinfo']['cancelled']) ) {
            include 'testcancelled.inc';
        } elseif( isset($test['test']['completeTime']) || count($pageData) > 0 ) {
            if( @$test['test']['type'] == 'traceroute' ) {
                include 'resultTraceroute.inc';
            } elseif( @$test['test']['type'] == 'lighthouse' ) {
                include 'lighthouse.php';
            } else {
                include 'result.inc';
            }
        } else {
            include 'running.inc';
        }
    }
}
?>
