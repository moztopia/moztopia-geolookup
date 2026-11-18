<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Http;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Moztopia\GeoLookup\Exceptions\LookupException;
use Moztopia\GeoLookup\GeoLookup;
use Symfony\Component\Yaml\Yaml;

/**
 * Adapt the shared PHP lookup service to a versioned JSON interface.
 */

final class GeoLookupController
{
    /**
     * Use host Laravel validation without requiring UI or application controllers.
     */

    public function __construct(private readonly GeoLookup $lookup, private readonly Factory $validation)
    {
    }

    /**
     * Validate only query-string inputs and return stable field errors.
     *
     * @param array<string, string> $rules Query validation rules.
     * @return array<string, mixed>
     */

    private function input(Request $request, array $rules): array
    {
        $validator = $this->validation->make($request->query(), $rules);
        if ($validator->fails()) {
            throw new LookupException('validation_failed', 'The request parameters are invalid.', fields: $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /**
     * Keep package error responses JSON even without an Accept header.
     */

    private function respond(Closure $action): JsonResponse
    {
        try {
            return new JsonResponse($action());
        } catch (LookupException $exception) {
            return new JsonResponse(['error' => [
                'code' => $exception->errorCode, 'message' => $exception->getMessage(),
                'fields' => (object) $exception->fields,
            ]], $exception->status);
        } catch (\JsonException) {
            return new JsonResponse(['error' => [
                'code' => 'dataset_unavailable', 'message' => 'The installed dataset is invalid.', 'fields' => (object) [],
            ]], 503);
        }
    }

    /**
     * Search progressively typed place names, context and postal codes.
     */

    public function search(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $input = $this->input($request, [
                'q' => 'required|string|max:200', 'within' => 'sometimes|uuid',
                'country' => 'sometimes|regex:/^[A-Z]{2}$/', 'types' => 'sometimes|string|max:1300',
                'limit' => 'sometimes|integer|min:1|max:100', 'offset' => 'sometimes|integer|min:0|max:'.(PHP_INT_MAX - 100),
            ]);

            return $this->lookup->search(
                $input['q'],
                $input['within'] ?? null,
                $input['country'] ?? null,
                isset($input['types']) ? explode(',', $input['types']) : [],
                (int) ($input['limit'] ?? 10),
                (int) ($input['offset'] ?? 0)
            );
        });
    }

    /**
     * Retrieve any selectable administrative node.
     */

    public function place(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->lookup->place($id));
    }

    /**
     * List direct children with bounded pagination.
     */

    public function children(Request $request, string $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $input = $this->input($request, ['limit' => 'sometimes|integer|min:1|max:100', 'offset' => 'sometimes|integer|min:0|max:'.(PHP_INT_MAX - 100)]);

            return $this->lookup->children($id, (int) ($input['limit'] ?? 10), (int) ($input['offset'] ?? 0));
        });
    }

    /**
     * Return ancestors in country-to-parent order.
     */

    public function ancestors(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->lookup->ancestors($id));
    }

    /**
     * Resolve coordinates through installed administrative boundaries.
     */

    public function reverse(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $input = $this->input($request, [
                'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
                'stop_at' => 'sometimes|regex:/^[a-z][a-z0-9_]{0,63}$/',
            ]);

            return $this->lookup->reverse((float) $input['latitude'], (float) $input['longitude'], $input['stop_at'] ?? null);
        });
    }

    /**
     * Serve the bundled contract with the host's configured relative base URL.
     */

    public function openapi(): Response
    {
        $document = Yaml::parseFile(__DIR__.'/../../openapi.yaml');
        $document['servers'] = [['url' => '/'.trim((string) config('geolookup.http.prefix'), '/')]];

        return new Response(Yaml::dump($document, 30, 2), 200, ['Content-Type' => 'application/yaml']);
    }
}
