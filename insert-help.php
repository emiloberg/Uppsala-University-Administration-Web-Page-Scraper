<?php

/**
 * @author Emil Öberg <emil.oberg@uadm.uu.se>
 * @version 1.0
 * @create-date 2012-06-13
 * @copyright WTFPL ( http://www.wtfpl.net/ )
 *
 * This script will loop through the database of scraped articles, as created by scrape.php
 * and find all
 * - images (<img>), and
 * - links to InfoGlue attachments (<a href="/digitalAssets">)
 *
 * If an image or attachment link is found in an article it'll add
 * - 'Embedded image: <link>', or
 * - 'Linked file <link>',
 * to the bottom of the article. That way editors can;
 * - Search the Employee Portal for those two strings to correct any pages with broken
 *   links to images and/or attachments.
 * - easily right click those links and save the assets for uploading into
 *   the Employee Portal (http://mp.uu.se)
 *
 * Intended use:	Scrape the content of the University Administration web pages at
 *					http://uadm.uu.se/* for migration to the Employee Portal (http://mp.uu.se).
 *
 *					The scraper will stay within the list of valid hosts but
 *					doesn't have a maximum depth. It will scrape until it can't
 *					find any more unscraped links within the valid host(s). Or until
 *					it has reached the set maximum number ('scrapemax' in settings).
 *
 *
 * Instructions:	1) Enter the username/password/address/database name, a few rows down,
 *                     to the same database as created by scrape.php
 *
 *					2) Run the script in terminal or by deploying it on a web server and visiting
 *                     the page.
 *
 */



/* ************************************************************************************ */
/* ************************************ SETTINGS ************************************** */
/* ************************************************************************************ */

$settings['db']['username'] = 'root'; 			// Database username
$settings['db']['password'] = 'root'; 			// Database password
$settings['db']['hostname'] = 'localhost'; 		// Database host
$settings['db']['database'] = 'fweb'; 	        // Database name

$settings['debug']['maxloop'] = 5000; //Just loop X images, for debugging purposes.

/* ************************************************************************************ */
/* ********** DONT TOUCH ANYTHING BELOW IF YOU'RE NOT SURE WHAT YOU'RE DOING ********** */
/* ************************************************************************************ */



/**
 * Set flush so that we can send updates as the script runs.
 *
 */
if (ob_get_level() == 0) ob_start();
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

/**
 * HTML DOM Helper
 *
 */
include_once('simple_html_dom.php');

/**
 * Start things up
 *
 */
$settings['counter'] = 1;
print '<pre>';
writeToLog("Fire Your Engines!");
writeToLog('---------------------------------------------------------------------------------------------------------');
FireYourEngine();
writeToLog('---------------------------------------------------------------------------------------------------------');
writeToLog("Ended Successfully");
print '</pre>';

ob_end_flush();



/* ************************************ FUNCTIONS ************************************** */



/**
 * Initialize, connect to database and start running
 *
 */
function FireYourEngine() {
    global $settings;
	$dbhandle = mysql_connect($settings['db']['hostname'], $settings['db']['username'], $settings['db']['password']) or die("Unable to connect to MySQL");
	$selected = mysql_select_db($settings['db']['database'],$dbhandle) or die("Could not connect to database");
	return dbLoopLinks($dbhandle, $selected);	
	mysql_close($dbhandle);
}

/**
 * Main loop. Get all the articles to be handled
 *
 */
function dbLoopLinks($dbhandle, $selected) {
    global $settings;
	$result = mysql_query("SELECT * FROM  `articles`");
	$i = 0;
	while ($row = mysql_fetch_array($result)) {
		$aBody = $row['farticlebody'];
		$aUrl = $row['furl'];
		$aId = $row['fid'];

		findImages($aUrl, $aId, $aBody);
		findLinks($aUrl, $aId, $aBody);
		
		$i++;
		if($i >= $settings['debug']['maxloop']) break;
	}
	writeToLog('All done, pages with images: ' . $i);
	return 0;
}


/**
 * Find all images in an article and add 'Embedded image: <image link>'
 * to the bottom of the same article.
 *
 */
function findImages($url, $fid, $farticlebody) {
	$regexp = "<img\s[^>]*src=(\"??)([^\" >]*?)\\1[^>]*>";
	if(preg_match_all("/$regexp/siU", $farticlebody, $matches, PREG_SET_ORDER)) {
		foreach($matches as $match) {
			if(strlen($match[2]) > 0) {
				$image = 'http://' . parse_url($url, PHP_URL_HOST) . $match[2];
				$farticlebody = $farticlebody . '<p>Embedded image: <a target="_blank" href="' . $image . '">' . $image . '</a></p>';
				}
		}
		updateBody($farticlebody, $fid);
	}
	return $farticlebody;	
}

/**
 * Find all links to InfoGlue attached files in an article and add 
 * 'Linked file: <file link>' to the bottom of the same article.
 *
 */
function findLinks($url, $fid, $farticlebody) {
	$regexp = "<a\s[^>]*href\s*=\s*(\"??)\/digitalAssets\/([^\" >]*?)\\1[^>]*>(.*)<\/a>";
	if(preg_match_all("/$regexp/siU", $farticlebody, $matches, PREG_SET_ORDER)) {
		foreach($matches as $match) {
			if(strlen($match[2]) > 0) {
				$linkurl = 'http://' . parse_url($url, PHP_URL_HOST) . '/digitalAssets/' . $match[2];
				$farticlebody = $farticlebody . '<p>Linked file: <a target="_blank" href="' . $linkurl . '">' . $match[3] . '</a></p>';
				}
		}
		updateBody($farticlebody, $fid);		
	}	
}

/**
 * Update the article text
 *
 */
function updateBody($newBody, $id){
		$insert = "UPDATE articles SET farticlebody = '" . mysql_real_escape_string($newBody) . "' WHERE fid = " . $id;
		mysql_query($insert) OR die(mysql_error());			
		writeToLog('Updated: ' . $id);
}

/**
 * Log what's happening to screen
 *
 */
function writeToLog($str){
	echo $str;
	echo "<br>";
	echo str_pad('',4096)."\n";

	ob_flush();
	flush();
}



// EOF - grab your towel and don't panic!