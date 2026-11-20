{{--
    Topographic contour lines (DESIGN.md motif), generated deterministically like the
    landing: nested rings around a summit, every 5th ring thicker (index contour).
    Params: seed (int), tone ('dark' on zamrud | 'light' on paper), class,
    peaks (array of [cx, cy, rings]) in a 1200 x 400 box.
--}}
@php
    $seed = (int) ($seed ?? 7);
    $tone = $tone ?? 'dark';
    $peaks = $peaks ?? [[1020, 170, 12], [180, 470, 8]];
    $rsRand = function (int $s) {
        $s &= 0x7fffffff;
        return function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7fffffff; return $s / 0x7fffffff; };
    };
    $rsSmooth = function (array $p) {
        $n = count($p);
        $at = fn ($i) => $p[(($i % $n) + $n) % $n];
        $d = 'M' . (int) round($p[0][0]) . ' ' . (int) round($p[0][1]);
        for ($i = 0; $i < $n; $i++) {
            [$x0, $y0] = $at($i - 1); [$x1, $y1] = $at($i); [$x2, $y2] = $at($i + 1); [$x3, $y3] = $at($i + 2);
            $d .= 'C' . (int) round($x1 + ($x2 - $x0) / 6) . ' ' . (int) round($y1 + ($y2 - $y0) / 6) . ' '
                . (int) round($x2 - ($x3 - $x1) / 6) . ' ' . (int) round($y2 - ($y3 - $y1) / 6) . ' ' . (int) round($x2) . ' ' . (int) round($y2);
        }
        return $d . 'Z';
    };
    $rsRings = [];
    foreach ($peaks as $pi => [$cx, $cy, $n]) {
        $rand = $rsRand($seed * 31 + $pi * 7);
        $harm = [];
        foreach ([2, 3, 5] as $h) $harm[] = [$h, 0.04 + $rand() * 0.06, $rand() * 2 * M_PI, ($rand() - 0.5) * 0.3];
        $tilt = $rand() * M_PI;
        for ($k = 0; $k < $n; $k++) {
            $r = 18 + $k * 30;
            $pts = [];
            for ($j = 0; $j < 24; $j++) {
                $t = 2 * M_PI * $j / 24;
                $f = 1;
                foreach ($harm as [$h, $a, $ph, $dr]) $f += $a * (1 + $k * 0.05) * sin($h * $t + $ph + $k * $dr);
                $x = $r * $f * cos($t); $y = $r * $f * sin($t) * 0.7;
                $pts[] = [$cx + $x * cos($tilt) - $y * sin($tilt), $cy + $x * sin($tilt) + $y * cos($tilt)];
            }
            $rsRings[] = ['d' => $rsSmooth($pts), 'index' => $k % 5 === 4];
        }
    }
    $stroke = $tone === 'dark' ? '#a7f3d0' : '#047857';
    [$thin, $thick] = $tone === 'dark' ? [.16, .3] : [.12, .22];
@endphp
<svg class="rs-topo {{ $class ?? '' }}" viewBox="0 0 1200 400" preserveAspectRatio="xMaxYMid slice" aria-hidden="true" focusable="false">
    <g fill="none" stroke="{{ $stroke }}" stroke-linejoin="round">
        @foreach($rsRings as $ring)
        <path pathLength="100" style="--i: {{ $loop->index % 12 }}" d="{{ $ring['d'] }}" stroke-width="{{ $ring['index'] ? 2 : 1.2 }}" stroke-opacity="{{ $ring['index'] ? $thick : $thin }}" vector-effect="non-scaling-stroke"/>
        @endforeach
    </g>
</svg>
