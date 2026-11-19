<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

/**
 * Test bootstrap. The suite runs without booting Craft; the settings model validates through
 * Laravel's validator, so the container gets just enough to build one.
 */

require_once __DIR__.'/../vendor/autoload.php';

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

$container = new Container;
Container::setInstance($container);
$translator = new Translator(new ArrayLoader, 'en');
$container->instance('translator', $translator);
$container->instance(
    Illuminate\Contracts\Validation\Factory::class,
    new Factory($translator, $container),
);
$container->alias(Illuminate\Contracts\Validation\Factory::class, 'validator');
$container->instance('events', new Dispatcher($container));
