<?php
/**
 * Visitor Location — manual name translations.
 *
 * DB-IP City Lite localizes only COUNTRY names; city and region names come back
 * in English (or the native spelling for names without an English exonym). This
 * table supplies the missing translations.
 *
 * Structure:  [ country_code => [ target_lang => [ english_name => translation ] ] ]
 *
 *   country_code : ISO 3166-1 alpha-2 of the VISITOR's country. Grouping by it here
 *                  scopes every entry, so a US "Vienna" is never turned into "Wien".
 *   target_lang  : output language = the site locale's 2-letter code (e.g. 'de').
 *   english_name : the exact string the DB returns. City names are matched AFTER
 *                  stripping DB-IP's "(district)" suffix (see clean_city()).
 *
 * A place and its like-named region share one entry (e.g. "Vienna" covers both the
 * city and the state; "Geneva" the city and the canton). Names identical in English
 * and German need no entry. Extend freely — add a country block, a language block,
 * or a single name.
 *
 * Sources: region strings extracted from the shipped DB; city exonyms verified
 * against it. Rare ASCII-folded small towns (e.g. Solden→Sölden) can be added as
 * they surface.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return array(

    // ── Austria ──────────────────────────────────────────────────
    // Bundesländer (Burgenland, Salzburg, Vorarlberg identical → no entry).
    'AT' => array(
        'de' => array(
            'Vienna'            => 'Wien',              // city + state
            'State of Vienna'   => 'Wien',
            'Carinthia'         => 'Kärnten',
            'Lower Austria'     => 'Niederösterreich',
            'Upper Austria'     => 'Oberösterreich',
            'State of Salzburg' => 'Salzburg',
            'Styria'            => 'Steiermark',
            'Tyrol'             => 'Tirol',
            'Solden'            => 'Sölden',            // DB stores it ASCII-folded
        ),
    ),

    // ── Germany ──────────────────────────────────────────────────
    // Bundesländer (Rheinland-Pfalz, Mecklenburg-Vorpommern already German in DB).
    'DE' => array(
        'de' => array(
            'Bavaria'                            => 'Bayern',
            'Hesse'                              => 'Hessen',
            'Lower Saxony'                       => 'Niedersachsen',
            'North Rhine-Westphalia'             => 'Nordrhein-Westfalen',
            'Saxony'                             => 'Sachsen',
            'Saxony-Anhalt'                      => 'Sachsen-Anhalt',
            'Thuringia'                          => 'Thüringen',
            'Baden-Wurttemberg'                  => 'Baden-Württemberg',  // ASCII variant in DB
            'State of Berlin'                    => 'Berlin',
            'City state Bremen'                  => 'Bremen',
            'Free Hanseatic City of Bremen'      => 'Bremen',
            'Free and Hanseatic City of Hamburg' => 'Hamburg',
            // Cities (English exonyms)
            'Munich'                             => 'München',
            'Cologne'                            => 'Köln',
            'Nuremberg'                          => 'Nürnberg',
            'Hanover'                            => 'Hannover',
            'Brunswick'                          => 'Braunschweig',
        ),
    ),

    // ── Switzerland ──────────────────────────────────────────────
    // Cantons + cities (French/Italian → German). Bern, Basel, Chur, Aargau,
    // Thurgau, Solothurn, Zug, Glarus, Uri, Schwyz … are identical → no entry.
    'CH' => array(
        'de' => array(
            'Zurich'     => 'Zürich',       // city + canton
            'Geneva'     => 'Genf',
            'Lucerne'    => 'Luzern',
            'Grisons'    => 'Graubünden',
            'Ticino'     => 'Tessin',
            'Valais'     => 'Wallis',
            'Vaud'       => 'Waadt',
            'Neuchâtel'  => 'Neuenburg',
            'Fribourg'   => 'Freiburg',     // see note in summary
            'Basel-City' => 'Basel-Stadt',
            'Saint Gall' => 'St. Gallen',
        ),
    ),
);
