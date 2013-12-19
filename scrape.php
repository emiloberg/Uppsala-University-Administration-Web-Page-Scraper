<?php

/**
 * @author Emil Öberg <emil.oberg@uadm.uu.se>
 * @version 1.0
 * @create-date 2012-06-13
 * @copyright WTFPL ( http://www.wtfpl.net/ )
 *
 * This script scrapes web pages at Uppsala University and puts the content in a database.
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
 * Instructions:	1) Create a mySQL database and enter the username/password/address/
 *					database name a few rows down.
 *
 *					2) Run the script in terminal or by deploying it on a web server and visiting
 *                  the page.
 *
 *					That's it! You can modify the other settings if you want to.
 *
 *
 *                  After running this script, you might want to run insert-help.php as well.
 *                  That script will loop through the database of scraped articles, as created by
 *                  this script and find all
 *                  - images (<img>), and
 *                  - links to InfoGlue attachments (<a href="/digitalAssets">)
 *
 *                  If an image or attachment link is found in an article it'll add
 *                  - 'Embedded image: <link>', or
 *                  - 'Linked file <link>',
 *                  to the bottom of the article. That way editors can;
 *                  - Search the Employee Portal for those two strings to correct any pages with broken
 *                    links to images and/or attachments.
 *                  - easily right click those links and save the assets for uploading into
 *                  the Employee Portal (http://mp.uu.se)
 *
 *                  You need to set the database settings at the top of insert-help.php as well.
 *
 * Fine print:		Must be run on a server where cURL is allowed.
 *
 */


/* ************************************************************************************ */
/* ************************************ SETTINGS ************************************** */
/* ************************************************************************************ */


$settings['db']['username'] = ''; 			// Database username
$settings['db']['password'] = ''; 			// Database password
$settings['db']['hostname'] = ''; 			// Database host
$settings['db']['database'] = ''; 			// Database name

$settings['startat'] = 'http://uadm.uu.se';		// URL to start the scrape at. WITHOUT TRAILING SLASH! 
                                                // Default: 'http://uadm.uu.se'.
											    // This value is only respected if cleardb is set to true. 
											    // If cleardb is set to false the script picks up where stopped.

$settings['validhosts'] = array("uadm.uu.se");	// Which domain(s) should be scraped? Default: 'uadm.uu.se'
											    // Multiple domains are ok, like this;
                                                // array("uadm.uu.se", "www.uppdragsutbildning.uu.se", "ull.uu.se")

$settings['debug']['cleardb'] = true;			// Empty database before run. true or false.
$settings['debug']['scrapemax'] = 50000; 		// Stop scraping after X pages. Script picks up where stopped when run again.


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
dbCreateTables();
if($settings['debug']['cleardb']) { dbDebugClearDB(); }
writeToLog("Fire Your Engines! I will now start scraping a maximum of ". $settings['debug']['scrapemax'] . " pages");
writeToLog('---------------------------------------------------------------------------------------------------------');
FireYourEngine();
writeToLog("Scrape Ended Successfully");
print '</pre>';

/**
 * And close things down with flushing.
 *
 */
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
 * Main loop. Get the next URL to be scraped from the database
 * This function is recursive and calls itself until there
 * a) are no more unindexed links in the database, or
 * b) until the max number of links has been indexed (set by $settings['debug']['scrapemax'])
 *
 */
function dbLoopLinks($dbhandle, $selected) {
    global $settings;

	$result = mysql_query("SELECT fid, furl, foriginid FROM links WHERE findexed = 0");
	while ($row = mysql_fetch_array($result)) {
		writeToLog("[" . $settings['counter'] . "] Scraping (Origin " . $row['foriginid'] . "): " . $row['furl']);
		scrapePage($row['furl'], $row['fid']);
		$settings['counter'] = $settings['counter'] + 1;
		if($settings['counter'] > $settings['debug']['scrapemax']) { 
			writeToLog('---------------------------------------------------------------------------------------------------------');		
			writeToLog("Exiting early: Number of scraped URLs reached set maximum of ". $settings['debug']['scrapemax']);
			return ; 			
		}
	}
	
	$result = mysql_query("SELECT fid, furl FROM links WHERE findexed = 0");
	if (!$result) die(mysql_error());
	$row = mysql_fetch_row($result);

	if($row[0] > 0) {
		dbLoopLinks($dbhandle, $selected);
	} else {
		writeToLog('All done! All pages are scraped. I can haz golden star?');
	}
	
}

