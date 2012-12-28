<?php
use Symfony\Component\DomCrawler\Crawler;

/**
 *  This class scrapes the Snopes site for various resources
 */

class SnopesScraper
{
    const OLD_STORIES = "oldStories";
    const NEW_STORIES = "newStories";
    const STORIES_PAGE = "storiesPage";
    const CATEGORY_PAGE = "categoryPage";

    const TRUTH_ICON = "green.gif";
    const FALSE_ICON = "red.gif";
    const MULTI_TRUTH_ICON = "multi.gif";
    const MIXED_ICON = "mixture.gif";
    const PARTLY_TRUE_ICON = "mostlytrue.gif";
    const UNDETERMINED_ICON = "yellow.gif";
    const UNCLASSIFIABLE_ICON = "white.gif";
    const LEGEND_ICON = "legend.gif";

    /**
     * Get an array of all the categories on Snopes
     *
     * return array
     */
    public static function getCategories()
    {
        $homepageHTML = file_get_contents("http://www.snopes.com/snopes.asp");
        echo "Loading " . "http://www.snopes.com/snopes.asp\n";

        $crawler = new Crawler($homepageHTML);
        $baseXPath = "/_root/html/body/div[1]/table[4]/tr/td/table/tr/td[2]/table[2]//a";

        $category = $crawler->filterXPath("$baseXPath");
        $categories = array();

        $counter = 0;
        foreach ($category as $categoryDOM) {
            $title = $categoryDOM->getAttribute("onmouseover");
            preg_match_all("/^.+\'(.+)\'/", $title, $result, PREG_PATTERN_ORDER);
            $title = self::clean($result[1][0]);
            $url = $categoryDOM->getAttribute("href");

            if (isset($categories[$title])) {
                continue;
            }

            $categories[$title] = array(
                "url" => $url,
                "subcategories" => self::getSubcategories($url)
            );
        }

        return $categories;
    }

    private static function getSubcategories($baseURL)
    {
        $categoryHTML = file_get_contents("http://www.snopes.com/" . $baseURL);
        echo "Loading " . "http://www.snopes.com/" . $baseURL . "\n";
        if (empty($categoryHTML)) {
            return array();
        }

        $subcategories = array();

        $empty = htmlqp($categoryHTML, "td[height='80']")->length;
        $subcatCount = htmlqp($categoryHTML, "td[height='80']")->find("a")->count();
        $parentURL = substr($baseURL, 0, strrpos($baseURL, "/"));


        for ($x = 0; $x < $subcatCount; $x++) {
            $title = self::clean(htmlqp($categoryHTML, "td[height='80']")->find("a")->eq($x)->text());
            $url = htmlqp($categoryHTML, "td[height='80']")->find("a")->eq($x)->attr("href");
            $url = $parentURL . "/" . $url;
            $description = htmlqp($categoryHTML, "td[height='80']")->find("font")->eq($x)->childrenText('|||');

            $description = self::clean(substr($description, strpos($description, "|||") + 3));

            $subcategories[$title] = array(
                "title" => $title,
                "url" => $url,
                "description" => $description
            );
        }

//            $categoryHTML = file_get_contents("tmp/crime.html");

//        $pageType = self::getPageType($categoryHTML);
//
//        $crawler = new Crawler($categoryHTML);
//
//        $parentURL = substr($baseURL, 0, strrpos($baseURL, "/"));
//        $baseXPath = "/_root/html/body/div[1]/table[3]/tr/td/table/tr/td[2]/table//a";
//
//        $subcategory = $crawler->filterXPath("$baseXPath");
//        $subcategories = array();
//
//        foreach ($subcategory as $subcategoryDOM) {
//            $description = self::clean($subcategoryDOM->parentNode->textContent);
//            $description = self::clean(
//                substr($description, strpos($description, "\r\n"))
//            );
//
//            $url = $parentURL . "/" . $subcategoryDOM->getAttribute(
//                "href"
//            );
//            $subcategories[self::clean($subcategoryDOM->nodeValue)] = array(
//                "url" => $url,
//                "description" => $description,
//                "summaries" => self::getStorySummaries($url)
//            );
//        }
//
//        // try a different xpath for older pages
//        if (count($subcategories) <= 0) {
//            $baseXPaths = array(
//                '//*[@id="main-content"]/table/tr/td[2]/center/font[2]/div/table//a',
//                '//*[@id="main-content"]/table/tr/td[2]/center/font[2]/div/table[2]//a',
//                '//*[@id="main-content"]/table/tr/td[2]/center/font[2]/div/table[3]//a',
//                '/_root/html/body/div[1]/table[3]/tr/td/table/tr/td[2]/div[2]/table//a'
//            );
//
//            foreach ($baseXPaths as $baseXPath) {
//                $subcategory = $crawler->filterXPath("$baseXPath");
//                if ($subcategory->count() > 0) {
//                    break;
//                }
//            }
//
//            $subcategories = array();
//            foreach ($subcategory as $subcategoryDOM) {
//                $title = self::clean($subcategoryDOM->nodeValue);
//                if (empty($title)) {
//                    continue;
//                }
//
//                $description = self::clean($subcategoryDOM->parentNode->textContent);
//                $description = self::clean(
//                    substr($description, strpos($description, "\r\n"))
//                );
//
//                $url = $parentURL . "/" . $subcategoryDOM->getAttribute(
//                    "href"
//                );
//                $subcategories[$title] = array(
//                    "url" => $url,
//                    "description" => $description,
//                    "summaries" => self::getStorySummaries($url)
//                );
//
//                if (count($subcategories[$title]["summaries"]) <= 0) {
//                    $i = 0;
//                }
//            }
//        }

        return $subcategories;
    }

