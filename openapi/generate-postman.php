<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

function resolveSchema(array $schema, array $components): array
{
    if (! isset($schema['$ref'])) {
        return $schema;
    }

    $reference = $schema['$ref'];
    $prefix = '#/components/schemas/';

    if (! str_starts_with($reference, $prefix)) {
        throw new RuntimeException("Unsupported schema reference: {$reference}");
    }

    $schemaName = substr($reference, strlen($prefix));
    $resolvedSchema = $components[$schemaName] ?? null;

    if (! is_array($resolvedSchema)) {
        throw new RuntimeException("Schema not found: {$schemaName}");
    }

    return $resolvedSchema;
}

function exampleValue(array $schema, string $propertyName, array $components): mixed
{
    $schema = resolveSchema($schema, $components);

    if (array_key_exists('example', $schema)) {
        return $schema['example'];
    }

    $type = $schema['type'] ?? 'string';

    if (is_array($type)) {
        $type = current(array_filter($type, static fn (string $candidate): bool => $candidate !== 'null')) ?: 'string';
    }

    if ($type === 'object') {
        $object = [];

        foreach ($schema['properties'] ?? [] as $name => $propertySchema) {
            $object[$name] = exampleValue($propertySchema, $name, $components);
        }

        return $object;
    }

    if (isset($schema['enum'][0])) {
        return $schema['enum'][0];
    }

    if ($propertyName === 'email') {
        return '{{email}}';
    }

    if ($propertyName === 'password' || $propertyName === 'password_confirmation') {
        return '{{password}}';
    }

    if ($propertyName === 'token') {
        return '{{reset_token}}';
    }

    if (in_array($propertyName, ['user', 'user_id', 'assigned_to_user_id'], true)) {
        return '{{user_id}}';
    }

    if (in_array($propertyName, ['deal', 'deal_id'], true)) {
        return '{{deal_id}}';
    }

    return match ($type) {
        'array' => [],
        'integer', 'number' => $schema['minimum'] ?? 1,
        'boolean' => false,
        default => ($schema['format'] ?? null) === 'email' ? '{{email}}' : 'string',
    };
}

function postmanVariableName(string $parameterName): string
{
    return match ($parameterName) {
        'user' => 'user_id',
        'deal' => 'deal_id',
        default => strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $parameterName)),
    };
}

