<?php

namespace RollandRock\ParamConverterBundle\Tests\Finder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RollandRock\ParamConverterBundle\Exception\FieldNotFoundInRequestException;
use RollandRock\ParamConverterBundle\Finder\RequestFinder;
use Symfony\Component\HttpFoundation\Request;

class RequestFinderTest extends TestCase
{
    #[DataProvider('requests')]
    public function testFindsValuesWithoutLosingTheirType(Request $request, mixed $expected): void
    {
        self::assertSame($expected, (new RequestFinder())->find('value', $request));
    }

    public static function requests(): iterable
    {
        yield 'attributes take precedence' => [new Request(['value' => 'query'], ['value' => 'body'], ['value' => 'route']), 'route'];
        yield 'query takes precedence over body' => [new Request(['value' => 'query'], ['value' => 'body']), 'query'];
        yield 'request body' => [new Request([], ['value' => 'body']), 'body'];
        yield 'JSON body' => [new Request(content: '{"value":"json"}'), 'json'];
        yield 'nested query data' => [new Request(['value' => ['id' => 1]]), ['id' => 1]];
        yield 'nested body data' => [new Request([], ['value' => ['id' => 1]]), ['id' => 1]];
        yield 'zero route value' => [new Request(attributes: ['value' => 0]), 0];
        yield 'zero string query value' => [new Request(['value' => '0']), '0'];
        yield 'false body value' => [new Request([], ['value' => false]), false];
        yield 'empty body string' => [new Request([], ['value' => '']), ''];
        yield 'empty body array' => [new Request([], ['value' => []]), []];
        yield 'null attribute takes precedence' => [new Request(['value' => 'query'], attributes: ['value' => null]), null];
        yield 'JSON null value' => [new Request(content: '{"value":null}'), null];
    }

    #[DataProvider('missingValues')]
    public function testMissingValuesThrowTheExpectedException(Request $request): void
    {
        $this->expectException(FieldNotFoundInRequestException::class);

        (new RequestFinder())->find('value', $request);
    }

    public static function missingValues(): iterable
    {
        yield 'empty request' => [new Request()];
        yield 'missing JSON key' => [new Request(content: '{"other":true}')];
        yield 'invalid JSON' => [new Request(content: '{')];
        yield 'non-object JSON' => [new Request(content: 'null')];
    }
}
