<?php

namespace Php\LaravelObfuscator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Php\LaravelObfuscator\ObfuscationCodec;
use Php\LaravelObfuscator\PhpIdentifierObfuscator;

final class CodeDeobfuscateCommand extends Command
{
    protected $signature = 'code:deobfuscate
                            {key : Obfuscation key (must match the one used for obfuscation)}
                            {--dry-run : Preview without making changes}
                            {--force : Ignore `.php_obfuscation_key_hash` mismatch}
                            {--path=* : Relative directory to scan (repeatable)}
                            {--include-vendor : Allow scanning vendor/ (dangerous; off by default)}';

    protected $description = 'De-obfuscate PHP code previously processed by code:obfuscate';

    public function handle(): int
    {
        $keyMaterial = (string) $this->argument('key');

        $hashFile = base_path('.php_obfuscation_key_hash');
        if (File::exists($hashFile)) {
            $expected = trim((string) File::get($hashFile));
            if (!$this->option('force') && $expected !== '' && !hash_equals($expected, hash('sha256', $keyMaterial))) {
                $this->error('Key does not match `.php_obfuscation_key_hash`');
                return Command::FAILURE;
            }
        }

        $includeDirs = $this->option('path');
        if (!is_array($includeDirs) || $includeDirs === []) {
            $includeDirs = ['app'];
        }

        $codec = new ObfuscationCodec($keyMaterial);
        $obf = new PhpIdentifierObfuscator($codec, false);

        $files = $this->listPhpFiles($includeDirs);
        if (!(bool) $this->option('include-vendor')) {
            $files = $this->filterCommonExcluded($files);
        }

        $this->info('Found ' . count($files) . ' PHP files');

        $changed = 0;
        $maybeObfuscated = 0;

        foreach ($files as $file) {
            if ($this->option('dry-run')) {
                $this->line('  Would process: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
                continue;
            }

            try {
                $before = File::get($file);
                if (!str_starts_with(ltrim($before), '<?php')) {
                    continue;
                }

                if (
                    str_contains($before, PhpIdentifierObfuscator::VAR_PREFIX) ||
                    str_contains($before, PhpIdentifierObfuscator::METHOD_PREFIX) ||
                    str_contains($before, PhpIdentifierObfuscator::PROP_PREFIX)
                ) {
                    $maybeObfuscated++;
                }

                $after = $obf->deobfuscate($before);
                if ($after !== $before) {
                    File::put($file, $after);
                    $changed++;
                    $this->line('  Deobfuscated: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
                }
            } catch (\Throwable $e) {
                $this->error('  Failed: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ' (' . $e->getMessage() . ')');
            }
        }

        if (!$this->option('dry-run')) {
            $this->info('Changed ' . $changed . ' files');
            if ($maybeObfuscated > 0 && $changed === 0) {
                $this->warn('No changes applied but obfuscated identifiers were detected; key is likely wrong or paths are incomplete.');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<int, string>  $includeDirs
     * @return array<int, string>
     */
    private function listPhpFiles(array $includeDirs): array
    {
        $files = [];

        foreach ($includeDirs as $dir) {
            $path = base_path((string) $dir);
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Avoid scanning vendor/ and other common "generated" directories by default.
     *
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    private function filterCommonExcluded(array $files): array
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $excludedPrefixes = [
            $base . 'vendor' . DIRECTORY_SEPARATOR,
            $base . 'storage' . DIRECTORY_SEPARATOR,
            $base . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR,
            $base . '.git' . DIRECTORY_SEPARATOR,
            $base . 'node_modules' . DIRECTORY_SEPARATOR,
        ];

        $out = [];
        foreach ($files as $file) {
            $skip = false;
            foreach ($excludedPrefixes as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $out[] = $file;
        }

        return $out;
    }
}