/**
 * Scrape the page
 * - Check if the URL gives a 200 response (all okay, page exists) or not.
 * - If 200 response: 
 *      - Find the relevant content on the page and insert into the database. 
 *      - Also find all links on the page and insert them into the database for indexing. 
 *
 */
function scrapePage($url, $id) {
    global $settings;
	$httpcode = doesUrlExist($url);
	if($httpcode != '200') {
		$insert = "UPDATE links SET findexed = " . $httpcode . " WHERE fid = " . $id;
		mysql_query($insert) OR die(mysql_error());		
		writeToLog('Page above (id: ' . $id . ') could not be accessed. HTTP Code: ' . $httpcode);
		return false;
	}

	$settings['urltree'] = explode("/", rtrim(substr($url, 7, strlen($url)-7),"/"));
	$html = file_get_html($url);

	dbInsertNewLinks(findLinks($html), $id);
	dbUpdateAsIndexed($id);

	$pc = getContent($html, $url);	
	$pc['linkid'] = $id;
	$insert = "INSERT INTO articles (furl, flinkid, ftitle, farticleheader, farticlebody, fmetacontentlanguage, fmetaowner, fmetaDCCreatorPersonalName, fmetaDCCreatorPersonalNameAddress, fmetaDCDescription, fmetaDCDateXMetadataLastModified) VALUES ('" . mysql_real_escape_string($pc['url']) . "', '" . mysql_real_escape_string($pc['linkid']) . "', '" . mysql_real_escape_string($pc['title']) . "', '" . mysql_real_escape_string($pc['article']['header']) . "', '" . mysql_real_escape_string($pc['article']['content']) . "', '" . mysql_real_escape_string($pc['meta']['content-language']) . "', '" . mysql_real_escape_string($pc['meta']['owner']) . "', '" . mysql_real_escape_string($pc['meta']['DC.Creator.PersonalName']) . "', '" . mysql_real_escape_string($pc['meta']['DC.Creator.PersonalName.Address']) . "', '" . mysql_real_escape_string($pc['meta']['DC.Description']) . "', '" . $pc['meta']['DC.Date.X-MetadataLastModified'] . "');";

	mysql_query($insert) OR die(mysql_error());
	return true;
}

/**
 * Mark the URL as indexed and all done
 *
 */
function dbUpdateAsIndexed($id) {
	$insert = "UPDATE links SET findexed = 1 WHERE fid = " . $id;
	mysql_query($insert) OR die(mysql_error());		
}

/**
 * Takes a link and insert it into the database, ready for indexing,
 * if it's already not in the database.
 * 
 */
function dbInsertNewLinks($links, $scrapedpageid) {
	foreach($links as $link) {
	
		$link = strtolower($link);

		$result = mysql_query("SELECT COUNT(fid) FROM links WHERE furl ='" . mysql_real_escape_string($link) . "'");
		if (!$result) die(mysql_error());
		$row = mysql_fetch_row($result);

		if($row[0] == 0) {
			$insert = "INSERT INTO links (furl, foriginid) VALUES ('" . mysql_real_escape_string($link) . "', '" . $scrapedpageid . "')";
			mysql_query($insert) OR die(mysql_error());		
		}
			
	}
	return true;	
}

/**
 * Do the actual scraping.
 * Takes the HTML of the page and returns an array with the content in an
 * orderly way. Is the pages you're scraping changes, this is where you want to
 * hack.
 *
 */
