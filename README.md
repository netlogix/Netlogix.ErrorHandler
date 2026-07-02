# Netlogix ErrorHandler for Neos

This package allows you to generate static error pages by using the content of Neos pages. These static files will
be used for error handling in Flow & Neos depending on their configuration.

You can also use these files as ErrorDocument in your webserver.

## Install package
`composer require netlogix/errorhandler`

## Configuration

### Option 1: Add error pages manually as document nodes.

Create a custom document node, which uses the
`Netlogix.ErrorHandler.NodeTypes:Mixin.ErrorPage` mixin.

Every child page of this error page as well as child pages of siblings of
this error age are covered by this error page.

Every error counts for its exact dimension and for the error codes configured
within that page node.

A dynamic error page is omitted, if no error code is assigned.

There still needs to be a storage folder where cached error pages will be
placed.

```yaml
Netlogix:
  ErrorHandler:

    # File path where this error page should be saved to. Available variables are `site`, `dimensions` and `node`.
    destination: '${"/var/www/default/" + site + "/" + dimensions + "/" + node + ".html"}'
```

## Generate error pages

To generate the static error pages, run the following Flow command:

```bash
./flow errorpage:generate --verbose
```

This will loop all error pages and download them to their destination. Depending on how
often the content of the configured Neos pages changes, you might want to do this during deployment
or periodically using a cronjob.

## Show current configuration

```bash
./flow errorpage:showconfiguration
```

This call responds in YAML format, although not all settings are actually
given in YAML settings. Instead, the result will be the merged result of both,
YAML configuration and node based configuration.