<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

/**
 * Pure PHP geometry calculations.
 * Mirrors geometry.py functions.
 */
class GeometryService
{
    private const R_EARTH = 6_371_000.0; // meters

    /**
     * Compute spherical distances between each pair of portals.
     * Using the Vincenty formula for a sphere.
     *
     * @param float[][] $portalsLL Nx2 array of [lon_rad, lat_rad]
     * @return int[][] NxN distance matrix in meters (rounded to nearest meter)
     */
    public function calcSphericalDistances(array $portalsLL): array
    {
        $n = \count($portalsLL);
        $dists = [];
        for ($i = 0; $i < $n; $i++) {
            $dists[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    $dists[$i][$j] = 0;
                    continue;
                }
                $lonI = $portalsLL[$i][0];
                $latI = $portalsLL[$i][1];
                $lonJ = $portalsLL[$j][0];
                $latJ = $portalsLL[$j][1];

                $dLon = abs($lonI - $lonJ);
                $cosLatI = cos($latI);
                $sinLatI = sin($latI);
                $cosLatJ = cos($latJ);
                $sinLatJ = sin($latJ);
                $cosDLon = cos($dLon);
                $sinDLon = sin($dLon);

                $numer = sqrt(
                    ($cosLatJ * $sinDLon) ** 2
                    + ($cosLatI * $sinLatJ - $sinLatI * $cosLatJ * $cosDLon) ** 2
                );
                $denom = $sinLatI * $sinLatJ + $cosLatI * $cosLatJ * $cosDLon;
                $angle = atan2($numer, $denom);

                $dists[$i][$j] = (int) round(self::R_EARTH * $angle);
            }
        }
        return $dists;
    }

    /**
     * Gnomonic (tangent-plane) projection centred on the centroid.
     *
     * @param float[][] $portalsLL Nx2 [lon_rad, lat_rad]
     * @return float[][] Nx2 [x, y]
     */
    public function gnomonicProjection(array $portalsLL): array
    {
        $lons = array_column($portalsLL, 0);
        $lats = array_column($portalsLL, 1);

        $lonC = min($lons) + (max($lons) - min($lons)) / 2.0;
        $latC = min($lats) + (max($lats) - min($lats)) / 2.0;

        $cosLatC = cos($latC);
        $sinLatC = sin($latC);

        $result = [];
        foreach ($portalsLL as $i => [$lon, $lat]) {
            $cosLat = cos($lat);
            $sinLat = sin($lat);
            $cosC = $sinLatC * $sinLat + $cosLatC * $cosLat * cos($lon - $lonC);

            if ($cosC <= 0.0) {
                throw new \InvalidArgumentException(
                    'Portals are too geographically separated. They must all lie in a single hemisphere.'
                );
            }

            $x = self::R_EARTH * $cosLat * sin($lon - $lonC) / $cosC;
            $y = self::R_EARTH * ($cosLatC * $sinLat - $sinLatC * $cosLat * cos($lon - $lonC)) / $cosC;
            $result[$i] = [$x, $y];
        }
        return $result;
    }

    /**
     * Web Mercator projection for a 640x640 pixel image.
     *
     * @param float[][] $portalsLL Nx2 [lon_rad, lat_rad]
     * @return array{0: float[][], 1: int, 2: float[]} [xy(Nx2), zoom, [center_lon, center_lat]]
     */
    public function webMercatorProjection(array $portalsLL): array
    {
        $xs = [];
        $ys = [];
        foreach ($portalsLL as [$lon, $lat]) {
            $xs[] = 256.0 / (2.0 * M_PI) * ($lon + M_PI);
            $ys[] = 256.0 / (2.0 * M_PI) * (M_PI - log(tan(M_PI / 4.0 + $lat / 2.0)));
        }

        $xMin = min($xs);
        $yMin = min($ys);
        $xs = array_map(fn($x) => $x - $xMin, $xs);
        $ys = array_map(fn($y) => $y - $yMin, $ys);

        $zoom = 1;
        for ($z = 20; $z > 1; $z--) {
            $scale = 2 ** $z;
            if (max($xs) * $scale < 640.0 && max($ys) * $scale < 640.0) {
                $zoom = $z;
                break;
            }
        }

        $scale = 2 ** $zoom;
        $xs = array_map(fn($x) => $x * $scale, $xs);
        $ys = array_map(fn($y) => $y * $scale, $ys);

        $xPad = (640.0 - max($xs)) / 2.0;
        $yPad = (640.0 - max($ys)) / 2.0;
        $xs = array_map(fn($x) => $x + $xPad, $xs);
        $ys = array_map(fn($y) => $y + $yPad, $ys);

        // Inverse transform for center
        $centerLon = M_PI / 128.0 * ((320.0 - $xPad) / $scale + $xMin) - M_PI;
        $centerLon = rad2deg($centerLon);
        $cl = M_PI - M_PI / 128.0 * ((320.0 - $yPad) / $scale + $yMin);
        $centerLat = 2.0 * atan(exp($cl)) - M_PI / 2.0;
        $centerLat = rad2deg($centerLat);

        $xy = [];
        foreach ($xs as $i => $x) {
            $xy[$i] = [$x, $ys[$i]];
        }

        return [$xy, $zoom, [$centerLon, $centerLat]];
    }

    /**
     * Returns convex hull vertex indices using Graham scan.
     *
     * @param float[][] $points Nx2 array of [x, y]
     * @return int[] Indices of hull vertices
     */
    public function convexHull(array $points): array
    {
        $n = \count($points);
        if ($n < 3) {
            return array_keys($points);
        }

        // Find lowest (then leftmost) point as pivot
        $pivotIdx = 0;
        foreach ($points as $i => $p) {
            if ($p[1] < $points[$pivotIdx][1] || ($p[1] === $points[$pivotIdx][1] && $p[0] < $points[$pivotIdx][0])) {
                $pivotIdx = $i;
            }
        }

        $pivot = $points[$pivotIdx];

        // Sort all other points by polar angle w.r.t. pivot
        $others = [];
        foreach ($points as $i => $p) {
            if ($i === $pivotIdx) {
                continue;
            }
            $dx = $p[0] - $pivot[0];
            $dy = $p[1] - $pivot[1];
            $others[$i] = [$dx, $dy, atan2($dy, $dx), $dx * $dx + $dy * $dy];
        }

        uasort($others, function ($a, $b) {
            $cmp = $a[2] <=> $b[2];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a[3] <=> $b[3]; // break ties by distance
        });

        $stack = [$pivotIdx];
        foreach (array_keys($others) as $i) {
            while (\count($stack) > 1) {
                $top = end($stack);
                $below = $stack[\count($stack) - 2];
                // Cross product
                $cross = $this->cross($points[$below], $points[$top], $points[$i]);
                if ($cross <= 0) {
                    array_pop($stack);
                } else {
                    break;
                }
            }
            $stack[] = $i;
        }

        return $stack;
    }

    private function cross(array $o, array $a, array $b): float
    {
        return ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    }
}