function getContent($html, $url) {
	$content = array();
	
	$content['url'] = $url;	
	$content['title'] = utf8_decode(removeTrailingUU($html->find('title', 0)->plaintext));
	
	//Find owner
	$owner = $html->find('div#footer', 0)->innertext;
	$regexp = "<a\s[^>]*href=.*empInfo\?id\=(.*)\">(.*)<\/a>";
	if(preg_match_all("/$regexp/siU", $owner, $matches, PREG_SET_ORDER)) {	
		$owner = $matches[0][1];
		$content['meta']['owner'] = $owner;
	} else {
		$content['meta']['owner'] = "";
	}

	//Find metadata
	$str = $html->find('html', 0)->innertext;

	$regexp = '<meta http\-equiv\=\"content\-language\" content\=\"(.*)\"\/>';
	if(preg_match_all("/$regexp/siU", $str, $matches, PREG_SET_ORDER)) {	
		$content['meta']['content-language'] = $matches[0][1];
	}

	$regexp = '<meta name\=\"DC\.Creator\.PersonalName\" content\=\"(.*)\"';	
	if(preg_match_all("/$regexp/siU", $str, $matches, PREG_SET_ORDER)) {	
		$content['meta']['DC.Creator.PersonalName'] = utf8_decode($matches[0][1]);
	}
	
	$regexp = '<meta name\=\"DC\.Creator\.PersonalName\.Address\" content\=\"(.*)\"';	
	if(preg_match_all("/$regexp/siU", $str, $matches, PREG_SET_ORDER)) {	
		$content['meta']['DC.Creator.PersonalName.Address'] = $matches[0][1];
	}

	$regexp = '<meta name\=\"DC\.Date\.X\-MetadataLastModified" content\=\"(.*)\"';	
	if(preg_match_all("/$regexp/siU", $str, $matches, PREG_SET_ORDER)) {	
		$content['meta']['DC.Date.X-MetadataLastModified'] = substr($matches[0][1], 0, 19);
	}

	$regexp = '<meta name\=\"DC\.Description" content\=\"(.*)\"';	
	if(preg_match_all("/$regexp/siU", $str, $matches, PREG_SET_ORDER)) {	
		$content['meta']['DC.Description'] = $matches[0][1];
	}
	
	//Find article header and body text
	$item['header'] = utf8_decode($html->find('div#centerCol div.header h1, div#centerColWide div.header h1', 0)->plaintext);
	$item['content'] = cleanupText($html->find('div#centerCol div.articleText, div#centerColWide div.articleText', 0)->innertext);		
	$content['article'] = $item;
	
	return $content;
	
}

/**
 * Do some cleanup to the scraped text.
 *
 */
function cleanupText($str) {
	$str = str_replace('<!-- RSPEAK_START -->', "", $str);
	$str = str_replace('<!-- RSPEAK_STOP -->', "", $str);
	$str = trim($str);
	return $str;
}

/**
 * Take the HTML and return an array with all links in it
 *
 */
function findLinks($html) {
    global $settings;
	$links = array();
	$regexp = "<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
	if(preg_match_all("/$regexp/siU", $html, $matches, PREG_SET_ORDER)) {
	
		foreach($matches as $match) {
			$link = appendUrlPrefix($match[2], $settings['urltree'][0]);
			$link = RemoveNoneHTMLURLs($link);			
			$link = removeTrailAfterHash($link);			
			$link = removeTrailingSlash($link);
			$link = removeTrailAfterQuestionmark($link);
			$link = removeAtInUrl($link);
			$link = removeTrailingSlash($link);	//Yes I know I did this a couple of lines up as well. So sue me!
			if(isValidHost($link)) {
				array_push($links, $link);
			}
		}
		return $links;
	}
}

/**
 * Clean up the <title>
 *
 */
function removeTrailingUU($str) {
	if(substr($str, -22) == " - Uppsala universitet") {
		$str = substr($str, 0, strlen($str)-22);
	}

	if(substr($str, -29) == " - Uppsala University, Sweden") {
		$str = substr($str, 0, strlen($str)-29);
	}	
	
	return $str;
}

