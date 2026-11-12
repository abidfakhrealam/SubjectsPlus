<?php

    /**
     *   @file feedme.php
     *   @brief Decide what to do with an RSS-like request (could be flickr, delicious, rss)
     *
     *   @author adarby, jlittle
     *
     *   Hardened for 4.6.0: only public http(s) feeds are fetched (no file://, php://, internal
     *   addresses — see PGFeed::fetchSource), and everything taken from the remote feed is escaped or
     *   purified before it is printed, because the response is inserted into public guide pages.
     */
    include("../../control/includes/autoloader.php");
    include_once("../../control/includes/config.php");
    include_once("../../control/includes/functions.php");

    use SubjectsPlus\Control\PGFeed;

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $type   = (string) ($_POST["type"] ?? '');
    $feed   = trim((string) ($_POST["feed"] ?? ''));
    $source = "";

    if ($type == "RSS") {
        $source = $feed;
    } elseif ($type == "Delicious") {
        // http://feeds.delicious.com/v2/{format}/{username}/{tag[+tag+...+tag]}
        $source = "http://feeds.delicious.com/v2/rss/" . rawurlencode($feed);
    } elseif ($type == "Tumblr") {
        $source = preg_match('/^[A-Za-z0-9-]{1,64}$/', $feed) ? "https://" . $feed . ".tumblr.com/rss" : '';
    }

    if (!preg_match('#^https?://#i', $source)) {
        http_response_code(400);
        exit;
    }

    $count = sp_int($_POST["count"] ?? 5, 5);
    if ($count < 1 || $count > 50) {
        $count = 5;
    }
    $show_desc = (int) ($_POST["show_desc"] ?? 0);
    $show_feed = (int) ($_POST["show_feed"] ?? 0);

    $p = new PGFeed;
    $p->setOptions(1, $count, 0, 7200);
    if ($p->parse($source) === false) {
        exit;
    }
    $channel = $p->getChannel(); // gets data about the feed as a whole
    $items   = $p->getItems();   // gets news items

    $link = function ($u) {
        $u = trim((string) $u);
        return preg_match('#^https?://#i', $u) ? sp_h($u) : '#';
    };
    $desc = function ($d) {
        return sp_purifier()->purify((string) $d);
    };

    $class = ($type === 'Tumblr') ? '' : " class='rss-list'";
    print "<ul$class>";
    foreach ((array) $items as $i) {
        print "<li class='rss-list-item'><a href=\"" . $link($i['link'] ?? '') . "\" target=\"_blank\" rel=\"noopener\">" . sp_h(html_entity_decode((string) ($i['title'] ?? ''), ENT_QUOTES, 'UTF-8')) . "</a>";
        if ($show_desc == 1) {
            print "<br />" . $desc($i['description'] ?? '');
        }
        print "</li>";
    }
    print "</ul>\n";

    if ($show_feed == 1) {
        $cl = (string) ($channel['link'] ?? '');
        print "<p>source: <a href=\"" . $link($cl) . "\">" . sp_h($cl) . "</a></p>";
    }
