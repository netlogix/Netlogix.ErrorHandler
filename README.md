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

### Option 2: Provide error pages via yaml

Provide configuration for every site and status code you need:

```yaml
Netlogix:
  ErrorHandler:
    pages:
      # siteNodeName of all Sites that you want to generate error pages for

      'my-site-without-dimensions':
        -
          # The status codes this error page is generated for
          matchingStatusCodes: [404, 410]

          # Dimensions to use for this error page. Use empty array if no dimensions are configured
          dimensions: []

          # Node identifier of documentNode to use for rendering
          source: '#550e8400-e29b-11d4-a716-446655440000'

          # File path where this error page should be saved to. Available variables are `site` and `dimensions`
          destination: '${"/var/www/default/mysite/errorpages/404.html"}'

        -
          # You can also configure path prefixes so some site areas have different error pages. Make sure to adjust the destination path accordingly.
          pathPrefixes: ['/some-special-path']

          # The status codes this error page is generated for
          matchingStatusCodes: [404, 410]

          # Dimensions to use for this error page. Use empty array if no dimensions are configured
          dimensions: []

          # Node identifier of documentNode to use for rendering
          source: '#f7c8d757-391a-4a85-bdb5-81df56d5e2c0'

          # File path where this error page should be saved to. Available variables are site and dimensions
          destination: '${"/var/www/default/mysite/errorpages/404-some-special-path.html"}'

      'my-site-with-dimensions':
        -
          matchingStatusCodes: [500]

          # The first path segment that determines the dimensions. Use empty string if no dimensions are configured
          dimensionPathSegment: 'en_US'

          # Dimensions to use for this error page. Use empty array if no dimensions are configured
          dimensions:
            language: ['en_US', 'en']

          source: '#550e8400-e29b-11d4-a716-446655440000'
          destination: '${"%FLOW_PATH_DATA%Persistent/ErrorPages/" + site + "-" + dimensions + "-500.html"}'
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