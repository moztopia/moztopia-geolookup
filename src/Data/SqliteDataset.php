<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Data;

use Normalizer;
use PDO;
use PDOException;
use Pdo\Sqlite;
use Moztopia\GeoLookup\Exceptions\LookupException;

/**
 * Provision new datasets explicitly and query installed datasets read-only.
 */

final class SqliteDataset
{
    private ?PDO $connection = null;

    /**
     * Keep dataset access lazy so discovery never requires installed data.
     */

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Provision a new dataset from validated host-owned place records.
     *
     * This creates a new file only; it never overwrites an installed dataset.
     *
     * @param list<array<string, mixed>> $places Place records with UUID identities.
     * @param list<array<string, mixed>> $boundaries Valid GeoJSON geometry records.
     */

    public static function create(string $path, array $places, array $boundaries = []): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new LookupException('dataset_unavailable', 'Local lookups require the PDO SQLite extension.', 503);
        }
        if (file_exists($path) || !is_dir(dirname($path))) {
            throw new LookupException('invalid_dataset_target', 'Choose a new file in an existing directory.');
        }

        $byId = [];
        foreach ($places as $place) {
            if (!is_array($place)) {
                throw new LookupException('invalid_dataset', 'Place records must be objects.');
            }
            $id = is_string($place['id'] ?? null) ? strtolower($place['id']) : '';
            $place['id'] = $id;
            if (is_string($place['parent_id'] ?? null)) {
                $place['parent_id'] = strtolower($place['parent_id']);
            }
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[47][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)
                || isset($byId[$id]) || !is_string($place['name'] ?? null) || trim($place['name']) === ''
                || !is_string($place['type'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $place['type'])
                || !is_string($place['country_code'] ?? null) || !preg_match('/^[A-Z]{2}$/D', $place['country_code'])) {
                throw new LookupException('invalid_dataset', 'Place identity, name, type or country is invalid.');
            }
            foreach (['aliases', 'postal_codes'] as $field) {
                $place[$field] = array_key_exists($field, $place) ? $place[$field] : [];
                if (!is_array($place[$field]) || !array_is_list($place[$field])) {
                    throw new LookupException('invalid_dataset', 'Aliases and postal codes must be lists.');
                }
                foreach ($place[$field] ?? [] as $value) {
                    if (!is_string($value) || trim($value) === '') {
                        throw new LookupException('invalid_dataset', 'Aliases and postal codes must be nonempty strings.');
                    }
                }
            }
            $byId[$id] = $place;
        }
        foreach ($byId as $place) {
            if (($place['local_type'] ?? null) !== null && !is_string($place['local_type'])) {
                throw new LookupException('invalid_dataset', 'Local types must be strings.');
            }
            if (($place['source'] ?? null) !== null && (!is_array($place['source']) || array_is_list($place['source']))) {
                throw new LookupException('invalid_dataset', 'Source provenance must be an object.');
            }
            if (array_key_exists('relationships', $place) && (!is_array($place['relationships']) || !array_is_list($place['relationships']))) {
                throw new LookupException('invalid_dataset', 'Additional relationships must be a list.');
            }
            foreach ($place['relationships'] ?? [] as $relation) {
                if (!is_array($relation) || !is_string($relation['type'] ?? null) || !is_string($relation['place_id'] ?? null) || !isset($byId[$relation['place_id']])
                    || array_diff(array_keys($relation), ['type', 'place_id']) !== []) {
                    throw new LookupException('invalid_dataset', 'Additional relationships must reference known places.');
                }
            }
        }

        $contexts = [];
        foreach ($byId as $id => $place) {
            $context = [];
            $seen = [];
            $node = $place;
            while ($node !== null) {
                if (isset($seen[$node['id']]) || $node['country_code'] !== $place['country_code']) {
                    throw new LookupException('invalid_dataset', 'Administrative parents must be acyclic and within one country.');
                }
                $seen[$node['id']] = true;
                $names = [$node['name'], $node['country_code'], ...($node['aliases'] ?? [])];
                foreach ($names as $name) {
                    $normalized = self::normalize($name);
                    $context[] = $normalized;
                    $context[] = str_replace(' ', '', $normalized);
                }
                $parent = $node['parent_id'] ?? null;
                if ($parent !== null && (!is_string($parent) || !isset($byId[$parent]))) {
                    throw new LookupException('invalid_dataset', 'An administrative parent is missing.');
                }
                $node = $parent === null ? null : $byId[$parent];
            }
            foreach ($place['postal_codes'] ?? [] as $code) {
                $context[] = str_replace(' ', '', self::normalize($code));
            }
            $contexts[$id] = implode(' ', $context);
        }

        $file = @fopen($path, 'x');
        if ($file === false) {
            throw new LookupException('invalid_dataset_target', 'Choose a new writable dataset file.');
        }
        fclose($file);
        $db = null;
        try {
            $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec((string) file_get_contents(__DIR__.'/../../database/schema.sql'));
            $db->exec('PRAGMA foreign_keys = ON');
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO places (id, parent_id, country_code, name, type, local_type, payload) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($byId as $id => $place) {
                $place += ['parent_id' => null, 'local_type' => null, 'aliases' => [], 'postal_codes' => [], 'relationships' => [], 'source' => null];
                $insert->execute([$id, $place['parent_id'], $place['country_code'], $place['name'], $place['type'], $place['local_type'], json_encode($place, JSON_THROW_ON_ERROR)]);
                $db->prepare('INSERT INTO place_search (place_id, context) VALUES (?, ?)')->execute([$id, $contexts[$id]]);
                $names = [$place['name'], ...$place['aliases']];
                foreach ($names as $name) {
                    $db->prepare('INSERT INTO names (place_id, compact) VALUES (?, ?)')->execute([$id, str_replace(' ', '', self::normalize($name))]);
                }
                foreach (array_unique($place['postal_codes']) as $code) {
                    $db->prepare('INSERT INTO postal_codes (place_id, code, compact) VALUES (?, ?, ?)')->execute([$id, $code, str_replace(' ', '', self::normalize($code))]);
                }
            }
            foreach ($boundaries as $boundary) {
                if (!is_array($boundary) || !is_string($boundary['place_id'] ?? null) || !isset($byId[$boundary['place_id']])) {
                    throw new LookupException('invalid_dataset', 'A boundary references a missing place.');
                }
                $geometry = $boundary['geometry'] ?? [];
                if (!is_array($geometry)) {
                    throw new LookupException('invalid_dataset', 'Boundary geometry must be an object.');
                }
                \Moztopia\GeoLookup\Services\BoundaryMatcher::validate($geometry);
                $points = [];
                $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
                foreach ($polygons as $polygon) {
                    foreach ($polygon as $ring) {
                        array_push($points, ...$ring);
                    }
                }
                $xs = array_column($points, 0);
                $ys = array_column($points, 1);
                $db->prepare('INSERT INTO boundaries (place_id, min_x, max_x, min_y, max_y, wraps, geometry) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$boundary['place_id'], min($xs), max($xs), min($ys), max($ys), max($xs) - min($xs) > 180 ? 1 : 0, json_encode($geometry, JSON_THROW_ON_ERROR)]);
            }
            $db->exec('PRAGMA user_version = 1');
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db?->inTransaction()) {
                $db->rollBack();
            }
            $db = null;
            unlink($path);
            throw $exception;
        }
    }

    /**
     * Fold case, separators and Latin accents while preserving other scripts.
     */

    private static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/(?<=\p{Latin})\p{M}+/u', '', $text) ?? $text;

        return trim(preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', mb_strtolower($text)) ?? '');
    }

    /**
     * Execute a bound query against the read-only store.
     *
     * @param list<mixed> $parameters Bound values.
     * @return list<array<string, mixed>>
     */

    public function query(string $sql, array $parameters = []): array
    {
        try {
            if ($this->connection === null) {
                if (!extension_loaded('pdo_sqlite') || !is_file($this->path)) {
                    throw new LookupException('dataset_unavailable', 'No compatible local dataset is installed.', 503);
                }
                $this->connection = new PDO('sqlite:'.$this->path, options: [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY,
                ]);
                if ((int) $this->connection->query('PRAGMA user_version')->fetchColumn() !== 1) {
                    $this->connection = null;
                    throw new LookupException('dataset_unavailable', 'No compatible local dataset is installed.', 503);
                }
            }
            $statement = $this->connection->prepare($sql);
            $statement->execute($parameters);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            throw new LookupException('dataset_unavailable', 'No compatible local dataset is installed.', 503);
        }
    }

    /**
     * Locate a place by its owned UUID, retaining source IDs separately.
     *
     * @return array<string, mixed>|null
     */

    public function find(string $id): ?array
    {
        $row = $this->query('SELECT payload FROM places WHERE id = ?', [$id])[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            $place = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LookupException('dataset_unavailable', 'The installed dataset is invalid.', 503);
        }
        if (!is_array($place) || ($place['id'] ?? null) !== $id || !is_string($place['name'] ?? null)
            || !is_string($place['type'] ?? null) || !is_string($place['country_code'] ?? null)
            || !array_key_exists('parent_id', $place) || ($place['parent_id'] !== null && !is_string($place['parent_id']))) {
            throw new LookupException('dataset_unavailable', 'The installed dataset is invalid.', 503);
        }

        return $place;
    }

    /**
     * Search indexed name/context fragments and directly associated postal codes.
     *
     * @param list<string> $types Country-specific administrative types.
     * @return list<array<string, mixed>>
     */

    public function search(string $query, ?string $within, ?string $country, array $types, int $limit, int $offset): array
    {
        $normalized = self::normalize($query);
        if ($normalized === '') {
            throw new LookupException('validation_failed', 'Enter a name or postal code.', fields: ['q' => ['Enter a name or postal code.']]);
        }
        $compact = str_replace(' ', '', $normalized);
        $match = implode(' AND ', array_map(static fn (string $token): string => '"'.str_replace('"', '""', $token).'"*', explode(' ', $normalized)));
        $sql = 'WITH RECURSIVE scope(id) AS (SELECT id FROM places WHERE id = ? UNION SELECT p.id FROM places p JOIN scope s ON p.parent_id = s.id)
            SELECT p.id, CASE
                WHEN EXISTS (SELECT 1 FROM postal_codes c WHERE c.place_id = p.id AND c.compact = ?) THEN 0
                WHEN EXISTS (SELECT 1 FROM postal_codes c WHERE c.place_id = p.id AND c.compact LIKE ?) THEN 1
                WHEN EXISTS (SELECT 1 FROM names n WHERE n.place_id = p.id AND n.compact = ?) THEN 2
                ELSE 3 END AS rank
            FROM places p WHERE (p.id IN (SELECT place_id FROM place_search WHERE place_search MATCH ?)
                OR p.id IN (SELECT place_id FROM postal_codes WHERE compact LIKE ?))';
        $parameters = [$within, $compact, $compact.'%', $compact, $match, $compact.'%'];
        if ($within !== null) {
            $sql .= ' AND p.id IN (SELECT id FROM scope)';
        }
        if ($country !== null) {
            $sql .= ' AND p.country_code = ?';
            $parameters[] = $country;
        }
        if ($types !== []) {
            $sql .= ' AND p.type IN ('.implode(',', array_fill(0, count($types), '?')).')';
            array_push($parameters, ...$types);
        }
        $sql .= ' ORDER BY rank, p.name COLLATE NOCASE, p.id LIMIT '.($limit + 1).' OFFSET '.$offset;

        return $this->query($sql, $parameters);
    }
}
