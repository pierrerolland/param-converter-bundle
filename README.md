# param-converter-bundle

This bundle aims to provide useful request attributes. For now, it provides a complete Doctrine entity argument resolver. See usage below.

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

Then, enable the bundle by adding it to the list of registered bundles
in the `app/AppKernel.php` file of your project:

```php
<?php
// app/AppKernel.php

// ...
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            // ...
            new RollandRock\ParamConverterBundle\RollandRockParamConverterBundle(),
        );

        // ...
    }

    // ...
}
```

## Converters

[1. Doctrine entity value resolver](Resources/doc/doctrine_entity.md)
