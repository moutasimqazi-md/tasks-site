<?php
declare(strict_types=1);
/**
 * One name per project. Notion "Subject" values and Google-Sheet tab names are typed independently, so the same
 * project shows up as "Safetrip" / "safetrip", "Green truck" / "Greentruck", "MaidX - V2" / "MaidX V2" ...
 *
 * Two layers:
 *  1. automatic: names that differ only by case, spaces or punctuation are merged;
 *  2. project_aliases(): a few obvious renames. Add a line (variant => the name to show) to merge more.
 *     Different versions (Safetrip V2, HousingX V2) are deliberately NOT merged.
 */
function project_aliases(): array
{
    return [
        '(no subject)'   => '(no project)',
        'Elements Lab'   => 'Element Labs',
        'Frush Food app' => 'Frush',
        'Fluxstore app'  => 'Fluxstore',
        'Islamic'        => 'Islamic Chatbot',
        'Movenetics Web' => 'Movenetics Website',
        'AI Door bell'   => 'AI Doorbell',
        'Green truck'    => 'Green Truck',
        'Greentruck'     => 'Green Truck',
        'MaidX - V2'     => 'MaidX V2',
        'MaidX - V1'     => 'MaidX V1',
        'Matrimony Event' => 'Matrimony Events',
    ];
}

function project_key(string $s): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $s));
}

/** Pure: raw project names => [groupKey => ['label' => shown name, 'variants' => [raw names]]]. */
function project_groups_from(array $raw): array
{
    $alias = [];
    foreach (project_aliases() as $from => $to) $alias[project_key($from)] = $to;
    $groups = [];
    foreach ($raw as $r) {
        $r = trim((string)$r);
        if ($r === '') continue;
        $explicit = $alias[project_key($r)] ?? null;
        $key = project_key($explicit ?? $r);
        $g = &$groups[$key];
        $g ??= ['label' => $explicit, 'variants' => [], 'plain' => []];
        $g['variants'][] = $r;
        if ($explicit === null) $g['plain'][] = $r;
        if ($g['label'] === null) $g['label'] = null;
        unset($g);
    }
    foreach ($groups as $k => &$g) {
        if ($g['label'] === null) {                 // pick the nicest spelling: capital first letter, then A-Z
            $c = $g['plain'];
            usort($c, fn($a, $b) => (ctype_upper($b[0] ?? 'a') <=> ctype_upper($a[0] ?? 'a')) ?: strcmp($a, $b));
            $g['label'] = $c[0];
        }
        $g['variants'] = array_values(array_unique($g['variants']));
        unset($g['plain']);
    }
    unset($g);
    return $groups;
}

function project_groups(): array
{
    static $g = null;
    if ($g !== null) return $g;
    $rows = db_all("SELECT project FROM (
                      SELECT DISTINCT project FROM tasks WHERE project IS NOT NULL AND project <> ''
                      UNION
                      SELECT DISTINCT project FROM events WHERE project IS NOT NULL AND project <> ''
                    ) p");
    return $g = project_groups_from(array_column($rows, 'project'));
}

/** Shown name for a raw project value. */
function project_display(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') return '(no project)';
    $groups = project_groups();
    $alias = project_aliases();
    foreach ($alias as $from => $to) {
        if (project_key($from) === project_key($raw)) return $groups[project_key($to)]['label'] ?? $to;
    }
    return $groups[project_key($raw)]['label'] ?? $raw;
}

/** Chosen (canonical) project names -> every raw spelling stored in the data. */
function project_expand(array $labels): array
{
    $groups = project_groups();
    $out = [];
    foreach ($labels as $l) {
        $g = $groups[project_key($l)] ?? null;
        foreach ($g['variants'] ?? [$l] as $v) $out[$v] = $v;
    }
    return array_values($out);
}

function project_list(): array
{
    $names = array_column(project_groups(), 'label');
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}
