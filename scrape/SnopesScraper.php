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
            echo "Fetching $baseURL\n";

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

//            self::getStories($baseURL);

//            print_r($subcategories);
            return $subcategories;
        }

        /**
         * Get a list of stories related to a category
         *
         * @param        $baseURL
         * @param string $version
         */
        private static function getStories($baseURL, $version = self::NEW_STORIES)
        {
            $categoryHTML = file_get_contents("http://www.snopes.com" . $baseURL);
//            $crawler      = new Crawler($categoryHTML);

//            $baseXPath = "/_root/html/body/div[1]/table[3]/tr/td/table/tr/td[2]/div[2]/table/tr/td";
//            $stories   = $crawler->filterXPath("$baseXPath");

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($categoryHTML);

//            $sequence = array();
//            switch($version)
//            {
//                case self::OLD_STORIES:
//                    break;
//                case self::NEW_STORIES:
//                    $sequence = array("a", "font", "b", "a");
//                    break;
//            }

            $q = new DOMXPath($dom);
            $s = $q->query("/html/body/div[1]/table[3]/tr/td/table/tr/td[2]/div[2]/table/tr/td");

            foreach ($s as $story) {
                $story = $dom->saveHTML($story);
                $story = str_replace("<br><br>", "\n\n-----------------\n\n", $story);
                $story = str_replace("\r\n", "", $story);

//                if(strpos($story, "href") !== false)
//                    echo "\n\n";

                preg_match_all(
                    '%(<a name="\w+"></a>)?<img src=".+" width="?36"? height="?36"? alt=".+" title="(.+)" align="?absmiddle"?><font.+\+1"?>.+</font.+<a href="/?(.+)" onmouseover="window.status=\'.+\';return.+>(.+)</a>.+<br>(.+)$%im',
                    $story,
                    $result,
                    PREG_PATTERN_ORDER
                );

                echo $story;
                print_r($result);

//                echo $story->tagName.":".$story->nodeValue."\n";
            }

//            die();
        }

        private static function getPageType($pageHTML)
        {
            $pageHTML = strtolower($pageHTML);
            if (strpos($pageHTML, '<b>ratings key</b>') !== false) {
                return self::STORIES_PAGE;
            }

            return self::CATEGORY_PAGE;
        }
    }
