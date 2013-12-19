Uppsala University Administration Web Page Scraper
==================

This script scrapes web pages at Uppsala University and puts the content in a database.

## Intended use
Scrape the content of the University Administration web pages at http://uadm.uu.se/* for migration to the [http://mp.uu.se](Employee Portal).

The scraper will stay within the list of valid hosts but doesn't have a maximum depth. It will scrape until it can't find any more unscraped links within the valid host(s). Or until it has reached the set maximum number ('scrapemax' in settings).


## Instructions
1. Create a mySQL database and enter the username/password/address/ database at the top of scrape.php
2. Run the script in terminal or by deploying it on a web server and visiting the page.

That's it! You can modify the other settings if you want to.

### Help the editors
After running this script, you might want to run insert-help.php as well. That script will loop through the database of scraped articles, as created by this script and find all

* images (`<img>`), and
* links to InfoGlue attachments (`<a href="/digitalAssets">`)

If an image or attachment link is found in an article it'll add

* `Embedded image: <link>`, or
* `Linked file <link>`,

to the bottom of the article. That way editors can;

* Search the Employee Portal for those two strings to correct any pages with broken links to images and/or attachments.
* easily right click those links and save the assets for uploading into the [http://mp.uu.se](Employee Portal)


You need to set the database settings at the top of insert-help.php as well.

## Fine Print
Must be run on a server where cURL is allowed.


## License
[WTFPL](http://www.wtfpl.net/)

However, these script uses [PHP Simple HTML DOM Parser](http://sourceforge.net/projects/simplehtmldom/) which are licenced under the [MIT Licence](http://opensource.org/licenses/MIT)
