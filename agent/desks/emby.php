<?php
declare(strict_types=1);

/*
 * John — the intern who loves Emby. For now a desk without tools:
 * he introduces himself and tells what he will do with EmbyCache
 * (github.com/helmi1987/embycache-for-unraid), which keeps what people are
 * about to watch on the fast pool so the array disks can sleep.
 *
 * Only cheap facts so far: is Python there, is an Emby container there.
 */

desk('emby', [
    'start'   => fn () => embyScan(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => embyScan()],
    ],
]);

function embyScan(): array
{
    $emby = [];
    foreach (houseContainers() as $c) {
        if (preg_match('#(^|/)emby(server)?(:|$)|embyserver#i', $c['image'])) {
            $emby[] = ['name' => $c['name'], 'image' => $c['image'], 'running' => $c['running']];
        }
    }
    [$exit, $out] = run(['python3', '--version'], 10);
    $state = [
        'time'   => time(),
        'python' => $exit === 0 ? trim(str_replace('Python', '', $out)) : null,
        'emby'   => $emby,
    ];
    writeAtomic(deskFile('emby'), jsonEncode($state));
    return $state;
}
