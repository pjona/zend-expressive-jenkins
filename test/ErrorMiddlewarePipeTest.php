<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @see       http://github.com/zendframework/zend-expressive for the canonical source repository
 * @copyright Copyright (c) 2015-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Expressive;

use PHPUnit_Framework_TestCase as TestCase;
use Prophecy\Argument;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\UriInterface as Uri;
use Zend\Expressive\ErrorMiddlewarePipe;
use Zend\Stratigility\Http\Response as StratigilityResponse;
use Zend\Stratigility\MiddlewarePipe;

class ErrorMiddlewarePipeTest extends TestCase
{
    public function setUp()
    {
        $this->internalPipe = new MiddlewarePipe();
        $this->errorPipe = new ErrorMiddlewarePipe($this->internalPipe);
    }

    public function testWillDispatchErrorMiddlewareComposedInInternalPipeline()
    {
        $error = (object) ['error' => true];
        $triggered = (object) [
            'first' => false,
            'second' => false,
            'third' => false,
        ];

        $first = function ($err, $request, $response, $next) use ($error, $triggered) {
            $this->assertSame($error, $err);
            $triggered->first = true;
            return $next($request, $response, $err);
        };
        $second = function ($request, $response, $next) use ($triggered) {
            $triggered->second = true;
            return $next($request, $response);
        };
        $third = function ($err, $request, $response, $next) use ($error, $triggered) {
            $this->assertSame($error, $err);
            $triggered->third = true;
            return $response;
        };

        $this->internalPipe->pipe($first);
        $this->internalPipe->pipe($second);
        $this->internalPipe->pipe($third);

        $uri = $this->prophesize(Uri::class);
        $uri->getPath()->willReturn('/');
        $request = $this->prophesize(Request::class);
        $request->getUri()->willReturn($uri->reveal());

        // The following is required due to Stratigility decorating requests:
        $request
            ->withAttribute('originalUri', Argument::that([$uri, 'reveal']))
            ->will([$request, 'reveal']);
        // Stratigility 1.3 also injects the originalRequest attribute
        if (method_exists($this->internalPipe, 'process')) {
            $request
                ->withAttribute('originalRequest', Argument::that([$request, 'reveal']))
                ->will([$request, 'reveal']);
        }

        $response = $this->prophesize(Response::class);

        $final = function ($request, $response, $err = null) {
            $this->fail('Final handler should not be triggered');
        };

        // Stratigility 1.3 deprecates error middleware
        set_error_handler(function ($errno, $errstr) {
            return false !== strstr($errstr, 'error middleware is deprecated');
        }, E_USER_DEPRECATED);

        $result = $this->errorPipe->__invoke($error, $request->reveal(), $response->reveal(), $final);

        restore_error_handler();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($triggered->first);
        $this->assertFalse($triggered->second);
        $this->assertTrue($triggered->third);
    }
}
