<?php

/**
 * @see       https://github.com/mezzio/mezzio-tooling for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-tooling/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-tooling/blob/master/LICENSE.md New BSD License
 */

namespace MezzioTest\Tooling\ScanForErrorMiddleware;

use Countable;
use IteratorAggregate;
use Laminas\Stdlib\ConsoleHelper;
use Laminas\Stratigility\ErrorMiddlewareInterface;
use Mezzio\Tooling\ScanForErrorMiddleware\ErrorMiddlewareFilter;
use Mezzio\Tooling\ScanForErrorMiddleware\Scanner;
use org\bovigo\vfs\vfsStream;
use PHPUnit_Framework_TestCase as TestCase;
use Prophecy\Argument;

class ScannerTest extends TestCase
{
    const CLASS_IMPLEMENTING_INTERFACE = <<< 'EOC'
<?php
namespace Foo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Stratigility\ErrorMiddlewareInterface;

class ErrorMiddleware implements ErrorMiddlewareInterface
{
    public function __invoke($error, ServerRequestInterface $request, ResponseInterface $response, callable $out = null)
    {
    }
}
EOC;

    const CLASS_DUCK_TYPING = <<< 'EOC'
<?php
namespace Foo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DuckTypedErrorMiddleware
{
    public function __invoke($error, ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
    }
}
EOC;

    const CLASS_INVOKING_ERROR = <<< 'EOC'
<?php
namespace Foo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class InvokeErrorMiddleware
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        return $next($request, $response, 'error');
    }
}
EOC;

    const BASIC_MIDDLEWARE = <<< 'EOC'
<?php
namespace Foo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class BasicMiddleware
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        return $next($request, $response);
    }
}
EOC;

    public function setUp()
    {
        $this->dir = vfsStream::setup('scanner');
        $this->path = vfsStream::url('scanner');
        $this->console = $this->prophesize(ConsoleHelper::class);
        $this->scanner = new Scanner($this->path, $this->console->reveal());
    }

    public function testImplementsCountable()
    {
        $this->assertInstanceOf(Countable::class, $this->scanner);
    }

    public function testCountIsZeroByDefault()
    {
        $this->assertCount(0, $this->scanner);
    }

    public function testImplementsIteratorAggregateAsAnErrorMiddlewareFilter()
    {
        $this->assertInstanceOf(IteratorAggregate::class, $this->scanner);
        $iterator = $this->scanner->getIterator();
        $this->assertInstanceOf(ErrorMiddlewareFilter::class, $iterator);
    }

    public function testScanningEmitsInfoToConsoleWhenEncounteringFilesOfInterest()
    {
        vfsStream::newFile('src/ErrorMiddleware.php')
            ->at($this->dir)
            ->setContent(self::CLASS_IMPLEMENTING_INTERFACE);
        vfsStream::newFile('src/DuckTypedErrorMiddleware.php')
            ->at($this->dir)
            ->setContent(self::CLASS_DUCK_TYPING);
        vfsStream::newFile('src/InvokeErrorMiddleware.php')
            ->at($this->dir)
            ->setContent(self::CLASS_INVOKING_ERROR);
        vfsStream::newFile('src/BasicMiddleware.php')
            ->at($this->dir)
            ->setContent(self::BASIC_MIDDLEWARE);

        $this->console
            ->writeLine(
                Argument::that(function ($arg) {
                    if (! strstr($arg, 'src/ErrorMiddleware.php')) {
                        return false;
                    }
                    if (! strstr($arg, sprintf('<error>implementing %s</error>', ErrorMiddlewareInterface::class))) {
                        return false;
                    }
                    return true;
                })
            )
            ->shouldBeCalled();

        $this->console
            ->writeLine(
                Argument::that(function ($arg) {
                    if (! strstr($arg, 'src/DuckTypedErrorMiddleware.php')) {
                        return false;
                    }
                    if (! strstr($arg, '<error>implementing invokable error middleware</error>')) {
                        return false;
                    }
                    return true;
                })
            )
            ->shouldBeCalled();

        $this->console
            ->writeLine(
                Argument::that(function ($arg) {
                    if (! strstr($arg, 'src/InvokeErrorMiddleware.php')) {
                        return false;
                    }
                    if (! strstr($arg, '<error>call to $next with an error argument</error>')) {
                        return false;
                    }
                    return true;
                })
            )
            ->shouldBeCalled();

        $this->assertNull($this->scanner->scan());

        return $this->scanner;
    }

    /**
     * @depends testScanningEmitsInfoToConsoleWhenEncounteringFilesOfInterest
     */
    public function testScanningIncrementsCount($scanner)
    {
        $this->assertCount(3, $scanner);
    }
}