function buildRequestBody(array $operation, array $components): ?string
{
    $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;

    if (! is_array($schema)) {
        return null;
    }

    $body = exampleValue($schema, '', $components);

    return json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

try {
    $projectRoot = dirname(__DIR__);
    $specification = Yaml::parseFile($projectRoot.'/openapi/openapi.yaml');
    $paths = $specification['paths'] ?? null;

    if (! is_array($paths)) {
        throw new RuntimeException('The OpenAPI document does not contain a paths object.');
    }

    $components = $specification['components']['schemas'] ?? [];
    $serverUrl = rtrim($specification['servers'][0]['url'] ?? '/api', '/');
    $parsedServerUrl = parse_url($serverUrl);
    $protocol = $parsedServerUrl['scheme'] ?? 'http';
    $baseUrl = $parsedServerUrl['host'] ?? 'localhost';

    if (isset($parsedServerUrl['port'])) {
        $baseUrl .= ':'.$parsedServerUrl['port'];
    }

    if (str_starts_with($serverUrl, '/')) {
        $baseUrl = 'localhost:8000';
    }

    $serverPath = $parsedServerUrl['path'] ?? (str_starts_with($serverUrl, '/') ? $serverUrl : '');
    $collectionItems = [];
    $pathVariables = [];
    $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    foreach ($paths as $path => $pathItem) {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $folderName = ucfirst($segments[0] ?? 'API');
        $folderIndex = array_search($folderName, array_column($collectionItems, 'name'), true);

        if ($folderIndex === false) {
            $collectionItems[] = ['name' => $folderName, 'item' => []];
            $folderIndex = array_key_last($collectionItems);
        }

        $postmanPath = preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $matches) use (&$pathVariables): string {
                $variableName = postmanVariableName($matches[1]);
                $pathVariables[$variableName] = $variableName === 'user_id' || $variableName === 'deal_id' ? '1' : '';

                return '{{'.$variableName.'}}';
            },
            $path,
        );
        $pathParameters = $pathItem['parameters'] ?? [];

        foreach ($httpMethods as $method) {
            $operation = $pathItem[$method] ?? null;

            if (! is_array($operation)) {
                continue;
            }

            $query = [];
            $headers = [
                ['key' => 'Accept', 'value' => 'application/json'],
            ];
            $parameters = array_merge($pathParameters, $operation['parameters'] ?? []);

            foreach ($parameters as $parameter) {
                if (($parameter['in'] ?? null) === 'header') {
                    $headerName = $parameter['name'];
                    $headerValue = $headerName === 'X-Webhook-Token'
                        ? '{{webhook_token}}'
                        : (string) exampleValue($parameter['schema'] ?? [], $headerName, $components);

                    $headers[] = ['key' => $headerName, 'value' => $headerValue];

                    continue;
                }

                if (($parameter['in'] ?? null) !== 'query') {
                    continue;
                }

                $querySchema = $parameter['schema'] ?? [];
                $query[] = [
                    'key' => $parameter['name'],
                    'value' => (string) exampleValue($querySchema, $parameter['name'], $components),
                    'disabled' => false,
                ];
            }

            $urlPath = trim($serverPath.$postmanPath, '/');
            $urlSegments = array_values(array_filter(explode('/', $urlPath), static fn (string $segment): bool => $segment !== ''));
            $rawUrl = $protocol.'://{{base_url}}/'.implode('/', $urlSegments);

            if ($query !== []) {
                $rawQuery = http_build_query(array_column($query, 'value', 'key'));
                $rawUrl .= '?'.$rawQuery;
            }

            $request = [
                'method' => strtoupper($method),
                'header' => $headers,
                'url' => [
                    'raw' => $rawUrl,
                    'protocol' => $protocol,
                    'host' => ['{{base_url}}'],
                    'path' => $urlSegments,
                    'query' => $query,
                ],
                'description' => $operation['summary'] ?? '',
            ];
            $body = buildRequestBody($operation, $components);

            if ($body !== null) {
                $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
                $request['header'] = $headers;
                $request['body'] = ['mode' => 'raw', 'raw' => $body, 'options' => ['raw' => ['language' => 'json']]];
            }

            $item = [
                'name' => $operation['summary'] ?? $operation['operationId'] ?? strtoupper($method).' '.$path,
                'request' => $request,
            ];

            if (in_array($operation['operationId'] ?? '', ['login', 'register'], true)) {
                $item['event'] = [[
                    'listen' => 'test',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => [
                            'if (pm.response.code >= 200 && pm.response.code < 300) {',
                            '    const responseBody = pm.response.json();',
                            '    if (responseBody.data && responseBody.data.id) {',
                            '        pm.collectionVariables.set("user_id", String(responseBody.data.id));',
                            '    }',
                            '}',
                        ],
                    ],
                ]];
            }

            $collectionItems[$folderIndex]['item'][] = $item;
        }
    }

    $collection = [
        'info' => [
            'name' => $specification['info']['title'] ?? 'API',
            'description' => 'Generated from openapi/openapi.yaml. Run the CSRF-cookie request once, then log in once; Postman reuses the session cookie for protected requests.',
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ],
        'item' => $collectionItems,
        'auth' => ['type' => 'noauth'],
        'event' => [[
            'listen' => 'prerequest',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const xsrfCookie = pm.cookies.get("XSRF-TOKEN");',
                    'if (xsrfCookie) {',
                    '    pm.request.headers.upsert({ key: "X-XSRF-TOKEN", value: decodeURIComponent(xsrfCookie) });',
                    '} else if (["POST", "PUT", "PATCH", "DELETE"].includes(pm.request.method)) {',
                    '    console.warn("No XSRF-TOKEN cookie found. Send the CSRF-cookie request before this request.");',
                    '}',
                ],
            ],
        ]],
        'variable' => array_map(
            static fn (string $key, string $value, string $type = 'string'): array => [
                'key' => $key,
                'value' => $value,
                'type' => $type,
            ],
            ['base_url', 'email', 'password', 'reset_token', 'webhook_token', ...array_keys($pathVariables)],
            [$baseUrl, '', '', '', '', ...array_values($pathVariables)],
            ['string', 'string', 'string', 'string', 'string', ...array_fill(0, count($pathVariables), 'string')],
        ),
    ];

    $outputDirectory = $projectRoot.'/postman';

    if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
        throw new RuntimeException("Unable to create output directory: {$outputDirectory}");
    }

    $collectionFile = $outputDirectory.'/Bid CRM API.postman_collection.json';
    $json = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($collectionFile, $json) === false) {
        throw new RuntimeException('Unable to write Postman collection.');
    }

    $environmentFile = $outputDirectory.'/Bid CRM Local.postman_environment.json';

    if (is_file($environmentFile) && ! unlink($environmentFile)) {
        throw new RuntimeException('Unable to remove the obsolete Postman environment file.');
    }

    fwrite(STDOUT, "Generated Postman collection with collection variables in {$outputDirectory}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Postman generation failed: {$exception->getMessage()}\n");
    exit(1);
}
