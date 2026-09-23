<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class VersionService
{
    private ?string $version = null;

    private ?string $build = null;

    /**
     * Get the application version.
     */
    public function getVersion(): string
    {
        if ($this->version === null) {
            $this->loadVersionInfo();
        }

        return $this->version ?? '1.0.0';
    }

    /**
     * Get the application build number.
     */
    public function getBuild(): string
    {
        if ($this->build === null) {
            $this->loadVersionInfo();
        }

        return $this->build ?? 'dev';
    }

    /**
     * Get version and build as an array.
     *
     * @return array{version: string, build: string}
     */
    public function getVersionInfo(): array
    {
        return [
            'version' => $this->getVersion(),
            'build' => $this->getBuild(),
        ];
    }

    /**
     * Load version information from .version file or composer.json.
     */
    private function loadVersionInfo(): void
    {
        // Try to load from .version file first
        $versionFile = base_path('.version');

        if (File::exists($versionFile)) {
            $content = File::get($versionFile);
            $lines = array_filter(array_map('trim', explode("\n", $content)));

            foreach ($lines as $line) {
                if (str_starts_with($line, 'VERSION=')) {
                    $this->version = substr($line, 8);
                } elseif (str_starts_with($line, 'BUILD=')) {
                    $this->build = substr($line, 6);
                }
            }

            return;
        }

        // Fallback to composer.json
        $composerFile = base_path('composer.json');

        if (File::exists($composerFile)) {
            $composer = json_decode(File::get($composerFile), true);

            if (isset($composer['version'])) {
                $this->version = $composer['version'];
            }
        }

        // Generate build from Git commit hash if available
        if (File::exists(base_path('.git'))) {
            try {
                $gitHash = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: '');
                if ($gitHash) {
                    $this->build = $gitHash;
                }
            } catch (\Exception $e) {
                // Git not available, use timestamp
                $this->build = date('Ymd.His');
            }
        } else {
            $this->build = date('Ymd.His');
        }
    }

    /**
     * Reset cached version information (useful for testing).
     */
    public function reset(): void
    {
        $this->version = null;
        $this->build = null;
    }

    /**
     * Format version string based on format type.
     *
     * @param  string  $format  The format to return (version-only, compact, full)
     */
    public function format(string $format = 'compact'): string
    {
        $version = $this->getVersion();
        $build = $this->getBuild();

        return match ($format) {
            'version-only' => $version,
            'full' => "{$version}+{$build}",
            default => "{$version}-{$build}", // compact
        };
    }
}
