<?php
/**
 * @see       https://github.com/zendframework/zend-expressive for the canonical source repository
 * @copyright Copyright (c) 2015-2017 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive;

use DomainException;
use Fig\Http\Message\StatusCodeInterface as StatusCode;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use TypeError;
use UnexpectedValueException;
use Zend\Diactoros\Response;
use Zend\Diactoros\Response\EmitterInterface;
use Zend\Diactoros\Response\SapiEmitter;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Expressive\Application;
use Zend\Expressive\Emitter\EmitterStack;
use Zend\Expressive\Exception;
use Zend\Expressive\Exception\InvalidMiddlewareException;
use Zend\Expressive\Handler;
use Zend\Expressive\Middleware;
use Zend\Expressive\MiddlewareContainer;
use Zend\Expressive\MiddlewareFactory;
use Zend\Expressive\Router\Exception as RouterException;
use Zend\Expressive\Router\Route;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

/**
 * @covers Zend\Expressive\Application
 */
class ApplicationTest extends TestCase
{
    use ContainerTrait;
    use RouteResultTrait;

    /** @var TestAsset\InteropMiddleware */
    private $noopMiddleware;

    /** @var RouterInterface|ObjectProphecy */
    private $router;

    public function setUp()
    {
        $this->noopMiddleware = new TestAsset\InteropMiddleware();
        $this->router = $this->prophesize(RouterInterface::class);
    }

    public function getApp()
    {
        $container = $this->mockContainerInterface();
        return new Application($this->router->reveal(), $container->reveal());
    }

    public function commonHttpMethods()
    {
        return [
            'GET'    => ['GET'],
            'POST'   => ['POST'],
            'PUT'    => ['PUT'],
            'PATCH'  => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public function testConstructorAcceptsRouterAsAnArgument()
    {
        $app = $this->getApp();
        $this->assertInstanceOf(Application::class, $app);
    }

    public function testApplicationIsAMiddlewareInterface()
    {
        $app = $this->getApp();
        $this->assertInstanceOf(MiddlewareInterface::class, $app);
    }

    public function testApplicationIsARequestHandlerInterface()
    {
        $app = $this->getApp();
        $this->assertInstanceOf(RequestHandlerInterface::class, $app);
    }

    public function testApplicationCreatesAMiddlewareFactoryFromTheContainer()
    {
        $container = $this->mockContainerInterface();
        $app = new Application($this->router->reveal(), $container->reveal());

        $r = new ReflectionProperty($app, 'middlewareFactory');
        $r->setAccessible(true);
        $factory = $r->getValue($app);

        $this->assertInstanceOf(MiddlewareFactory::class, $factory);

        $r = new ReflectionProperty($factory, 'container');
        $r->setAccessible(true);
        $factoryContainer = $r->getValue($factory);

        $this->assertInstanceOf(MiddlewareContainer::class, $factoryContainer);
        $this->assertAttributeSame($container->reveal(), 'container', $factoryContainer);
    }

    public function testRouteMethodReturnsRouteInstance()
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalled();
        $route = $this->getApp()->route('/foo', $this->noopMiddleware);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/foo', $route->getPath());
        $this->assertSame($this->noopMiddleware, $route->getMiddleware());
    }

    public function testAnyRouteMethod()
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalled();
        $route = $this->getApp()->any('/foo', $this->noopMiddleware);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/foo', $route->getPath());
        $this->assertSame($this->noopMiddleware, $route->getMiddleware());
        $this->assertSame(Route::HTTP_METHOD_ANY, $route->getAllowedMethods());
    }

