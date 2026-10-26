# param-converter-bundle

This bundle aims to provide useful request attributes. For now, it provides a complete Doctrine entity argument resolver. See usage below.

Requires PHP 8.2 or later and Symfony 6.2+ (including 6.4 LTS) or Symfony 7.

## Installation


### Applications that use Symfony Flex


Open a command console, enter your project directory and execute:

```console
$ composer require rollandrock/param-converter-bundle
```

### Applications that don't use Symfony Flex

#### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require rollandrock/param-converter-bundle
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

#### Step 2: Enable the Bundle

Then, enable the bundle in your project's `config/bundles.php` file:

```php
<?php
// config/bundles.php
return [
    // ...
    RollandRock\ParamConverterBundle\RollandRockParamConverterBundle::class => ['all' => true],
];
```

## Converters

[1. Doctrine entity value resolver](Resources/doc/doctrine_entity.md)

## Tests

```console
composer install
composer test
```
