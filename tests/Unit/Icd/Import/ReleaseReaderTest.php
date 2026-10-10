<?php

use App\Icd\Import\ImportException;
use App\Icd\Import\ReleaseReader;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/icd-test-'.bin2hex(random_bytes(6));
    mkdir($this->tmp);
});

afterEach(function () {
    array_map('unlink', glob($this->tmp.'/*'));
    rmdir($this->tmp);
});

it('strips the BOM and the CRLF from the header', function () {
    $header = ReleaseReader::fromTxt(icdFixture('format-2026-01.txt'))->header();

    expect($header)->toHaveCount(20)
        ->and($header[0])->toBe('Foundation URI')
        ->and($header[19])->toBe('Version:2026 Jan 17 - 05:30 UTC');
});

it('reads CRLF records and parses quoted TSV', function () {
    $records = readIcdRecords(ReleaseReader::fromTxt(icdFixture('format-2025-01.txt')), 17);

    expect($records)->toHaveCount(10)
        ->and(array_keys($records))->toBe([2, 3, 4, 5, 6, 7, 8, 9, 10, 11])
        ->and($records[2][4])->toBe('Synthetic chapter outside scope')
        ->and($records[2][9])->toBe('=hyperlink("https://icd.who.int/browse/latestrelease/mms/en#synthetic-ch01","browser")')
        ->and($records[11][16])->toBe('');
});

it('reassembles a record whose CodingNote spans three physical lines', function () {
    $records = readIcdRecords(ReleaseReader::fromTxt(icdFixture('format-2026-01.txt')), 19);

    expect($records)->toHaveCount(10)
        ->and(array_keys($records))->toBe([2, 3, 4, 5, 6, 7, 10, 11, 17, 18])
        ->and($records[7][2])->toBe('ZZ01.0')
        ->and($records[7][17])->toBe("Synthetic first paragraph of a coding note.\n\nSynthetic second paragraph of a coding note.")
        ->and($records[7][18])->toBe('http://id.who.int/icd/entity/synthetic/zz01')
        ->and($records[10][2])->toBe('ZZ01.Z');

    foreach ($records as $fields) {
        expect($fields)->toHaveCount(19);
    }
});

it('reassembles a record whose CodingNote is a bulleted list over six physical lines', function () {
    $records = readIcdRecords(ReleaseReader::fromTxt(icdFixture('format-2026-01.txt')), 19);

    expect($records[11][3])->toBe('BlockL1-ZZ1')
        ->and($records[11][17])->toBe(implode("\n", [
            'Synthetic block includes the following:',
            '• synthetic item one',
            '• synthetic item two',
            '• synthetic item three',
            '• synthetic item four',
            '• synthetic item five',
        ]))
        ->and($records[11][18])->toBe('http://id.who.int/icd/entity/synthetic/ch21')
        ->and($records[17][2])->toBe('ZZ02');
});

it('fails on a record with too many fields, naming the line where it starts', function () {
    readIcdRecords(ReleaseReader::fromTxt(icdFixture('too-many-fields.txt')), 19);
})->throws(ImportException::class, 'line 4');

it('fails when the file ends inside a record, naming the line where it starts', function () {
    readIcdRecords(ReleaseReader::fromTxt(icdFixture('eof-inside-record.txt')), 19);
})->throws(ImportException::class, 'line 5');

it('fails on a stray line between records, naming its line', function () {
    readIcdRecords(ReleaseReader::fromTxt(icdFixture('stray-line.txt')), 19);
})->throws(ImportException::class, 'starting on line 4 ends on line 4 with 1 fields, expected 19');

it('reads only the data .txt from the zip', function () {
    $path = makeIcdZip($this->tmp, [
        'readme.txt' => "not data\r\n",
        'SimpleTabulation-ICD-11-MMS-en.xlsx' => 'not a spreadsheet',
        'SimpleTabulation-ICD-11-MMS-en.txt' => file_get_contents(icdFixture('format-2026-01.txt')),
    ]);

    $reader = ReleaseReader::fromZip($path);

    expect($reader->header()[0])->toBe('Foundation URI')
        ->and(readIcdRecords($reader, 19))->toHaveCount(10);
});

it('fails on a zip without a data .txt', function () {
    ReleaseReader::fromZip(makeIcdZip($this->tmp, ['readme.txt' => 'x', 'data.xlsx' => 'x']));
})->throws(ImportException::class, 'no data .txt');

it('fails on a zip with several candidate .txt files, listing them', function () {
    ReleaseReader::fromZip(makeIcdZip($this->tmp, ['readme.txt' => 'x', 'a.txt' => 'x', 'b.TXT' => 'x']));
})->throws(ImportException::class, 'a.txt, b.TXT');

it('fails on a file that is not a zip, naming the path', function () {
    file_put_contents($path = $this->tmp.'/release.zip', 'not a zip file');

    ReleaseReader::fromZip($path);
})->throws(ImportException::class, 'as a zip file');

it('fails on a truncated zip, naming the path', function () {
    $zip = file_get_contents(makeIcdZip($this->tmp, ['data.txt' => file_get_contents(icdFixture('format-2026-01.txt'))]));
    file_put_contents($path = $this->tmp.'/truncated.zip', substr($zip, 0, intdiv(strlen($zip), 2)));

    ReleaseReader::fromZip($path);
})->throws(ImportException::class, 'truncated.zip');
