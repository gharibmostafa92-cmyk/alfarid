<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/** L8: a client-supplied request id is only trusted behind a trusted proxy. */
class AssignRequestIdTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_TRAEFIK);
        parent::tearDown();
    }

    public function test_client_request_id_is_not_adopted_without_a_trusted_proxy(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X-Request-ID' => 'CLIENT-ID-01']);

        $response = (new AssignRequestId)->handle($request, fn () => response('ok'));

        $adopted = $response->headers->get('X-Request-ID');
        $this->assertNotSame('CLIENT-ID-01', $adopted);
        $this->assertSame('CLIENT-ID-01', Context::get('client_request_id'));
        $this->assertSame($adopted, Context::get('request_id'));
    }

    public function test_client_request_id_is_adopted_from_a_trusted_proxy(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], SymfonyRequest::HEADER_X_FORWARDED_TRAEFIK);
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X-Request-ID' => 'PROXY-TRACE-99',
        ]);

        $response = (new AssignRequestId)->handle($request, fn () => response('ok'));

        $this->assertSame('PROXY-TRACE-99', $response->headers->get('X-Request-ID'));
    }
}
