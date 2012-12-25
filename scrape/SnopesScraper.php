<?php
    use Symfony\Component\DomCrawler\Crawler;

    /**
     *  This class scrapes the Snopes site for various resources
     */

    class SnopesScraper
    {
        const OLD_STORIES   = "oldStories";
        const NEW_STORIES   = "newStories";
        const STORIES_PAGE  = "storiesPage";
        const CATEGORY_PAGE = "categoryPage";

        const TRUTH_ICON          = "green.gif";
        const FALSE_ICON          = "red.gif";
        const MULTI_TRUTH_ICON    = "multi.gif";
        const MIXED_ICON          = "mixture.gif";
        const PARTLY_TRUE_ICON    = "mostlytrue.gif";
        const UNDETERMINED_ICON   = "yellow.gif";
        const UNCLASSIFIABLE_ICON = "white.gif";
        const LEGEND_ICON         = "legend.gif";

        /**
         * Get an array of all the categories on Snopes
         *
         * return array
         */
        public static function getCategories()
        {
//            $homepageHTML = file_get_contents("http://www.snopes.com/");
            $homepageHTML = file_get_contents("tmp/homepage.html");

            $crawler   = new Crawler($homepageHTML);
            $baseXPath = "/_root/html/body/div[1]/table[4]/tr/td/table/tr/td[2]/table[2]//a";

            $category   = $crawler->filterXPath("$baseXPath");
            $categories = array();

            $counter = 0;
            foreach ($category as $categoryDOM) {
                $title = $categoryDOM->getAttribute("onmouseover");
                preg_match_all("/^.+\'(.+)\'/", $title, $result, PREG_PATTERN_ORDER);
                $title = trim($result[1][0]);
                $url   = $categoryDOM->getAttribute("href");

                if (isset($categories[$title])) {
                    continue;
                }

                $categories[$title] = array(
                    "url"           => $url,
                    "subcategories" => self::getSubcategories($url)
                );
            }

            return $categories;
        }

        private static function getSubcategories($baseURL)
        {
            $categoryHTML = file_get_contents("http://www.snopes.com" . $baseURL);
//            $categoryHTML = file_get_contents("tmp/crime.html");

            $pageType = self::getPageType($categoryHTML);
            if ($pageType != self::CATEGORY_PAGE) {
                return array();
            }

            $crawler = new Crawler($categoryHTML);
            echo "http://www.snopes.com$baseURL\n";

            $baseXPath = "/_root/html/body/div[1]/table[3]/tr/td/table/tr/td[2]/table//a";

            $subcategory   = $crawler->filterXPath("$baseXPath");
            $subcategories = array();

            foreach ($subcategory as $subcategoryDOM) {
                $description                                     = trim($subcategoryDOM->parentNode->textContent);
                $description                                     = trim(
                    substr($description, strpos($description, "\r\n"))
                );
                $subcategories[trim($subcategoryDOM->nodeValue)] = array(
                    "url"         => $subcategoryDOM->getAttribute("href"),
                    "description" => $description
                );
            }

            // try a different xpath for older pages
            if (count($subcategories) <= 0) {
                $baseXPath = '//*[@id="main-content"]/table/tr/td[2]/center/font[2]/div/table//a';

                $subcategory   = $crawler->filterXPath("$baseXPath");
                $subcategories = array();

                foreach ($subcategory as $subcategoryDOM) {

                    $description                                     = trim($subcategoryDOM->parentNode->textContent);
                    $description                                     = trim(
                        substr($description, strpos($description, "\r\n"))
                    );
                    $subcategories[trim($subcategoryDOM->nodeValue)] = array(
                        "url"         => $subcategoryDOM->getAttribute("href"),
                        "description" => $description
                    );
                }
            }

            return $subcategories;
        }

        /**
         * Get a list of stories related to a category
         *
         * @param        $baseURL
         */
        public static function getStorySummaries($baseURL)
        {
            $categoryHTML = file_get_contents("http://www.snopes.com" . $baseURL);

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($categoryHTML);

            $q = new DOMXPath($dom);

            // try to get list for pages like:
            // http://www.snopes.com/cokelore/cokelore.asp
            $s = $q->query("/html/body/div[1]/table[3]/tr/td/table/tr/td[2]/div[2]/table/tr/td");

            // otherwise, try to get list for pages like:
            // http://www.snopes.com/military/military.asp
            if ($s->length == 0) {
                $s = $q->query('//*[@id="main-content"]/table/tr/td[2]/center/font[2]/div/table[2]/tr/td');
            }

            foreach ($s as $story) {
                $story = $dom->saveHTML($story);
                $story = str_replace("<br><br>", "\n\n-----------------\n\n", $story);
                $story = str_replace("\r\n", "", $story);

                // try to parse list for pages like:
                // http://www.snopes.com/cokelore/cokelore.asp
                $result = self::parseNewSummaryPage($story);

                // otherwise, try to parse list for pages like:
                // http://www.snopes.com/military/military.asp
                if (!$result) {
                    $result = self::parseOldSummaryPage($story);
                }

                print_r($result);
            }
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
                '%(<a name="\w+"></a>)?<img src="(.+)" width="?36"? height="?36"? alt=".+" title=".+" align="?absmiddle"?><font.+\+1"?>.+</font.+<a href="/?(.+)" onmouseover="window.status=\'.+\';return.+>(.+)</a>.+<br>(.+)$%im',
                $story,
                $result,
                PREG_PATTERN_ORDER
            );

            if (count($result) == 0 || count($result[0]) == 0) {
                return null;
            }

            $objs    = array();
            $counter = 0;
            for ($i = 0; $i < count($result[0]); $i++) {
                $rating  = self::getClassificationByImg($result[2][$counter]);
                $url     = $result[3][$counter];
                $summary = $result[5][$counter];

                $objs[] = array(
                    "rating"  => $rating,
                    "summary" => $summary,
                    "url"     => $url
                );

                $counter++;
            }

            return $objs;
        }

        private static function parseOldSummaryPage($story)
        {
            preg_match_all(
                '%(<a name="\w+"></a>)?<img.+?src="(.+)".+?align="ABSMIDDLE">(.+)%im',
                $story,
                $result,
                PREG_PATTERN_ORDER
            );

            $objs    = array();
            $counter = 0;
            for ($i = 0; $i < count($result[0]); $i++) {

                $summary = preg_replace('%(.+)?<a href="([^"]+).+>(.+)</a>(.+)?%im', '$1$3$4', $result[3][$counter]);
                $summary = preg_replace('%<font.+</font>%im', '', $summary);
                $summary = preg_replace('%(</?[^<]+>)%im', '', $summary);
                $summary = trim($summary);

                $url = preg_replace('%(.+)?<a href="([^"]+).+>(.+)</a>(.+)?%im', '$2', $result[3][$counter]);

                $objs[] = array(
                    "rating"  => self::getClassificationByImg($result[2][$counter]),
                    "summary" => $summary,
                    "url"     => $url
                );

                $counter++;
            }

            return $objs;
        }
    }
