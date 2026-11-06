<?php

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Dedoc\Scramble\Generator;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

it('documents every API method with the authentication required by its route', function () {
    $documents = [
        app(Generator::class)(),
        json_decode(file_get_contents(base_path('api.json')), true, 512, JSON_THROW_ON_ERROR),
    ];

    foreach ($documents as $document) {
        $documentTags = array_column($document['tags'] ?? [], 'name');

        expect($document['components']['securitySchemes']['http'] ?? null)
            ->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
            ->and($document['security'])->toBe([['http' => []]])
            ->and($documentTags)->not->toBeEmpty();

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.substr($route->uri(), strlen('api/'));
            $requiresAuthentication = in_array('auth:sanctum', $route->gatherMiddleware(), true);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operation = $document['paths'][$path][strtolower($method)] ?? null;

                expect($operation)->not->toBeNull();
                expect($operation['security'] ?? $document['security'])
                    ->toBe($requiresAuthentication ? [['http' => []]] : []);

                foreach ($operation['tags'] ?? [] as $tag) {
                    expect($documentTags)->toContain($tag);
                }
            }
        }
    }
});

it('documents the login token and the conditional asset upload owner', function () {
    $documents = [
        app(Generator::class)(),
        json_decode(file_get_contents(base_path('api.json')), true, 512, JSON_THROW_ON_ERROR),
    ];

    foreach ($documents as $document) {
        $login = $document['paths']['/v1/auth/login']['post'];
        $loginSchema = $document['components']['schemas']['LoginRequest'];
        $responseSchema = $login['responses']['200']['content']['application/json']['schema'];
        $assetSchema = $document['components']['schemas']['StoreAssetRequest'];

        expect($loginSchema['properties']['email']['examples'])->toContain('user@example.com')
            ->and($responseSchema['type'])->toBe('object')
            ->and($responseSchema['properties']['data']['properties'])->toHaveKeys(['user', 'token', 'token_type'])
            ->and($assetSchema['properties']['layout_id']['description'])->toContain('page_version_id')
            ->and($assetSchema['properties']['page_version_id']['description'])->toContain('layout_id');
    }
});

it('documents the successful response envelope and its optional data', function () {
    $documents = [
        app(Generator::class)(),
        json_decode(file_get_contents(base_path('api.json')), true, 512, JSON_THROW_ON_ERROR),
    ];

    foreach ($documents as $document) {
        $listSchema = $document['paths']['/v1/websites']['get']['responses']['200']['content']['application/json']['schema'];
        $logoutSchema = $document['paths']['/v1/auth/logout']['post']['responses']['200']['content']['application/json']['schema'];

        $listWithData = collect($listSchema['anyOf'] ?? [$listSchema])
            ->first(fn (array $schema): bool => isset($schema['properties']['data']));
        $logoutWithoutData = collect($logoutSchema['anyOf'] ?? [$logoutSchema])
            ->contains(fn (array $schema): bool => ! in_array('data', $schema['required'] ?? [], true));

        expect($listWithData['type'])->toBe('object')
            ->and($listWithData['properties']['success']['type'])->toBe('boolean')
            ->and($listWithData['properties']['message']['type'])->toBe('string')
            ->and($listWithData['properties']['data']['type'])->toBe('array')
            ->and($logoutWithoutData)->toBeTrue();
    }
});

it('omits success data only when it is null', function (mixed $data, bool $hasData) {
    $response = ApiResponse::success($data, 'Example', 202);
    $body = $response->getData(true);

    expect($response->status())->toBe(202)
        ->and($body)->toMatchArray(['success' => true, 'message' => 'Example'])
        ->and(array_key_exists('data', $body))->toBe($hasData);

    if ($hasData) {
        expect($body['data'])->toBe($data);
    }
})->with([
    'null' => [null, false],
    'false' => [false, true],
    'zero' => [0, true],
    'empty string' => ['', true],
    'empty array' => [[], true],
]);

it('accepts the documented login and authenticated website request examples', function () {
    User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('your-password'),
    ]);

    $document = json_decode(file_get_contents(base_path('api.json')), true, 512, JSON_THROW_ON_ERROR);
    $loginFields = $document['components']['schemas']['LoginRequest']['properties'];
    $websiteFields = $document['components']['schemas']['StoreWebsiteRequest']['properties'];

    $login = $this->postJson(route('api.v1.auth.login'), [
        'email' => $loginFields['email']['examples'][0],
        'password' => $loginFields['password']['examples'][0],
        'device_name' => $loginFields['device_name']['examples'][0],
    ])->assertSuccessful();

    $token = $login->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    $this->withToken($token)->postJson(route('api.v1.websites.store'), [
        'name' => $websiteFields['name']['examples'][0],
        'domain' => $websiteFields['domain']['examples'][0],
    ])->assertCreated()
        ->assertJsonPath('data.domain', 'example.com');
});