/**
 * Add 'http://' if the inputed URL is relative (starting with a slash)
 *
 */
function appendUrlPrefix($urlstr, $urlroot) {
	if(substr($urlstr, 0, 1) == "/") {
		$urlstr = 'http://' . $urlroot . $urlstr;
	}
	return $urlstr;
}

/**
 * Ignore page links by removing everything after a hash sign in the URL.
 * Makes http://uadm.uu.se/uppdragsutbildning#toc into 
 * http://uadm.uu.se/uppdragsutbildning
 *
 */
function removeTrailAfterHash($url) {
	if(strpos($url, '#') > 0) {
		$url = substr($url, 0, strpos($url, '#'));
	}
	return $url;
}

/**
 * Ignore query string variables by removing everything
 * after a question mark in the URL
 *
 */
function removeTrailAfterQuestionmark($url) {
	if(strpos($url, '?') > 0) {
		$url = substr($url, 0, strpos($url, '?'));
	}
	return $url;
}

/**
 * Hack to remove strange links found on the pages
 * Makes http://selma-support@uadm.uu.se/uppdragsutbildning into 
 * http://uadm.uu.se/uppdragsutbildning
 */
function removeAtInUrl($url) {
	$urltree = explode("/", substr($url, 7, strlen($url)-7));	
	if(strpos($urltree[0], '@') > 0) {
		$urltree[0] = substr($urltree[0], strpos($urltree[0], '@')+1);
		$url = 'http://' . implode('/', $urltree);
	}
	return $url;
}

/**
 * Make sure URL:s with or without a trailing slash are treated as the same
 * by removing the trailing slash if one exists.
 * 
 */
function removeTrailingSlash($urlstr) {
	if(substr($urlstr, -1) == "/") {
		$urlstr = substr($urlstr, 0, strlen($urlstr)-1);
	}
	return $urlstr;
}

/**
 * Check if the URL is within the range of the valid hosts
 * set in $settings['validhosts']
 *
 */
function isValidHost($urlstr) {
    global $settings;
	$parsedurl = parse_url($urlstr);
	$urlhost = (string) $parsedurl['host'];	
	return in_array($urlhost, $settings['validhosts'], false);
}

/**
 * Remove URL:s which doesn't lead to a page we want to scrape
 *
 */
function RemoveNoneHTMLURLs($urlstr) {

	//Removes mail links
	if(strpos($urlstr, 'mailto:') > -1) {
		$urlstr = "";					
	}

	//Removes links to InfoGlue attachments
	if(strpos($urlstr, 'digitalAssets') > -1) {
		$urlstr = "";		
	}

	//Removes links to English pages
	if(strpos($urlstr, '?languageId=') > -1) {
		$urlstr = "";		
	}

	//Removes links to the news database
	if(strpos($urlstr, 'insidans-nyheter-dokumentvisning') > -1) {
		$urlstr = "";		
	}

    //Removes links to news
	if(strpos($urlstr, 'tarContentId') > -1) {
		$urlstr = "";		
	}	
	
	return $urlstr;
}


/**
 * Check to see if an URL exists and if so,
 * return the response code
 * 
 */
function doesUrlExist($url){
    $agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)";
    $ch = curl_init();
    curl_setopt ($ch, CURLOPT_URL,$url );
    curl_setopt($ch, CURLOPT_USERAGENT, $agent);
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt ($ch,CURLOPT_VERBOSE,false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $page=curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($httpcode >= 200 && $httpcode < 300){ 
        return 200;
    }
    else {
        return $httpcode;
    }
}

/**
 * Wipe the database clean,
 * This is done if $settings['debug']['cleardb'] is set to true.
 * 
 * After truncating the databae, insert the starting url set by
 * $settings['startat']
 *
 */
