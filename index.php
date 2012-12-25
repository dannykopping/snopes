<?php
    /**
     *
     */

    require_once "vendor/autoload.php";
    require_once "scrape/SnopesScraper.php";
    require 'vendor/querypath/QueryPath/src/QueryPath/QueryPath.php';

    $c = SnopesScraper::getCategories();
    foreach($c as $category)
    {
        if(count($category["subcategories"]) <= 0)
            echo $category["url"].":::::".count($category["subcategories"])."\n";
    }

    file_put_contents("data.txt", print_r($c, true));
