<?php

use KandySoft\MediaKit\Tests\TestCase;

// Feature tests boot a Laravel application through testbench; unit tests are
// plain PHP and need nothing.
uses(TestCase::class)->in('Feature');