function dbDebugClearDB() {
    global $settings;
    $dbhandle = mysql_connect($settings['db']['hostname'], $settings['db']['username'], $settings['db']['password']) or die("Unable to connect to MySQL");
    $selected = mysql_select_db($settings['db']['database'],$dbhandle) or die("Could not connect to database");

    $sql = "TRUNCATE TABLE links";
    mysql_query($sql) OR die(mysql_error());
    $sql = "INSERT INTO links (furl) VALUES ('" . $settings['startat'] . "')";
    mysql_query($sql) OR die(mysql_error());
    $sql = "TRUNCATE TABLE articles";
    mysql_query($sql) OR die(mysql_error());

    mysql_close($dbhandle);

    writeToLog('Emptied database');
    writeToLog('Starting point set to ' . $settings['startat']);
}

/**
 * Create the tables we're going to use.
 *
 */
function dbCreateTables() {
    global $settings;

	$dbhandle = mysql_connect($settings['db']['hostname'], $settings['db']['username'], $settings['db']['password']) or die(mysql_error());
	$selected = mysql_select_db($settings['db']['database'], $dbhandle) or die(mysql_error());

	if(!mysql_num_rows(mysql_query("SHOW TABLES LIKE 'links'"))) {
		$sql = "CREATE TABLE `links` (`fid` int(11) NOT NULL AUTO_INCREMENT,`foriginid` int(11) NOT NULL,`findexed` int(11) NOT NULL DEFAULT '0',`furl` text COLLATE utf8_swedish_ci NOT NULL,`ftitle` text COLLATE utf8_swedish_ci NOT NULL,PRIMARY KEY (`fid`)) ENGINE=MyISAMDEFAULT CHARSET=utf8 COLLATE=utf8_swedish_ci AUTO_INCREMENT=1816 ;";
		mysql_query($sql) OR die(mysql_error());
		$sql = "TRUNCATE TABLE links";
		mysql_query($sql) OR die(mysql_error());			
		writeToLog('Created table: links');
	}
	
	if(!mysql_num_rows(mysql_query("SHOW TABLES LIKE 'articles'"))) {
		$sql = "CREATE TABLE `articles` (`fid` int(11) NOT NULL AUTO_INCREMENT,`flinkid` mediumint(9) NOT NULL,`furl` text COLLATE utf8_swedish_ci NOT NULL,`ftitle` text COLLATE utf8_swedish_ci NOT NULL,`fmetacontentlanguage` text COLLATE utf8_swedish_ci NOT NULL,`fmetaowner` text COLLATE utf8_swedish_ci NOT NULL,`fmetaDCCreatorPersonalName` text COLLATE utf8_swedish_ci NOT NULL,`fmetaDCCreatorPersonalNameAddress` text COLLATE utf8_swedish_ci NOT NULL,`fmetaDCDateXMetadataLastModified` datetime NOT NULL,`fmetaDCDescription` text COLLATE utf8_swedish_ci NOT NULL,`farticleheader` text COLLATE utf8_swedish_ci NOT NULL,`farticlebody` mediumtext COLLATE utf8_swedish_ci NOT NULL,PRIMARY KEY (`fid`)) ENGINE=MyISAMDEFAULT CHARSET=utf8 COLLATE=utf8_swedish_ci AUTO_INCREMENT=1644 ;";
		mysql_query($sql) OR die(mysql_error());
		$sql = "TRUNCATE TABLE articles";
		mysql_query($sql) OR die(mysql_error());					
		writeToLog('Created table: articles');
	}
	
	$result = mysql_query("SELECT COUNT(fid) FROM links");
	if (!$result) die(mysql_error());
	$row = mysql_fetch_row($result);
	if($row[0] == 0) {
		$sql = "INSERT INTO links (furl) VALUES ('" . $settings['startat'] . "')";
		mysql_query($sql) OR die(mysql_error());
		writeToLog('Start point set to ' . $settings['startat']);
	}
	
	mysql_close($dbhandle);
		
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