    /**
     * Get a list of stories related to a category
     *
     * @param        $baseURL
     *
     * @return array
     */
    public static function getStorySummaries($baseURL)
    {
        if (substr($baseURL, 0, 1) != "/") {
            $baseURL = "/" . $baseURL;
        }

        $categoryHTML = @file_get_contents("http://www.snopes.com" . $baseURL);
        echo "\tLoading " . "http://www.snopes.com/" . $baseURL . "\n";
        if (empty($categoryHTML)) {
            return array();
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($categoryHTML);

        $q = new DOMXPath($dom);

        $story = htmlqp($categoryHTML, "table[width='90%'][align='CENTER']")->children("td")->last()->html();
        $story = str_replace("&#13;", "", $story);
        $story = str_replace("\r\n", "", $story);
        $story = str_replace("\n", "", $story);
        $story = str_replace("<br /><br />", "<br/><br/>", $story);
        $story = str_replace("<br/><br/>", "\n\n-----------------\n\n", $story);

        // try to parse list for pages like:
        // http://www.snopes.com/cokelore/cokelore.asp
        $result = self::parseNewSummaryPage($story);

        // otherwise, try to parse list for pages like:
        // http://www.snopes.com/military/military.asp
        if (!$result) {
            $result = self::parseOldSummaryPage($story);
        }


        return $result;
    }

    private static function findBestMatch(DOMDocument $dom, DOMXPath $q, array $xpaths, $url)
    {
        $bestMatch = null;
        $longestMatch = null;
        $matchLength = 0;

        foreach ($xpaths as $xpath) {
            $match = $q->query($xpath);
            if (!$match || $match->length <= 0) {
                continue;
            }

            $raw = $dom->saveHTML($match->item(0));

            $icons = array(
                self::TRUTH_ICON,
                self::FALSE_ICON,
                self::MULTI_TRUTH_ICON,
                self::MIXED_ICON,
                self::PARTLY_TRUE_ICON,
                self::UNDETERMINED_ICON,
                self::UNCLASSIFIABLE_ICON,
                self::LEGEND_ICON
            );

            $hasOneIcon = false;
            foreach ($icons as $icon) {
                if (strpos($raw, $icon) !== false) {
                    $hasOneIcon = true;
                    break;
                }
            }

            // check by non-existence of "rating key" string and existence of truth indicator icon(s)
            // - if no matches can be found with icons (which are more likely to be valid)), select the longest result
            if (strpos(strtolower($raw), "ratings key") === false) {
                if ($hasOneIcon) {
                    $bestMatch = $match;
                } elseif (!$hasOneIcon) {
                    $longestMatch = $match;
                    $matchLength = strlen($raw);
                }
            }
        }

        $domH = null;
        if (!$bestMatch) {
//            $domH = $dom->saveHTML();

            if ($longestMatch) {
                $bestMatch = $longestMatch;
            }
        }

        return $bestMatch;
    }

    private static function getClassificationByImg($imgURL)
    {
        $imgURL = substr($imgURL, strrpos($imgURL, "/") + 1);
        switch ($imgURL) {
            case self::TRUTH_ICON:
                return "true";
                break;
            case self::FALSE_ICON:
                return "false";
                break;
            case self::MULTI_TRUTH_ICON:
                return "multiple truths";
                break;
            case self::MIXED_ICON:
                return "mixture";
                break;
            case self::PARTLY_TRUE_ICON:
                return "partly true";
                break;
            case self::UNDETERMINED_ICON:
                return "undetermined";
                break;
            case self::UNCLASSIFIABLE_ICON:
                return "unclassifiable";
                break;
            case self::LEGEND_ICON:
                return "legend";
                break;
        }

        return null;
    }

    private static function getPageType($pageHTML)
    {
        $pageHTML = strtolower($pageHTML);
        if (strpos($pageHTML, '<b>ratings key</b>') !== false) {
            return self::STORIES_PAGE;
        }

        return self::CATEGORY_PAGE;
    }

    private static function parseNewSummaryPage($story)
    {
        preg_match_all(
            '%(<a name="\w+"></a>)?<img src="(.+)" width="?36"? height="?36"? alt=".+" title=".+" align="?absmiddle"? /><font.+\+1"?>.+</font.+<a href="/?(.+)" onmouseover="window.status=\'.+\';return.+>(.+)</a>.+<br />(.+)$%im',
            $story,
            $result,
            PREG_PATTERN_ORDER
        );

        if (count($result) == 0 || count($result[0]) == 0) {
            return null;
        }

        $objs = array();
        $counter = 0;
        for ($i = 0; $i < count($result[0]); $i++) {
            $rating = self::getClassificationByImg($result[2][$counter]);
            $url = self::clean($result[3][$counter]);
            $title = ucfirst(self::clean($result[4][$counter]));

            $summary = $result[5][$counter];
            $summary = preg_replace('%<font.+</font>%im', '', $summary);
            $summary = ucfirst(self::clean(preg_replace('%(</?[^<]+>)%im', '', $summary)));

            $objs[] = array(
                "rating" => $rating,
                "summary" => $summary,
                "title" => $title,
                "url" => $url
            );

            $counter++;
        }

        return $objs;
    }

    private static function parseOldSummaryPage($story)
    {
        preg_match_all(
            '%(<a name="\w+"></a>)?<img.+?src="(.+)".+?align="ABSMIDDLE"/>(.+)%im',
            $story,
            $result,
            PREG_PATTERN_ORDER
        );

        $objs = array();
        $counter = 0;
        for ($i = 0; $i < count($result[0]); $i++) {

            $title = ucfirst(
                self::clean(
                    preg_replace(
                        '%(.+)?<a href="([^"]+)"(?:.+status=\'([^\']+).+)?>(.+)</a>(.+)?%im',
                        '$3',
                        $result[3][$counter]
                    )
                )
            );

            $summary = self::clean(
                preg_replace(
                    '%(.+)?<a href="([^"]+)"(?:.+status=\'([^\']+).+)?>(.+)</a>(.+)?%im',
                    '$1$4$5',
                    $result[3][$counter]
                )
            );
            $summary = preg_replace('%<font.+</font>%im', '', $summary);
            $summary = preg_replace('%(</?[^<]+>)%im', '', $summary);
            $summary = ucfirst(self::clean($summary));

            $url = self::clean(
                preg_replace('%(.+)?<a href="([^"]+).+>(.+)</a>(.+)?%im', '$2', $result[3][$counter])
            );

            if ($summary == '= unclassifiable veracity' || $title == '= unclassifiable veracity' || $url == '= unclassifiable veracity') {
                continue;
            }

            $objs[] = array(
                "rating" => self::getClassificationByImg($result[2][$counter]),
                "summary" => $summary,
                "title" => $title,
                "url" => $url
            );

            $counter++;
        }

        return $objs;
    }

    private static function clean($str)
    {
        // trim whitespace
        $str = trim($str);
        // remove tags
        $str = preg_replace('%(</?[^<]+>)%im', '', $str);

        return $str;
    }
}
