<?php

declare(strict_types=1);

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Moztopia\GeoLookup\Data\SqliteDataset;
use Moztopia\GeoLookup\GeoLookup;
use Moztopia\GeoLookup\GeoLookupServiceProvider;
use Moztopia\GeoLookup\Tests\Fixtures\HostMiddleware;
use Moztopia\GeoLookup\Tests\Fixtures\Locations;
use Nyholm\Psr7\Response as PsrResponse;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->datasetPath = sys_get_temp_dir().'/geolookup-api-'.bin2hex(random_bytes(8)).'.sqlite';
    Locations::create($this->datasetPath);
    config(['geolookup.database' => $this->datasetPath, 'geolookup.http.enabled' => true, 'geolookup.http.middleware' => []]);
    $this->app->register(GeoLookupServiceProvider::class, true);
});

afterEach(function (): void {
    $this->app->forgetInstance(GeoLookup::class);
    $this->app->forgetInstance(SqliteDataset::class);
    if (is_file($this->datasetPath)) {
        unlink($this->datasetPath);
    }
});

it('serves real lookup responses matching the published OpenAPI schemas', function (string $operation, string $url): void {
    $response = $this->get('/api/geolookup/v1'.$url)->assertOk();
    $validator = (new ValidatorBuilder())->fromYamlFile(dirname(__DIR__, 2).'/openapi.yaml')->getResponseValidator();
    $validator->validate(new OperationAddress('/'.ltrim($operation, '/'), 'get'), new PsrResponse(200, ['Content-Type' => 'application/json'], $response->getContent()));
})->with([
    ['search', '/search?q=Bethel%20Acres%2C%20OK'],
    ['search', '/search?q=80160'],
    ['places/{id}', '/places/'.Locations::id(4)],
    ['places/{id}/children', '/places/'.Locations::id(1).'/children'],
    ['places/{id}/ancestors', '/places/'.Locations::id(4).'/ancestors'],
    ['reverse', '/reverse?latitude=8.5&longitude=99.5'],
    ['reverse', '/reverse?latitude=8.5&longitude=99.5&stop_at=village'],
]);

it('returns the same results through PHP and HTTP', function (): void {
    $expected = app(GeoLookup::class)->search('Thaiburi, Thailand', types: ['subdistrict']);
    $this->get('/api/geolookup/v1/search?q=Thaiburi%2C%20Thailand&types=subdistrict')->assertExactJson($expected);
});

it('validates malformed inputs as JSON without relying on an Accept header', function (string $url): void {
    $response = $this->get('/api/geolookup/v1'.$url)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $operation = str_starts_with($url, '/reverse') ? '/reverse' : (str_starts_with($url, '/places') ? '/places/{id}' : '/search');
    $validator = (new ValidatorBuilder())->fromYamlFile(dirname(__DIR__, 2).'/openapi.yaml')->getResponseValidator();
    $validator->validate(new OperationAddress($operation, 'get'), new PsrResponse(422, ['Content-Type' => 'application/json'], $response->getContent()));
})->with([
    '/search', '/search?q=---', '/search?q=Thai&limit=101', '/search?q[]=Thai',
    '/search?q=Thai&offset=-1', '/search?q=Thai&within=42', '/search?q=Thai&country=th',
    '/search?q=Thai&offset=999999999999999999999999',
    '/search?q=Thai&types=subdistrict,,village', '/places/not-a-uuid',
    '/reverse?latitude=91&longitude=99', '/reverse?latitude=NaN&longitude=99',
    '/reverse?latitude=8&longitude=181', '/reverse?latitude[]=8&longitude=99',
]);

it('distinguishes an unknown place from an empty result set', function (): void {
    $this->get('/api/geolookup/v1/places/'.Locations::id(999))->assertNotFound()->assertJsonPath('error.code', 'place_not_found');
    $this->get('/api/geolookup/v1/search?q=no-such-place')->assertOk()->assertJsonPath('data', []);
    $this->get('/api/geolookup/v1/search?q=Thai&offset=1000001')->assertOk()->assertJsonPath('data', []);
    $this->get('/api/geolookup/v1/places/'.Locations::id(1).'/children?offset=1000001')->assertOk()->assertJsonPath('data', []);
});

it('reports a missing or incompatible dataset without creating it or leaking its path', function (): void {
    unlink($this->datasetPath);
    $this->get('/api/geolookup/v1/search?q=Thai')->assertStatus(503)
        ->assertJsonPath('error.code', 'dataset_unavailable')->assertDontSee($this->datasetPath);
    expect(file_exists($this->datasetPath))->toBeFalse();
    file_put_contents($this->datasetPath, 'not a sqlite dataset');
    $this->get('/api/geolookup/v1/search?q=Thai')->assertStatus(503)->assertJsonPath('error.code', 'dataset_unavailable');
});

it('reports malformed stored boundaries as a dataset failure rather than a request error', function (): void {
    $db = new PDO('sqlite:'.$this->datasetPath);
    $db->exec("UPDATE boundaries SET geometry = '{}' ");
    $db = null;
    $response = $this->get('/api/geolookup/v1/reverse?latitude=8.5&longitude=99.5')->assertStatus(503)
        ->assertJsonPath('error.code', 'dataset_unavailable');
    $validator = (new ValidatorBuilder())->fromYamlFile(dirname(__DIR__, 2).'/openapi.yaml')->getResponseValidator();
    $validator->validate(new OperationAddress('/reverse', 'get'), new PsrResponse(503, ['Content-Type' => 'application/json'], $response->getContent()));
});

it('rejects a malformed stored place payload without emitting an invalid success response', function (): void {
    $db = new PDO('sqlite:'.$this->datasetPath);
    $db->exec("UPDATE places SET payload = '{}' ");
    $db = null;
    $this->get('/api/geolookup/v1/places/'.Locations::id(4))->assertStatus(503)->assertJsonPath('error.code', 'dataset_unavailable');
});

it('serves the contract without data and honors the host prefix and middleware', function (): void {
    config(['geolookup.http.prefix' => 'internal/geo', 'geolookup.http.middleware' => [HostMiddleware::class]]);
    $this->app->register(GeoLookupServiceProvider::class, true);
    unlink($this->datasetPath);
    $this->get('/internal/geo/openapi.yaml')->assertForbidden();
    $response = $this->withHeader('X-Host-Token', 'fixture-token')->get('/internal/geo/openapi.yaml')->assertOk();
    $document = Yaml::parse($response->getContent());
    expect($document['servers'][0]['url'])->toBe('/internal/geo')
        ->and($document['paths'])->toHaveKeys(['/search', '/reverse', '/places/{id}']);
});
