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
    | Where downloaded release files are kept, one folder per version. A local
    | release file placed at {storage_path}/{version}/release.zip is used as if
    | it had been downloaded.
    */
    'storage_path' => storage_path('app/icd'),

    'download' => [
        // Pause between the three download attempts; 0 in tests.
        'retry_delay_ms' => (int) env('ICD_DOWNLOAD_RETRY_DELAY_MS', 1000),
    ],

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
