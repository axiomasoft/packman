<?php

declare(strict_types=1);

namespace Packman\Plugins\Tests\Fixtures;

interface Greeter
{
    public function key(): string;

    public function greet(): string;
}

final class HelloGreeter implements Greeter
{
    public function key(): string
    {
        return 'test.hello';
    }

    public function greet(): string
    {
        return 'hello';
    }
}

final class HolaGreeter implements Greeter
{
    public function key(): string
    {
        return 'test.hola';
    }

    public function greet(): string
    {
        return 'hola';
    }
}

final class LoudGreeter implements Greeter
{
    public function key(): string
    {
        return 'test.loud';
    }

    public function greet(): string
    {
        return 'HELLO';
    }
}

final class NotAGreeter
{
    public function key(): string
    {
        return 'test.impostor';
    }
}

final class BadKeyGreeter implements Greeter
{
    public function key(): string
    {
        return 'Bad Key';
    }

    public function greet(): string
    {
        return '';
    }
}

interface PipelineStep
{
    public function name(): string;
}

final class FirstStep implements PipelineStep
{
    public function name(): string
    {
        return 'first';
    }
}

final class SecondStep implements PipelineStep
{
    public function name(): string
    {
        return 'second';
    }
}

final class ThirdStep implements PipelineStep
{
    public function name(): string
    {
        return 'third';
    }
}
