<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class Phase17SwaggerDocumentationTest extends TestCase
{
    protected string $docPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docPath = storage_path('api-docs/api-docs.json');
    }

    public function test_artisan_l5_swagger_generate_command_executes_successfully(): void
    {
        $exitCode = Artisan::call('l5-swagger:generate');
        $this->assertEquals(0, $exitCode, 'l5-swagger:generate must exit with status code 0');
        $this->assertFileExists($this->docPath, 'Generated api-docs.json must exist in storage/api-docs');
    }

    public function test_swagger_ui_endpoint_is_accessible(): void
    {
        $response = $this->get('/api/documentation');
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('swagger-ui', strtolower($content));
    }

    public function test_openapi_json_endpoint_is_accessible_and_valid(): void
    {
        $response = $this->get('/docs');
        $response->assertStatus(200);

        $json = json_decode($response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertStringStartsWith('3.', $json['openapi'] ?? '');
        $this->assertEquals('ORVELL PULSE Wholesale ERP Backend API', $json['info']['title'] ?? '');
    }

    public function test_openapi_specification_file_structure_and_version(): void
    {
        $this->assertFileExists($this->docPath);
        $raw = File::get($this->docPath);
        $spec = json_decode($raw, true);

        $this->assertEquals(JSON_ERROR_NONE, json_last_error(), 'OpenAPI spec must be valid JSON');
        $this->assertArrayHasKey('openapi', $spec);
        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('tags', $spec);
    }

    public function test_all_core_domain_endpoints_are_documented(): void
    {
        $spec = json_decode(File::get($this->docPath), true);
        $paths = $spec['paths'] ?? [];

        $expectedEndpoints = [
            // Payments
            '/api/payment/cash'                  => 'post',
            '/api/payment/verify'                => 'post',
            '/api/payment/history'               => 'get',
            '/api/payment/create'                => 'post',

            // Invoice Amendments
            '/api/invoices/amendment-request'     => 'post',
            '/api/invoices/amendment/{id}/approve' => 'post',
            '/api/invoices/amendment/{id}/reject'  => 'post',
            '/api/invoices/amendment/list'       => 'get',

            // Pickups
            '/api/pickup/validate'               => 'post',
            '/api/pickup/release'                => 'post',

            // Expenses
            '/api/expenses/create'               => 'post',
            '/api/expenses/list'                 => 'get',
            '/api/expenses/summary'              => 'get',

            // Bank Deposits
            '/api/bank-deposits/create'          => 'post',
            '/api/bank-deposits/list'            => 'get',
            '/api/bank-deposits/summary'         => 'get',

            // EOD Reconciliation
            '/api/reconciliation/eod/generate'   => 'post',
            '/api/reconciliation/eod/show'       => 'get',

            // Webhooks
            '/api/whatsapp/webhook'              => 'post',
            '/api/whatsapp/chatterly'            => 'post',
            '/api/webhooks/paystack'             => 'post',
        ];

        foreach ($expectedEndpoints as $path => $method) {
            $this->assertArrayHasKey($path, $paths, "Missing OpenAPI documentation for endpoint: {$path}");
            $this->assertArrayHasKey($method, $paths[$path], "Missing HTTP method [{$method}] for endpoint: {$path}");
            $this->assertNotEmpty($paths[$path][$method]['summary'] ?? null, "Endpoint {$path} must have a summary");
            $this->assertNotEmpty($paths[$path][$method]['responses'] ?? null, "Endpoint {$path} must have responses defined");
        }
    }

    public function test_security_schemes_and_protected_endpoints(): void
    {
        $spec = json_decode(File::get($this->docPath), true);

        // Verify Bearer Security Scheme
        $securitySchemes = $spec['components']['securitySchemes'] ?? [];
        $this->assertArrayHasKey('bearerAuth', $securitySchemes);
        $this->assertEquals('http', $securitySchemes['bearerAuth']['type']);
        $this->assertEquals('bearer', $securitySchemes['bearerAuth']['scheme']);

        // Verify protected endpoints enforce bearerAuth
        $protectedEndpoints = [
            ['/api/payment/cash', 'post'],
            ['/api/payment/verify', 'post'],
            ['/api/payment/history', 'get'],
            ['/api/invoices/amendment-request', 'post'],
            ['/api/invoices/amendment/{id}/approve', 'post'],
            ['/api/invoices/amendment/{id}/reject', 'post'],
            ['/api/invoices/amendment/list', 'get'],
            ['/api/pickup/validate', 'post'],
            ['/api/pickup/release', 'post'],
            ['/api/expenses/create', 'post'],
            ['/api/expenses/list', 'get'],
            ['/api/expenses/summary', 'get'],
            ['/api/bank-deposits/create', 'post'],
            ['/api/bank-deposits/list', 'get'],
            ['/api/bank-deposits/summary', 'get'],
            ['/api/reconciliation/eod/generate', 'post'],
            ['/api/reconciliation/eod/show', 'get'],
        ];

        foreach ($protectedEndpoints as [$path, $method]) {
            $endpointDoc = $spec['paths'][$path][$method] ?? null;
            $this->assertNotNull($endpointDoc, "Endpoint {$path} must exist");
            $this->assertArrayHasKey('security', $endpointDoc, "Protected endpoint {$path} must have security scheme attached");
        }
    }

    public function test_no_duplicate_operation_ids_and_tag_integrity(): void
    {
        $spec = json_decode(File::get($this->docPath), true);
        $paths = $spec['paths'] ?? [];

        $operationIds = [];
        $tags = collect($spec['tags'] ?? [])->pluck('name')->toArray();

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $opId = $operation['operationId'];
                    $this->assertNotContains($opId, $operationIds, "Duplicate operationId detected: {$opId} on {$method} {$path}");
                    $operationIds[] = $opId;
                }

                if (is_array($operation) && isset($operation['tags'])) {
                    foreach ($operation['tags'] as $tag) {
                        $this->assertContains($tag, $tags, "Tag [{$tag}] used on {$path} must be declared in root tags");
                    }
                }
            }
        }
    }
}
