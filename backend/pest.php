<?php

use Illuminate\Foundation\Application;
use Pest\Pluggable\Plugins;

/*
|--------------------------------------------------------------------------
| Pest Configuration
|--------------------------------------------------------------------------
|
| Pest is a testing framework for PHP with a focus on simplicity.
| This file configures Pest for your Laravel application.
|
*/

$plugins = new Plugins(Application::getInstance());

$plugins->usePestLaravel();

/*
|--------------------------------------------------------------------------
| Test Configuration
|--------------------------------------------------------------------------
|
| Configure test behavior, timeout, parallel execution, etc.
|
*/

/*
|--------------------------------------------------------------------------
| Test Directories
|--------------------------------------------------------------------------
|
| Specify where your tests are located.
|
*/

// tests/Unit - Unit tests
// tests/Feature - Feature tests

/*
|--------------------------------------------------------------------------
| Test Environment
|--------------------------------------------------------------------------
|
| Configure environment variables for testing.
|
*/

/*
|--------------------------------------------------------------------------
| Custom Functions
|--------------------------------------------------------------------------
|
| Add custom helper functions for your tests.
|
*/

/*
|--------------------------------------------------------------------------
| Test Suites
|--------------------------------------------------------------------------
|
| Define test suites for different test types.
|
*/

// afterEach(function () {
//     // Clean up after each test
// });

// beforeAll(function () {
//     // Setup before all tests
// });
