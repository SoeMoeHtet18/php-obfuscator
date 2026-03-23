<?php

namespace Php\LaravelObfuscator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Php\LaravelObfuscator\ObfuscationCodec;
use Php\LaravelObfuscator\PhpIdentifierObfuscator;

final class CodeObfuscateCommand extends Command
{
    protected $signature = 'code:obfuscate
                            {key : Obfuscation key (required for reversible deobfuscation)}
                            {--dry-run : Preview without making changes}
                            {--aggressive : Also rename protected members (higher risk)}
                            {--path=* : Relative directory to scan (repeatable)}
                            {--include-vendor : Allow scanning vendor/ (dangerous; off by default)}';

    protected $description = 'Obfuscate PHP identifiers (reversible with the same key)';

    public function handle(): int
    {
        $keyMaterial = (string) $this->argument('key');

        // Default to app/ only so Artisan can still boot while code is obfuscated.
        $includeDirs = $this->option('path');
        if (!is_array($includeDirs) || $includeDirs === []) {
            $includeDirs = ['app'];
        }

        $codec = new ObfuscationCodec($keyMaterial);
        $obf = new PhpIdentifierObfuscator($codec, (bool) $this->option('aggressive'));

        $files = $this->listPhpFiles($includeDirs);
        if (!(bool) $this->option('include-vendor')) {
            $files = $this->filterCommonExcluded($files);
        }

        $this->info('Found ' . count($files) . ' PHP files');

        $changed = 0;
        $failed = 0;

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

                $after = $obf->obfuscate($before);
                if ($after !== $before) {
                    File::put($file, $after);
                    $changed++;
                    $this->line('  Obfuscated: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  Failed: ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ' (' . $e->getMessage() . ')');
            }
        }

        if (!$this->option('dry-run')) {
            // Used as a guardrail to reduce accidental deobfuscation with a different key.
            File::put(base_path('.php_obfuscation_key_hash'), hash('sha256', $keyMaterial));

            $this->info('Changed ' . $changed . ' files');
            if ($failed > 0) {
                $this->warn('Failed ' . $failed . ' files (skipped)');
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
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

