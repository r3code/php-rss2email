<?php


/* 
   Copyright (c) 2013, r3code <dimon.etti@gmail.com>
   
   This script parses a given Redmine 2 News RSS/ATOM feed and send every entry to the subscribesrs
   listed in a config file. It has a data file alowing it not to send entities to subscriber twice.
   Run the  script periodically through CRON to retreive actual news. 
   Tested at PHP 5.2+   
*/

class FileReadError extends Exception {};

$DEBUG = 0;

$SCRIPT_DIR = dirname( __FILE__ );

$db_file = "$SCRIPT_DIR/db_rss_sent_state.json";
$recepients_list_file = "$SCRIPT_DIR/rss_recepients_list.json";

/*

  Example of recepients_list_file
  {
    "http:\/\/site\/projects\/news.atom?key=2cdbbbe5dc809a33d2ba4aa5391da4875bd42c40":
    [
      "user1@example.org",
      "user2@example.org"
    ],
    "feed2_url":[subscribers_list],



  
  } 
 
*/

$config_file = "$SCRIPT_DIR/config.json";

/*

  Example of config_file
  {

    "from": "mailfromaddr@example.org"
  }
*/


function loadFeedsSettings($varFile) 
{
  if ( !file_exists($varFile) ) 
    throw new FileReadError("Couldn't open feeds config file $varFile");
    $feedSettings = json_decode(file_get_contents($varFile), true);
  return $feedSettings; 
}



function prepareLetter($varFeed, $varFeedEntry, $from, $recepient) 
{
  $feedLink = $varFeed->link[0]['href'];
  $feedAuthor =  $varFeed->author->name;
  $subject = sprintf('[%s] %s', $varFeed->title, $varFeedEntry->title);
  $link = $varFeedEntry->link['href'];
  $date =  date_format(date_create($varFeedEntry->updated), "d.m.Y H:i:s"); // decode ISO date
  $author = $varFeedEntry->author->name;
  $content  = $varFeedEntry->content;
  $body = <<<EOT
<html>
<body>  
<h1><a href="$link">$varFeedEntry->title</a></h1>
<p><i>$date</i>, $author</p> 

$content

<hr />
$feedAuthor
</body>
</html>

EOT;
  

  // Set Content-type to HTML
  $headers  = 'MIME-Version: 1.0' . "\r\n";
  $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";


  // Extra fields
  $headers .= "To: $recepient" . "\r\n";
  $headers .= "From: $from" . "\r\n";
  
  return array(
    'to' => $recepient,
    'subject' => $subject,
    'body' => $body, 
    'headers' => $headers,
  );
}


function isFeedEntrySent($entryId, $recepient)
{
  global $db_file;
  // load db json
  if ( !file_exists($db_file) ) 
    return false;
  $feedStat = json_decode(file_get_contents($db_file), true);
  if ( !$feedStat ) 
    return false;
  else 
    return $feedStat["$entryId"]["$recepient"];
}


function setFeedEntrySent($entryId, $recepient) 
{
  global $db_file;
  // load current db
  if ( file_exists($db_file) ) 
  {

    $file_text = file_get_contents($db_file);
    //echo "<b>IN FILE: $file_text</b><br>";
    $feedStat= json_decode($file_text, true);

  } 
    
  if ( !$feedStat ) $feedStat = array(); // if no entries have been sent

  /* 
    Internal DB file structure
    { 
      "entryId1": {mail1: 1, mail2: 1},
      "entryId2": {mail1: 1, mail2: 2},
    }
  */
  $item = array("$entryId" => array("$recepient" => 1));
  if ( !$feedStat["$entryId"] ) $feedStat["$entryId"] = array(); // new record
  
  unset($feedStat["$entryId"]["$recepient"]); // remove old sent status for the recepient
  // set entry has been sent for the recepient
  $feedStat["$entryId"] = array_merge($item["$entryId"], $feedStat["$entryId"]);
  
  $json_text = json_encode($feedStat);   
  //DEBUG: echo " <hr /> FOR FILE: $json_text<br><br>";
  // save sent entries data into a file
  file_put_contents($db_file, $json_text);
}


function mailRssFeed($mailConfig, $feedURL, $recepients) {
  $from = $mailConfig['from']; // "mail@example.org"
  $feed = simplexml_load_file($feedURL);
    $MsgCount = 0;
  foreach ($feed->entry as $entry) 
  {      
    foreach ($recepients as $recepient)
    {
      if ( !isFeedEntrySent($entry->id, $recepient) ) 
      {
        $letter = prepareLetter($feed,$entry, $from, $recepient);    
        
        $mail = mail($letter['to'], $letter['subject'], $letter['body'], $letter['headers']);
        if ( $mail ) 
        {
          setFeedEntrySent($entry->id, $recepient);
          echo $letter['subject'] . " - sent to " . $letter['to'] . "<br />";
          $MsgCount ++;
        }
        else
        {
          echo $letter['subject'] . " - NOT sent to " . $letter['to'] . "<br />";
        }  
      }
    }  
  }
  echo "Messages sent: $MsgCount";
}

// == Run the script ==

if ( !file_exists($config_file) ) 
    throw new FileReadError("Couldn't open config file $config_file");
    
$mailConfig = json_decode(file_get_contents($config_file), true);
if ($mailConfig === null) 
{

  echo "Script Config file $config_file is empty or incorrect!";
}

$feeds = loadFeedsSettings($recepients_list_file);

foreach ($feeds as $feedUrl=>$recepients) 
{
  mailRssFeed($mailConfig, $feedUrl, $recepients);
}

?>
