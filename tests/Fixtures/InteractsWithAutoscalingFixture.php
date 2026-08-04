<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Cbox\LaravelQueueAutoscale\Tests\TestCase;

/**
 * A real composition site for the shipped testing trait.
 *
 * The package's own specs compose it through Pest's `uses()`, which PHPStan
 * cannot see — so without this the trait is never analysed, and a type error
 * inside it would only surface in a consumer's suite. Analysing it here means
 * the helper we ship is checked as strictly as the code it helps test.
 */
class InteractsWithAutoscalingFixture extends TestCase
{
    use InteractsWithAutoscaling;
}
