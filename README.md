# Php Laravel Obfuscator (Composer package)

This package provides reversible PHP identifier obfuscation for Laravel projects.

Scope (what it actually obfuscates)
- Local variable names everywhere.
- Private members (methods/properties) and their `self/static/parent` and `$this->...` references.
- With `--aggressive`, protected members too.

It does **not** rename public APIs/classes/interfaces/traits, because Laravel (container bindings, reflection, route/model conventions) will break.

## Install (into a Laravel app)

Add it as a path repository (for local dev in this mono-repo):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-obfuscator"
    }
  ],
  "require": {
    "Php/laravel-obfuscator": "*"
  }
}
```

Then:

```bash
composer update Php/laravel-obfuscator
```

Laravel auto-discovers the service provider. If you have discovery disabled, register:
`Php\LaravelObfuscator\LaravelObfuscatorServiceProvider`.

## Use (Artisan)

Obfuscate (writes `.php_obfuscation_key_hash`):

```bash
php artisan code:obfuscate "YOUR_KEY" --path=app
```

Deobfuscate:

```bash
php artisan code:deobfuscate "YOUR_KEY" --path=app
```

Options
- `--dry-run`: show which files would be processed.
- `--aggressive`: also rename protected members (higher risk).
- `--path=...` repeatable: scan multiple directories.
- `--include-vendor`: off by default. Do not enable unless you know exactly what you're doing.

## Use (vendor/bin CLI, no Laravel boot)

```bash
vendor/bin/php-obfuscate obfuscate "YOUR_KEY" --root=/path/to/project --path=app --path=routes
vendor/bin/php-obfuscate deobfuscate "YOUR_KEY" --root=/path/to/project --path=app --path=routes
```

# php-obfuscator
