<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup;

use Moztopia\GeoLookup\Data\SqliteDataset;
use Moztopia\GeoLookup\Exceptions\LookupException;
use Moztopia\GeoLookup\Services\BoundaryMatcher;

/**
 * Share the same local lookup contract between PHP and HTTP consumers.
 */

final class GeoLookup
{
    /**
     * Inject a lazy dataset and boundary matcher without reading data at boot.
     */

    public function __construct(private readonly SqliteDataset $dataset, private readonly BoundaryMatcher $boundaries)
    {
    }

    /**
     * Require an owned UUID without conflating it with an external source code.
     */

    private function validateId(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[47][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)) {
            throw new LookupException('validation_failed', 'A place identifier must be a UUID.', fields: ['id' => ['A place identifier must be a UUID.']]);
        }
    }

    /**
     * Find a place or produce a stable not-found error.
     *
     * @return array<string, mixed>
     */

    private function requirePlace(string $id): array
    {
        $this->validateId($id);
        $place = $this->dataset->find(strtolower($id));
        if ($place === null) {
            throw new LookupException('place_not_found', 'The requested place does not exist in the installed dataset.', 404);
        }

        return $place;
    }

    /**
     * Read the display parent chain and reject malformed imported cycles.
     *
     * @param array<string, mixed> $place The selected place.
     * @return list<array<string, mixed>>
     */

    private function chain(array $place): array
    {
        $chain = [];
        $seen = [];
        while (true) {
            if (isset($seen[$place['id']])) {
                throw new LookupException('dataset_unavailable', 'The installed hierarchy is invalid.', 503);
            }
            $seen[$place['id']] = true;
            array_unshift($chain, $place);
            if ($place['parent_id'] === null) {
                break;
            }
            $parent = $this->dataset->find($place['parent_id']);
            if ($parent === null || $parent['country_code'] !== $place['country_code']) {
                throw new LookupException('dataset_unavailable', 'The installed hierarchy is invalid.', 503);
            }
            $place = $parent;
        }

        return $chain;
    }

    /**
     * Serialize a selectable node with its full administrative context.
     *
     * @param array<string, mixed> $place A stored record.
     * @return array<string, mixed>
     */

    private function serialize(array $place): array
    {
        $place = array_intersect_key($place, array_flip(['id', 'parent_id', 'country_code', 'name', 'type', 'local_type', 'aliases', 'postal_codes', 'relationships', 'source']));
        $chain = $this->chain($place);
        $place['hierarchy'] = array_map(static fn (array $node): array => array_intersect_key($node, array_flip(['id', 'name', 'type', 'local_type'])), $chain);
        $place['label'] = implode(', ', array_column(array_reverse($chain), 'name'));
        $place['has_children'] = $this->dataset->query('SELECT id FROM places WHERE parent_id = ? LIMIT 1', [$place['id']]) !== [];

        return $place;
    }

    /**
     * Fetch a final selection at any available administrative depth.
     *
     * @return array{data: array<string, mixed>}
     */

    public function place(string $id): array
    {
        return ['data' => $this->serialize($this->requirePlace($id))];
    }

    /**
     * Validate bounded pagination before SQL is constructed.
     */

    private function pagination(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > PHP_INT_MAX - 100) {
            throw new LookupException('validation_failed', 'Limit must be 1–100 and offset must be a nonnegative integer within the supported range.');
        }
    }

    /**
     * Return a deterministic page, including explicit continuation metadata.
     *
     * @param list<array<string, mixed>> $data At most limit plus one records.
     * @return array<string, mixed>
     */

    private function page(array $data, int $limit, int $offset): array
    {
        $more = count($data) > $limit;

        return ['data' => array_slice($data, 0, $limit), 'meta' => [
            'limit' => $limit, 'offset' => $offset, 'has_more' => $more,
            'next_offset' => $more ? $offset + $limit : null,
        ]];
    }

    /**
     * Search names, ancestor context and postal codes without a fixed hierarchy.
     *
     * @param list<string> $types Optional country-specific type filters.
     * @return array<string, mixed>
     */

    public function search(string $query, ?string $within = null, ?string $country = null, array $types = [], int $limit = 10, int $offset = 0): array
    {
        $this->pagination($limit, $offset);
        if (mb_strlen($query) > 200 || trim($query) === '') {
            throw new LookupException('validation_failed', 'Query must contain 1–200 characters.');
        }
        if ($within !== null) {
            $within = $this->requirePlace($within)['id'];
        }
        if (count($types) > 20) {
            throw new LookupException('validation_failed', 'At most 20 place types may be requested.');
        }
        foreach ($types as $type) {
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type)) {
                throw new LookupException('validation_failed', 'Place types must be lowercase identifiers.');
            }
        }
        if ($country !== null && !preg_match('/^[A-Z]{2}$/D', $country)) {
            throw new LookupException('validation_failed', 'Country must be an uppercase ISO alpha-2 code.');
        }
        $rows = $this->dataset->search($query, $within, $country, $types, $limit, $offset);
        $data = [];
        foreach ($rows as $row) {
            $place = $this->serialize($this->requirePlace($row['id']));
            $rank = (int) $row['rank'];
            $place['match'] = ['field' => $rank < 2 ? 'postal_code' : 'name', 'kind' => in_array($rank, [0, 2], true) ? 'exact' : 'partial'];
            $data[] = $place;
        }

        return $this->page($data, $limit, $offset);
    }

    /**
     * List direct children; selecting their parent remains valid.
     *
     * @return array<string, mixed>
     */

    public function children(string $id, int $limit = 10, int $offset = 0): array
    {
        $this->pagination($limit, $offset);
        $id = $this->requirePlace($id)['id'];
        $rows = $this->dataset->query('SELECT id FROM places WHERE parent_id = ? ORDER BY name COLLATE NOCASE, id LIMIT '.($limit + 1).' OFFSET '.$offset, [$id]);
        $data = array_map(fn (array $row): array => $this->serialize($this->requirePlace($row['id'])), $rows);

        return $this->page($data, $limit, $offset);
    }

    /**
     * Return country-to-parent ancestors, excluding the selected place itself.
     *
     * @return array<string, mixed>
     */

    public function ancestors(string $id): array
    {
        $chain = $this->chain($this->requirePlace($id));
        array_pop($chain);

        return ['data' => array_map(fn (array $place): array => $this->serialize($place), $chain)];
    }

    /**
     * Resolve coordinates by boundary containment, retaining ambiguous matches.
     *
     * @return array<string, mixed>
     */

    public function reverse(float $latitude, float $longitude, ?string $stopAt = null): array
    {
        if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180
            || ($stopAt !== null && !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $stopAt))) {
            throw new LookupException('validation_failed', 'Coordinates or stop_at type are invalid.');
        }
        $rows = $this->dataset->query('SELECT place_id, geometry FROM boundaries WHERE min_y <= ? AND max_y >= ? AND (wraps = 1 OR (min_x <= ? AND max_x >= ?)) ORDER BY place_id', [$latitude, $latitude, $longitude, $longitude]);
        $matches = [];
        foreach ($rows as $row) {
            try {
                $geometry = json_decode($row['geometry'], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($geometry)) {
                    throw new LookupException('invalid_dataset', 'Boundary geometry must be an object.');
                }
                $contains = $this->boundaries->contains($geometry, $latitude, $longitude);
            } catch (LookupException|\JsonException) {
                throw new LookupException('dataset_unavailable', 'The installed boundary data is invalid.', 503);
            }
            if ($contains) {
                $matches[$row['place_id']] = $this->chain($this->requirePlace($row['place_id']));
            }
        }
        $ancestors = [];
        foreach ($matches as $chain) {
            foreach (array_slice($chain, 0, -1) as $parent) {
                $ancestors[$parent['id']] = true;
            }
        }
        $data = [];
        foreach ($matches as $id => $chain) {
            if (isset($ancestors[$id])) {
                continue;
            }
            $matched = $chain[array_key_last($chain)];
            $selected = $matched;
            $complete = $stopAt === null;
            if ($stopAt !== null) {
                foreach (array_reverse($chain) as $node) {
                    if ($node['type'] === $stopAt) {
                        $selected = $node;
                        $complete = true;
                        break;
                    }
                }
            }
            $data[$selected['id']] ??= ['place' => $this->serialize($selected), 'resolution' => [
                'method' => 'boundary', 'matched_type' => $matched['type'],
                'resolved_type' => $selected['type'], 'requested_type' => $stopAt,
                'complete' => $complete, 'reason' => $complete ? null : 'requested_type_unavailable',
            ]];
        }

        return ['data' => array_values($data), 'meta' => ['latitude' => $latitude, 'longitude' => $longitude, 'ambiguous' => count($data) > 1]];
    }
}
