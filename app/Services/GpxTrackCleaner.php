<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/**
 * Turns an uploaded recording into one track from the trailhead to the summit.
 *
 * Hikers usually upload the whole outing: up and back down the same path,
 * a loop that comes down another trail, several tracks in one file, or a
 * detour to a spring that returns to the same spot. All of these inflate
 * distance, gain and Naismith time. The cleaner only removes points; it never
 * moves or invents them, so every kept point is the hiker's own GPS fix.
 *
 * Steps, in order:
 *  1. split into pieces at track/segment breaks and GPS jumps; join pieces
 *     whose ends meet, keep the joined piece that holds the highest point;
 *  2. reverse a descent-only recording so it runs up to the summit;
 *  3. cut what comes after the summit when it goes back down the same path
 *     (out and back) or another path to the start (loop). A traverse that
 *     ends far from the start is kept whole, and so is a route whose name
 *     says it is a loop or a crossing (steps 2 and 3 are skipped);
 *  4. remove side trips: stretches that leave the trail and come back to the
 *     same point at the same height.
 */
class GpxTrackCleaner
{
    public const JUMP_M = 300;          // gap that separates two pieces of recording
    public const SAME_PATH_M = 60;      // median gap for "came back the same way"
    public const TAIL_SHARE = 0.15;     // part after the summit worth looking at
    public const LOOP_CLOSE_M = 800;    // start-end gap below which a two-trail recording is a loop
    public const SPUR_RADIUS_M = 25;    // back at the same spot
    public const SPUR_MIN_M = 150;      // side trip shorter than this is GPS jitter
    public const SPUR_MAX_ELE_M = 10;   // same spot means same height too (keeps switchbacks)
    public const SPUR_MAX_M = 2000;     // longer returns are not a side trip we can judge automatically
    public const SPUR_MAX_SHARE = 0.4;  // never cut more than this share of the track as one side trip
    /** Names that say the loop or crossing is the point of the route. */
    public const KEEP_WHOLE_PATTERN = '/\b(loop|lintas|traverse|travers|putar|melingkar)\b/iu';
    private const MIN_POINTS = 10;

    /**
     * Clean the GPX file in place.
     *
     * @return array{changed: bool, actions: list<array{0: string, 1: array}>, km_before: float, km_after: float}
     *         actions are translation keys with their replacements
     */
    public function clean(string $path, ?string $routeName = null): array
    {
        $keepWhole = $routeName !== null && preg_match(self::KEEP_WHOLE_PATTERN, $routeName) === 1;
        $result = ['changed' => false, 'actions' => [], 'km_before' => 0.0, 'km_after' => 0.0];

        $doc = $this->load($path);
        if (! $doc) {
            return $result;
        }

        $segments = $this->segments($doc);
        $all = array_merge(...($segments ?: [[]]));
        if (count($all) < self::MIN_POINTS) {
            return $result;
        }
        $result['km_before'] = $result['km_after'] = round($this->length($all) / 1000, 2);

        // 1. pieces
        [$points, $dropped, $joined] = $this->mainPiece($segments);
        if ($dropped > 0) {
            $result['actions'][] = [':count potongan rekaman terpisah dibuang (beberapa track atau lompatan GPS).', ['count' => $dropped]];
        } elseif ($joined > 0) {
            $result['actions'][] = [':count bagian rekaman disambung jadi satu jalur.', ['count' => $joined + 1]];
        }

        $hasEle = $this->eleShare($points) >= 0.8;

        // 2 + 3. orientation and the part after the summit
        if ($hasEle && count($points) >= self::MIN_POINTS) {
            if (! $keepWhole) {
                [$points, $action] = $this->toSummit($points);
                if ($action) {
                    $result['actions'][] = $action;
                }
            }

            // 4. side trips
            [$points, $spurs, $spurM] = $this->removeSpurs($points);
            if ($spurs > 0) {
                $result['actions'][] = [':count cabang atau putaran yang kembali ke titik sama dibuang (:km km).', [
                    'count' => $spurs,
                    'km' => number_format($spurM / 1000, 2),
                ]];
            }
        }

        if (! $result['actions']) {
            return $result;
        }

        $this->write($doc, $points, $path);
        $result['changed'] = true;
        $result['km_after'] = round($this->length($points) / 1000, 2);

        return $result;
    }

