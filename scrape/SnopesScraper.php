<?php
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
//        echo "Loading " . "http://www.snopes.com/snopes.asp\n";

        // Typical category nodes on this page are identified as follows:
        /*
         * <td align="LEFT" valign="CENTER"><a href="autos/autos.asp" onmouseover="window.status='Automobiles';return true" onmouseout="window.status='';return true">
         * <img src="graphics/icons/main/autos.gif" border="0" valign="ABSMIDDLE"></a></td>
         *
         * <td align="LEFT" valign="CENTER"><a href="autos/autos.asp" onmouseover="window.status='Automobiles';return true" onmouseout="window.status='';return true">
         * <font face="Verdana"><b>Autos</b></font></a></td>
        */

        // There are two <td> nodes for each category - one for the image, one for the text - we need to select
        // the <td> elements without constituent <img> tags for the text data

        $catCount = htmlqp($homepageHTML, "td[align='LEFT'][valign='CENTER']:not(img)")->count();
        $categories = array();

        for ($x = 0; $x < $catCount; $x++) {
            $title = self::clean(htmlqp($homepageHTML, "td[align='LEFT'][valign='CENTER']:not(img)")->eq($x)->text());
            if (empty($title)) {
                continue;
            }

            $url = htmlqp($homepageHTML, "td[align='LEFT'][valign='CENTER']:not(img)")->eq($x)->find("a")->attr("href");
            $icon = htmlqp($homepageHTML, "td[align='LEFT'][valign='CENTER']:not(font)")->eq($x)->find("img")->attr(
                "src"
            );

            $categories[$title] = array(
                "title" => $title,
                "url" => $url,
                "icon" => $icon,
                "subcategories" => self::getSubcategories($url)
            );

            if (empty($categories[$title]["subcategories"])) {
                $categories[$title]["stories"] = self::getStorySummaries($url);
            }
        }

        return $categories;
    }

    /**
     * Get all the subcategories for a given category (provided by the $baseURL argument)
     *
     * @param $baseURL
     * @return array
     */
    private static function getSubcategories($baseURL)
    {
        $categoryHTML = file_get_contents("http://www.snopes.com/" . $baseURL);
        // echo "Loading " . "http://www.snopes.com/" . $baseURL . "\n";
        if (empty($categoryHTML)) {
            return array();
        }

        $subcategories = array();

        $subcatCount = htmlqp($categoryHTML, "td[height='80']")->find("a")->count();
        $parentURL = substr($baseURL, 0, strrpos($baseURL, "/"));
        $separator = '|||';
        for ($x = 0; $x < $subcatCount; $x++) {
            try {
                $title = self::clean(htmlqp($categoryHTML, "td[height='80']")->eq($x)->find("a")->text());
                if (empty($title)) {
                    continue;
                }

                // get the current element's previous sibling, which should contain an icon
                $icon = htmlqp($categoryHTML, "td[height='80']")->eq($x)->prev()->find("img")->attr("src");

                $url = htmlqp($categoryHTML, "td[height='80']")->eq($x)->find("a")->attr("href");
                $url = $parentURL . "/" . $url;

                $description = htmlqp($categoryHTML, "td[height='80']")->eq($x)->find("font")->childrenText($separator);
            } catch (Exception $e) {
                continue;
            }

            if (strpos($description, $separator) !== false) {
                $description = explode($separator, $description);

                // always remove first element because it'll be the contents of the <a> tag and we already have that
                array_shift($description);
                $description = self::clean(implode(" ", $description));
            }

            $subcategories[$title] = array(
                "title" => $title,
                "url" => $url,
                "icon" => $icon,
                "description" => $description,
                "stories" => self::getStorySummaries($url)
            );
        }

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
        // echo "\tLoading " . "http://www.snopes.com/" . $baseURL . "\n";
        if (empty($categoryHTML)) {
            return array();
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($categoryHTML);

        $q = new DOMXPath($dom);

        $storiesHTML = htmlqp($categoryHTML, "table[width='90%'][align='CENTER']")->children("td")->last()->html();
        $storiesHTML = str_replace("&#13;", "", $storiesHTML);
        $storiesHTML = str_replace("\r\n", "", $storiesHTML);
        $storiesHTML = str_replace("\n", "", $storiesHTML);
        $storiesHTML = str_replace("<br /><br />", "<br/><br/>", $storiesHTML);
        $storiesHTML = str_replace("<br/><br/>", "\n\n-----------------\n\n", $storiesHTML);

        return self::parseStoriesPage($storiesHTML);
    }

    /**
     * Parse stories summary page
     *
     * @param $storiesHTML
     * @return array|null
     */
    private static function parseStoriesPage($storiesHTML)
    {
        // try parsing "newer" page format, otherwise fall back to "older" page parsing
        $parsed = self::parseNewSummaryPage($storiesHTML);
        if (!$parsed) {
            return self::parseOldSummaryPage($storiesHTML);
        }

        return $parsed;
    }

    /**
     * Parse "newer" page format like:
     * http://www.snopes.com/cokelore/cokelore.asp
     *
     * @param $storiesHTML
     * @return array|null
     */
    private static function parseNewSummaryPage($storiesHTML)
    {
        preg_match_all(
            '%(<a name="\w+"></a>)?<img src="(.+)" width="?36"? height="?36"? alt=".+" title=".+" align="?absmiddle"? /><font.+\+1"?>.+</font.+<a href="/?(.+)" onmouseover="window.status=\'.+\';return.+>(.+)</a>.+<br />(.+)$%im',
            $storiesHTML,
            $result,
            PREG_PATTERN_ORDER
        );

        if (count($result) == 0 || count($result[0]) == 0) {
            return null;
        }

        $summaries = array();
        $counter = 0;
        for ($i = 0; $i < count($result[0]); $i++) {
            $rating = self::getClassificationByImg($result[2][$counter]);
            $url = trim($result[3][$counter]);
            $title = ucfirst(self::clean($result[4][$counter]));

            $summary = $result[5][$counter];
            $summary = preg_replace('%<font.+</font>%im', '', $summary);
            $summary = ucfirst(self::clean(preg_replace('%(</?[^<]+>)%im', '', $summary)));

            $summaries[] = array(
                "rating" => $rating,
                "summary" => $summary,
                "title" => $title,
                "url" => $url
            );

            $counter++;
        }

        return $summaries;
    }

    /**
     * Parse "older" page format like:
     * http://www.snopes.com/military/military.asp
     *
     * @param $storiesHTML
     * @return array
     */
    private static function parseOldSummaryPage($storiesHTML)
    {
        preg_match_all(
            '%(<a name="\w+"></a>)?<img.+?src="(.+)".+?align="ABSMIDDLE"/>(.+)%im',
            $storiesHTML,
            $result,
            PREG_PATTERN_ORDER
        );

        $summaries = array();
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

            $url = trim(preg_replace('%(.+)?<a href="([^"]+).+>(.+)</a>(.+)?%im', '$2', $result[3][$counter]));

            if ($summary == '= unclassifiable veracity' || $title == '= unclassifiable veracity' || $url == '= unclassifiable veracity') {
                continue;
            }

            $summaries[] = array(
                "rating" => self::getClassificationByImg($result[2][$counter]),
                "summary" => $summary,
                "title" => $title,
                "url" => $url
            );

            $counter++;
        }

        return $summaries;
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

    private static function clean($str)
    {
        // trim excessive whitespace (internal and external)
        // notice that 2 or more "blank" unicode chars and spaces is shortened to a single space
        // - there is a slight different between "blank" chars and spaces for some reason in PCRE regex
        $str = trim(preg_replace('/([  \s]{2,})+/', ' ', $str));
        // remove tags
        $str = preg_replace('%(</?[^<]+>)%im', '', $str);

        return $str;
    }
}
