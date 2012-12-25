<?php
/**
 *
 */

require_once "vendor/autoload.php";
require_once "scrape/SnopesScraper.php";

    $urls = array(
"/autos/accident/accident.asp",
"/autos/erotica/erotica.asp",
"/cokelore/cokelore.asp",
//"/legal/legal.asp",
//"/military/military.asp",
"/glurge/glurge.asp",
    );

//    $c = SnopesScraper::getCategories();

    foreach($urls as $url)
    {
        echo "URL >>> $url\n\n\n";
        $c = SnopesScraper::getStorySummaries($url);
        print_r($c);
    }
