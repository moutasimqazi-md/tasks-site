<?php
declare(strict_types=1);
/**
 * One canonical name per person. The sheets' Assignee column is typed by hand
 * (first names, typos, "Naushad." ...), so the same person appears under many
 * spellings. GENERATED from the same list as the n8n "Find changes" node.
 * To add or fix a person, edit the list below (variant => canonical name).
 */

function people_map(): array
{
    return [
        'Abdul Vaheed' => ['Abdul', 'Aabdul'],
        'Abdulkhalik Valsangkar' => ['Abdul Khalik', 'Abdulkhalik'],
        'Abdul Shaikh' => [],
        'Abhay Gupta' => [],
        'Adarsh Kumar Rai' => [],
        'Akruti Patel' => [],
        'Alfiya Shaikh' => ['Alfiya'],
        'Aman Bhandari' => ['Aman'],
        'Anikeath Ekka' => [],
        'Annanya' => ['Annaya'],
        'Arshad Shaikh' => ['Arshad'],
        'Asad Dongri' => ['Asad'],
        'Binson Abraham' => [],
        'Faizahmed Mulla' => ['Faiz'],
        'Hamidabe Avula' => ['Hamda', 'Hamida', 'Hamidabe'],
        'Harsh Kumar' => ['Harsh'],
        'Husna' => [],
        'Ismail' => [],
        'Jashwanti' => [],
        'Jeevan Choudhary' => ['Jeevan', 'Jeevn'],
        'Kashish Dalal' => [],
        'Kevin Gondaliya' => ['Kevin'],
        'Lakshmi Saritha' => [],
        'Mahek Shaikh' => ['Mahak', 'Mahek', 'Mehak'],
        'Misba' => [],
        'Mitali Joshi' => ['Metali', 'Mitali'],
        'Umama Qazi' => ['Moutasim', 'Moutasim Qazi', 'Moutasin', 'Moutsim'],
        'Naushad Salami' => ['Nausahd', 'Naush', 'Naushad', 'Naushd'],
        'Neha Jethanandani' => ['Neha'],
        'Noor' => [],
        'Praveen' => [],
        'Premkishan Shrigopal Suvarnkar' => [],
        'Raza' => [],
        'Rehan Shaikh' => [],
        'Sabahat Shaikh' => ['Sabahat'],
        'Sadik Saiyed' => ['Sadik'],
        'Sakina Razvisayed' => ['Sakina'],
        'Sanchita Kore' => [],
        'Sanjay Lade' => [],
        'Sapna Kaintu' => [],
        'Shailaja Joshi' => ['Shaielja', 'Shailaja', 'Shaileja', 'Joshishailja23'],
        'Shreya' => [],
        'Shubham' => ['Shubam', 'Shuham'],
        'Siddesh' => [],
        'Sohel Sheikh' => ['Sohel'],
        'Subiddit' => [],
        'Sumit' => [],
        'Varsha Bansal' => ['Varsha', 'Varshad'],
        'Vijayapriya S' => [],
        'Yusuf Shaikh' => [],
        'Zoya Bandukwale' => [],
    ];
}

/** A single typed value that stands for several people. */
function people_multi(): array
{
    return [
        'Sadik.hamida' => ['Sadik Saiyed', 'Hamidabe Avula'],
    ];
}

/** Values that are not names (a status typed into the Assignee column). */
function people_noise(): array
{
    return ['done', 'na', 'nil', 'none', 'tbd', 'unassigned'];
}

function person_key(string $s): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $s));
}

/** variant key => list of canonical names */
function person_index(): array
{
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    foreach (people_map() as $canon => $aliases) {
        foreach (array_merge([$canon], $aliases) as $a) $idx[person_key($a)] = [$canon];
    }
    foreach (people_multi() as $a => $list) $idx[person_key($a)] = $list;
    return $idx;
}

/** Canonical names for a raw "A, B" assignee string (noise dropped, duplicates merged). */
function person_names(?string $raw): array
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', (string)$raw) as $r) {
        $r = trim($r);
        $k = person_key($r);
        if ($k === '' || in_array($k, people_noise(), true)) continue;
        $names = person_index()[$k] ?? [ucwords(strtolower(trim($r, " .\t")))];
        foreach ($names as $n) if (!in_array($n, $out, true)) $out[] = $n;
    }
    return $out;
}

function assignee_display(?string $raw): string
{
    return implode(', ', person_names($raw));
}

/** Every raw spelling that belongs to a canonical name (used to filter the data). */
function person_variants(string $canonical): array
{
    $v = [$canonical];
    foreach (people_map()[$canonical] ?? [] as $a) $v[] = $a;
    foreach (people_multi() as $a => $list) if (in_array($canonical, $list, true)) $v[] = $a;
    foreach ($v as $x) $v[] = $x . '.';   // hand-typed trailing dots ("Naushad.")
    return array_values(array_unique($v));
}
