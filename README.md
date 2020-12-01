# Netlogix ErrorHandler for Neos

## Install package
`composer require netlogix/errorhandler`

## Configuration
Provide configuration for every site and status code
```yaml
Netlogix:
  ErrorHandler:
    pages:
      'my-site':
        -
          dimensionPathSegment: 'en_US'
          dimensions:
            language: ['en_US', 'en']
          matchingStatusCodes: [404, 500]
          source: '#550e8400-e29b-11d4-a716-446655440000'
          destination: '${"/var/www/default/" + site + "/" + dimensions + "/500.html"}'
```

## Generate error pages

```bash
./flow errorpage:generate --verbose
```