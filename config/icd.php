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
        // 0.4 measured against ten misspellings on 2026-01: 0.3 adds noise, 0.5 loses alzhiemer.
        'trigram_threshold' => 0.4,
    ],

    /*
    | WHO attribution, sent as meta.attribution on every successful API
    | response (CC BY-ND 3.0 IGO).
    */
    'attribution' => 'ICD-11 for Mortality and Morbidity Statistics (ICD-11 MMS), World Health Organization (WHO), https://icd.who.int/. Licensed under CC BY-ND 3.0 IGO, https://creativecommons.org/licenses/by-nd/3.0/igo/.',

];
