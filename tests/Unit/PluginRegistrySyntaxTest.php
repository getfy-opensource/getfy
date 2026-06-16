<?php

namespace Tests\Unit;

use App\Plugins\PluginRegistry;
use Tests\TestCase;

class PluginRegistrySyntaxTest extends TestCase
{
    public function test_validate_plugin_package_accepts_valid_bootstrap(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'getfy-plugin-syntax-'.uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/plugin.json', json_encode([
            'slug' => 'syntax-test',
            'name' => 'Syntax Test',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/bootstrap.php', "<?php\nreturn function (): void {};\n");

        try {
            $errors = PluginRegistry::validatePluginPackage($dir);
            $bootstrapErrors = array_filter($errors, fn (string $e) => str_starts_with($e, 'bootstrap.php:'));
            $this->assertSame([], array_values($bootstrapErrors));
        } finally {
            @unlink($dir.'/bootstrap.php');
            @unlink($dir.'/plugin.json');
            @rmdir($dir);
        }
    }

    public function test_validate_plugin_package_reports_invalid_bootstrap_syntax(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'getfy-plugin-syntax-'.uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/plugin.json', json_encode([
            'slug' => 'syntax-test',
            'name' => 'Syntax Test',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/bootstrap.php', "<?php\nfunction broken( {\n");

        try {
            $errors = PluginRegistry::validatePluginPackage($dir);
            $this->assertNotEmpty($errors);
            $this->assertTrue(
                collect($errors)->contains(fn (string $e) => str_starts_with($e, 'bootstrap.php:'))
            );
        } finally {
            @unlink($dir.'/bootstrap.php');
            @unlink($dir.'/plugin.json');
            @rmdir($dir);
        }
    }
}
