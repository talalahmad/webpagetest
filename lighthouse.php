<?php
if(array_key_exists("HTTP_IF_MODIFIED_SINCE",$_SERVER) && strlen(trim($_SERVER['HTTP_IF_MODIFIED_SINCE']))) {
  header("HTTP/1.0 304 Not Modified");
} else {
  include 'common.inc';
  $ok = false;
  if (isset($testPath) && is_dir($testPath)) {
    if (isset($_REQUEST['f']) && $_REQUEST['f'] == 'json') {
      $file = "lighthouse.json.gz";
      $filePath = "$testPath/$file";
      if (is_file($filePath)) {
        $ok = true;
        header('Last-Modified: ' . gmdate('r'));
        header('Cache-Control: public,max-age=31536000');
        header('Content-type: application/json');
        gz_readfile_chunked($filePath);
      }
    } else {
      $file = "lighthouse.html.gz";
      $filePath = "$testPath/$file";
      if (is_file($filePath)) {
        $ok = true;

        // Cache for a year
        header('Last-Modified: ' . gmdate('r'));
        header('Cache-Control: public,max-age=31536000');
        header('Content-type: text/html');
        $lighthouseTrace = "$testPath/lighthouse_trace.json";
        if (gz_is_file($lighthouseTrace)) {
          // Add the HTML to view/download the trace and timelines to the raw html
          $html = gz_file_get_contents($filePath);
          $insert = '<div style="text-align: center; line-height: 2em;"><span>';
          $insert .= "<p>Timeline from test: <a href=\"/getTimeline.php?test=$id&run=lighthouse\">Download</a> or <a href=\"/chrome/timeline.php?test=$id&run=lighthouse\" target=\"_blank\" rel=\"noopener\">View</a> &nbsp; -  &nbsp; ";
          $insert .= "Trace from test: <a href=\"/getgzip.php?test=$id&file=lighthouse_trace.json\">Download</a> or <a href=\"/chrome/trace.php?test=$id&run=lighthouse\" target=\"_blank\" rel=\"noopener\">View</a></p>";
          $insert .= "</span>";
          $insert .= '</div>';
          $insert_pos = strpos($html, '</footer>');
          if ($insert_pos !== false) {
            echo substr($html, 0, $insert_pos);
            echo $insert;
            echo substr($html, $insert_pos);
          } else {
            echo $html;
          }
        } else {
          gz_readfile_chunked($filePath);
        }
      } else {
        $info = GetTestInfo($testPath);
        if (isset($info) && is_array($info) && isset($info['lighthouse']) && $info['lighthouse']) {
          $ok = true;
          header('Content-type: text/html');
          echo "<html><head></head><body>";
          echo "<p>Sorry, Lighthouse had some issues gathering your report on WebPageTest. Please try again or try using Lighthouse through <a href=\"https://developers.google.com/web/tools/lighthouse/\">another way</a></p>";
          $file = "lighthouse.log";
          $filePath = "$testPath/$file";
          if (gz_is_file($filePath)) {
            echo "<p>Lighthouse test log:</p>\n";
            echo "<pre>";
            echo htmlspecialchars(gz_file_get_contents($filePath));
            echo "</pre>";
          }
          echo "</body></html>";
        }
      }
    }
  }
  if (!$ok) {
    header("HTTP/1.0 404 Not Found");
  }
}
?>
