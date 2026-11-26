{{--
    A recorded track drawn as one SVG line (no map tiles), so a page of 20 cards stays light.
    The post page draws the same track on a Leaflet map. Params: track ([[lat, lng], ...]), label.
--}}
@php
    $w = 400; $h = 210; $pad = 16;
    $lats = array_column($track, 0);
    $lngs = array_column($track, 1);
    [$minLat, $maxLat, $minLng, $maxLng] = [min($lats), max($lats), min($lngs), max($lngs)];
    $kx = cos(deg2rad(($minLat + $maxLat) / 2));
    $spanX = max(($maxLng - $minLng) * $kx, 1e-6);
    $spanY = max($maxLat - $minLat, 1e-6);
    $scale = min(($w - 2 * $pad) / $spanX, ($h - 2 * $pad) / $spanY);
    $ox = ($w - $spanX * $scale) / 2;
    $oy = ($h - $spanY * $scale) / 2;
    $xy = fn ($p) => [round($ox + ($p[1] - $minLng) * $kx * $scale, 1), round($h - $oy - ($p[0] - $minLat) * $scale, 1)];
    $points = array_map($xy, $track);
    $d = 'M' . implode(' L', array_map(fn ($q) => $q[0] . ' ' . $q[1], $points));
    $start = $points[0];
    $end = $points[count($points) - 1];
@endphp
<svg viewBox="0 0 {{ $w }} {{ $h }}" class="block w-full h-auto bg-[#eef4ef]" role="img" aria-label="{{ $label ?? __('Gambar jalur rekaman') }}">
    <path d="{{ $d }}" fill="none" stroke="#ffffff" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" />
    <path d="{{ $d }}" fill="none" stroke="#047857" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
    <circle cx="{{ $start[0] }}" cy="{{ $start[1] }}" r="6" fill="#ffffff" stroke="#053b30" stroke-width="3" />
    <circle cx="{{ $end[0] }}" cy="{{ $end[1] }}" r="6" fill="#053b30" stroke="#ffffff" stroke-width="2" />
</svg>
