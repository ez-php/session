<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\Session\Driver\ArraySessionHandler;
use EzPhp\Session\Flash;
use EzPhp\Session\SessionRegenerator;
use EzPhp\Session\StartSessionMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;

/**
 * Class StartSessionMiddlewareTest
 *
 * @package Tests
 * @uses \Tests\Support\FakeConfig
 */
#[CoversClass(StartSessionMiddleware::class)]
#[UsesClass(ArraySessionHandler::class)]
#[UsesClass(Flash::class)]
#[UsesClass(SessionRegenerator::class)]
final class StartSessionMiddlewareTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_write_close();
        }
    }

    /**
     * @return void
     */
    public function test_it_starts_the_session_and_calls_next(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());
        $request = $this->makeRequest();

        $response = $middleware->handle($request, function (Request $r): Response {
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());

            return new Response('OK', 200);
        });

        $this->assertSame(200, $response->status());
    }

    /**
     * @return void
     */
    public function test_it_does_not_restart_an_already_active_session(): void
    {
        session_set_save_handler(new ArraySessionHandler(), true);
        session_start();
        $idBefore = session_id();

        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());
        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));

        $this->assertSame($idBefore, session_id());
    }

    /**
     * @return void
     */
    public function test_it_ages_flash_data_before_calling_next(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());

        $middleware->handle($this->makeRequest(), function (Request $r): Response {
            Flash::set('message', 'first request');

            return new Response('OK', 200);
        });

        $middleware->handle($this->makeRequest(), function (Request $r): Response {
            $this->assertSame('first request', Flash::get('message'));

            return new Response('OK', 200);
        });
    }

    /**
     * @return void
     */
    public function test_it_regenerates_the_id_when_the_interval_has_elapsed(): void
    {
        $config = new FakeConfig(['session.regenerate_interval' => 60]);
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), $config);
        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));
        $idAfterFirst = session_id();
        $_SESSION['_session_regenerated_at'] = time() - 100;

        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));

        $this->assertNotSame($idAfterFirst, session_id());
    }

    /**
     * @return void
     */
    public function test_it_does_not_regenerate_the_id_when_disabled(): void
    {
        $config = new FakeConfig(['session.regenerate_interval' => 0]);
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), $config);
        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));
        $idAfterFirst = session_id();

        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));

        $this->assertSame($idAfterFirst, session_id());
    }

    /**
     * @return void
     */
    public function test_options_default_to_hardened_cookie_settings(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());

        $options = $middleware->options($this->makeRequest());

        $this->assertTrue($options['use_strict_mode']);
        $this->assertTrue($options['use_only_cookies']);
        $this->assertTrue($options['cookie_httponly']);
        $this->assertFalse($options['cookie_secure']);
        $this->assertSame('Lax', $options['cookie_samesite']);
        $this->assertSame(0, $options['cookie_lifetime']);
        $this->assertSame('/', $options['cookie_path']);
        $this->assertArrayNotHasKey('name', $options);
    }

    /**
     * @return void
     */
    public function test_secure_is_auto_detected_from_https_request(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());

        $this->assertTrue($middleware->options($this->makeRequest(server: ['HTTPS' => 'on']))['cookie_secure']);
        $this->assertFalse($middleware->options($this->makeRequest(server: ['HTTPS' => 'off']))['cookie_secure']);
    }

    /**
     * @return void
     */
    public function test_config_overrides_the_defaults(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig([
            'session.cookie.secure' => true,
            'session.cookie.samesite' => 'Strict',
            'session.cookie.lifetime' => 3600,
            'session.cookie.domain' => 'example.com',
            'session.cookie.name' => 'app_session',
        ]));

        $options = $middleware->options($this->makeRequest());

        $this->assertTrue($options['cookie_secure']);
        $this->assertSame('Strict', $options['cookie_samesite']);
        $this->assertSame(3600, $options['cookie_lifetime']);
        $this->assertSame('example.com', $options['cookie_domain']);
        $this->assertSame('app_session', $options['name']);
    }

    /**
     * Regression: sessions were started with PHP defaults (no HttpOnly, no SameSite).
     *
     * @return void
     */
    public function test_started_session_uses_the_hardened_cookie_params(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());

        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));

        $params = session_get_cookie_params();
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertSame('1', ini_get('session.use_strict_mode'));
    }

    /**
     * Regression: an id chosen by the client was adopted (session fixation).
     *
     * @return void
     */
    public function test_client_chosen_unknown_session_id_is_not_adopted(): void
    {
        $middleware = new StartSessionMiddleware(new ArraySessionHandler(), new FakeConfig());
        session_id('attackerchosenid1234567890abcdef');

        $middleware->handle($this->makeRequest(), fn (Request $r): Response => new Response('OK', 200));

        $this->assertNotSame('attackerchosenid1234567890abcdef', session_id());
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     *
     * @return Request
     */
    private function makeRequest(array $headers = [], array $server = []): Request
    {
        return new Request(
            method: 'GET',
            uri: '/',
            headers: $headers,
            server: array_merge(['REMOTE_ADDR' => '127.0.0.1'], $server),
        );
    }
}
