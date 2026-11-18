<?php

declare(strict_types=1);

use Moztopia\GeoLookup\Data\SqliteDataset;
use Moztopia\GeoLookup\Exceptions\LookupException;
use Moztopia\GeoLookup\GeoLookup;
use Moztopia\GeoLookup\Tests\Fixtures\Locations;

beforeEach(function (): void {
    $this->datasetPath = sys_get_temp_dir().'/geolookup-'.bin2hex(random_bytes(8)).'.sqlite';
    Locations::create($this->datasetPath);
    config(['geolookup.database' => $this->datasetPath]);
});

afterEach(function (): void {
    $this->app->forgetInstance(GeoLookup::class);
    $this->app->forgetInstance(SqliteDataset::class);
    if (is_file($this->datasetPath)) {
        unlink($this->datasetPath);
    }
});

it('finds the hometown through its state abbreviation and postal association', function (): void {
    $lookup = app(GeoLookup::class);
    $result = $lookup->search('Bethel Acres, OK')['data'];
    expect(array_column($result, 'name'))->toBe(['Bethel Acres'])
        ->and($result[0]['label'])->toBe('Bethel Acres, Pottawatomie, Oklahoma, United States')
        ->and($result[0]['postal_codes'])->toContain('74801');
    expect(array_column($lookup->search('74801')['data'], 'name'))->toContain('Bethel Acres', 'Shawnee');
    expect(array_column($lookup->search('Bethel Acres, OK 74801')['data'], 'name'))->toBe(['Bethel Acres']);
});

it('matches joined names, native script and administrative context', function (): void {
    $lookup = app(GeoLookup::class);
    expect(array_column($lookup->search('Thaiburi, Thailand', types: ['subdistrict'])['data'], 'name'))->toBe(['Thai Buri']);
    expect(array_column($lookup->search('ไทยบุรี', types: ['subdistrict'])['data'], 'name'))->toBe(['Thai Buri']);
    expect(array_column($lookup->search('ThaiB', types: ['subdistrict'])['data'], 'name'))->toBe(['Thai Buri']);
    expect($lookup->search('Thaiburi, Oklahoma')['data'])->toBe([]);
    expect(array_column($lookup->search('aland')['data'], 'name'))->toContain('Åland dotted-İ fixture');
});

it('ranks exact postal codes before prefixes and preserves leading zeros', function (): void {
    $lookup = app(GeoLookup::class);
    $rows = $lookup->search('123')['data'];
    expect(array_column($rows, 'name'))->toBe(['Exact fixture', 'Prefix fixture'])
        ->and($rows[0]['match'])->toBe(['field' => 'postal_code', 'kind' => 'exact'])
        ->and($rows[1]['match'])->toBe(['field' => 'postal_code', 'kind' => 'partial'])
        ->and($lookup->search('0012')['data'][0]['postal_codes'])->toBe(['0012'])
        ->and($lookup->search('00 12')['data'][0]['match']['kind'])->toBe('exact');
});

it('supports selecting a country or subdistrict without selecting its children', function (): void {
    $lookup = app(GeoLookup::class);
    $country = $lookup->place(Locations::id(1))['data'];
    $subdistrict = $lookup->place(Locations::id(4))['data'];
    expect($country['has_children'])->toBeTrue()->and(count($country['hierarchy']))->toBe(1)
        ->and($subdistrict['has_children'])->toBeTrue()->and(count($subdistrict['hierarchy']))->toBe(4)
        ->and($lookup->place(Locations::id(5))['data']['hierarchy'])->toHaveCount(5)
        ->and($lookup->ancestors(Locations::id(1))['data'])->toBe([])
        ->and(array_column($lookup->ancestors(Locations::id(4))['data'], 'type'))->toBe(['country', 'province', 'district']);
});

it('scopes search and provides stable nonoverlapping pages', function (): void {
    $lookup = app(GeoLookup::class);
    expect($lookup->search('Thai', within: Locations::id(6))['data'])->toBe([])
        ->and($lookup->search('Thai', country: 'US')['data'])->toBe([]);
    $first = $lookup->children(Locations::id(11), limit: 1);
    $second = $lookup->children(Locations::id(11), limit: 1, offset: $first['meta']['next_offset']);
    expect($first['meta']['has_more'])->toBeTrue()
        ->and($first['data'][0]['id'])->not->toBe($second['data'][0]['id']);
    expect($lookup->children(Locations::id(5))['data'])->toBe([]);
});

