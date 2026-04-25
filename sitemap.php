<?php

$url = "https://qvbadminton.com/product-sitemap.xml";

// load sitemap
$xml = simplexml_load_file($url);

if (!$xml) {
    die("Không load được sitemap");
}

// loop toàn bộ URL
foreach ($xml->url as $item) {
    echo $item->loc . "<br>";
}