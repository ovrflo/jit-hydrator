<?php

namespace Ovrflo\JitHydrator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A generated hydrator is written while other requests may read it: a request that finds the file empty or cut short
 * (another one still writing it, or an opcode cache holding what it compiled then) still hydrates, and the file is
 * written whole (to a temporary file renamed into place).
 */
class ConcurrentCacheFileTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/jit-hydrator-race-' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
        @rmdir($this->cacheDir);
    }

    public function testAnEmptyHydratorFileLeftByAnotherRequestStillHydrates(): void
    {
        self::assertSame('1', $this->hydrateInAFreshProcess());
        $files = glob($this->cacheDir . '/Hydrator_*.php') ?: [];
        self::assertCount(1, $files, 'the hydrator is written once, with no temporary file left behind');
        self::assertStringContainsString('class Hydrator_', (string) file_get_contents($files[0]));

        file_put_contents($files[0], '');
        self::assertSame('1', $this->hydrateInAFreshProcess());
        file_put_contents($files[0], '<?php namespace __CG__\Doctrine\JitHydrator; // cut short');
        self::assertSame('1', $this->hydrateInAFreshProcess());
    }

    /**
     * A new process, as another request: the generated class isn't loaded yet.
     */
    private function hydrateInAFreshProcess(): string
    {
        $script = sprintf(<<<'PHP'
            require %s;
            $em = \Ovrflo\JitHydrator\Tests\EntityManagerFactory::create();
            \Ovrflo\JitHydrator\JitObjectHydrator::setProxyDir(%s);
            $em->persist(new \Ovrflo\JitHydrator\Tests\Fixtures\Author('Terry Pratchett'));
            $em->flush();
            $em->clear();
            echo count($em->createQuery('SELECT a FROM ' . \Ovrflo\JitHydrator\Tests\Fixtures\Author::class . ' a')->getResult('jit'));
            PHP, var_export(\dirname(__DIR__) . '/vendor/autoload.php', true), var_export($this->cacheDir, true));
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');

        return trim((string) $output);
    }
}