it('resolves containing branches with honest requested-depth results', function (): void {
    $lookup = app(GeoLookup::class);
    $match = $lookup->reverse(8.5, 99.5)['data'];
    expect($match)->toHaveCount(1)->and($match[0]['place']['type'])->toBe('subdistrict');
    $country = $lookup->reverse(8.5, 99.5, 'country')['data'][0];
    expect($country['place']['name'])->toBe('Thailand')->and($country['resolution']['complete'])->toBeTrue();
    $village = $lookup->reverse(8.5, 99.5, 'village')['data'][0];
    expect($village['place']['type'])->toBe('subdistrict')
        ->and($village['resolution']['complete'])->toBeFalse()
        ->and($village['resolution']['reason'])->toBe('requested_type_unavailable');
    expect($lookup->reverse(20, 99.5)['data'])->toBe([]);
});

it('does not mutate or create datasets during reads', function (): void {
    $before = hash_file('sha256', $this->datasetPath);
    app(GeoLookup::class)->search('80160');
    expect(hash_file('sha256', $this->datasetPath))->toBe($before);
    expect(fn () => app(SqliteDataset::class)->query('DELETE FROM places'))->toThrow(LookupException::class);
    expect(hash_file('sha256', $this->datasetPath))->toBe($before);
});

it('rejects malformed trees and refuses to overwrite data', function (): void {
    expect(fn () => Locations::create($this->datasetPath))->toThrow(LookupException::class);
    $path = $this->datasetPath.'-invalid';
    expect(fn () => SqliteDataset::create($path, [[
        'id' => Locations::id(1), 'parent_id' => Locations::id(1), 'country_code' => 'TH', 'name' => 'Cycle', 'type' => 'country',
    ]]))->toThrow(LookupException::class);
    expect(file_exists($path))->toBeFalse();
});

it('treats text as data rather than SQL or FTS operators', function (): void {
    $lookup = app(GeoLookup::class);
    expect($lookup->search('" OR *')['data'])->toBe([])
        ->and($lookup->search('Bethel Acres')['data'][0]['name'])->toBe('Bethel Acres');
});

it('cleans up failed provisioning and keeps corrupt JSON errors consistent in PHP', function (): void {
    $path = $this->datasetPath.'-invalid';
    expect(fn () => SqliteDataset::create($path, [[
        'id' => Locations::id(1), 'country_code' => 'TH', 'name' => 'Fixture', 'type' => 'country',
    ]], [['place_id' => Locations::id(1), 'geometry' => ['type' => 'Point']]]))->toThrow(LookupException::class);
    expect(file_exists($path))->toBeFalse();

    $db = new PDO('sqlite:'.$this->datasetPath);
    $db->exec("UPDATE places SET payload = 'invalid json'");
    $db->exec("UPDATE boundaries SET geometry = 'invalid json'");
    $db = null;
    foreach ([fn () => app(GeoLookup::class)->place(Locations::id(4)), fn () => app(GeoLookup::class)->reverse(8.5, 99.5)] as $action) {
        try {
            $action();
            test()->fail('Corrupt installed JSON must fail.');
        } catch (LookupException $exception) {
            expect($exception->errorCode)->toBe('dataset_unavailable')->and($exception->status)->toBe(503);
        }
    }
});

it('retains shared-border alternatives and deduplicates a requested common parent', function (): void {
    $db = new PDO('sqlite:'.$this->datasetPath);
    $db->prepare('INSERT INTO boundaries (place_id, min_x, max_x, min_y, max_y, wraps, geometry) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([Locations::id(5), 100, 101, 8, 9, 0, json_encode(Locations::box(100, 8, 101, 9), JSON_THROW_ON_ERROR)]);
    $db = null;
    $lookup = app(GeoLookup::class);
    $result = $lookup->reverse(8.5, 100);
    // The synthetic village is a descendant, so only its branch is deepest.
    expect($result['data'])->toHaveCount(1)->and($result['data'][0]['place']['type'])->toBe('village');
    $db = new PDO('sqlite:'.$this->datasetPath);
    $db->prepare('UPDATE places SET parent_id = ? WHERE id = ?')->execute([Locations::id(3), Locations::id(5)]);
    $record = json_decode($db->query("SELECT payload FROM places WHERE id = '".Locations::id(5)."'")->fetchColumn(), true);
    $record['parent_id'] = Locations::id(3);
    $db->prepare('UPDATE places SET payload = ? WHERE id = ?')->execute([json_encode($record, JSON_THROW_ON_ERROR), Locations::id(5)]);
    $db = null;
    $result = $lookup->reverse(8.5, 100);
    expect($result['data'])->toHaveCount(2)->and($result['meta']['ambiguous'])->toBeTrue();
    $country = $lookup->reverse(8.5, 100, 'country');
    expect($country['data'])->toHaveCount(1)->and($country['data'][0]['place']['name'])->toBe('Thailand')
        ->and($country['meta']['ambiguous'])->toBeFalse();
});
