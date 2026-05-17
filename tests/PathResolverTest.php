<?php

use PHPUnit\Framework\TestCase;
use OPNsense\Netboot\PathResolver;

/**
 * Tests for OPNsense\Netboot\PathResolver -- the security-critical
 * path resolution used by FilesController to gate every untrusted
 * path from the GUI.
 *
 * We build a real temp directory tree so realpath() actually works.
 * The tree:
 *
 *   $root/                 (the "content root")
 *     foo.txt
 *     sub/
 *       bar.txt
 *     symlink-out  -> outside/secret.txt
 *     symlink-in   -> ../$rootName/sub
 *   $outside/
 *     secret.txt           (we MUST NOT be able to read this through the resolver)
 */
class PathResolverTest extends TestCase
{
    /** @var string */
    private $root;
    /** @var string */
    private $outside;
    /** @var string */
    private $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/netboot-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpBase, 0755, true);
        $this->root    = $this->tmpBase . '/root';
        $this->outside = $this->tmpBase . '/outside';
        mkdir($this->root);
        mkdir($this->outside);
        mkdir($this->root . '/sub');
        file_put_contents($this->root . '/foo.txt', 'hello');
        file_put_contents($this->root . '/sub/bar.txt', 'world');
        file_put_contents($this->outside . '/secret.txt', 'classified');
        symlink($this->outside . '/secret.txt', $this->root . '/symlink-out');
        symlink($this->root . '/sub', $this->root . '/symlink-in');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpBase);
    }

    private function rrmdir(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->rrmdir($path . '/' . $name);
        }
        @rmdir($path);
    }

    // ----------- accepts legitimate paths -------------

    public function testEmptyPathResolvesToRoot(): void
    {
        $this->assertSame(realpath($this->root), PathResolver::within($this->root, ''));
    }

    public function testSimpleFileResolves(): void
    {
        $this->assertSame(realpath($this->root) . '/foo.txt',
            PathResolver::within($this->root, 'foo.txt'));
    }

    public function testNestedFileResolves(): void
    {
        $this->assertSame(realpath($this->root) . '/sub/bar.txt',
            PathResolver::within($this->root, 'sub/bar.txt'));
    }

    public function testLeadingSlashIsStripped(): void
    {
        // The user might pass '/foo.txt' -- we should treat it relative.
        $this->assertSame(realpath($this->root) . '/foo.txt',
            PathResolver::within($this->root, '/foo.txt'));
    }

    public function testNonExistentPathAcceptedWhenMustExistFalse(): void
    {
        // Upload/mkdir case: parent exists, leaf doesn't.
        $this->assertSame(realpath($this->root) . '/new.iso',
            PathResolver::within($this->root, 'new.iso', false));
        $this->assertSame(realpath($this->root) . '/sub/new.iso',
            PathResolver::within($this->root, 'sub/new.iso', false));
    }

    // ----------- rejects traversal attempts -------------

    public function testParentSegmentRejected(): void
    {
        $this->assertSame('', PathResolver::within($this->root, '..'));
        $this->assertSame('', PathResolver::within($this->root, '../etc/passwd'));
        $this->assertSame('', PathResolver::within($this->root, 'sub/../../../etc/passwd'));
    }

    public function testTraversalInUploadDestinationRejected(): void
    {
        $this->assertSame('', PathResolver::within($this->root, '../new.iso', false));
        $this->assertSame('', PathResolver::within($this->root, 'sub/../../../new.iso', false));
    }

    public function testNonExistentPathRejectedWhenMustExistTrue(): void
    {
        $this->assertSame('', PathResolver::within($this->root, 'nope.iso', true));
    }

    public function testEmptyRootRejected(): void
    {
        $this->assertSame('', PathResolver::within('/this/does/not/exist', 'foo.txt'));
    }

    // ----------- defeats symlink trickery -------------

    public function testSymlinkPointingOutsideRootRejected(): void
    {
        // The link itself exists inside the root, but realpath() resolves
        // through it to the outside file. The containment check must
        // reject this.
        $this->assertSame('', PathResolver::within($this->root, 'symlink-out'));
    }

    public function testSymlinkPointingInsideRootAccepted(): void
    {
        // Symlink that stays inside the root is OK.
        $result = PathResolver::within($this->root, 'symlink-in');
        $this->assertNotSame('', $result);
        $this->assertSame(realpath($this->root) . '/sub', $result);
    }

    // ----------- defeats prefix-confusion attacks -------------

    public function testSiblingDirectoryWithRootPrefixRejected(): void
    {
        // Create a sibling that shares the root's name as a prefix:
        //   $tmpBase/rootevil
        // and verify that resolving its contents through the root does NOT
        // succeed. (This is the classic '/var/wwwsibling' vs '/var/www' bug.)
        $evil = $this->tmpBase . '/rootevil';
        mkdir($evil);
        file_put_contents($evil . '/leak.txt', 'pwn');
        // Use the FULL path of the sibling as the supposed rel path;
        // strip-leading-slash will make it relative-looking, but it won't
        // resolve inside the root.
        $this->assertSame('', PathResolver::within($this->root, '../rootevil/leak.txt'));
    }

    // ----------- containsTraversal helper -------------

    public function testContainsTraversalSegmentAware(): void
    {
        // Whole '..' segments: traversal
        $this->assertTrue(PathResolver::containsTraversal('..'));
        $this->assertTrue(PathResolver::containsTraversal('a/../b'));
        $this->assertTrue(PathResolver::containsTraversal('a/b/..'));

        // '..' as a substring of a real filename: NOT traversal
        $this->assertFalse(PathResolver::containsTraversal('foo..bar'));
        $this->assertFalse(PathResolver::containsTraversal('.bashrc'));
        $this->assertFalse(PathResolver::containsTraversal('a/foo..bar/b'));
    }

    // ----------- isSafeName -------------

    public function testIsSafeNameAcceptsTypicalFiles(): void
    {
        $this->assertTrue(PathResolver::isSafeName('netboot.xyz.kpxe'));
        $this->assertTrue(PathResolver::isSafeName('netboot.xyz.efi'));
        $this->assertTrue(PathResolver::isSafeName('Clonezilla-live-amd64.iso'));
        $this->assertTrue(PathResolver::isSafeName('memtest86+.bin'));
    }

    public function testIsSafeNameRejectsHiddenAndSpecial(): void
    {
        $this->assertFalse(PathResolver::isSafeName(''));
        $this->assertFalse(PathResolver::isSafeName('.'));
        $this->assertFalse(PathResolver::isSafeName('..'));
        $this->assertFalse(PathResolver::isSafeName('.bashrc'));
    }

    public function testIsSafeNameRejectsPathSeparators(): void
    {
        $this->assertFalse(PathResolver::isSafeName('a/b'));
        $this->assertFalse(PathResolver::isSafeName('a\\b'));
    }

    public function testIsSafeNameRejectsControlChars(): void
    {
        $this->assertFalse(PathResolver::isSafeName("foo\x00bar"));
        $this->assertFalse(PathResolver::isSafeName("foo\nbar"));
        $this->assertFalse(PathResolver::isSafeName("foo\tbar"));
    }
}
