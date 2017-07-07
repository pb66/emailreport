<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access'); 
   
function emailreport_generate($config) 
{
    $title = $config["title"];
    $emailto = $config["email"];
    $apikey = $config["apikey"];
    $timezone = $config["timezone"];
    $usefeedid = $config["feedid"];

    if (!$timezone) return false;

    $date = new DateTime();
    $date->setTimezone(new DateTimeZone($timezone));

    // Get start and end time of weeks
    $date->setTimestamp(time());
    //$date->modify("midnight");
    $date->modify("this monday");
    if ($date->getTimestamp()>time()) {
        $date->modify("last monday");
    }
    $date->modify("-2 weeks");
    $startofpreviousweek = $date->getTimestamp();
    $date->modify("+1 week");
    $startofweek = $date->getTimestamp();
    $date->modify("+1 week");
    $date->modify("+1 day");
    $endofweek = $date->getTimestamp();

    // Start and end time in seconds
    $start = $startofweek*1000; $end = $endofweek*1000;
    // Fetch the week of data
    $data = json_decode(file_get_contents("http://emoncms.org/feed/data.json?id=$usefeedid&start=$start&end=$end&mode=daily&apikey=$apikey"));

    // print json_encode($data);

    if (count($data)!=8) {
        echo "Not enough days returned in data request\n"; die;
    }

    // Calculate daily consumption for the week
    $total = 0;
    $daily = array();
    $days = array("Mon"=>"Monday","Tue"=>"Tuesday","Wed"=>"Wednesday","Thu"=>"Thursday","Fri"=>"Friday","Sat"=>"Saturday","Sun"=>"Sunday");
    for ($i=1; $i<count($data); $i++) {
        $date->setTimestamp($data[$i-1][0]*0.001);
        $day = $data[$i][1] - $data[$i-1][1];
        $daily[] = array("day"=>$days[$date->format('D')], "kwh"=>$day);
        $total += $day;
    }

    // Calculate average kWh per day
    $totaltime = ($data[7][0] - $data[0][0])*0.001;
    $kwhday = (($total * 3600000) / $totaltime) * 0.024;

    // Calculate saving vs previous week
    $startofpreviousweek *= 1000;
    $tmp = json_decode(file_get_contents("http://emoncms.org/feed/data.json?id=$usefeedid&start=$startofpreviousweek&end=".($startofpreviousweek+1000)."&interval=1&apikey=$apikey"));
    $kwhpreviousweek = $data[0][1] - $tmp[0][1];

    if ($kwhpreviousweek>0) {
        $prcless = 100 * (1 - ($total / $kwhpreviousweek));
    } else {
        $prcless = 0;
    }

    $text_lastweek = "";
    if ($prcless>=0) {
        $text_lastweek = "You used <b>".round($prcless)."% less</b> than the previous week\n";
    } else {
        $prcless = 100 * (1 - ($kwhpreviousweek/$total));
        $text_lastweek = "You used <b>".round($prcless)."% more</b> than the previous week\n";
    }

    // Calculate saving vs average household
    $prcless = 100 * (1 - ($kwhday / 9.0));
    $text_averagecmp = "";
    if ($prcless>=0) $text_averagecmp = "You used <b>".round($prcless)."% less</b> than UK household average\n";



    // --------------------------------------------------------------------------------------------------------
    // UK Renewable
    // --------------------------------------------------------------------------------------------------------
    $end = $start + (3600*24*7*1000);
    $totaltime = ($end - $start) * 0.001;

    // Wind
    $data = json_decode(file_get_contents("http://emoncms.org/feed/data.json?id=67088&start=$start&end=$end&interval=1800&skipmissing=0&limitinterval=1"));
    $ukwind = average($data);
    $ukwindGWh = ($ukwind * $totaltime) / 3600000;

    // Demand
    $data = json_decode(file_get_contents("http://emoncms.org/feed/data.json?id=97736&start=$start&end=$end&interval=1800&skipmissing=0&limitinterval=1"));
    $ukdemand = average($data);
    $ukdemandGWh = ($ukdemand * $totaltime) / 3600000;

    // Hydro
    $data = json_decode(file_get_contents("http://emoncms.org/feed/data.json?id=97703&start=$start&end=$end&interval=1800&skipmissing=0&limitinterval=1"));
    $ukhydro = average($data);
    $ukhydroGWh = ($ukhydro * $totaltime) / 3600000;

    // Get average wind output in this time
    $data = json_decode(file_get_contents("http://emoncms.org/feed/data.json?id=114934&start=$start&end=$end&interval=1800&skipmissing=0&limitinterval=1"));
    $sum = 0; $n = 0;
    for ($i=0; $i<count($data); $i++) {
        if ($data[$i][1]!=null) $sum += $data[$i][1];
        $n++;
    }
    $solar = $sum / $n;
    $solarGWh = ($solar * $totaltime) / 3600000;

    $ukdemandGWh += $solarGWh;
    $windprc = 100 * ($ukwindGWh / $ukdemandGWh);
    $solarprc = 100 * ($solarGWh / $ukdemandGWh);
    $hydroprc = 100 * ($ukhydroGWh / $ukdemandGWh);

    // --------------------------------------------------------------------------------------------------------

    $message = view("Modules/emailreport/emailview.php",array(
        "total"=>$total,
        "kwhday"=>$kwhday,
        "daily"=>$daily,
        "text_lastweek"=>$text_lastweek,
        "text_averagecmp"=>$text_averagecmp,
        "solarGWh"=>$solarGWh,
        "solarprc"=>$solarprc,
        "ukwindGWh"=>$ukwindGWh,
        "windprc"=>$windprc,
        "ukhydroGWh"=>$ukhydroGWh,
        "hydroprc"=>$hydroprc
    ));
    
    return array(
        "emailto"=>$emailto,
        "subject"=>$title.": Emoncms Energy Update: ".number_format($kwhday,1)."kWh/d",
        "message"=>$message
    );
}

function emailreport_send($redis,$emailreport)
{
    $redis->rpush("emailqueue",json_encode(array(
        "emailto"=>$emailreport['emailto'],
        "type"=>"weeklyenergyupdate",
        "subject"=>$emailreport['subject'],
        "message"=>$emailreport['message']
    )));
}

function average($data) {
    $sum = 0; $n = 0; $val = 0;
    for ($i=0; $i<count($data); $i++) {
        if ($data[$i][1]!=null) {
            $val = $data[$i][1]; 
        }
        $sum += $val;
        $n++;
    }
    return $sum / $n;
}
