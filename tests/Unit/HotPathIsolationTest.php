<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The hot path moves to a Go service later, so it may depend only on docs/contract.md:
 * no models, no database and no other application classes.
 */
class HotPathIsolationTest extends TestCase
{
    private const array FORBIDDEN = [
        '/(?<![\w\\\\])\\\\?App\\\\(?!HotPath\b)[\w\\\\]*/',
        '/Illuminate\\\\Database\\\\[\w\\\\]*/',
        '/Illuminate\\\\Support\\\\Facades\\\\(?:DB|Schema)\b/',
        '/(?<![\w\\\\])\\\\?(?:DB|Schema)::/',
        '/\bEloquent\b/',
    ];

    public function test_hot_path_references_nothing_outside_the_module(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->hotPathFiles($root) as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $relativePath = substr($path, strlen($root) + 1);

            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                foreach ($this->forbiddenReferences($line) as $reference) {
                    $violations[] = "{$relativePath}:{$lineNumber}: {$reference}";
                }
            }
        }

        $message = "Hot path references outside App\\HotPath:\n".implode("\n", $violations);

        $this->assertSame([], $violations, $message);
    }

    public function test_patterns_catch_a_model_import(): void
    {
        $references = $this->forbiddenReferences('use App\Models\Campaign;');

        $this->assertSame(['App\Models\Campaign'], $references);
    }

    public function test_patterns_allow_the_module_itself(): void
    {
        $namespaceReferences = $this->forbiddenReferences('namespace App\HotPath;');
        $importReferences = $this->forbiddenReferences('use App\HotPath\RedirectController;');

        $this->assertSame([], $namespaceReferences);
        $this->assertSame([], $importReferences);
    }

    /**
     * @return list<string>
     */
    private function hotPathFiles(string $root): array
    {
        $directory = new RecursiveDirectoryIterator("{$root}/app/HotPath", RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $files[] = "{$root}/routes/hot-path.php";

        return $files;
    }

    /**
     * @return list<string>
     */
    private function forbiddenReferences(string $line): array
    {
        $references = [];

        foreach (self::FORBIDDEN as $pattern) {
            preg_match_all($pattern, $line, $matches);
            array_push($references, ...$matches[0]);
        }

        return $references;
    }
}
