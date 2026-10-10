<?php

return [

    /*
    | WHO release file URL. {version} is replaced by the validated YYYY-MM
    | version argument.
    */
    'release_url' => env(
        'ICD_RELEASE_URL',
        'https://icdcdn.who.int/static/releasefiles/{version}/SimpleTabulation-ICD-11-MMS-en.zip',
    ),

    /*
    | Chapters kept at import. Strings, because ChapterNo is two digits with a
    | leading zero.
    */
    'chapters' => ['06', '15', '21', '24'],

    'search' => [
        // Provisional; chosen against real queries in task 5.7.
        'trigram_threshold' => 0.4,
    ],

];
