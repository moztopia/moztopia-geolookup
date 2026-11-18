<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Services;

use Moztopia\GeoLookup\Exceptions\LookupException;

/**
 * Evaluate dataset polygon containment, including holes and antimeridian rings.
 */

final class BoundaryMatcher
{
    /**
     * Reject geometry that cannot be used for administrative containment.
     *
     * @param array<string, mixed> $geometry A GeoJSON Polygon or MultiPolygon.
     */

    public static function validate(array $geometry): void
    {
        if (!in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
            || !is_array($geometry['coordinates'] ?? null) || !array_is_list($geometry['coordinates']) || $geometry['coordinates'] === []) {
            throw new LookupException('invalid_dataset', 'Boundaries require Polygon or MultiPolygon coordinates.');
        }
        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
        foreach ($polygons as $polygon) {
            if (!is_array($polygon) || !array_is_list($polygon) || $polygon === []) {
                throw new LookupException('invalid_dataset', 'A polygon must have an exterior ring.');
            }
            foreach ($polygon as $ring) {
                if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4 || $ring[0] !== $ring[array_key_last($ring)]) {
                    throw new LookupException('invalid_dataset', 'Polygon rings must be closed and have at least four positions.');
                }
                foreach ($ring as $point) {
                    if (!is_array($point) || !isset($point[0], $point[1])
                        || !is_numeric($point[0]) || !is_numeric($point[1])
                        || !is_finite((float) $point[0]) || !is_finite((float) $point[1])
                        || abs((float) $point[0]) > 180 || abs((float) $point[1]) > 90) {
                        throw new LookupException('invalid_dataset', 'Boundary positions must be valid longitude/latitude pairs.');
                    }
                }
            }
        }
    }

    /**
     * Include exterior edges and exclude holes, without using place centres.
     *
     * @param array<string, mixed> $geometry A validated GeoJSON geometry.
     */

    public function contains(array $geometry, float $latitude, float $longitude): bool
    {
        self::validate($geometry);
        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
        foreach ($polygons as $polygon) {
            if (!$this->inRing($polygon[0], $longitude, $latitude)) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($polygon, 1) as $hole) {
                if ($this->inRing($hole, $longitude, $latitude)) {
                    $inHole = true;
                    break;
                }
            }
            if (!$inHole) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cast a ray in a longitude-unwrapped planar GeoJSON ring.
     *
     * @param list<list<float|int>> $ring A closed ring.
     */

    private function inRing(array $ring, float $x, float $y): bool
    {
        $unwrapped = [];
        $previous = (float) $ring[0][0];
        foreach ($ring as $point) {
            $longitude = (float) $point[0];
            while ($longitude - $previous > 180) {
                $longitude -= 360;
            }
            while ($longitude - $previous < -180) {
                $longitude += 360;
            }
            $unwrapped[] = [$longitude, (float) $point[1]];
            $previous = $longitude;
        }
        $x += 360 * round(($unwrapped[0][0] - $x) / 360);
        $inside = false;
        for ($i = 1; $i < count($unwrapped); $i++) {
            [$ax, $ay] = $unwrapped[$i - 1];
            [$bx, $by] = $unwrapped[$i];
            $cross = ($x - $ax) * ($by - $ay) - ($y - $ay) * ($bx - $ax);
            if (abs($cross) < 1.0e-10 && $x >= min($ax, $bx) - 1.0e-10 && $x <= max($ax, $bx) + 1.0e-10
                && $y >= min($ay, $by) - 1.0e-10 && $y <= max($ay, $by) + 1.0e-10) {
                return true;
            }
            if (($ay > $y) !== ($by > $y) && $x < ($bx - $ax) * ($y - $ay) / ($by - $ay) + $ax) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
