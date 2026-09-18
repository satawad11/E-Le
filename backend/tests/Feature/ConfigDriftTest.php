<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * กัน "config drift" ของค่า PHP ที่สำคัญต่อการรันเทสต์/ระบบจริง
 *
 * memory_limit เคยถูกตั้งไว้ที่เดียว (docker/php/php.ini ผ่าน compose) ทำให้การรัน
 * image ตรง ๆ หรือรันเทสต์ในบริบทที่ไม่ผ่าน compose ได้ค่า default 128M แล้ว OOM
 * ตอนนี้ค่าเดียวกันถูกตั้งไว้ 3 ที่ — เทสต์นี้บังคับให้ทั้งสามตรงกันเสมอ:
 *   1) backend/Dockerfile        (อบมากับ image)
 *   2) docker/php/php.ini        (override ของ compose)
 *   3) .github/workflows/ci.yml  (setup-php ของ GitHub Actions)
 *
 * ไฟล์ (2) และ (3) อยู่นอกโฟลเดอร์ backend — เมื่อรัน container โดย mount เฉพาะ
 * backend/ จะหาไม่เจอ จึง skip (เหมือน ApiSurfaceContractTest) แต่บน CI ที่ checkout
 * ทั้ง repo จะรันจริงและบังคับความตรงกัน
 */
class ConfigDriftTest extends TestCase
{
    #[Test]
    public function memory_limit_is_consistent_across_dockerfile_php_ini_and_ci(): void
    {
        $dockerfile = base_path('Dockerfile');
        $phpIni = base_path('../docker/php/php.ini');
        $ciYml = base_path('../.github/workflows/ci.yml');

        foreach ([$dockerfile, $phpIni, $ciYml] as $path) {
            if (! is_file($path)) {
                $this->markTestSkipped('ไม่พบไฟล์คอนฟิกนอก backend จากบริบทนี้ (บังคับด้วย CI): '.$path);
            }
        }

        // ใช้ pattern เจาะจงตำแหน่ง config จริงของแต่ละไฟล์ เพื่อเลี่ยง "decoy"
        // เช่น คอมเมนต์ที่พูดถึง 128M หรือแฟล็ก -d memory_limit=1G ของ phpstan
        $fromDockerfile = $this->matchValue(
            file_get_contents($dockerfile),
            "/echo '[^']*memory_limit\s*=\s*(\d+[KMG]?)/i"
        );
        $fromPhpIni = $this->matchValue(
            file_get_contents($phpIni),
            '/^memory_limit\s*=\s*(\d+[KMG]?)/im'
        );
        $fromCi = $this->matchValue(
            file_get_contents($ciYml),
            '/ini-values:\s*memory_limit=(\d+[KMG]?)/i'
        );

        $this->assertNotNull($fromDockerfile, 'ไม่พบ memory_limit ที่อบใน backend/Dockerfile');
        $this->assertNotNull($fromPhpIni, 'ไม่พบ memory_limit ใน docker/php/php.ini');
        $this->assertNotNull($fromCi, 'ไม่พบ ini-values: memory_limit ใน .github/workflows/ci.yml');

        $this->assertSame(
            $fromPhpIni,
            $fromDockerfile,
            "memory_limit ไม่ตรงกัน: Dockerfile={$fromDockerfile} แต่ php.ini={$fromPhpIni}"
        );
        $this->assertSame(
            $fromPhpIni,
            $fromCi,
            "memory_limit ไม่ตรงกัน: ci.yml={$fromCi} แต่ php.ini={$fromPhpIni}"
        );
    }

    /**
     * ดึงค่าตัวแรกที่ capture ได้จาก pattern ที่ส่งเข้ามา (คืน null ถ้าไม่พบ)
     */
    private function matchValue(string $contents, string $pattern): ?string
    {
        if (preg_match($pattern, $contents, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
