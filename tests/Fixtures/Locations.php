<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Tests\Fixtures;

use Moztopia\GeoLookup\Data\SqliteDataset;

/**
 * Provision verified name facts and explicitly synthetic test-only geometry.
 */

final class Locations
{
    /**
     * Return fixed fixture UUIDs without reusing external source identifiers.
     */

    public static function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    /**
     * Write a small local dataset; polygons deliberately do not claim real borders.
     */

    public static function create(string $path): void
    {
        $records = [
            [1, null, 'TH', 'Thailand', 'country', ['TH'], []],
            [2, 1, 'TH', 'Nakhon Si Thammarat', 'province', [], []],
            [3, 2, 'TH', 'Tha Sala', 'district', [], ['80160']],
            [4, 3, 'TH', 'Thai Buri', 'subdistrict', ['Thaiburi', 'ไทยบุรี'], ['80160']],
            [5, 4, 'TH', 'Synthetic village fixture', 'village', [], []],
            [6, null, 'US', 'United States', 'country', ['US', 'USA'], []],
            [7, 6, 'US', 'Oklahoma', 'state', ['OK'], []],
            [8, 7, 'US', 'Pottawatomie', 'county', [], []],
            [9, 8, 'US', 'Bethel Acres', 'town', [], ['74801']],
            [10, 8, 'US', 'Shawnee', 'city', [], ['74801']],
            [11, null, 'ZZ', 'Synthetic fixture country', 'country', ['ZZ'], []],
            [12, 11, 'ZZ', 'Prefix fixture', 'locality', [], ['1234']],
            [13, 11, 'ZZ', 'Exact fixture', 'locality', [], ['123']],
            [14, 11, 'ZZ', 'Åland dotted-İ fixture', 'neighbourhood', [], ['0012']],
        ];
        $places = [];
        foreach ($records as [$id, $parent, $country, $name, $type, $aliases, $postcodes]) {
            $places[] = [
                'id' => self::id($id), 'parent_id' => $parent === null ? null : self::id($parent),
                'country_code' => $country, 'name' => $name, 'type' => $type,
                'local_type' => $id === 4 ? 'tambon' : null, 'aliases' => $aliases,
                'postal_codes' => $postcodes, 'source' => $id === 4 ? ['name' => 'GeoNames', 'id' => '12647746'] : null,
            ];
        }
        SqliteDataset::create($path, $places, [
            ['place_id' => self::id(3), 'geometry' => self::box(98, 7, 101, 10)],
            ['place_id' => self::id(4), 'geometry' => self::box(99, 8, 100, 9)],
        ]);
    }

    /**
     * Create a closed rectangular test geometry in longitude/latitude order.
     *
     * @return array<string, mixed>
     */

    public static function box(float $west, float $south, float $east, float $north): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[
            [$west, $south], [$east, $south], [$east, $north], [$west, $north], [$west, $south],
        ]]];
    }
}
