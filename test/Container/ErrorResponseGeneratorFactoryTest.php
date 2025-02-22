<?php

declare(strict_types=1);

namespace MezzioTest\Container;

use ArrayAccess;
use Mezzio\Container\ErrorResponseGeneratorFactory;
use Mezzio\Middleware\ErrorResponseGenerator;
use Mezzio\Template\TemplateRendererInterface;
use MezzioTest\InMemoryContainer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ErrorResponseGeneratorFactoryTest extends TestCase
{
    /** @var InMemoryContainer */
    private $container;

    /** @var TemplateRendererInterface&MockObject */
    private $renderer;

    public function setUp(): void
    {
        $this->container = new InMemoryContainer();
        $this->renderer  = $this->createMock(TemplateRendererInterface::class);
    }

    public function testNoConfigurationCreatesInstanceWithDefaults(): void
    {
        $factory = new ErrorResponseGeneratorFactory();

        $generator = $factory($this->container);

        self::assertEquals(new ErrorResponseGenerator(), $generator);
    }

    public function testUsesDebugConfigurationToSetDebugFlag(): void
    {
        $this->container->set('config', ['debug' => true]);
        $factory = new ErrorResponseGeneratorFactory();

        $generator = $factory($this->container);

        self::assertEquals(new ErrorResponseGenerator(true), $generator);
    }

    public function testUsesConfiguredTemplateRenderToSetGeneratorRenderer(): void
    {
        $this->container->set(TemplateRendererInterface::class, $this->renderer);
        $factory = new ErrorResponseGeneratorFactory();

        $generator = $factory($this->container);

        self::assertEquals(new ErrorResponseGenerator(false, $this->renderer), $generator);
    }

    public function testUsesTemplateConfigurationToSetTemplate(): void
    {
        $this->container->set('config', [
            'mezzio' => [
                'error_handler' => [
                    'template_error' => 'error::custom',
                    'layout'         => 'layout::custom',
                ],
            ],
        ]);
        $factory = new ErrorResponseGeneratorFactory();

        $generator = $factory($this->container);

        self::assertEquals(new ErrorResponseGenerator(false, null, 'error::custom', 'layout::custom'), $generator);
    }

    public function testNullifyLayout(): void
    {
        $this->container->set('config', [
            'mezzio' => [
                'error_handler' => [
                    'template_error' => 'error::custom',
                    'layout'         => null,
                ],
            ],
        ]);
        $factory = new ErrorResponseGeneratorFactory();

        $generator = $factory($this->container);

        // ideally we would like to keep null there,
        // but right now ErrorResponseGeneratorFactory does not accept null for layout
        self::assertEquals(new ErrorResponseGenerator(false, null, 'error::custom', ''), $generator);
    }

    public function testCanHandleConfigWithArrayAccess(): void
    {
        $config = $this->createMock(ArrayAccess::class);
        $this->container->set('config', $config);

        $factory = new ErrorResponseGeneratorFactory();
        $factory($this->container);
        $this->expectNotToPerformAssertions();
    }
}