    /**
     * @dataProvider commonHttpMethods
     *
     * @param string $method
     */
    public function testCanCallRouteWithHttpMethods($method)
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalled();
        $route = $this->getApp()->route('/foo', $this->noopMiddleware, [$method]);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/foo', $route->getPath());
        $this->assertSame($this->noopMiddleware, $route->getMiddleware());
        $this->assertTrue($route->allowsMethod($method));
        $this->assertSame([$method], $route->getAllowedMethods());
    }

    public function testCanCallRouteWithMultipleHttpMethods()
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalled();
        $methods = array_keys($this->commonHttpMethods());
        $route = $this->getApp()->route('/foo', $this->noopMiddleware, $methods);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/foo', $route->getPath());
        $this->assertSame($this->noopMiddleware, $route->getMiddleware());
        $this->assertSame($methods, $route->getAllowedMethods());
    }

    public function testCallingRouteWithExistingPathAndOmittingMethodsArgumentRaisesException()
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalledTimes(2);
        $app = $this->getApp();
        $app->route('/foo', $this->noopMiddleware);
        $app->route('/bar', $this->noopMiddleware);
        $this->expectException(DomainException::class);
        $app->route('/foo', function ($req, $res, $next) {
        });
    }

    public function testCallingRouteWithOnlyAPathRaisesAnException()
    {
        $app = $this->getApp();
        $this->expectException(Exception\InvalidMiddlewareException::class);
        $app->route('/path', null);
    }

    public function invalidPathTypes()
    {
        return [
            'null'       => [null],
            'true'       => [true],
            'false'      => [false],
            'zero'       => [0],
            'int'        => [1],
            'zero-float' => [0.0],
            'float'      => [1.1],
            'array'      => [['path' => 'route']],
            'object'     => [(object) ['path' => 'route']],
        ];
    }

    /**
     * @dataProvider invalidPathTypes
     *
     * @param mixed $path
     */
    public function testCallingRouteWithAnInvalidPathTypeRaisesAnException($path)
    {
        $app = $this->getApp();
        $this->expectException(TypeError::class);
        $app->route($path, new TestAsset\InteropMiddleware());
    }

    /**
     * @dataProvider commonHttpMethods
     *
     * @param mixed $method
     */
    public function testCommonHttpMethodsAreExposedAsClassMethodsAndReturnRoutes($method)
    {
        $app = $this->getApp();
        $route = $app->{$method}('/foo', $this->noopMiddleware);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/foo', $route->getPath());
        $this->assertSame($this->noopMiddleware, $route->getMiddleware());
        $this->assertEquals([$method], $route->getAllowedMethods());
    }

    public function testCreatingHttpRouteMethodWithExistingPathButDifferentMethodCreatesNewRouteInstance()
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalledTimes(2);
        $app = $this->getApp();
        $route = $app->route('/foo', $this->noopMiddleware, []);

        $middleware = new TestAsset\InteropMiddleware();
        $test = $app->get('/foo', $middleware);
        $this->assertNotSame($route, $test);
        $this->assertSame($route->getPath(), $test->getPath());
        $this->assertSame(['GET'], $test->getAllowedMethods());
        $this->assertSame($middleware, $test->getMiddleware());
    }

    public function testCreatingHttpRouteWithExistingPathAndMethodRaisesException()
    {
        $this->router->addRoute(Argument::type(Route::class))->shouldBeCalledTimes(1);
        $app   = $this->getApp();
        $app->get('/foo', $this->noopMiddleware);

        $this->expectException(DomainException::class);
        $app->get('/foo', function ($req, $res, $next) {
        });
    }

    public function testCanInjectDefaultHandlerViaConstructor()
    {
        $defaultHandler = $this->prophesize(RequestHandlerInterface::class)->reveal();
        $app  = new Application($this->router->reveal(), null, $defaultHandler);
        $test = $app->getDefaultHandler();
        $this->assertSame($defaultHandler, $test);
    }

    public function testDefaultHandlerIsUsedAtInvocationIfNoOutArgumentIsSupplied()
    {
        $routeResult = RouteResult::fromRouteFailure([]);
        $this->router->match()->willReturn($routeResult);

        $finalResponse = $this->prophesize(ResponseInterface::class)->reveal();
        $defaultHandler = $this->prophesize(RequestHandlerInterface::class);
        $defaultHandler->handle(Argument::type(ServerRequestInterface::class))
            ->willReturn($finalResponse);

        $emitter = $this->prophesize(EmitterInterface::class);
        $emitter->emit($finalResponse)->shouldBeCalled();

        $app = new Application($this->router->reveal(), null, $defaultHandler->reveal(), $emitter->reveal());

        $request  = new ServerRequest([], [], 'http://example.com/');

        $app->run($request);
    }

    public function testComposesEmitterStackWithSapiEmitterByDefault()
    {
        $app   = $this->getApp();
        $stack = $app->getEmitter();
        $this->assertInstanceOf(EmitterStack::class, $stack);

        $this->assertCount(1, $stack);
        $test = $stack->pop();
        $this->assertInstanceOf(SapiEmitter::class, $test);
    }

    public function testAllowsInjectingEmitterAtInstantiation()
    {
        $emitter = $this->prophesize(EmitterInterface::class);
        $app     = new Application(
            $this->router->reveal(),
            null,
            null,
            $emitter->reveal()
        );
        $test = $app->getEmitter();
        $this->assertSame($emitter->reveal(), $test);
    }

    public function testComposedEmitterIsCalledByRun()
    {
        $routeResult = RouteResult::fromRouteFailure([]);
        $this->router->match()->willReturn($routeResult);

        $finalResponse = $this->prophesize(ResponseInterface::class)->reveal();
        $defaultHandler = $this->prophesize(RequestHandlerInterface::class);
        $defaultHandler->handle(Argument::type(ServerRequestInterface::class))
            ->willReturn($finalResponse);

        $emitter = $this->prophesize(EmitterInterface::class);
        $emitter->emit(
            Argument::type(ResponseInterface::class)
        )->shouldBeCalled();

        $app = new Application($this->router->reveal(), null, $defaultHandler->reveal(), $emitter->reveal());

        $request  = new ServerRequest([], [], 'http://example.com/');
        $response = $this->prophesize(ResponseInterface::class);
        $response->withStatus(StatusCode::STATUS_NOT_FOUND)->will([$response, 'reveal']);

        $app->run($request, $response->reveal());
    }

    public function testCallingGetContainerReturnsComposedInstance()
    {
        $container = $this->prophesize(ContainerInterface::class);
        $app = new Application($this->router->reveal(), $container->reveal());
        $this->assertSame($container->reveal(), $app->getContainer());
    }

    public function testCallingGetContainerWhenNoContainerComposedWillRaiseException()
    {
        $app = new Application($this->router->reveal());
        $this->expectException(RuntimeException::class);
        $app->getContainer();
    }

    /**
     * @group lazy-piping
     */
    public function testPipingAllowsPassingMiddlewareServiceNameAsSoleArgument()
    {
        $middleware = new TestAsset\InteropMiddleware();

        $container = $this->mockContainerInterface();
        $this->injectServiceInContainer($container, 'foo', $middleware);

        $app = new Application($this->router->reveal(), $container->reveal());
        $app->pipe('foo');

        $r = new ReflectionProperty($app, 'pipeline');
        $r->setAccessible(true);
        $pipeline = $r->getValue($app);

        $r = new ReflectionProperty($pipeline, 'pipeline');
        $r->setAccessible(true);
        $pipeline = iterator_to_array($r->getValue($pipeline));

        $test = array_shift($pipeline);
        $this->assertInstanceOf(Middleware\LazyLoadingMiddleware::class, $test);
        $this->assertAttributeEquals('foo', 'middlewareName', $test);
    }

    /**
     * @group lazy-piping
     */
    public function testPipingNotInvokableMiddlewareRaisesExceptionWhenInvokingRoute()
    {
        $middleware = 'not callable';

        $container = $this->mockContainerInterface();
        $this->injectServiceInContainer($container, 'foo', $middleware);

        $app = new Application($this->router->reveal(), $container->reveal());
        $app->pipe('foo');

        $request = $this->prophesize(ServerRequest::class)->reveal();
        $delegate = $this->prophesize(RequestHandlerInterface::class)->reveal();

        $this->expectException(InvalidMiddlewareException::class);
        $app->process($request, $delegate);
    }

    public function invalidRequestExceptions()
    {
        return [
            'invalid file'             => [
                InvalidArgumentException::class,
                'Invalid value in files specification',
            ],
            'invalid protocol version' => [
                UnexpectedValueException::class,
                'Unrecognized protocol version (foo-bar)',
            ],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider invalidRequestExceptions
     *
     * @param string $expectedException
     * @param string $message
     */
    public function testRunReturnsResponseWithBadRequestStatusWhenServerRequestFactoryRaisesException(
        $expectedException,
        $message
    ) {
        // try/catch is necessary in the case that the test fails.
        // Assertion exceptions raised inside prophecy expectations normally
        // are fine, but in the context of runInSeparateProcess, these
        // lead to closure serialization errors. try/catch allows us to
        // catch those and provide failure assertions.
        try {
            Mockery::mock('alias:' . ServerRequestFactory::class)
                ->shouldReceive('fromGlobals')
                ->withNoArgs()
                ->andThrow($expectedException, $message)
                ->once()
                ->getMock();

            $emitter = $this->prophesize(EmitterInterface::class);
            $emitter->emit(Argument::that(function ($response) {
                $this->assertInstanceOf(ResponseInterface::class, $response, 'Emitter did not receive a response');
                $this->assertEquals(StatusCode::STATUS_BAD_REQUEST, $response->getStatusCode());
                return true;
            }))->shouldBeCalled();

            $app = new Application($this->router->reveal(), null, null, $emitter->reveal());

            $app->run();
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testRetrieveRegisteredRoutes()
    {
        // $route = new Route('/foo', $this->noopMiddleware);
        $this->router->addRoute(Argument::that(function ($arg) {
            Assert::assertInstanceOf(Route::class, $arg);
            Assert::assertSame('/foo', $arg->getPath());
            Assert::assertSame($this->noopMiddleware, $arg->getMiddleware());
            return true;
        }))->shouldBeCalled();

        $app = $this->getApp();
        $test = $app->route('/foo', $this->noopMiddleware);

        $this->assertInstanceOf(Route::class, $test);
        $this->assertSame('/foo', $test->getPath());
        $this->assertSame($this->noopMiddleware, $test->getMiddleware());

        $routes = $app->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertContainsOnlyInstancesOf(Route::class, $routes);
    }

    /**
     * This test verifies that if the ErrorResponseGenerator service is available,
     * it will be used to generate a response related to exceptions raised when
     * creating the server request.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider invalidRequestExceptions
     *
     * @param string $expectedException
     * @param string $message
     */
    public function testRunReturnsResponseGeneratedByErrorResponseGeneratorWhenServerRequestFactoryRaisesException(
        $expectedException,
        $message
    ) {
        // try/catch is necessary in the case that the test fails.
        // Assertion exceptions raised inside prophecy expectations normally
        // are fine, but in the context of runInSeparateProcess, these
        // lead to closure serialization errors. try/catch allows us to
        // catch those and provide failure assertions.
        try {
            $generator = $this->prophesize(Middleware\ErrorResponseGenerator::class);
            $generator
                ->__invoke(
                    Argument::type($expectedException),
                    Argument::type(ServerRequestInterface::class),
                    Argument::type(ResponseInterface::class)
                )->will(function ($args) {
                    return $args[2];
                });

            $container = $this->mockContainerInterface();
            $this->injectServiceInContainer($container, Middleware\ErrorResponseGenerator::class, $generator);

            Mockery::mock('alias:' . ServerRequestFactory::class)
                ->shouldReceive('fromGlobals')
                ->withNoArgs()
                ->andThrow($expectedException, $message)
                ->once()
                ->getMock();

            $expectedResponse = $this->prophesize(ResponseInterface::class)->reveal();

            $emitter = $this->prophesize(EmitterInterface::class);
            $emitter->emit(Argument::that(function ($response) use ($expectedResponse) {
                $this->assertSame($expectedResponse, $response, 'Unexpected response provided to emitter');
                return true;
            }))->shouldBeCalled();

            $app = new Application(
                $this->router->reveal(),
                $container->reveal(),
                null,
                $emitter->reveal(),
                $expectedResponse
            );

            $app->run();
        } catch (Throwable $e) {
            $this->fail(sprintf("(%d) %s:\n%s", $e->getCode(), $e->getMessage(), $e->getTraceAsString()));
        }
    }

    public function testGetDefaultHandlerWillPullFromContainerIfServiceRegistered()
    {
        $handler = $this->prophesize(RequestHandlerInterface::class)->reveal();
        $container = $this->mockContainerInterface();
        $this->injectServiceInContainer($container, 'Zend\Expressive\Handler\DefaultHandler', $handler);

        $app = new Application($this->router->reveal(), $container->reveal());

        $test = $app->getDefaultHandler();

        $this->assertSame($handler, $test);
    }

    public function testWillCreateAndConsumeNotFoundHandlerFactoryToCreateHandlerIfNoHandlerInContainer()
    {
        $container = $this->mockContainerInterface();
        $container->has('Zend\Expressive\Handler\DefaultHandler')->willReturn(false);
        $container->has(TemplateRendererInterface::class)->willReturn(false);
        $app = new Application($this->router->reveal(), $container->reveal());

        $handler = $app->getDefaultHandler();

        $this->assertInstanceOf(Handler\NotFoundHandler::class, $handler);

        $r = new ReflectionProperty($app, 'responsePrototype');
        $r->setAccessible(true);
        $appResponsePrototype = $r->getValue($app);

        $this->assertAttributeNotSame($appResponsePrototype, 'responsePrototype', $handler);
        $this->assertAttributeEmpty('renderer', $handler);
    }

    public function testWillUseConfiguredTemplateRendererWhenCreatingHandlerFromNotFoundHandlerFactory()
    {
        $container = $this->mockContainerInterface();
        $container->has('Zend\Expressive\Handler\DefaultHandler')->willReturn(false);

        $renderer = $this->prophesize(TemplateRendererInterface::class)->reveal();
        $this->injectServiceInContainer($container, TemplateRendererInterface::class, $renderer);

        $app = new Application($this->router->reveal(), $container->reveal());

        $handler = $app->getDefaultHandler();

        $this->assertInstanceOf(Handler\NotFoundHandler::class, $handler);

        $r = new ReflectionProperty($app, 'responsePrototype');
        $r->setAccessible(true);
        $appResponsePrototype = $r->getValue($app);

        $this->assertAttributeNotSame($appResponsePrototype, 'responsePrototype', $handler);
        $this->assertAttributeSame($renderer, 'renderer', $handler);
    }

    public function testAllowsNestedMiddlewarePipelines()
    {
        $app     = $this->getApp();
        $counter = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            $count   = $request->getAttribute('count', 0);
            $request = $request->withAttribute('count', $count + 1);

            return $handler->handle($request);
        };

        $app->pipe([
            // First level
            $counter,
            [
                // Second level
                $counter,
                $counter
            ],
            [
                [
                    // Third level
                    $counter,
                    $counter
                ]
            ]
        ]);

        $request  = new ServerRequest();
        $response = new Response();
        $handler  = $this->prophesize(RequestHandlerInterface::class);

        $handler->handle($request->withAttribute('count', 5))
            ->shouldBeCalled()
            ->willReturn($response);

        $this->assertSame($response, $app->process($request, $handler->reveal()));
    }
}