    /**
     * One-line summary, or null when nothing changed. Pass 'id' for the stored
     * change note (history stays in one language); leave null for the viewer's.
     */
    public static function summary(array $report, ?string $locale = null): ?string
    {
        if (empty($report['changed'])) {
            return null;
        }

        return __('Jalur dirapikan otomatis: :actions Jarak :before km jadi :after km.', [
            'actions' => implode(' ', array_map(fn ($a) => __($a[0], $a[1], $locale), $report['actions'])),
            'before' => number_format($report['km_before'], 2),
            'after' => number_format($report['km_after'], 2),
        ], $locale);
    }

    private function load(string $path): ?DOMDocument
    {
        if (! is_file($path)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $ok = $doc->load($path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok && $doc->documentElement && $doc->documentElement->localName === 'gpx' ? $doc : null;
    }

    /** @return list<list<array{0: float, 1: float, 2: ?float, 3: DOMElement}>> */
    private function segments(DOMDocument $doc): array
    {
        $segments = [];
        foreach ($doc->getElementsByTagNameNS('*', 'trkseg') as $seg) {
            $segments[] = $this->points($seg, 'trkpt');
        }
        if (! array_filter($segments)) {
            $segments = [];
            foreach ($doc->getElementsByTagNameNS('*', 'rte') as $rte) {
                $segments[] = $this->points($rte, 'rtept');
            }
        }

        return array_values(array_filter($segments));
    }

    private function points(DOMElement $parent, string $tag): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->localName !== $tag) {
                continue;
            }
            $lat = $node->getAttribute('lat');
            $lon = $node->getAttribute('lon');
            if (! is_numeric($lat) || ! is_numeric($lon)) {
                continue;
            }
            $ele = null;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'ele' && is_numeric(trim($child->textContent))) {
                    $ele = (float) trim($child->textContent);
                }
            }
            $out[] = [(float) $lat, (float) $lon, $ele, $node];
        }

        return $out;
    }

    /**
     * Split at segment breaks and jumps, chain pieces whose ends meet, keep the
     * chain with the highest point (or the longest when there is no elevation).
     *
     * @return array{0: list<array>, 1: int, 2: int} points, chains dropped, joins made
     */
    private function mainPiece(array $segments): array
    {
        $pieces = [];
        foreach ($segments as $seg) {
            $piece = [$seg[0]];
            for ($i = 1, $n = count($seg); $i < $n; $i++) {
                if ($this->dist($seg[$i - 1], $seg[$i]) > self::JUMP_M) {
                    $pieces[] = $piece;
                    $piece = [];
                }
                $piece[] = $seg[$i];
            }
            $pieces[] = $piece;
        }

        $chains = [array_shift($pieces)];
        $joins = 0;
        foreach ($pieces as $piece) {
            $last = &$chains[count($chains) - 1];
            if ($this->dist($last[count($last) - 1], $piece[0]) <= self::JUMP_M) {
                $last = array_merge($last, $piece);
                $joins++;
            } else {
                $chains[] = $piece;
            }
            unset($last);
        }

        if (count($chains) === 1) {
            return [$chains[0], 0, $joins];
        }

        $best = 0;
        $bestScore = -INF;
        foreach ($chains as $k => $chain) {
            $top = max(array_map(fn ($p) => $p[2] ?? -INF, $chain));
            $score = $top > -INF ? $top : $this->length($chain);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$k, $score];
            }
        }

        return [$chains[$best], count($chains) - 1, $joins];
    }

    /**
     * Make the track run up to the summit and drop the way back.
     *
     * @return array{0: list<array>, 1: ?array} points, action as [translation key, replacements]
     */
    private function toSummit(array $points): array
    {
        $top = $this->summitIndex($points);
        $cum = $this->cumulative($points);
        $total = end($cum) ?: 1.0;
        $n = count($points);

        // Recorded from the summit down: reverse so it climbs.
        if ($cum[$top] / $total < self::TAIL_SHARE && $points[$top][2] - ($points[$n - 1][2] ?? $points[$top][2]) > 100) {
            $rest = array_slice($points, $top);
            if (count($rest) >= self::MIN_POINTS) {
                return [array_reverse($rest), ['Rekaman dari puncak ke bawah dibalik supaya jalurnya naik ke puncak.', []]];
            }
        }

        $afterShare = ($total - $cum[$top]) / $total;
        if ($afterShare <= self::TAIL_SHARE || $top < self::MIN_POINTS || $n - $top < self::MIN_POINTS) {
            return [$points, null];
        }

        $before = array_slice($points, 0, $top + 1);
        $after = array_slice($points, $top);
        $sep = $this->medianGap($after, $before);
        $ascent = $before;

        if ($sep <= self::SAME_PATH_M) {
            return [$ascent, ['Rekaman naik lalu turun lewat jalur yang sama: bagian turunnya dibuang.', []]];
        }
        if ($this->dist($points[0], $points[$n - 1]) <= self::LOOP_CLOSE_M) {
            return [$ascent, ['Rekaman berupa loop (turun lewat jalur lain): hanya jalur naiknya yang disimpan.', []]];
        }

        return [$points, null]; // traverse: ends somewhere else, keep it whole
    }

    /**
     * Highest point after light smoothing, so a single elevation spike is not the summit.
     */
    private function summitIndex(array $points): int
    {
        $ele = array_map(fn ($p) => $p[2] ?? -INF, $points);
        $n = count($ele);
        $w = 5;
        $bestI = 0;
        $bestV = -INF;
        // Running mean over i-w .. i+w, skipping points without elevation.
        $sum = 0.0;
        $cnt = 0;
        for ($t = 0; $t <= min($n - 1, $w); $t++) {
            if ($ele[$t] > -INF) {
                $sum += $ele[$t];
                $cnt++;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if ($cnt > 0 && $sum / $cnt > $bestV) {
                [$bestI, $bestV] = [$i, $sum / $cnt];
            }
            $in = $i + $w + 1;
            $out = $i - $w;
            if ($in < $n && $ele[$in] > -INF) {
                $sum += $ele[$in];
                $cnt++;
            }
            if ($out >= 0 && $ele[$out] > -INF) {
                $sum -= $ele[$out];
                $cnt--;
            }
        }
        $top = $bestI;
        for ($i = max(0, $bestI - $w); $i <= min($n - 1, $bestI + $w); $i++) {
            if ($ele[$i] > $ele[$top]) {
                $top = $i;
            }
        }

        return $top;
    }

    /** Median distance from (up to 60) points of $a to the nearest of (up to 400) points of $b. */
    private function medianGap(array $a, array $b): float
    {
        $sa = $this->sample($a, 60);
        $sb = $this->sample($b, 400);
        $gaps = [];
        foreach ($sa as $p) {
            $min = INF;
            foreach ($sb as $q) {
                $min = min($min, $this->dist($p, $q));
            }
            $gaps[] = $min;
        }
        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }

    /**
     * Loop erasure on points about 10 m apart: when the track comes back within
     * SPUR_RADIUS_M of an earlier point at the same height after walking at
     * least SPUR_MIN_M, everything in between was a side trip.
     *
     * @return array{0: list<array>, 1: int, 2: float} points, side trips cut, metres cut
     */
    private function removeSpurs(array $points): array
    {
        $cum = $this->cumulative($points);
        $total = end($cum);
        $summit = $this->summitIndex($points); // a side trip may never take the summit with it

        // Thin to one point per ~10 m for the search.
        $idx = [0];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if ($cum[$i] - $cum[end($idx)] >= 10) {
                $idx[] = $i;
            }
        }

        // Grid of SPUR_RADIUS_M cells, in local metres.
        $lat0 = deg2rad($points[0][0]);
        $xy = [];
        $grid = [];
        foreach ($idx as $k => $i) {
            $x = deg2rad($points[$i][1]) * cos($lat0) * 6371000;
            $y = deg2rad($points[$i][0]) * 6371000;
            $xy[$k] = [$x, $y];
            $grid[(int) floor($x / self::SPUR_RADIUS_M) . ':' . (int) floor($y / self::SPUR_RADIUS_M)][] = $k;
        }

        $keep = array_fill(0, count($points), true);
        $spurs = 0;
        $cut = 0.0;
        $m = count($idx);
        for ($k = 0; $k < $m; $k++) {
            $i = $idx[$k];
            [$x, $y] = $xy[$k];
            $cx = (int) floor($x / self::SPUR_RADIUS_M);
            $cy = (int) floor($y / self::SPUR_RADIUS_M);
            $far = -1;
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    foreach ($grid[($cx + $dx) . ':' . ($cy + $dy)] ?? [] as $l) {
                        if ($l <= $k || $l <= $far) {
                            continue;
                        }
                        $j = $idx[$l];
                        if ($cum[$j] - $cum[$i] < self::SPUR_MIN_M) {
                            continue;
                        }
                        if (hypot($xy[$l][0] - $x, $xy[$l][1] - $y) > self::SPUR_RADIUS_M) {
                            continue;
                        }
                        $ei = $points[$i][2];
                        $ej = $points[$j][2];
                        if ($ei === null || $ej === null || abs($ei - $ej) > self::SPUR_MAX_ELE_M) {
                            continue;
                        }
                        $far = $l;
                    }
                }
            }
            if ($far > $k) {
                $j = $idx[$far];
                $walk = $cum[$j] - $cum[$i];
                // The whole excursion from here is too long to judge, or holds the
                // summit: leave all of it, so no half of a side trip is cut.
                if ($walk > self::SPUR_MAX_M || $walk > self::SPUR_MAX_SHARE * $total || ($i < $summit && $summit < $j)) {
                    $k = $far - 1;
                    continue;
                }
                for ($t = $i + 1; $t < $j; $t++) {
                    $keep[$t] = false;
                }
                $spurs++;
                $cut += $cum[$j] - $cum[$i];
                $k = $far - 1; // continue from the point where the side trip rejoined
            }
        }

        if ($spurs === 0) {
            return [$points, 0, 0.0];
        }

        return [array_values(array_filter($points, fn ($p, $i) => $keep[$i], ARRAY_FILTER_USE_BOTH)), $spurs, $cut];
    }

    /** Replace every track and route in the document with one track of the kept points. */
    private function write(DOMDocument $doc, array $points, string $path): void
    {
        $root = $doc->documentElement;
        $ns = $root->namespaceURI;
        $name = null;

        // Copy the kept points before their tracks are removed (libxml frees removed subtrees).
        $make = fn (string $tag) => $ns ? $doc->createElementNS($ns, $tag) : $doc->createElement($tag);
        $kept = [];
        foreach ($points as $p) {
            if ($p[3]->localName === 'trkpt') {
                $kept[] = $p[3]->cloneNode(true);
                continue;
            }
            $pt = $make('trkpt'); // a route point becomes a track point with the same data
            $pt->setAttribute('lat', $p[3]->getAttribute('lat'));
            $pt->setAttribute('lon', $p[3]->getAttribute('lon'));
            for ($child = $p[3]->firstChild; $child; $child = $child->nextSibling) {
                $pt->appendChild($child->cloneNode(true));
            }
            $kept[] = $pt;
        }

        foreach (['trk', 'rte'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagNameNS('*', $tag)) as $node) {
                if ($name === null) {
                    foreach ($node->childNodes as $child) {
                        if ($child instanceof DOMElement && $child->localName === 'name') {
                            $name = $child->textContent;
                        }
                    }
                }
                $node->parentNode->removeChild($node);
            }
        }

        $trk = $make('trk');
        if ($name !== null && $name !== '') {
            $trk->appendChild($make('name'))->appendChild($doc->createTextNode($name));
        }
        $seg = $trk->appendChild($make('trkseg'));
        foreach ($kept as $pt) {
            $seg->appendChild($pt);
        }

        // Tracks come after waypoints and routes in GPX 1.1; extensions stay last.
        $extensions = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'extensions') {
                $extensions = $child;
            }
        }
        $root->insertBefore($trk, $extensions);

        $doc->formatOutput = true;
        $doc->save($path);
    }

    private function eleShare(array $points): float
    {
        return count(array_filter($points, fn ($p) => $p[2] !== null)) / max(1, count($points));
    }

    private function sample(array $points, int $max): array
    {
        $step = max(1, intdiv(count($points), $max));
        $out = [];
        for ($i = 0, $n = count($points); $i < $n; $i += $step) {
            $out[] = $points[$i];
        }

        return $out;
    }

    private function cumulative(array $points): array
    {
        $cum = [0.0];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $cum[] = $cum[$i - 1] + $this->dist($points[$i - 1], $points[$i]);
        }

        return $cum;
    }

    private function length(array $points): float
    {
        $cum = $this->cumulative($points);

        return end($cum) ?: 0.0;
    }

    private function dist(array $a, array $b): float
    {
        $la1 = deg2rad($a[0]);
        $la2 = deg2rad($b[0]);
        $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }
}
