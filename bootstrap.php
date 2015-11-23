<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = new Silex\Application();

$app->register(new Silex\Provider\SwiftmailerServiceProvider());

$app['debug'] = true;

$dotenv = new Dotenv(__DIR__);
$dotenv->load(__DIR__);

$app['swiftmailer.options'] = array(
    'host' => $_ENV['EMAIL_HOST'],
    'port' => $_ENV['EMAIL_PORT'],
    'username' => $_ENV['EMAIL_USERNAME'],
    'password' => $_ENV['EMAIL_PASSWORD'],
    'encryption' => 'ssl',
    'auth_mode' => null
);

$bitbucket = new Bitbucket\bitbucket();

$bitbucket_teams = array(
    $_ENV['BITBUCKET_TEAM']
);